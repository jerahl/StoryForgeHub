"""
engine.py — reconcile orchestration (MASTER-PLAN Phase 2, local intra-box).

Wires the pure reconcile (reconcile.py) to the real world:
    folder snapshot (codex_sync_lib) + DB snapshot (api.php export) + state.json
        -> reconcile -> [dry-run report]  (default)
                     -> apply via api.php / write_entry, then commit state.json (--apply)

SCOPE: this reconciles ENTRIES — the round-trippable contract surface (entries
sync both ways; manuscript prose and hand-authored notes stay folder-owned and
are push-only, handled by the existing push path). Chapters/meta/notes 3-way
reconcile + the "write prose back to disk" path are deliberately out of scope
here (the latter is the Phase 9 contract change). The full continuous-sync
cycle lives in cycle.py and reuses these helpers.

DRY-RUN BY DEFAULT. `--apply` performs writes. Conflicts and deletions are only
ever reported — never auto-resolved.

Usage:
    python -m sync_engine.engine --books /srv/codex/books \
        --api http://127.0.0.1:8081/api.php --token "$API_KEY" [--state state.json] [--apply]
"""
from __future__ import annotations
import argparse
import hashlib
import json
import os
import sys
from typing import Dict, Tuple

# Make sibling modules importable whether this is run as `python -m sync_engine.engine`
# (parent dir on the path) or as `python engine.py` (this dir on the path).
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from reconcile import reconcile, next_state, Decision, KeyResult
import codex_sync_lib as csl
from api_client import CodexApi


# ---- keying + hashing (pure; unit-tested offline) -------------------------
def entry_key(book_id: str, db: str, slug: str) -> str:
    return f"entry:{book_id}:{db}:{slug}"


def hash_entry(struct: dict) -> str:
    """Content hash of an entry = md5 of its canonical rendered Markdown, so the
    folder side and DB side compare equal iff the rendered entry is identical."""
    return hashlib.md5(csl.render_entry(struct).encode("utf-8")).hexdigest()


def _entries_to_maps(books: list) -> Tuple[Dict[str, str], Dict[str, dict]]:
    """From a list of {book:{id,...}, entries:[...]} -> (key->hash, key->struct)."""
    hashes: Dict[str, str] = {}
    structs: Dict[str, dict] = {}
    for bk in books:
        bid = bk["book"]["id"]
        for e in bk.get("entries", []):
            if e.get("error"):
                continue  # unparseable file — never let it drive a delete/overwrite
            k = entry_key(bid, e["db"], e["slug"])
            hashes[k] = hash_entry(e)
            structs[k] = e
    return hashes, structs


def build_folder_maps(snapshot: dict):
    return _entries_to_maps(snapshot.get("books", []))


def build_db_maps(export: dict):
    return _entries_to_maps(export.get("books", []))


# ---- state.json -----------------------------------------------------------
def load_state(path: str) -> Dict[str, str]:
    if path and os.path.isfile(path):
        with open(path, encoding="utf-8") as f:
            return json.load(f)
    return {}


def save_state(path: str, state: Dict[str, str]) -> None:
    d = os.path.dirname(path)
    if d:
        os.makedirs(d, exist_ok=True)  # create the state dir when permitted
    tmp = path + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(state, f, indent=0, sort_keys=True)
    os.replace(tmp, path)  # atomic


# ---- report ---------------------------------------------------------------
def summarize(results: Dict[str, KeyResult]) -> Dict[str, int]:
    counts: Dict[str, int] = {}
    for r in results.values():
        counts[r.decision.value] = counts.get(r.decision.value, 0) + 1
    return counts


def print_report(results: Dict[str, KeyResult]) -> None:
    counts = summarize(results)
    order = [Decision.PUSH, Decision.WRITE_DOWN, Decision.CREATE_DB, Decision.CREATE_FOLDER,
             Decision.FOLDER_WINS, Decision.CONVERGED, Decision.CONFLICT, Decision.DELETION,
             Decision.CLEAR_STATE, Decision.NOOP]
    print("Reconcile plan (entries):")
    for d in order:
        n = counts.get(d.value, 0)
        if n:
            print(f"  {d.value:13} {n}")
    # Always surface the items that need a human.
    for r in results.values():
        if r.decision in (Decision.CONFLICT, Decision.DELETION):
            print(f"  ! {r.decision.value.upper():9} {r.key}")


# ---- apply (guarded; --apply only) ----------------------------------------
def apply(api: CodexApi, books_root: str, results: Dict[str, KeyResult],
          folder_structs: Dict[str, dict], db_structs: Dict[str, dict]) -> Dict[str, KeyResult]:
    """Apply only the safe, confirmed-write decisions. Returns the subset of
    results that were successfully written (so state can advance for them)."""
    done: Dict[str, KeyResult] = {}
    books_cfg = {b["id"]: b for b in csl.load_books_config(books_root)}
    for k, r in results.items():
        try:
            if r.writes_to_db:                       # folder -> DB via api.php push
                _, bid, db, slug = k.split(":", 3)
                struct = folder_structs[k]
                relpath = f"Codex/{csl.DBMETA[db]['folder']}/{slug}.md"
                api.push([{"folder": books_cfg[bid]["folder"],
                           "book": {"id": bid, **books_cfg[bid]},
                           "files": {relpath: csl.render_entry(struct)}}])
                done[k] = r
            elif r.writes_to_folder:                 # DB -> folder (entries only)
                _, bid, db, slug = k.split(":", 3)
                csl.write_entry(books_root, bid, db, db_structs[k])
                done[k] = r
            else:
                done[k] = r                          # noop / converged / clear_state: state may advance
        except Exception as ex:  # noqa: BLE001 — one bad item must not abort the run
            print(f"  x apply failed for {k}: {ex}")
    return done


def main() -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--books", required=True, help="canonical books root (e.g. /srv/codex/books)")
    ap.add_argument("--api", default="http://127.0.0.1:8081/api.php")
    ap.add_argument("--token", default=None, help="defaults to $API_KEY")
    ap.add_argument("--state", default="sync_state.json")
    ap.add_argument("--apply", action="store_true", help="perform writes (default: dry-run)")
    args = ap.parse_args()
    token = args.token or os.environ.get("API_KEY", "")
    if not token:
        print("ERROR: no token (pass --token or set API_KEY).")
        return 2

    api = CodexApi(args.api, token)
    folder_hashes, folder_structs = build_folder_maps(csl.snapshot(args.books))
    db_hashes, db_structs = build_db_maps(api.export())
    state = load_state(args.state)

    results = reconcile(folder_hashes, db_hashes, state)
    print_report(results)

    if not args.apply:
        print("\n(dry-run — no changes written. Re-run with --apply to act.)")
        return 0

    done = apply(api, args.books, results, folder_structs, db_structs)
    new_state = next_state(state, done)
    save_state(args.state, new_state)
    print(f"\nApplied. state.json now tracks {len(new_state)} entries.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
