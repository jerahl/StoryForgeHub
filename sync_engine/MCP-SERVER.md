# codex-mcp — remote MCP tool surface (Phase 3)

Always-on MCP server exposing Codex tools to Claude as a **remote connector**.
Streamable-HTTP on loopback `127.0.0.1:8765`, fronted by Caddy at
`https://<domain>/mcp`, gated by a **static bearer token** (the app's `API_KEY`).

## Files
- `mcp_tools.py` — the capabilities (call api.php / run the reconcile cycle). Tested offline.
- `mcp_server.py` — FastMCP streamable-http app + bearer-auth ASGI middleware.
- `requirements.txt` — `mcp`, `uvicorn` (installed into `.venv` by 04-configure.sh).

## Tools
`codex_status`, `codex_search`, `codex_get_entry`, `codex_save_entry`,
`codex_list_chapters`, `codex_get_tasks`, `codex_complete_task`,
`codex_log_writing`, `codex_sync(dry_run)`. Every write flows through api.php.

## Bring it up
```bash
sudo bash deploy/04-configure.sh        # creates the venv, renders units + Caddy /mcp
sudo systemctl reload caddy
sudo systemctl enable --now codex-mcp.service
systemctl status codex-mcp.service
curl -s -o /dev/null -w '%{http_code}\n' https://<domain>/mcp        # 401 (no token) = gate works
curl -s -o /dev/null -w '%{http_code}\n' -H "Authorization: Bearer $API_KEY" \
     -H "Accept: text/event-stream" https://<domain>/mcp            # not 401 = reachable
```

## Connect Claude (token-in-URL)
Claude's "Add custom connector" UI takes only a URL (+ optional OAuth), with no
header field, so the token rides in the URL:

  Customize -> Connectors -> "+" -> Add custom connector
  URL:  https://<domain>/mcp?k=<API_KEY>      (API_KEY from /etc/codex/codex.env)

No OAuth/advanced settings needed. Enable it per-conversation via "+" -> Connectors.
The server also still accepts `Authorization: Bearer <API_KEY>` (used by mcp_smoke.py
and SDK clients). Caddy `log_skip`s /mcp so the token isn't written to the access log.
Rotate API_KEY if the URL is ever exposed.

## Notes / next
- Auth is a static bearer now; per-client OAuth is a later upgrade (FastMCP
  supports a `token_verifier`/`auth` provider when you want it).
- `codex_get_entry`/`search`/`list_chapters` read via `api.php?action=export`
  (whole-snapshot) — fine at this size; add granular read endpoints to api.php
  if it grows. `codex_get_chapter` body isn't exposed yet (chapters export omits
  body) — add an api.php action when needed.
- Still to retire PowerShell fully: migrate the `codex-webapp-sync` skill to call
  these tools instead of the file bridge, then delete the old token + scheduled task.
