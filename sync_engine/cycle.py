"""
cycle.py — one full continuous-sync cycle (MASTER-PLAN Phase 3).

The always-on replacement for the Windows scheduled task (sync-codex.ps1's main
flow). Run by codex-sync.timer every N minutes on the box:

    entry reconcile (engine.reconcile)            # bidirectional, 3-way
      -> apply pulls   (DB -> folder, entries)
      -> one consolidated push (folder -> DB):
           changed entries + ALL manuscript + ALL meta + ALL notes
           + manuscript_present (lets the app archive removed chapters)
      -> commit state.json (pulls immediately; pushes only after the app confirms)

Token comes from $API_KEY (systemd EnvironmentFile) so no secret on the cmdline.
Dry-run with --dry-run. Conflicts/deletions are reported, never auto-resolved.
NOT ported from PS yet: books.json regeneration / new-book auto-register, and the
tasks inbox/outbox bridge (the latter becomes remote MCP tools — next slice).

Usage:
    python -m sync_engine.cycle --books /srv/codex/books [--api ...] [--dry-run]
"""
from __future__ import annotations
import argparse
import glob
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from reconcile import reconcile, next_state, Decision
import codex_sync_lib as csl
from api_client import CodexApi, ApiError
from engine import build_folder_maps, build_db_maps, hash_entry, load_state, save_state


def read_book_files(book_dir: str):
    """Collect the push-only folder-owned files for one book, mirroring PS:
    all Manuscript/*.md, Codex/Meta/*.md, and Codex/Notes/**/*.md (raw, BOM-safe).
    Returns (files{relpath:content}, manuscript_present[basenames])."""
    files = {}
    present = []
    ms = os.path.join(book_dir, "Manuscript")
    if os.path.isdir(ms):
        for p in sorted(glob.glob(os.path.join(ms, "*.md"))):
            name = os.path.basename(p)
            files[f"Manuscript/{name}"] = _read(p)
            bn = name.lower()
            if bn != "readme.md" and not bn.startswith("_"):
                present.append(name)
    meta = os.path.join(book_dir, "Codex", "Meta")
    if os.path.isdir(meta):
        for p in sorted(glob.glob(os.path.join(meta, "*.md"))):
            files[f"Codex/Meta/{os.path.basename(p)}"] = _read(p)
    notes = os.path.join(book_dir, "Codex", "Notes")
    if os.path.isdir(notes):
        for p in sorted(glob.glob(os.path.join(notes, "**", "*.md"), recursive=True)):
            rel = os.path.relpath(p, book_dir).replace(os.sep, "/")
            files[rel] = _read(p)
    return files, present


def _read(path: str) -> str:
    with open(path, encoding="utf-8-sig") as f:
        return f.read().replace("\x00", "")


def build_push(books_root, results, folder_structs):
    """Assemble the per-book push payload. Returns (books_payload, pending) where
    pending maps entry-key -> folder hash to commit to state ONLY after the app
    confirms the push."""
    books_cfg = {b["id"]: b for b in csl.load_books_config(books_root)}
    pending = {}
    per_book = {}  # folder -> files dict
    # 1) changed entries (folder -> DB)
    for k, r in results.items():
        if not r.writes_to_db:
            continue
        _, bid, db, slug = k.split(":", 3)
        cfg = books_cfg.get(bid)
        if not cfg:
            continue
        folder = cfg["folder"]
        relpath = f"Codex/{csl.DBMETA[db]['folder']}/{slug}.md"
        per_book.setdefault(folder, {})[relpath] = csl.render_entry(folder_structs[k])
        pending[k] = r.folder  # folder hash
    # 2) always-refresh folder-owned files per book (manuscript/meta/notes)
    payload = []
    for bid, cfg in books_cfg.items():
        folder = cfg["folder"]
        book_dir = os.path.join(books_root, folder)
        files = per_book.get(folder, {})
        extra, present = read_book_files(book_dir)
        files.update(extra)
        if not files:
            continue
        bk = {"folder": folder, "files": files, "book": cfg}
        if os.path.isdir(os.path.join(book_dir, "Manuscript")):
            bk["manuscript_present"] = present
            bk["manuscript_count"] = len(present)
        payload.append(bk)
    return payload, pending


