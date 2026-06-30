# Codex MCP sync service — design

> ⚠ **PARTIALLY SUPERSEDED (2026-06-28).** The runtime model changed with the move to a
> **Debian VPS**: the MCP server is now a **server-side `systemd` service over HTTPS with
> continuous `systemd`-timer sync** (not a local stdio, on-demand script), and the
> folder↔DB reconcile runs **locally on the box**. See `MASTER-PLAN-vps-2026-06-28.md`
> (Phases 2–3) for the current design. The tool surface, reconcile guarantees, and the
> reuse of `codex_sync_lib.py` below are still accurate.

Replacing the `sync-codex.ps1` scheduled task with a **Python MCP server** that Claude connects to directly, so sync and Codex operations happen as live tool calls instead of files passed through a bridge folder.

*Design doc. Nothing built yet. Decisions baked in: **design-first**, **on-demand sync** (no continuous background loop), **Python runtime reusing `codex_sync_lib.py`**.*

---

## The short version

Today, three moving parts cooperate: a **PowerShell scheduled task** (`sync-codex.ps1`) moves data every 15 minutes, a **bridge folder** (`codex-web-sync/` with `inbox/`, `outbox/`, `state.json`) buffers it, and the **`codex-webapp-sync` skill** does the intelligent folder-side work by reading the inbox and writing the outbox. It works, but it's indirect: Claude can't see the app's state directly, sync only happens on a timer, and the API token sits in plaintext in the script.

We replace the PowerShell mover and the bridge files with a **local Python MCP server**. It talks to the same two things the script did — the web app over `api.php` (HTTPS + token) and the local book folders (filesystem) — and exposes them to Claude as tools: `codex_status`, `codex_sync`, `codex_get_tasks`, `codex_complete_task`, `codex_log_writing`, `codex_get_entry`, `codex_save_entry`, and a few more. The 3-way reconcile engine that lives in the PS script moves into the server, reusing the **already-tested** `codex_sync_lib.py` for all Markdown parse/render. The `codex-webapp-sync` skill becomes a thin wrapper that calls these tools instead of shuffling JSON files.

Result: you say "sync my codex" or "run the flagged tasks," Claude calls a tool, and it happens in-conversation with a structured report back — no scheduled task, no bridge folder, no plaintext token in source.

---

## What the PowerShell script does today (the spec to match)

Reading `sync-codex.ps1`, one cycle is:

1. **`ping`** the API; abort if the host returns non-JSON (anti-bot/HTML challenge guard).
2. **Pull** the app's entry Markdown (`pull`), **gather** the folder's entry Markdown, and **reconcile per file** using a 3-way comparison — folder hash vs. web hash vs. last-synced hash in `state.json`:
   - changed only in folder → **push up**
   - changed only in app → **write down** into the folder
   - changed on both sides → **CONFLICT**, skipped and reported
   - missing on one side but previously known → **DELETION**, never auto-propagated
   - brand-new on one side → created on the other
   - first-ever sight of a differing file (no state) → **folder wins**
3. **Discover book identities** from each `Codex/Meta/book.json`; regenerate the central `books.json`.
4. **Push** folder-changed entries plus an always-refreshed bundle of manuscript files (with `manuscript_present` so the app can soft-archive removed chapters), `Codex/Meta/*`, progressions, and `Codex/Notes/**`. Commit hashes to `state.json` **only after** the app confirms the write.
5. **Pull tasks** flagged for Claude (`tasks?for_claude=1&status=todo`) → `inbox/tasks.json`; pull `writing-log` → inbox.
6. **Post** the skill's `outbox/results.json` (task results, writing-log rows, thread status) via `apply`, then archive it.
7. **Persist** the per-file hash manifest.

The MCP server must preserve every guarantee in bold above — especially **never auto-delete**, **conflicts are skipped not merged**, and **commit state only on confirmed push**. These are the safety properties that make the sync trustworthy.

## What the API already gives us (reuse, don't rebuild)

`api.php` is a clean token-auth REST surface and needs **no changes** for v1:

