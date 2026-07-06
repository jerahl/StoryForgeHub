"""
mcp_tools.py — the capabilities behind the MCP server (MASTER-PLAN Phase 3).

Pure-ish wrappers over api.php (the single DB-writer path) and the reconcile
engine. Kept separate from the transport (mcp_server.py) so the logic is testable
offline with a fake api object. Every write still flows through api.php.
"""
from __future__ import annotations
import datetime
import os
import re
import subprocess
import sys
from typing import Any, Dict, List, Optional

_MANUSCRIPT_RE = re.compile(r"^Manuscript/.+\.md$", re.IGNORECASE)

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import codex_sync_lib as csl


class CodexTools:
    def __init__(self, api, books_root: str, engine_dir: Optional[str] = None):
        self.api = api
        self.books_root = books_root
        self.engine_dir = engine_dir or os.path.dirname(os.path.abspath(__file__))

    # ---- status / search / reads ----
    def status(self) -> Dict[str, Any]:
        ping = self.api.ping()
        exp = self.api.export()
        books = exp.get("books", [])
        return {
            "app": ping.get("app"), "time": ping.get("time"),
            "books": len(books),
            "entries": sum(len(b.get("entries", [])) for b in books),
            "chapters": sum(len(b.get("chapters", [])) for b in books),
        }

    def _all_entries(self):
        for b in self.api.export().get("books", []):
            bid = b["book"]["id"]
            for e in b.get("entries", []):
                if not e.get("error"):
                    yield bid, e

    def search(self, query: str, book: Optional[str] = None, limit: int = 25) -> List[dict]:
        """Server-side search (api.php `search`): entries, chapters, and notes,
        each hit with a snippet — no whole-snapshot export on this path anymore."""
        q = (query or "").strip()
        if not q:
            return []
        return self.api.search(q, book, max(1, int(limit)))

    def get_entry(self, book: str, db: str, slug: str) -> Optional[str]:
        for bid, e in self._all_entries():
            if bid == book and e["db"] == db and e["slug"] == slug:
                return csl.render_entry(e)
        return None

    def list_entries(self, book: str, db: Optional[str] = None) -> List[dict]:
        return self.api.list_entries(book, db)

    def get_chapter(self, book: str, chapter_id=None, file: Optional[str] = None) -> dict:
        if chapter_id is None and not file:
            raise ValueError("pass chapter_id or file")
        return self.api.get_chapter(book, chapter_id, file)

    def get_diagnostics(self, book: str, chapter_id) -> dict:
        return self.api.get_diagnostics(book, chapter_id)

    def list_chapters(self, book: Optional[str] = None) -> List[dict]:
        out = []
        for b in self.api.export().get("books", []):
            bid = b["book"]["id"]
            if book and bid != book:
                continue
            for c in b.get("chapters", []):
                out.append({"book": bid, "num": c.get("num"), "title": c.get("title"),
                            "status": c.get("status"), "words": c.get("words"), "file": c.get("file")})
        return out

    # ---- tasks / writing log (all via the apply action) ----
    def get_tasks(self, book: Optional[str] = None, for_claude: Optional[int] = None,
                  status: Optional[str] = None) -> List[dict]:
        return self.api.get_tasks(book, for_claude, status)

    def complete_task(self, task_id: int, result: str = "") -> dict:
        return self.api.apply({"task_results": [{"id": task_id, "status": "done", "result": result}]})

    def create_task(self, book: str, title: str, body: str = "",
                    for_claude: bool = False, priority: str = "med") -> dict:
        title = (title or "").strip()
        if not title:
            raise ValueError("title is required")
        if priority not in ("low", "med", "high"):
            raise ValueError("priority must be low|med|high")
        return self.api.create_task({"book": book, "title": title, "body": body,
                                     "for_claude": bool(for_claude), "priority": priority})

    def update_task(self, task_id: int, status: Optional[str] = None,
                    result: Optional[str] = None, title: Optional[str] = None,
                    body: Optional[str] = None) -> dict:
        patch = {"id": int(task_id)}
        if status is not None:
            if status not in ("todo", "doing", "done"):
                raise ValueError("status must be todo|doing|done")
            patch["status"] = status
        if result is not None:
            patch["result"] = result
        if title is not None:
            patch["title"] = title
        if body is not None:
            patch["body"] = body
        if len(patch) == 1:
            raise ValueError("nothing to update")
        return self.api.update_task(patch)

    def apply_results(self, payload: dict) -> dict:
        return self.api.apply(payload)

    def log_writing(self, book: str, words_added: int, total_words: int = 0,
                    chapters: str = "", minutes: int = 0, mood: str = "", note: str = "") -> dict:
        row = {"book_id": book, "log_date": datetime.date.today().isoformat(),
               "words_added": int(words_added), "total_words": int(total_words),
               "chapters": chapters, "minutes": int(minutes), "mood": mood,
               "note": note, "source": "claude"}
        return self.api.apply({"writing_log": [row]})

    # ---- writes ----
    def _resolve_book(self, book: str):
        """Resolve a book to (folder, metadata, snapshot). Prefer the live server
        snapshot (the source of truth for the on-disk folder name) and fall back
        to the local books config, so a push works even without a populated
        books_root. `snapshot` is the export's book struct (or None)."""
        for b in self.api.export().get("books", []):
            rec = b.get("book") or {}
            if rec.get("id") == book and rec.get("folder"):
                return rec["folder"], dict(rec), b
        cfg = {b["id"]: b for b in csl.load_books_config(self.books_root)}.get(book)
        if cfg:
            return cfg["folder"], {"id": book, **cfg}, None
        raise ValueError(f"unknown book {book}")

    def push_files(self, book: str, files: Dict[str, str],
                   reconcile_chapters: bool = False) -> dict:
        """Push one or more files (relpath -> Markdown) to a book via api.php.

        Accepts any relpath api.php's push understands: Manuscript/<file>.md
        (chapters), Codex/<Folder>/<slug>.md (entries), Codex/Notes/<slug>.md,
        Codex/Meta/<slug>.md, Codex/Sources/<key>.md, Codex/Meta/progressions.md.

        Guard: pushing any Manuscript/*.md normally makes the app archive every
        chapter NOT in the push (folder is treated as the full set). Unless
        reconcile_chapters=True, we declare the book's current chapters present so
        adding one chapter never archives the rest.
        """
        files = {str(k).replace("\\", "/"): v for k, v in (files or {}).items()}
        if not files:
            raise ValueError("no files to push")
        folder, meta, snapshot = self._resolve_book(book)
        entry = {"folder": folder, "book": meta, "files": files}
        pushed_ms = [k for k in files if _MANUSCRIPT_RE.match(k)]
        if pushed_ms and not reconcile_chapters:
            present = {os.path.basename(k) for k in pushed_ms}
            for c in (snapshot or {}).get("chapters", []):
                if c.get("file"):
                    present.add(os.path.basename(c["file"]))
            entry["manuscript_present"] = sorted(present)
        return self.api.push([entry])

    def save_entry(self, book: str, db: str, slug: str, md: str) -> dict:
        if db not in csl.DBMETA:
            raise ValueError(f"unknown db {db}")
        relpath = f"Codex/{csl.DBMETA[db]['folder']}/{slug}.md"
        return self.push_files(book, {relpath: md})

    def save_chapter(self, book: str, filename: str, md: str,
                     reconcile: bool = False) -> dict:
        """Create/update a manuscript chapter from Markdown. `filename` is a bare
        file name (e.g. 'ch-05-the-wall.md'); '.md' is appended if missing. Adding
        a chapter never archives the others unless reconcile=True."""
        fn = os.path.basename(str(filename).replace("\\", "/").strip())
        if not fn:
            raise ValueError("filename is required")
        if not fn.lower().endswith(".md"):
            fn += ".md"
        return self.push_files(book, {f"Manuscript/{fn}": md}, reconcile_chapters=reconcile)

    # ---- sync (runs the reconcile cycle out-of-process) ----
    def sync(self, dry_run: bool = True, token: Optional[str] = None,
             api_url: str = "http://127.0.0.1:8081/api.php") -> str:
        cmd = [sys.executable, os.path.join(self.engine_dir, "cycle.py"),
               "--books", self.books_root, "--api", api_url,
               "--state", "/var/lib/codex/sync_state.json"]
        if dry_run:
            cmd.append("--dry-run")
        env = dict(os.environ)
        if token:
            env["API_KEY"] = token
        p = subprocess.run(cmd, capture_output=True, text=True, env=env, timeout=180)
        return (p.stdout + p.stderr).strip()
