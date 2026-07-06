"""
Chapter authority: a fresh DB write must win over a stale folder copy.

Covers the pure decision (decide_chapter) and the build_push gating that withholds
a stale-folder manuscript push when the DB copy was edited since the last sync,
reports a both-sides conflict, still pushes a genuinely changed folder, and keeps
brand-new app-created chapters out of the archive sweep.
"""
import os, sys, tempfile, unittest, json

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from reconcile import reconcile          # noqa: E402
import cycle                             # noqa: E402

CH01 = "# Chapter 1\nprose"             # matches the file written in setUp


class DecideChapter(unittest.TestCase):
    def test_first_sight_pushes(self):
        # No baseline -> folder establishes the baseline (folder wins first sight).
        self.assertEqual(cycle.decide_chapter("f", "d", None), ("push", False))

    def test_db_unchanged_pushes(self):
        # DB body identical to baseline -> folder authoritative (incl. no-change refresh).
        self.assertEqual(cycle.decide_chapter("fNEW", "d", ("fOLD", "d")), ("push", False))

    def test_db_changed_withheld(self):
        # DB body changed since baseline, folder unchanged -> fresh DB write wins.
        self.assertEqual(cycle.decide_chapter("f", "dNEW", ("f", "dOLD")), ("withhold", False))

    def test_both_changed_is_conflict(self):
        # DB changed AND folder changed -> withhold + flag conflict (never auto-merge).
        self.assertEqual(cycle.decide_chapter("fNEW", "dNEW", ("fOLD", "dOLD")), ("withhold", True))

    def test_db_absent_is_not_a_change(self):
        # DB row gone (None) -> not treated as a divergence -> push (recreate).
        self.assertEqual(cycle.decide_chapter("f", None, ("f", "dOLD")), ("push", False))


class BuildPushGating(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()
        bd = os.path.join(self.root, "book-one")
        for sub in ["Codex/Characters", "Manuscript", "Codex/Meta", "Codex/Notes"]:
            os.makedirs(os.path.join(bd, sub), exist_ok=True)
        open(os.path.join(bd, "Manuscript/ch01.md"), "w").write(CH01)
        open(os.path.join(bd, "Codex/Meta/style-guide.md"), "w").write("# Style")
        json.dump([{"id": "b1", "folder": "book-one", "title": "Book One"}],
                  open(os.path.join(self.root, "books.json"), "w"))
        self.folder_md5 = cycle._md5(CH01)
        self.results = reconcile({}, {}, {})   # no entry writes — isolate chapter logic

    def _build(self, db_chapters, chapter_state):
        return cycle.build_push(self.root, self.results, {}, db_chapters, chapter_state)

    def test_fresh_db_edit_is_withheld(self):
        # Folder unchanged (baseline folder hash == current), DB body differs.
        state = {"chapter:b1:ch01.md": f"{self.folder_md5}|dbOLD"}
        payload, _, chrep = self._build({"book-one": {"ch01.md": "dbNEW"}}, state)
        files = payload[0]["files"]
        self.assertNotIn("Manuscript/ch01.md", files)          # stale folder NOT pushed
        self.assertIn("chapter:b1:ch01.md", chrep["withheld"])
        self.assertEqual(chrep["conflicts"], [])
        self.assertIn("ch01.md", payload[0]["manuscript_present"])  # still protected from archive

    def test_conflict_reported(self):
        # Folder hash differs from baseline AND DB body differs -> withhold + conflict.
        state = {"chapter:b1:ch01.md": "folderOLD|dbOLD"}
        payload, _, chrep = self._build({"book-one": {"ch01.md": "dbNEW"}}, state)
        self.assertNotIn("Manuscript/ch01.md", payload[0]["files"])
        self.assertEqual(chrep["conflicts"], ["chapter:b1:ch01.md"])

    def test_folder_change_still_pushes(self):
        # DB unchanged vs baseline; folder changed -> folder authoritative -> push.
        state = {"chapter:b1:ch01.md": f"folderOLD|dbSAME"}
        payload, _, chrep = self._build({"book-one": {"ch01.md": "dbSAME"}}, state)
        self.assertIn("Manuscript/ch01.md", payload[0]["files"])
        self.assertEqual(chrep["withheld"], [])
        self.assertEqual(chrep["baselines"]["chapter:b1:ch01.md"], self.folder_md5)

    def test_first_sight_pushes_and_stages_baseline(self):
        payload, _, chrep = self._build({"book-one": {"ch01.md": "whatever"}}, {})
        self.assertIn("Manuscript/ch01.md", payload[0]["files"])
        self.assertIn("chapter:b1:ch01.md", chrep["baselines"])

    def test_brand_new_db_only_chapter_protected_from_archive(self):
        # A chapter that exists in the DB but not the folder, with no baseline, must
        # be kept in manuscript_present so the archive sweep doesn't remove it.
        state = {"chapter:b1:ch01.md": f"{self.folder_md5}|dbSAME"}
        payload, _, chrep = self._build(
            {"book-one": {"ch01.md": "dbSAME", "ch99.md": "dbNEWCHAP"}}, state)
        self.assertIn("ch99.md", payload[0]["manuscript_present"])
        self.assertIn("chapter:b1:ch99.md", chrep["protected_new"])

    def test_gating_off_when_no_db_chapters(self):
        # Backward-compat: without db_chapters, manuscript is pushed as before.
        payload, _, chrep = cycle.build_push(self.root, self.results, {})
        self.assertIn("Manuscript/ch01.md", payload[0]["files"])
        self.assertEqual(chrep["withheld"], [])


if __name__ == "__main__":
    unittest.main(verbosity=2)