def run(books_root, api, state_path, dry_run):
    # Ping guard: never touch state if the endpoint isn't really the app
    # (e.g. an HTML challenge page) — mirrors PS's guard.
    try:
        p = api.ping()
    except ApiError as e:
        print(f"ERROR: cannot reach API ({e}). Aborting before touching state.")
        return 1
    if not p.get("app"):
        print("ERROR: API did not return a valid app ping. Aborting (state untouched).")
        return 1

    folder_h, folder_s = build_folder_maps(csl.snapshot(books_root))
    db_h, db_s = build_db_maps(api.export())
    state = load_state(state_path)
    results = reconcile(folder_h, db_h, state)

    counts = {}
    for r in results.values():
        counts[r.decision.value] = counts.get(r.decision.value, 0) + 1
    print("Reconcile (entries):", ", ".join(f"{k}={v}" for k, v in sorted(counts.items())))
    for r in results.values():
        if r.decision in (Decision.CONFLICT, Decision.DELETION):
            print(f"  ! {r.decision.value.upper()} {r.key}")

    if dry_run:
        payload, _ = build_push(books_root, results, folder_s)
        nfiles = sum(len(b["files"]) for b in payload)
        print(f"(dry-run) would write {sum(1 for r in results.values() if r.writes_to_folder)} pull(s) "
              f"and push {nfiles} file(s) across {len(payload)} book(s). No changes made.")
        return 0

    # 1) apply entry pulls (DB -> folder); commit their state immediately (file written)
    pulled = 0
    for k, r in results.items():
        if r.writes_to_folder:
            _, bid, db, slug = k.split(":", 3)
            csl.write_entry(books_root, bid, db, db_s[k])
            state[k] = db_h[k]
            pulled += 1

    # 2) one consolidated push (changed entries + manuscript/meta/notes)
    payload, pending = build_push(books_root, results, folder_s)
    pushed_ok = False
    if payload:
        resp = api.push(payload)
        report = (resp or {}).get("report") if isinstance(resp, dict) else None
        if not report or report.get("entries") is None:
            print("  ERROR: push not confirmed (no report.entries). Leaving state so the next run retries.")
        else:
            pushed_ok = True
            for k, h in pending.items():
                state[k] = h  # commit ONLY confirmed pushes
            print(f"  pushed: entries={report.get('entries')} chapters={report.get('chapters')} "
                  f"meta={report.get('meta')} notes={report.get('notes')} "
                  f"archived={report.get('archived')}")

    # 3) advance state for noop/converged/clear (no write needed); never for conflict/deletion
    for k, r in results.items():
        if r.decision in (Decision.NOOP, Decision.CONVERGED):
            state[k] = folder_h.get(k) or db_h.get(k)
        elif r.decision is Decision.CLEAR_STATE:
            state.pop(k, None)
    save_state(state_path, state)

    conflicts = sum(1 for r in results.values() if r.decision is Decision.CONFLICT)
    print(f"=== cycle done. pulled={pulled} pushed={'yes' if pushed_ok else 'no'} conflicts={conflicts} ===")
    return 0


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--books", required=True)
    ap.add_argument("--api", default="http://127.0.0.1:8081/api.php")
    ap.add_argument("--token", default=None, help="defaults to $API_KEY")
    ap.add_argument("--state", default="sync_state.json")
    ap.add_argument("--dry-run", action="store_true")
    args = ap.parse_args()
    token = args.token or os.environ.get("API_KEY", "")
    if not token:
        print("ERROR: no token (pass --token or set API_KEY).")
        return 2
    return run(args.books, CodexApi(args.api, token), args.state, args.dry_run)


if __name__ == "__main__":
    raise SystemExit(main())
