"""Offline test of the cycle payload builder against a temp book folder."""
import os, sys, tempfile, unittest, json

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from reconcile import reconcile  # noqa: E402
from engine import entry_key      # noqa: E402
import cycle                       # noqa: E402


def E(slug, name, body="Hi"):
    return {"slug": slug, "name": name, "db": "characters", "status": "seed", "type": "Character",
            "fields": [{"label": "Species", "value": "Human"}], "related": [], "relatedRaw": None,
            "sections": [{"h": "Overview", "body": body}]}


class BuildPush(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()
        bd = os.path.join(self.root, "book-one")
        for sub in ["Codex/Characters", "Manuscript", "Codex/Meta", "Codex/Notes"]:
            os.makedirs(os.path.join(bd, sub), exist_ok=True)
        open(os.path.join(bd, "Codex/Characters/aria.md"), "w").write("# Aria\n")
        open(os.path.join(bd, "Manuscript/ch01.md"), "w").write("# Chapter 1\nprose")
        open(os.path.join(bd, "Manuscript/_scratch.md"), "w").write("ignore me")
        open(os.path.join(bd, "Manuscript/README.md"), "w").write("readme")
        open(os.path.join(bd, "Codex/Meta/style-guide.md"), "w").write("# Style")
        open(os.path.join(bd, "Codex/Notes/idea.md"), "w").write("# Idea")
        json.dump([{"id": "b1", "folder": "book-one", "title": "Book One"}],
                  open(os.path.join(self.root, "books.json"), "w"))

    def test_payload_includes_changed_entry_and_folder_owned_files(self):
        k = entry_key("b1", "characters", "aria")
        # folder changed, db unchanged -> PUSH
        results = reconcile({k: "hNEW"}, {k: "hOLD"}, {k: "hOLD"})
        payload, pending = cycle.build_push(self.root, results, {k: E("aria", "Aria")})
        self.assertEqual(len(payload), 1)
        files = payload[0]["files"]
        self.assertIn("Codex/Characters/aria.md", files)          # changed entry pushed
        self.assertIn("Manuscript/ch01.md", files)                # manuscript refreshed
        self.assertIn("Codex/Meta/style-guide.md", files)         # meta refreshed
        self.assertIn("Codex/Notes/idea.md", files)               # notes refreshed
        self.assertEqual(payload[0]["manuscript_present"], ["ch01.md"])  # readme/_scratch excluded
        self.assertEqual(pending[k], "hNEW")                      # commit hash staged
        self.assertEqual(payload[0]["book"]["id"], "b1")

    def test_no_entry_change_still_refreshes_folder_files(self):
        k = entry_key("b1", "characters", "aria")
        results = reconcile({k: "h"}, {k: "h"}, {k: "h"})         # NOOP entry
        payload, pending = cycle.build_push(self.root, results, {k: E("aria", "Aria")})
        self.assertEqual(pending, {})                              # nothing to commit
        self.assertIn("Manuscript/ch01.md", payload[0]["files"])  # but folder files still refreshed


if __name__ == "__main__":
    unittest.main(verbosity=2)
