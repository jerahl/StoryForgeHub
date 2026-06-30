"""
Offline test of engine wiring: snapshot/export dicts -> hash maps -> reconcile.
No server, no filesystem. Run with the rest:
    python -m unittest discover -s sync_engine/tests -v
"""
import os, sys, unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from engine import build_folder_maps, build_db_maps, entry_key, hash_entry  # noqa: E402
from reconcile import reconcile, Decision  # noqa: E402


def E(slug, name, body="Hi", db="characters"):
    return {
        "slug": slug, "name": name, "db": db, "status": "seed", "type": "Character",
        "fields": [{"label": "Species", "value": "Human"}],
        "related": [], "relatedRaw": None,
        "sections": [{"h": "Overview", "body": body}],
    }


class HashAndKeys(unittest.TestCase):
    def test_identical_structs_hash_equal(self):
        self.assertEqual(hash_entry(E("a", "Aria")), hash_entry(E("a", "Aria")))

    def test_changed_body_changes_hash(self):
        self.assertNotEqual(hash_entry(E("a", "Aria", body="Hi")),
                            hash_entry(E("a", "Aria", body="Changed")))

    def test_key_format(self):
        self.assertEqual(entry_key("b1", "characters", "aria"), "entry:b1:characters:aria")

    def test_error_entries_skipped(self):
        snap = {"books": [{"book": {"id": "b1"}, "entries": [
            E("a", "Aria"),
            {"slug": "bad", "db": "characters", "error": "parse fail"},
        ]}]}
        hashes, structs = build_folder_maps(snap)
        self.assertIn("entry:b1:characters:a", hashes)
        self.assertNotIn("entry:b1:characters:bad", hashes)  # unparseable never drives sync


class EngineReconcile(unittest.TestCase):
    def test_end_to_end_decisions(self):
        # A: unchanged everywhere. B: changed in folder. C: new in folder. D: deleted in folder.
        a = E("a", "Aria")
        b_old, b_new = E("b", "Bram", body="old"), E("b", "Bram", body="NEW")
        c = E("c", "Cael")
        d = E("d", "Dax")

        snapshot = {"books": [{"book": {"id": "b1"}, "entries": [a, b_new, c]}]}
        export   = {"books": [{"book": {"id": "b1"}, "entries": [a, b_old, d]}]}
        state = {
            entry_key("b1", "characters", "a"): hash_entry(a),
            entry_key("b1", "characters", "b"): hash_entry(b_old),
            entry_key("b1", "characters", "d"): hash_entry(d),
        }
        fh, _ = build_folder_maps(snapshot)
        dh, _ = build_db_maps(export)
        r = reconcile(fh, dh, state)
        self.assertEqual(r[entry_key("b1","characters","a")].decision, Decision.NOOP)
        self.assertEqual(r[entry_key("b1","characters","b")].decision, Decision.PUSH)        # folder changed
        self.assertEqual(r[entry_key("b1","characters","c")].decision, Decision.CREATE_DB)   # new in folder
        self.assertEqual(r[entry_key("b1","characters","d")].decision, Decision.DELETION)    # gone from folder


if __name__ == "__main__":
    unittest.main(verbosity=2)
