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
`codex_list_entries`, `codex_get_chapter` (**includes the Markdown body** and its
`body_hash`), `codex_list_chapters`, `codex_get_diagnostics` (the Smart-editing
analysis), `codex_get_tasks`.
Writes: `codex_save_entry`, `codex_save_chapter` (**base-hash guarded**, Track A:
creating needs no hash; updating requires the `body_hash` from `codex_get_chapter`,
and a stale hash comes back as a refusal carrying `current_hash` + `current_body`
to merge against — never a clobber), `codex_create_chapter`, `codex_push_files`,
`codex_create_task`, `codex_update_task`, `codex_complete_task`,
`codex_log_writing`. Every write flows through api.php, at the caller's role, and
records a revision with the caller's identity (A1).
Admin: `codex_sync(dry_run)` — service token only; personal tokens are refused
(it reconciles the whole books folder). Retires with the A4 cutover.

The granular reads/writes ride api.php's object-level actions (`chapter`,
`save_chapter`, `chapter_create`, `entries`, `search`, `diagnostics`,
`task_create`, `task_update`); only `codex_get_entry`/`codex_list_chapters`/
`codex_status` still read via the `export` snapshot.

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

## Connect Claude

**OAuth sign-in (Track B3, the recommended path).** Add the connector with just
the URL — no token:

  Customize -> Connectors -> "+" -> Add custom connector
  URL:  https://<domain>/mcp

Claude gets a 401 whose `WWW-Authenticate` points at
`/.well-known/oauth-protected-resource` (served by `oauth.php` via a Caddy
rewrite), discovers the app's authorization server (RFC 8414), registers itself
(RFC 7591 dynamic client registration, public client + PKCE S256), and sends
the user to the app's sign-in + consent screen. The issued access token is a
bearer `api.php` resolves like a personal token (`user_for_oauth_token`), so
the pass-through and scoping are identical. Refresh tokens rotate on every use;
a replayed refresh token revokes the whole grant. Users see and disconnect
their grants at **Account → Connected apps**.

**Token-in-URL (fallback for clients without OAuth).** Mint a token at
**Account → API tokens**, then use `https://<domain>/mcp?k=<personal token>`.
The server also accepts `Authorization: Bearer <token>` (used by mcp_smoke.py
and SDK clients). Caddy `log_skip`s /mcp so tokens aren't written to the access
log. Tokens are revocable per-user on the Account page; rotate the service
`API_KEY` if it is ever exposed.

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
- OAuth (B3) is implemented app-side (`src/oauth.php` + `htdocs/oauth.php`);
  conformance against a live Claude org still needs a manual pass on the
  deployed box (the e2e drives the same flow with the SDK + httpx).
- `codex_get_entry`/`codex_list_chapters`/`codex_status` still read the whole
  `export` snapshot — fine at this size; move them to granular actions if it
  grows.
- The DB-canonical flip (Track A) is in: the app no longer needs
  `CODEX_BOOKS_DIR`, `codex_save_chapter` rides the base-hash-guarded
  `save_chapter` action, and every save records an attributed revision.
  `codex_sync` + mirror mode survive only until the A4 cutover (runbook in
  MASTER-PLAN-standalone A4).
