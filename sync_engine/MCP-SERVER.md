# codex-mcp — remote MCP tool surface

Always-on MCP server exposing Codex tools to Claude as a **remote connector**.
Streamable-HTTP (stateless) on loopback `127.0.0.1:8765`, fronted by Caddy at
`https://<domain>/mcp`.

## Auth — per-user (standalone plan, Track B1)

Every request carries a token, either as `Authorization: Bearer <t>` or as
`?k=<t>` in the URL (Claude's "Add custom connector" UI takes only a URL).
Two kinds are accepted:

- **A personal token** from **Account → API tokens** — the recommended way.
  The MCP passes it through as the `X-Codex-Token` on every `api.php` call, so
  the request **acts as that user**: the same book-membership scoping, role
  checks, and activity-log attribution as the web UI. Personal tokens are
  validated against `api.php` and cached for 60s (`mcp_auth.VALIDATE_TTL`), so
  a revoked token dies within a minute.
- **The service `API_KEY`** (from `/etc/codex/codex.env`) — unscoped, for
  admin automation and the reconcile. Checked in constant time, no round-trip.

The server runs FastMCP **stateless**, so each tool call executes inside the
HTTP request that carried it and the caller's token rides a contextvar
(`mcp_server.CURRENT_TOKEN`) from the auth middleware into the api client —
two users on the connector at once cannot bleed into each other (covered by
the e2e suite below).

## Files
- `mcp_tools.py` — the capabilities (call api.php / run the reconcile cycle). Tested offline.
- `mcp_auth.py` — the token gate (extract, constant-time service check, TTL-cached per-user validation). Stdlib-only, tested offline.
- `mcp_server.py` — FastMCP stateless streamable-http app + token middleware.
- `requirements.txt` — `mcp`, `uvicorn` (installed into `.venv` by 04-configure.sh).

## Tools
Reads: `codex_status`, `codex_search` (server-side, snippets), `codex_get_entry`,
`codex_list_entries`, `codex_get_chapter` (**includes the Markdown body**),
`codex_list_chapters`, `codex_get_diagnostics` (the Smart-editing analysis),
`codex_get_tasks`.
Writes: `codex_save_entry`, `codex_save_chapter`, `codex_push_files`,
`codex_create_task`, `codex_update_task`, `codex_complete_task`,
`codex_log_writing`. Every write flows through api.php, at the caller's role.
Admin: `codex_sync(dry_run)` — service token only; personal tokens are refused
(it reconciles the whole books folder). Retires with the DB-canonical flip.

The granular reads/writes ride api.php's object-level actions
(`chapter`, `entries`, `search`, `diagnostics`, `task_create`, `task_update`);
only `codex_get_entry`/`codex_list_chapters`/`codex_status` still read via the
`export` snapshot.

### Pushing new files
- `codex_save_entry(book, db, slug, markdown)` — a Codex entry.
- `codex_save_chapter(book, filename, markdown)` — a manuscript chapter.
- `codex_push_files(book, files, reconcile_chapters=False)` — a map of
  relpath→Markdown for any type api.php's push understands: `Manuscript/<file>.md`,
  `Codex/<Folder>/<slug>.md`, `Codex/Notes/<slug>.md`, `Codex/Meta/<slug>.md`,
  `Codex/Sources/<key>.md`, `Codex/Meta/progressions.md`.

The book's on-disk folder is resolved from the live `export` snapshot, so pushes
work without a populated `CODEX_BOOKS_DIR`. Pushing a `Manuscript/*.md` file
normally makes the app archive every chapter the folder omits; the chapter/push
tools guard against this by declaring the book's current chapters present, so
**adding** a chapter never archives the others. Pass `reconcile_chapters=True`
(or `reconcile=True` on `codex_save_chapter`) only when you intend a full
manuscript reconcile that archives omitted chapters.

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
Each user mints their own token at **Account → API tokens**, then:

  Customize -> Connectors -> "+" -> Add custom connector
  URL:  https://<domain>/mcp?k=<personal token>

No OAuth/advanced settings needed (OAuth is the Track B3 upgrade). Enable it
per-conversation via "+" -> Connectors. The server also accepts
`Authorization: Bearer <token>` (used by mcp_smoke.py and SDK clients). Caddy
`log_skip`s /mcp so tokens aren't written to the access log. Tokens are
revocable per-user on the Account page; rotate the service `API_KEY` if it is
ever exposed.

## Tests
- Offline unit: `python3 -m unittest discover -s sync_engine/tests`
  (`test_mcp_auth.py` = the gate, `test_mcp_tools.py` = the capabilities).
- **End-to-end** (the B1 gate): `bash sync_engine/tests/e2e/run.sh` boots
  `php -S` over a seeded sqlite DB plus the real MCP server under uvicorn and
  drives it with the MCP client SDK as three identities — no token (401),
  the service key (unscoped), and a personal token (scoped to its book,
  refused elsewhere, `codex_sync` refused, identities stable across
  interleaved sessions). Needs php-cli + `pip install mcp uvicorn`.
- On-box smoke: `mcp_smoke.py` (unchanged; works with either token kind).

## Notes / next
- OAuth 2.1 + dynamic client registration for Claude connectors is Track B3
  (FastMCP supports a `token_verifier`/`auth` provider when we want it);
  token-in-URL stays as the fallback.
- `codex_get_entry`/`codex_list_chapters`/`codex_status` still read the whole
  `export` snapshot — fine at this size; move them to granular actions if it
  grows.
- With the DB-canonical flip (Track A), `codex_sync` and the folder-shaped
  push semantics retire; `codex_save_chapter` moves onto a base-hash-guarded
  `save_chapter` action.