| Action | Method | Purpose |
|---|---|---|
| `ping` | GET | health + book count |
| `pull` | GET | web → folder: all entry Markdown, keyed by folder/relpath |
| `push` | POST | folder → web: `{books:[{folder,files,manuscript_present,book}]}` |
| `tasks` | GET | tasks, filterable by `for_claude` / `status` |
| `apply` | POST | `{task_results,writing_log,thread_status}` back into the app |
| `writing-log` | GET | log rows |
| `export` / `import` | GET/POST | full snapshot (seeding/debug) |

So the MCP server is a **client** of this API plus the filesystem — the same position the PS script held. (The web app stays on shared hosting and runs nothing new.)

---

## Architecture

```
                         your PC (where Cowork/Claude runs)
   ┌───────────────────────────────────────────────────────────┐
   │  Claude (Cowork)                                            │
   │     │  MCP (stdio)                                          │
   │     ▼                                                       │
   │  codex-mcp  (Python)                                        │
   │     ├── reconcile engine  (3-way hash diff + state.json)    │
   │     ├── imports codex_sync_lib.py  (parse/render/snapshot)  │
   │     ├── filesystem  ◀──▶  C:\Users\steph\Cowork\projects\books
   │     └── HTTPS + token ───────────────┐                      │
   └──────────────────────────────────────┼──────────────────────┘
                                           ▼
                              api.php  (shared host, PHP+MySQL)
```

- **Transport: stdio**, launched by Cowork as a local MCP server. Simplest, no open port, no daemon to manage. Because we chose **on-demand sync**, there's no background loop to keep alive — stdio's "runs while the client is connected" model is exactly right.
- **Runtime: Python**, so it `import`s `codex_sync_lib.py` directly. That module already implements `parse_entry`, `render_entry`, `write_entry`, `parse_chapters`, `parse_progressions`, `parse_meta`, `parse_book`, and `snapshot`, plus `DBS`/`DBMETA`/`STATUS_VALUES`. Reusing it means the MCP server and the skill agree byte-for-byte with the PHP side (which is itself a port of this module) — zero new Markdown logic to test.
- **Config**, not hardcoded secrets: a `codex-mcp.toml` (or env vars) holding `base_url`, `api_token`, `books_root`, and `state_path`. The token never lives in tracked source. (Contrast: `sync-codex.ps1:33` ships the token in plaintext — see Security.)

### Package layout
```
codex-mcp/
  server.py            # MCP entrypoint: registers tools, stdio loop
  sync_engine.py       # the 3-way reconcile (ported from the PS script)
  api_client.py        # thin requests wrapper over api.php (+ non-JSON guard)
  config.py            # loads codex-mcp.toml / env; resolves paths
  codex_sync_lib.py    # symlink/vendor of the skill's tested module
  pyproject.toml
  README.md
```

---

## Tool surface

Two tiers. **Sync tools** replicate the script; **granular tools** are the new capability — they let Claude read and edit the Codex live, which the file-bridge never allowed.

### Sync (replaces the scheduled task)
- **`codex_status()`** → ping + book list + counts; surfaces the non-JSON-host guard as a clear error.
- **`codex_sync(mode="sync"|"push"|"pull", dry_run=false, book=None)`** → runs the full reconcile cycle and returns a structured report: `{pushed:[...], pulled:[...], conflicts:[...], deletions:[...], archived:N, created_books:[...]}`. This is the headline tool — "sync my codex."
- **`codex_resolve_conflict(file, keep="folder"|"app")`** → explicit resolution for anything `codex_sync` reported as a CONFLICT (the one thing the script could only log, never fix).

### Tasks & log (replaces inbox/outbox)
- **`codex_get_tasks(book=None, for_claude=true, status="todo")`** → the tasks the skill used to read from `inbox/tasks.json`, returned directly.
- **`codex_complete_task(id, result, status="done")`** and **`codex_apply_results(task_results, writing_log, thread_status)`** → what used to be written to `outbox/results.json`, posted straight through `apply`.
- **`codex_log_writing(book, words_added, minutes, note, ...)`** → append a writing-log row (covers "fill in the writing log").

