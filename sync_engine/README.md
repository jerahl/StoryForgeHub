# sync_engine — the codex-mcp service

> The name is historical: this directory once held the folder↔DB reconcile
> engine (MASTER-PLAN Phases 2–3). That engine — `reconcile.py`, `engine.py`,
> `cycle.py`, the `codex-sync.timer` — **retired with the A4 cutover**
> (`MASTER-PLAN-standalone-2026-07-06.md`): the DB is the single source of
> truth and there is nothing to reconcile. What lives here now is the
> **remote MCP service** and its shared Markdown library.

## Contents
- `mcp_server.py` — stateless FastMCP app + per-user token middleware
  (see `MCP-SERVER.md` for the full tool surface, auth, and OAuth story).
- `mcp_tools.py` — the capabilities, thin clients of `api.php`.
- `mcp_auth.py` — the token gate (stdlib-only, offline-tested).
- `api_client.py` — stdlib urllib client for `api.php`.
- `codex_sync_lib.py` — the Markdown parse/render library (still the shared
  dialect reference; `render_entry`/`DBMETA` are used by the MCP tools).
- `mcp_smoke.py` — on-box smoke test.
- `tests/` — offline unit tests + the e2e suite (`tests/e2e/run.sh`) that
  boots the real PHP app and drives the MCP with the client SDK.

## Run the tests
```bash
python3 -m unittest discover -s sync_engine/tests    # offline
bash sync_engine/tests/e2e/run.sh                    # full stack on loopback
```
