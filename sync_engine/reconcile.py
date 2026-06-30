"""
reconcile.py — pure 3-way reconcile decision logic (MASTER-PLAN Phase 2).

This is the *risky core* of sync, isolated from all I/O so it can be exhaustively
fixture-tested before being wired into the service (Phase 3). It is a re-statement
of the branch-for-branch parity bar documented in MASTER-PLAN-vps-2026-06-28.md:

    changed-in-folder      -> PUSH        (folder -> DB)
    changed-in-app         -> WRITE_DOWN  (DB -> folder)
    changed-both (diverge) -> CONFLICT    (skip + report; never merge)
    missing-but-known      -> DELETION    (never auto-propagate; report)
    new-on-one-side        -> CREATE_*    (create on the other side)
    first-sight (both,diff)-> FOLDER_WINS  (folder is source of truth)
    + commit state.json ONLY on a confirmed write

Each item (entry / chapter / meta page / …) is reduced to three content hashes:
    folder : hash of the on-disk Markdown   (None = absent in folder)
    db     : hash of the app/DB version     (None = absent in DB)
    state  : last-synced hash from state.json (None = key never synced before)

`decide()` is a pure function of those three. `reconcile()` maps it over a key set.
NOTE: this is built to the *documented* parity bar; before retiring the PowerShell
sync, diff these decisions against sync-codex.ps1 / historical sync.log.
"""

from __future__ import annotations
from dataclasses import dataclass
from enum import Enum
from typing import Dict, Iterable, Optional


class Decision(str, Enum):
    NOOP        = "noop"          # in sync, nothing to do
    PUSH        = "push"          # folder changed -> write folder version to DB
    WRITE_DOWN  = "write_down"    # app changed   -> write DB version to folder
    CREATE_DB   = "create_db"     # new in folder only -> create in DB
    CREATE_FOLDER = "create_folder"  # new in app only -> create in folder
    FOLDER_WINS = "folder_wins"   # first sight, both present & differ -> folder source of truth
    CONVERGED   = "converged"     # both changed to the SAME value -> no write, just record state
    CONFLICT    = "conflict"      # both changed & diverge -> skip + report (never merge)
    DELETION    = "deletion"      # known key now missing on one side -> report, never auto-delete
    CLEAR_STATE = "clear_state"   # known key gone from BOTH sides -> drop from state


# Which decisions cause a write, and in which direction. Used by the engine to
# know when it may commit state.json (only AFTER a confirmed write/converge).
WRITES_TO_DB     = {Decision.PUSH, Decision.CREATE_DB, Decision.FOLDER_WINS}
WRITES_TO_FOLDER = {Decision.WRITE_DOWN, Decision.CREATE_FOLDER}
# Decisions that mean "record the current synced hash" (a write happened, or the
# two sides already agree). CONFLICT and DELETION never advance state.
COMMITS_STATE    = WRITES_TO_DB | WRITES_TO_FOLDER | {Decision.NOOP, Decision.CONVERGED}


def decide(folder: Optional[str], db: Optional[str], state: Optional[str]) -> Decision:
    """Pure 3-way decision for a single key from its folder/db/state hashes.

    None means "absent on that side". Equality of two non-None hashes means the
    content is identical.
    """
    f, d = folder is not None, db is not None
    s = state is not None

    # Gone everywhere.
    if not f and not d:
        return Decision.CLEAR_STATE if s else Decision.NOOP

    if not s:
        # First sight: no prior sync record to arbitrate against.
        if f and not d:
            return Decision.CREATE_DB        # brand new in the folder
        if d and not f:
            return Decision.CREATE_FOLDER    # brand new in the app
        # Present on both sides with no history.
        return Decision.NOOP if folder == db else Decision.FOLDER_WINS

    # Known key (we have a last-synced hash).
    if f and not d:
        return Decision.DELETION             # removed in the app — never auto-propagate
    if d and not f:
        return Decision.DELETION             # removed in the folder — never auto-propagate

    # Present on both sides, with history.
    f_changed = folder != state
    d_changed = db != state
    if not f_changed and not d_changed:
        return Decision.NOOP
    if f_changed and not d_changed:
        return Decision.PUSH                 # changed in folder only
    if d_changed and not f_changed:
        return Decision.WRITE_DOWN           # changed in app only
    # Both changed.
    return Decision.CONVERGED if folder == db else Decision.CONFLICT


@dataclass
class KeyResult:
    key: str
    decision: Decision
    folder: Optional[str]
    db: Optional[str]
    state: Optional[str]

    @property
    def writes_to_db(self) -> bool:     return self.decision in WRITES_TO_DB
    @property
    def writes_to_folder(self) -> bool: return self.decision in WRITES_TO_FOLDER
    @property
    def is_conflict(self) -> bool:      return self.decision is Decision.CONFLICT
    @property
    def is_deletion(self) -> bool:      return self.decision is Decision.DELETION


def reconcile(folder_hashes: Dict[str, str],
              db_hashes: Dict[str, str],
              state_hashes: Dict[str, str]) -> Dict[str, KeyResult]:
    """Run decide() over the union of all keys across the three maps.

    Returns key -> KeyResult. The caller (engine) applies writes via the single
    DB-writer path (api.php) and, ONLY on confirmed success, advances state.json
    for keys whose decision is in COMMITS_STATE.
    """
    keys: Iterable[str] = set(folder_hashes) | set(db_hashes) | set(state_hashes)
    out: Dict[str, KeyResult] = {}
    for k in sorted(keys):
        f = folder_hashes.get(k)
        d = db_hashes.get(k)
        s = state_hashes.get(k)
        out[k] = KeyResult(k, decide(f, d, s), f, d, s)
    return out


def next_state(prev_state: Dict[str, str], results: Dict[str, KeyResult]) -> Dict[str, str]:
    """Compute the state.json map AFTER a successful apply pass.

    - COMMITS_STATE keys advance to the now-synced content hash (the folder hash
      for DB-bound writes / noop / converge; the DB hash for folder-bound writes).
    - CLEAR_STATE keys are dropped.
    - CONFLICT and DELETION keys are left UNCHANGED (state is not advanced), so the
      next run re-sees and re-reports them until a human resolves them.
    """
    new = dict(prev_state)
    for k, r in results.items():
        if r.decision is Decision.CLEAR_STATE:
            new.pop(k, None)
        elif r.decision in WRITES_TO_FOLDER:
            new[k] = r.db                    # folder now matches the DB version
        elif r.decision in WRITES_TO_DB or r.decision in (Decision.NOOP, Decision.CONVERGED):
            new[k] = r.folder if r.folder is not None else r.db
        # CONFLICT / DELETION: leave prev state as-is.
    return new