### Granular Codex access (net-new value)
- **`codex_list_books()`, `codex_get_entry(book, db, slug)`, `codex_search(book, query)`** → read.
- **`codex_save_entry(book, db, slug, markdown)`** → validate via `codex_sync_lib.parse_entry`, write through the reconcile path so it lands in both folder and app safely.
- **`codex_get_chapter(book, id|file)`, `codex_list_chapters(book)`** → manuscript reads (and a hook for the expansion plan's scene work later).

All write tools route through the **same reconcile/confirm logic** as `codex_sync` — no tool is allowed to write the folder or the app without honoring conflict/deletion safety.

---

## On-demand sync model (the chosen tradeoff)

The scheduled task synced every 15 minutes whether or not you were working. On-demand means **sync runs when Claude calls `codex_sync`** — typically when you start or end a session, or when you say "sync." Implications and mitigations:

- **You control timing.** No surprise writes; every sync is a visible tool call with a report. Good for trust, good for debugging.
- **Edits made while away aren't reconciled until the next call.** That's fine for a single-author workflow — nothing is lost, the 3-way diff catches up whenever it next runs. The "commit state only on confirmed push" rule means an interrupted sync just retries cleanly next time.
- **Optional belt-and-suspenders:** a Cowork **scheduled task** (the `schedule` skill) can fire a "sync my codex" run each morning, giving you periodic sync without resurrecting PowerShell or a daemon. This stays within the on-demand model — it's just an automated caller.

If you later want true continuous sync, the same server can grow a background loop, but that's explicitly out of scope here.

---

## Migration path

1. **Build `codex-mcp` and point it at the same `state.json`** the PS script uses (or copy it). Sharing the manifest means the MCP server picks up exactly where the script left off — no first-run "folder wins" stampede.
2. **Run them in parallel briefly.** Keep the scheduled task enabled but lengthen its interval; drive sync through the MCP tool and confirm reports match what the script's `sync.log` would show. The reconcile is idempotent, so overlap is safe.
3. **Repoint the skill.** `codex-webapp-sync` changes from "read `inbox/tasks.json`, write `outbox/results.json`" to "call `codex_get_tasks` / `codex_complete_task`." Its folder-side intelligence (running tasks against the Codex, following the writing rules) is unchanged.
4. **Retire the scheduled task** and the bridge folder once a few real sessions go through cleanly. `state.json` stays as the MCP server's manifest.
5. **Rotate the API token** during cutover (it's been in plaintext) and put the new one only in `codex-mcp.toml`.

Rollback is trivial: re-enable the scheduled task; the shared `state.json` keeps both consistent.

---

## Security notes

- **Plaintext token today.** `sync-codex.ps1` line 33 contains the live `api_token`, and the script sits in a synced workspace. Move it into untracked config and **rotate it** at cutover.
- **MCP server holds two secrets** — the API token and filesystem access to your books. Keep `codex-mcp.toml` out of any synced/tracked location, file-permission it to your user, and never echo the token in tool output or logs.
- **Least surprise on writes.** Every write tool returns what it changed; `dry_run` on `codex_sync` mirrors the script's `-WhatIf` so you can preview before committing.
- **Host guard preserved.** Port the script's "abort if `ping` isn't valid JSON" check so a host serving an anti-bot page can never corrupt `state.json`.

## Risks & open questions
- **Reconcile parity is the whole ballgame.** The port of the 3-way diff must reproduce the script's branch-for-branch behavior (new-on-one-side, deletion-noticed, first-sight-folder-wins, confirmed-push-only commit). Recommend a small fixture suite that runs the engine against synthetic folder/web/state combinations and asserts the same decisions — this is the natural verification step before retiring PowerShell.
- **`codex_sync_lib.py` sourcing.** Vendor a copy into `codex-mcp/` or import from the skill path? Vendoring avoids a runtime dependency on the skill's location; a periodic diff check keeps them aligned. (The Notion sync skill ships its own copy too — same pattern.)
- **Python availability on your PC.** The MCP server needs a Python runtime where Cowork launches it; confirm the interpreter and how Cowork registers a local stdio MCP server (custom connector config).
- **Multi-device.** On-demand + stdio assumes one machine. If you write on two PCs, each runs its own server against the same app; the app remains the shared source of truth, which is fine.

## Recommended first step
Build `api_client.py` + `sync_engine.py` as a plain CLI first (no MCP yet) that reproduces `codex_sync` and passes the fixture suite against the current `state.json`. Once its reports match the PowerShell `sync.log`, wrap it in `server.py` as the `codex_sync` / `codex_status` tools, then add tasks and granular tools. This validates the risky part (reconcile parity) before any MCP plumbing.
