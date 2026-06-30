"""
Fixture suite for the 3-way reconcile (MASTER-PLAN Phase 2 — "the gate").

Asserts a decision for every branch of the parity bar over synthetic
folder/DB/state combinations, plus the state.json advance rules. Run:

    python -m unittest discover -s sync_engine/tests -v
"""
import os, sys, unittest

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from reconcile import (  # noqa: E402
    decide, reconcile, next_state, Decision,
    WRITES_TO_DB, WRITES_TO_FOLDER, COMMITS_STATE,
)

# Synthetic content hashes. A != B != C, all distinct.
A, B, C = "hashA", "hashB", "hashC"
N = None  # absent on that side


class DecideBranches(unittest.TestCase):
    """Each case is (folder, db, state) -> expected Decision."""

    CASES = [
        # --- nothing / gone ---
        ((N, N, N), Decision.NOOP),          # not present anywhere
        ((N, N, A), Decision.CLEAR_STATE),   # known, now gone from BOTH sides

        # --- first sight (no state) ---
        ((A, N, N), Decision.CREATE_DB),     # new in folder only
        ((N, A, N), Decision.CREATE_FOLDER), # new in app only
        ((A, A, N), Decision.NOOP),          # both present, identical, no history
        ((A, B, N), Decision.FOLDER_WINS),   # both present, differ, no history -> folder wins

        # --- known key, both present ---
        ((A, A, A), Decision.NOOP),          # unchanged
        ((B, A, A), Decision.PUSH),          # changed in folder only
        ((A, B, A), Decision.WRITE_DOWN),    # changed in app only
        ((B, C, A), Decision.CONFLICT),      # both changed, diverge
        ((B, B, A), Decision.CONVERGED),     # both changed to the SAME value

        # --- known key, missing on one side -> never auto-propagate ---
        ((N, A, A), Decision.DELETION),      # removed in folder (db unchanged)
        ((A, N, A), Decision.DELETION),      # removed in app (folder unchanged)
        ((N, B, A), Decision.DELETION),      # removed in folder, db also changed -> still DELETION
        ((B, N, A), Decision.DELETION),      # removed in app, folder also changed -> still DELETION
    ]

    def test_all_branches(self):
        for (f, d, s), expected in self.CASES:
            with self.subTest(folder=f, db=d, state=s):
                self.assertEqual(decide(f, d, s), expected)

    def test_conflict_never_writes(self):
        self.assertNotIn(Decision.CONFLICT, WRITES_TO_DB | WRITES_TO_FOLDER)

    def test_deletion_never_writes(self):
        self.assertNotIn(Decision.DELETION, WRITES_TO_DB | WRITES_TO_FOLDER)

    def test_write_directions(self):
        self.assertEqual(decide(B, A, A), Decision.PUSH)        # -> DB
        self.assertIn(decide(B, A, A), WRITES_TO_DB)
        self.assertEqual(decide(A, B, A), Decision.WRITE_DOWN)  # -> folder
        self.assertIn(decide(A, B, A), WRITES_TO_FOLDER)


class ReconcileMap(unittest.TestCase):
    def test_union_of_keys_and_decisions(self):
        folder = {"ch01": A, "ch02": B, "ch03": B, "only_folder": A}
        db     = {"ch01": A, "ch02": A, "ch03": C, "only_db": A}
        state  = {"ch01": A, "ch02": A, "ch03": A, "gone": A}
        r = reconcile(folder, db, state)
        self.assertEqual(r["ch01"].decision, Decision.NOOP)
        self.assertEqual(r["ch02"].decision, Decision.PUSH)        # folder changed
        self.assertEqual(r["ch03"].decision, Decision.CONFLICT)    # both diverged
        self.assertEqual(r["only_folder"].decision, Decision.CREATE_DB)
        self.assertEqual(r["only_db"].decision, Decision.CREATE_FOLDER)
        self.assertEqual(r["gone"].decision, Decision.CLEAR_STATE)
        self.assertEqual(set(r), {"ch01","ch02","ch03","only_folder","only_db","gone"})


class StateAdvance(unittest.TestCase):
    def test_state_only_advances_on_safe_decisions(self):
        folder = {"push": B, "wdown": A, "conf": B, "del": A, "new": A, "ok": A}
        db     = {"push": A, "wdown": B, "conf": C, "del": N, "new": N, "ok": A}
        state  = {"push": A, "wdown": A, "conf": A, "del": A, "ok": A}
        r = reconcile(folder, db, state)
        ns = next_state(state, r)
        # PUSH advances to folder hash; WRITE_DOWN advances to db hash.
        self.assertEqual(ns["push"], B)
        self.assertEqual(ns["wdown"], B)
        # NOOP keeps the synced hash; CREATE_DB records the new hash.
        self.assertEqual(ns["ok"], A)
        self.assertEqual(ns["new"], A)
        # CONFLICT and DELETION must NOT advance state (re-seen next run).
        self.assertEqual(ns["conf"], A)
        self.assertEqual(ns["del"], A)

    def test_clear_state_drops_key(self):
        r = reconcile({}, {}, {"gone": A})
        ns = next_state({"gone": A}, r)
        self.assertNotIn("gone", ns)


if __name__ == "__main__":
    unittest.main(verbosity=2)
