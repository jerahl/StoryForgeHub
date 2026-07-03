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
import hashlib
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from reconcile import reconcile, next_state, Decision
import codex_sync_lib as csl
from api_client import CodexApi, ApiError
from engine import build_folder_maps, build_db_maps, hash_entry, load_state, save_state


# ---- chapter authority (DB-fresh beats stale folder) ----------------------
# Manuscript prose is folder-owned and push-only (folder -> DB). That means a
# chapter edited in the app/MCP (a DB write) gets reverted the next time the sync
# re-pushes the stale folder copy. To make a *fresh DB write* authoritative over a
# *stale folder* copy, we track, per chapter, the (folder, db) content hashes we
# last synced, in state.json under `chapter:<book>:<basename>` = "<folderMd5>|<dbMd5>".
#
# On each cycle we recompute both hashes (same-source comparisons, so no PHP<->Python
# markdown-normalization parity is required):
#   db unchanged since baseline  -> folder authoritative -> PUSH (unchanged behavior)
#   db changed since baseline    -> a fresh DB write      -> WITHHOLD (don't clobber)
#     ...and if the folder ALSO changed, it's a real CONFLICT -> withhold + report
# First sight (no baseline) pushes folder->DB to establish the baseline, matching
# how first-sight entries treat the folder as source of truth.

def _md5(text: str) -> str:
    return hashlib.md5((text or "").encode("utf-8")).hexdigest()


def _parse_baseline(val):
    """Parse a stored 'chapter:...' state value 'folderMd5|dbMd5' -> (folder, db)."""
    if not val or "|" not in val:
        return None
    f, d = val.split("|", 1)
    return (f, d)


def decide_chapter(folder_md5, db_md5, baseline):
    """Pure per-chapter decision. baseline is (folderMd5, dbMd5) or None.

    Returns (action, conflict) where action is "push" or "withhold".
    - No baseline: "push" (first sight — folder establishes the baseline).
    - DB body changed since baseline: "withhold" (fresh DB write wins); conflict=True
      when the folder also changed (never auto-merge — report it).
    - Otherwise: "push" (folder authoritative; includes the no-change refresh).
    """
    if baseline is None:
        return ("push", False)
    folder_base, db_base = baseline
    db_changed = db_md5 is not None and db_md5 != db_base
    folder_changed = folder_md5 != folder_base
    if db_changed:
        return ("withhold", folder_changed)
    return ("push", False)


def build_db_chapters(export: dict):
    """From an api.php export -> {book_folder: {basename_lower: body_md5}}. Skips
    README/underscore files (never chapters), mirroring the push-side rules."""
    out = {}
    for b in export.get("books", []):
        folder = (b.get("book") or {}).get("folder")
        if not folder:
            continue
        m = {}
        for c in b.get("chapters", []):
            f = c.get("file")
            if not f:
                continue
            bn = os.path.basename(str(f)).lower()
            if bn == "readme.md" or bn.startswith("_"):
                continue
            m[bn] = c.get("body_md5")
        out[folder] = m
    return out


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


def build_push(books_root, results, folder_structs, db_chapters=None, chapter_state=None):
    """Assemble the per-book push payload.

    Returns (books_payload, pending, chapter_report):
      pending         entry-key -> folder hash, committed to state ONLY after the
                      app confirms the push (unchanged).
      chapter_report  {"withheld", "conflicts", "protected_new", "baselines"} —
                      chapters whose fresh DB copy was protected from a stale-folder
                      overwrite (withheld), true both-sides conflicts, brand-new
                      DB-only chapters kept out of the archive sweep, and the folder
                      hashes to record as the new baseline for chapters we DO push.

    When db_chapters is None (e.g. entries-only callers / offline tests) chapter
    gating is disabled and manuscript files are pushed exactly as before.
    """
    books_cfg = {b["id"]: b for b in csl.load_books_config(books_root)}
    pending = {}
    chapter_report = {"withheld": [], "conflicts": [], "protected_new": [], "baselines": {}}
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
    state = chapter_state or {}
    for bid, cfg in books_cfg.items():
        folder = cfg["folder"]
        book_dir = os.path.join(books_root, folder)
        files = per_book.get(folder, {})
        extra, present = read_book_files(book_dir)
        files.update(extra)
        has_manuscript = os.path.isdir(os.path.join(book_dir, "Manuscript"))

        # ---- chapter authority: withhold stale-folder pushes over fresh DB edits ----
        if db_chapters is not None:
            dbmap = db_chapters.get(folder, {})
            present_lower = {p.lower() for p in present}
            for rel in [r for r in list(files) if r.startswith("Manuscript/")]:
                bn = os.path.basename(rel).lower()
                key = f"chapter:{bid}:{bn}"
                action, conflict = decide_chapter(
                    _md5(files[rel]), dbmap.get(bn), _parse_baseline(state.get(key)))
                if action == "withhold":
                    del files[rel]                       # don't clobber the fresh DB copy
                    chapter_report["withheld"].append(key)
                    if conflict:
                        chapter_report["conflicts"].append(key)
                else:
                    chapter_report["baselines"][key] = _md5_of_rel(files, rel)
            # Protect brand-new DB-only chapters (created in-app, never synced to the
            # folder) from the archive sweep — without a baseline they'd otherwise be
            # archived as "folder-removed". Previously-synced-then-folder-deleted
            # chapters (baseline present) still archive as before.
            for bn in dbmap:
                if bn in present_lower:
                    continue
                if state.get(f"chapter:{bid}:{bn}") is None:
                    present.append(bn)
                    chapter_report["protected_new"].append(f"chapter:{bid}:{bn}")

        # Emit the book when it has files, or (in a real gated run) whenever it has a
        # Manuscript dir — so manuscript_present still flows to drive archival even if
        # every chapter was withheld this cycle.
        if not files and not (db_chapters is not None and has_manuscript):
            continue
        bk = {"folder": folder, "files": files, "book": cfg}
        if has_manuscript:
            bk["manuscript_present"] = present
            bk["manuscript_count"] = len(present)
        payload.append(bk)
    return payload, pending, chapter_report


