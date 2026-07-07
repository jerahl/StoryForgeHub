"""Offline tests for CodexTools using a fake api (no server, no network)."""
import os, sys, unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from api_client import ApiError   # noqa: E402
from mcp_tools import CodexTools  # noqa: E402


def E(slug, name, db="characters", body="Hi"):
    return {"slug": slug, "name": name, "db": db, "status": "seed", "type": "Character",
            "fields": [{"label": "Species", "value": "Human"}], "related": [], "relatedRaw": None,
            "sections": [{"h": "Overview", "body": body}]}


class FakeApi:
    def __init__(self):
        self.applied = []
        self.pushed = []
        self.calls = []
        self.conflict = False
        self._export = {"books": [{"book": {"id": "b1", "folder": "book-one"},
                                   "entries": [E("aria", "Aria", body="captain"), E("bram", "Bram")],
                                   "chapters": [{"num": "01", "title": "The Wall", "status": "drafted",
                                                 "words": "1,200", "file": "ch01.md"},
                                                {"num": "02", "title": "The Gate", "status": "drafted",
                                                 "words": "900", "file": "ch02.md"}]}]}
    def ping(self): return {"app": "Stephen's Codex", "time": "now"}
    def export(self): return self._export
    def get_tasks(self, book=None, for_claude=None, status=None): return [{"id": 4, "title": "x"}]
    def apply(self, payload): self.applied.append(payload); return {"ok": True, "report": {"tasks": 1}}
    def push(self, books): self.pushed.append(books); return {"ok": True, "report": {"entries": 1}}
    # granular actions (Track A3/B2) — record the call, echo a plausible shape
    def search(self, q, book=None, limit=25):
        self.calls.append(("search", q, book, limit)); return [{"kind": "entry", "slug": "aria"}]
    def get_chapter(self, book, chapter=None, file=None):
        self.calls.append(("chapter", book, chapter, file)); return {"id": 7, "body": "# One"}
    def list_entries(self, book, db=None):
        self.calls.append(("entries", book, db)); return [{"db": "characters", "slug": "aria"}]
    def get_diagnostics(self, book, chapter):
        self.calls.append(("diagnostics", book, chapter)); return {"data": {"flags": []}}
    def create_task(self, task):
        self.calls.append(("task_create", task)); return {"ok": True, "task": {"id": 9, **task}}
    def update_task(self, patch):
        self.calls.append(("task_update", patch)); return {"ok": True, "task": {"id": patch["id"]}}
    def save_chapter(self, payload):
        self.calls.append(("save_chapter", payload))
        if self.conflict:
            raise ApiError("save_chapter: conflict",
                           {"error": "conflict", "current_hash": "abc123", "current_body": "# Newer"})
        return {"ok": True, "chapter": {"id": 7, "body_hash": "def456"}}
    def create_chapter(self, payload):
        self.calls.append(("chapter_create", payload)); return {"ok": True, "chapter": {"id": 8, "file": "ch-03-x.md"}}


