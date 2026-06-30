# sync_engine — local reconcile engine (MASTER-PLAN Phase 2)

The 3-way reconcile logic, ported to Python and isolated from I/O so it can be
exhaustively fixture-tested **before** it's wired into the MCP service (Phase 3).
On the VPS this runs locally: it reconciles `/srv/codex/books` against the DB and
writes through `api.php` on `localhost` (the single DB-writer path) — no PC, no
HTTP hop, no plaintext token on the wire.

## Status

| Piece | State |
|---|---|
| `reconcile.py` — pure 3-way decision logic | **done** |
| `tests/test_reconcile.py` — fixture suite (the gate) | **done, green** |
| `codex_sync_lib.py` — parse/render/snapshot | **vendored** (folder side) |
| `api_client.py` — localhost `api.php` client (stdlib) | **done** |
| `engine.py` — orchestration (snapshot → export → reconcile → report / apply) | **done (entries; dry-run default)** |
| `tests/test_engine_wiring.py` — offline wiring test | **done, green** |
| `cycle.py` — full continuous-sync cycle (timer entrypoint) | **done (Phase 3)** |
| `tests/test_cycle.py` — payload-builder test | **done, green** |

Run the tests (12, all green):

```bash
python -m unittest discover -s sync_engine/tests -v
```

Run the engine against the box (dry-run first — shows the plan, writes nothing):

```bash
python -m sync_engine.engine --books /srv/codex/books \
    --api http://127.0.0.1:8081/api.php --token "$API_KEY"
# then, once the plan looks right and matches sync.log:
python -m sync_engine.engine --books /srv/codex/books \
    --api http://127.0.0.1:8081/api.php --token "$API_KEY" --apply
```

### Engine scope (read this)

The orchestration reconciles **entries** — the round-trippable contract surface.
Chapters / meta / notes are folder-owned and **push-only** (the existing push path
handles them); writing prose back to disk is the deliberate Phase 9 change and is
NOT done here. The engine is **dry-run by default**; `--apply` performs writes,
and CONFLICT / DELETION are always report-only (never auto-resolved).
`engine.py` still needs validation against a **live** `api.php` export (the entry
struct shape) — the offline tests use synthetic structs.

## The parity bar (decision → action)

Each item is reduced to three content hashes — `folder`, `db`, `state` (last
synced; `None` = absent). `decide(folder, db, state)` is a pure function of them:

| Situation | Decision | Action |
|---|---|---|
| folder changed, app unchanged | `PUSH` | write folder → DB |
| app changed, folder unchanged | `WRITE_DOWN` | write DB → folder |
| both changed, **diverge** | `CONFLICT` | skip + report (never merge) |
| both changed to the **same** value | `CONVERGED` | no write; record state |
| known key, now missing one side | `DELETION` | report; **never** auto-delete |
| new in folder only | `CREATE_DB` | create in DB |
| new in app only | `CREATE_FOLDER` | create in folder |
| first sight, both present, differ | `FOLDER_WINS` | folder is source of truth → DB |
| in sync | `NOOP` | nothing |
| known key gone from **both** sides | `CLEAR_STATE` | drop from state.json |

**state.json advances ONLY on a confirmed write** (or NOOP/CONVERGED). `CONFLICT`
and `DELETION` never advance state, so they are re-seen and re-reported every run
until a human resolves them — implementing "never auto-delete; conflicts are
skipped, not merged; commit state only on confirmed write."

## Parity vs sync-codex.ps1 — CHECKED ✓ (see PARITY.md)

The decision tree was diffed line-by-line against the real `sync-codex.ps1`
reconcile loop and matches branch-for-branch. See **PARITY.md** for the table and
the handful of deliberate differences (notably: the engine hashes the *canonical
rendered* Markdown rather than raw file text, so formatting-only edits are NOOP
not PUSH — switchable if you want byte-exact PS behaviour).

## Design notes

- `reconcile.py` has **no I/O** — it takes/returns plain dicts, so every branch is
  unit-testable and deterministic. The engine layer does the folder/DB reads and
  the `api.php` writes.
- Hash inputs come from: folder side via `codex_sync_lib.snapshot()`; DB side via
  `api.php?action=export`. Use a stable content hash (e.g. md5 of the rendered
  Markdown / canonical JSON) so equal content compares equal across sides.
- Keys are stable per item, e.g. `entry:<book>:<db>:<slug>`, `chapter:<book>:<file>`.