def _md5_of_rel(files, rel):
    """Folder-side md5 of a manuscript file's pushed bytes (baseline to record)."""
    return _md5(files.get(rel, ""))


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
    export = api.export()
    db_h, db_s = build_db_maps(export)
    db_chapters = build_db_chapters(export)      # book_folder -> {basename: body_md5}
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
        payload, _, chrep = build_push(books_root, results, folder_s, db_chapters, state)
        nfiles = sum(len(b["files"]) for b in payload)
        print(f"(dry-run) would write {sum(1 for r in results.values() if r.writes_to_folder)} pull(s) "
              f"and push {nfiles} file(s) across {len(payload)} book(s). No changes made.")
        _print_chapter_report(chrep)
        return 0

    # 1) apply entry pulls (DB -> folder); commit their state immediately (file written)
    pulled = 0
    for k, r in results.items():
        if r.writes_to_folder:
            _, bid, db, slug = k.split(":", 3)
            csl.write_entry(books_root, bid, db, db_s[k])
            state[k] = db_h[k]
            pulled += 1

    # 2) one consolidated push (changed entries + manuscript/meta/notes). Chapters
    #    whose DB copy was edited since the last sync are withheld (fresh DB wins).
    payload, pending, chrep = build_push(books_root, results, folder_s, db_chapters, state)
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
            # Advance chapter baselines for the chapters we actually pushed (folder
            # authoritative), reading the now-current DB body hash from a fresh
            # export. Withheld/conflict chapters are absent from chrep["baselines"],
            # so their baseline is preserved and their fresh DB copy stays protected.
            if chrep["baselines"]:
                fresh_db_chapters = build_db_chapters(api.export())
                bid2folder = {b["id"]: b["folder"] for b in csl.load_books_config(books_root)}
                for key, fmd5 in chrep["baselines"].items():
                    _, bid, bn = key.split(":", 2)
                    dbmd5 = (fresh_db_chapters.get(bid2folder.get(bid, ""), {}) or {}).get(bn)
                    state[key] = f"{fmd5}|{dbmd5 or ''}"
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
    _print_chapter_report(chrep)
    print(f"=== cycle done. pulled={pulled} pushed={'yes' if pushed_ok else 'no'} "
          f"conflicts={conflicts} chapters_protected={len(chrep['withheld'])} ===")
    return 0


def _print_chapter_report(chrep) -> None:
    """Surface the chapters the DB-authority guard acted on, so a stale-folder
    overwrite that was prevented is visible (never silent)."""
    if chrep["withheld"]:
        kept = len(chrep["withheld"]) - len(chrep["conflicts"])
        print(f"  chapters: protected fresh DB copy on {kept}, conflict on {len(chrep['conflicts'])} "
              f"(folder push withheld — the DB copy is authoritative)")
        for key in chrep["conflicts"]:
            print(f"  ! CHAPTER-CONFLICT {key} (edited in BOTH the app and the folder — resolve manually)")
    if chrep["protected_new"]:
        print(f"  chapters: kept {len(chrep['protected_new'])} app-created chapter(s) out of the archive sweep")


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
