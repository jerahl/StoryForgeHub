"""
mcp_tools.py — the capabilities behind the MCP server (MASTER-PLAN Phase 3).

Pure-ish wrappers over api.php (the single DB-writer path) and the reconcile
engine. Kept separate from the transport (mcp_server.py) so the logic is testable
offline with a fake api object. Every write still flows through api.php.
"""
from __future__ import annotations
import datetime
import os
import subprocess
import sys
from typing import Any, Dict, List, Optional

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
        q = (query or "").lower().strip()
        hits = []
        for bid, e in self._all_entries():
            if book and bid != book:
                continue
            hay = " ".join([e.get("name", ""), e.get("slug", ""),
                            " ".join(f.get("value", "") for f in e.get("fields", [])),
                            " ".join(s.get("body", "") for s in e.get("sections", []))]).lower()
            if q in hay:
                hits.append({"book": bid, "db": e["db"], "slug": e["slug"], "name": e.get("name", "")})
                if len(hits) >= limit:
                    break
        return hits

    def get_entry(self, book: str, db: str, slug: str) -> Optional[str]:
        for bid, e in self._all_entries():
            if bid == book and e["db"] == db and e["slug"] == slug:
                return csl.render_entry(e)
        return None

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
    def save_entry(self, book: str, db: str, slug: str, md: str) -> dict:
        cfg = {b["id"]: b for b in csl.load_books_config(self.books_root)}.get(book)
        if not cfg:
            raise ValueError(f"unknown book {book}")
        relpath = f"Codex/{csl.DBMETA[db]['folder']}/{slug}.md"
        return self.api.push([{"folder": cfg["folder"], "book": {"id": book, **cfg},
                               "files": {relpath: md}}])

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
