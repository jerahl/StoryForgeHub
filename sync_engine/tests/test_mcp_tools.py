"""Offline tests for CodexTools using a fake api (no server, no network)."""
import os, sys, unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from mcp_tools import CodexTools  # noqa: E402


def E(slug, name, db="characters", body="Hi"):
    return {"slug": slug, "name": name, "db": db, "status": "seed", "type": "Character",
            "fields": [{"label": "Species", "value": "Human"}], "related": [], "relatedRaw": None,
            "sections": [{"h": "Overview", "body": body}]}


class FakeApi:
    def __init__(self):
        self.applied = []
        self.pushed = []
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


class Tools(unittest.TestCase):
    def setUp(self):
        self.api = FakeApi()
        self.t = CodexTools(self.api, "/tmp/books")

    def test_status(self):
        s = self.t.status()
        self.assertEqual(s["books"], 1)
        self.assertEqual(s["entries"], 2)
        self.assertEqual(s["chapters"], 2)

    def test_search(self):
        self.assertEqual([h["slug"] for h in self.t.search("captain")], ["aria"])
        self.assertEqual(self.t.search("nonexistent"), [])

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

    def test_save_chapter_preserves_existing_chapters(self):
        # Adding one chapter must NOT archive ch01/ch02: manuscript_present lists
        # the new file plus every existing chapter.
        self.t.save_chapter("b1", "ch-03-the-tower", "# Chapter 3")
        book = self.api.pushed[-1][0]
        self.assertEqual(book["folder"], "book-one")
        self.assertIn("Manuscript/ch-03-the-tower.md", book["files"])  # .md appended
        self.assertEqual(set(book["manuscript_present"]),
                         {"ch01.md", "ch02.md", "ch-03-the-tower.md"})

    def test_save_chapter_reconcile_omits_guard(self):
        # reconcile=True: caller opts into archive-on-push, no present list sent.
        self.t.save_chapter("b1", "ch03.md", "# 3", reconcile=True)
        self.assertNotIn("manuscript_present", self.api.pushed[-1][0])

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
