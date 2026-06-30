# Parity check: reconcile.py vs sync-codex.ps1

Diffed `reconcile.py` against the real PowerShell reconcile loop
(`sync-codex.ps1`, lines 147–184). Terminology map: PS **web** = app/DB side
(`db`), PS **disk** = folder side (`folder`), PS **last/known** = `state`;
PS **push** = folder→DB (`PUSH`), PS **pull** = DB→folder (`WRITE_DOWN`).

## Decision logic — FAITHFUL MATCH ✓

| Situation | sync-codex.ps1 | reconcile.py | Match |
|---|---|---|---|
| both sides identical | `hWeb==hDisk` → set state, continue | `NOOP` / `CONVERGED` (state advances) | ✓ |
| both changed to same value | caught by `hWeb==hDisk` (in agreement) | `CONVERGED` | ✓ |
| new in folder only (no state) | push (create in app) | `CREATE_DB` | ✓ |
| new in app only (no state) | pull (create in folder) | `CREATE_FOLDER` | ✓ |
| known, now missing one side | DELETION, never propagate | `DELETION` | ✓ |
| folder changed, app unchanged | push | `PUSH` | ✓ |
| app changed, folder unchanged | pull | `WRITE_DOWN` | ✓ |
| both changed, diverge | CONFLICT (skip) | `CONFLICT` | ✓ |
| first sight, both present, differ | folder wins (push) | `FOLDER_WINS` | ✓ |
| commit state | pushes only after app confirms; pulls after write | `next_state` advances only for items in `apply()`'s `done` | ✓ |

Every decision branch is reproduced. The fixture suite (`test_reconcile.py`)
encodes the same table.

## Differences to be aware of

1. **Hash basis (the important one).** PS hashes **raw file text** (CRLF→LF
   normalized) and compares the app's `pull` output against the on-disk file.
   `engine.py` hashes the **canonical re-rendered** Markdown
   (`md5(render_entry(parse(...)))`) on both sides. Consequence: a folder edit
   that changes only *non-semantic formatting* (extra blank lines, comment
   placement, field order) is a **PUSH** under PS but a **NOOP** under the new
   engine, because the parser normalizes it away. The new behaviour avoids
   per-run churn and matches "the parser is the source of truth", but it is **not
   byte-identical detection**. Your live run was 175/175 NOOP, so today's folder
   is already canonical. → If you want byte-exact PS parity instead, say so and
   I'll switch the hash to raw-text + CRLF-normalize (one function).

2. **Stale state keys.** PS only iterates keys present on at least one side, so a
   key gone from BOTH sides lingers in `state.json` forever. The engine includes
   state-only keys and emits `CLEAR_STATE` to prune them. Improvement; differs
   only on the rare "deleted both sides then resurrected" edge.

3. **Modes.** PS supports `-Mode PushOnly` / `-Mode PullOnly`; the engine is
   always `Sync` (what the continuous timer needs). Easy to add if wanted.

4. **Scope not yet ported.** PS does more than entry reconcile: it also (a)
   push-refreshes manuscript word-counts + meta + notes + progressions
   (push-only, non-destructive), (b) regenerates `books.json` from per-book
   `book.json`, auto-registering new books, and (c) shuttles the tasks
   inbox/outbox bridge. `engine.py` currently does **entries only**. Per the
   plan, (a)+(b) become additional push steps in the service and (c) becomes
   remote MCP tools the skill calls — both land in **Phase 3**.

5. **Cosmetic:** SHA256 (PS) vs md5 (engine), and key scheme `folder|relpath`
   (PS) vs `entry:<book>:<db>:<slug>` (engine). Internal to each tool's own
   state file; no effect on decisions.

## Verdict

The risky core — the 3-way decision tree — matches branch-for-branch. Before
retiring PowerShell, decide on point (1) (canonical vs raw hashing), then the
remaining work is porting scope item (4) into the Phase 3 service.