class Tools(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        self.t = CodexTools(self.api)

    def test_status(self):
        s = self.t.status()
        self.assertEqual(s["books"], 1)
        self.assertEqual(s["entries"], 2)
        self.assertEqual(s["chapters"], 2)

    def test_search_delegates_to_server(self):
        hits = self.t.search("  captain  ", "b1", 10)
        self.assertEqual(hits[0]["slug"], "aria")
        self.assertEqual(self.api.calls[-1], ("search", "captain", "b1", 10))
        self.assertEqual(self.t.search("   "), [])     # blank query never hits the API
        self.assertEqual(len(self.api.calls), 1)

    def test_get_chapter(self):
        c = self.t.get_chapter("b1", 7)
        self.assertEqual(c["body"], "# One")
        self.assertEqual(self.api.calls[-1], ("chapter", "b1", 7, None))
        self.t.get_chapter("b1", file="ch01.md")
        self.assertEqual(self.api.calls[-1], ("chapter", "b1", None, "ch01.md"))
        with self.assertRaises(ValueError):
            self.t.get_chapter("b1")                   # neither id nor file

    def test_list_entries(self):
        self.t.list_entries("b1", "characters")
        self.assertEqual(self.api.calls[-1], ("entries", "b1", "characters"))

    def test_get_diagnostics(self):
        self.t.get_diagnostics("b1", 7)
        self.assertEqual(self.api.calls[-1], ("diagnostics", "b1", 7))

    def test_create_task_shape(self):
        self.t.create_task("b1", " Fix ch2 ", "details", for_claude=True, priority="high")
        kind, task = self.api.calls[-1]
        self.assertEqual(task, {"book": "b1", "title": "Fix ch2", "body": "details",
                                "for_claude": True, "priority": "high"})
        with self.assertRaises(ValueError):
            self.t.create_task("b1", "   ")
        with self.assertRaises(ValueError):
            self.t.create_task("b1", "x", priority="urgent")

    def test_update_task_patch(self):
        self.t.update_task(9, status="done", result="ok")
        self.assertEqual(self.api.calls[-1], ("task_update", {"id": 9, "status": "done", "result": "ok"}))
        with self.assertRaises(ValueError):
            self.t.update_task(9)                      # empty patch
        with self.assertRaises(ValueError):
            self.t.update_task(9, status="blocked")    # not a real status

    def test_get_entry_renders_md(self):
        md = self.t.get_entry("b1", "characters", "aria")
        self.assertIn("# Aria", md)
        self.assertIsNone(self.t.get_entry("b1", "characters", "ghost"))

    def test_list_chapters(self):
        ch = self.t.list_chapters("b1")
        self.assertEqual(ch[0]["file"], "ch01.md")

    def test_complete_task_shape(self):
        self.t.complete_task(4, "done it")
        self.assertEqual(self.api.applied[-1]["task_results"][0], {"id": 4, "status": "done", "result": "done it"})

    def test_log_writing_shape(self):
        self.t.log_writing("b1", 500, note="good day")
        row = self.api.applied[-1]["writing_log"][0]
        self.assertEqual(row["book_id"], "b1")
        self.assertEqual(row["words_added"], 500)
        self.assertEqual(row["source"], "claude")

    def test_save_entry_resolves_folder_from_snapshot(self):
        # No local books_root: folder must come from the server export.
        self.t.save_entry("b1", "characters", "cade", "# Cade")
        book = self.api.pushed[-1][0]
        self.assertEqual(book["folder"], "book-one")
        self.assertEqual(book["files"], {"Codex/Characters/cade.md": "# Cade"})
        self.assertNotIn("manuscript_present", book)  # entries never trigger archive

    def test_save_entry_rejects_unknown(self):
        with self.assertRaises(ValueError):
            self.t.save_entry("nope", "characters", "x", "y")
        with self.assertRaises(ValueError):
            self.t.save_entry("b1", "not-a-db", "x", "y")

    def test_save_chapter_routes_to_guarded_action(self):
        r = self.t.save_chapter("b1", "ch-03-the-tower", "# Chapter 3")
        kind, payload = self.api.calls[-1]
        self.assertEqual(kind, "save_chapter")
        self.assertEqual(payload, {"book": "b1", "file": "ch-03-the-tower.md", "markdown": "# Chapter 3"})
        self.assertTrue(r["ok"])
        self.t.save_chapter("b1", "ch01.md", "# One v2", base_hash="aaa")
        self.assertEqual(self.api.calls[-1][1]["base_hash"], "aaa")
        with self.assertRaises(ValueError):
            self.t.save_chapter("b1", "   ", "# x")

    def test_save_chapter_conflict_is_returned_as_data(self):
        # A refused save must come back with the current hash + body, not raise.
        self.api.conflict = True
        r = self.t.save_chapter("b1", "ch01.md", "# Stale edit", base_hash="old")
        self.assertEqual(r["status"], "refused")
        self.assertEqual(r["error"], "conflict")
        self.assertEqual(r["current_hash"], "abc123")
        self.assertEqual(r["current_body"], "# Newer")

    def test_create_chapter(self):
        r = self.t.create_chapter("b1", " The Tower ", "3")
        self.assertEqual(self.api.calls[-1], ("chapter_create", {"book": "b1", "title": "The Tower", "num": "3"}))
        self.assertEqual(r["chapter"]["file"], "ch-03-x.md")
        with self.assertRaises(ValueError):
            self.t.create_chapter("b1", "  ")

    def test_push_files_multi(self):
        self.t.push_files("b1", {"Codex/Notes/outline.md": "# O",
                                 "Codex\\Meta\\theme.md": "# T"})
        book = self.api.pushed[-1][0]
        self.assertEqual(set(book["files"]), {"Codex/Notes/outline.md", "Codex/Meta/theme.md"})
        self.assertNotIn("manuscript_present", book)  # no manuscript file in push

    def test_push_files_empty_rejected(self):
        with self.assertRaises(ValueError):
            self.t.push_files("b1", {})


if __name__ == "__main__":
    unittest.main(verbosity=2)
