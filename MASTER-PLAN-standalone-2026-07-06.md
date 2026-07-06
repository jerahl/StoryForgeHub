# StoryForgeHub — master plan: the standalone app (2026-07-06)

> Successor to `MASTER-PLAN-vps-2026-06-28.md`. That plan's phases 0–9 and Track E
> (accounts → per-user tokens → presence) are built. This plan is the next level:
> **the app stops being a mirror of a books folder and becomes the thing itself.**

---

## The short version

Today the app is architecturally a *sync target*: the canonical prose lives in
`/srv/codex/books`, a Python reconcile engine keeps folder↔DB agreeing on a systemd
timer, chapter editing is literally disabled unless `CODEX_BOOKS_DIR` is set, and the
MCP server's headline tool is `codex_sync`. That model was right when the Codex lived
in a Cowork folder on a PC; it's dead weight now that everything runs on one box and
users (plural, since Track E) live in the web app.

Three moves, in order of leverage:

1. **DB-canonical flip.** The database becomes the single source of truth. Markdown
   stays the *format* — the dialect every parser, diagnostic, and MCP payload speaks —
   but stops being a second live *copy* that needs reconciling. The folder sync, the
   reconcile engine, the timer, and the conflict/deletion bookkeeping all retire. The
   safety nets the folder provided (git history, "never auto-delete") move into the DB
   as first-class revisions and soft-deletes, plus a nightly Markdown export snapshot.

2. **MCP is the product surface, not a sync adapter.** Every user connects their own
   Claude to `https://<domain>/mcp` with their own identity (personal token now, OAuth
   next), and gets a granular tool surface — read/write chapters, entries, scenes,
   tasks, the writing log — scoped by the same Phase 18/19 membership and role checks
   as the web UI. `codex_sync` and the push/pull verbs disappear; there is nothing left
   to sync.

3. **True WYSIWYG everywhere.** TipTap graduates from "entry_edit only" to the whole
   app — including the manuscript, where the blocker (TipTap destroys the
   `<!-- comments -->` chapters rely on) is solved with custom ProseMirror nodes and a
   round-trip test gate, not by avoidance. Editing feels like a modern writing app;
   saving still emits the exact Codex Markdown dialect, so nothing downstream changes.

The order matters: the flip (Track A) deletes the very complexity that made chapter
WYSIWYG scary (file/DB dual-write conflicts) and makes the MCP story coherent (tools
write the truth, not a copy). Tracks B and C then run largely in parallel.

---

## Where we are (honest inventory)

What already works for us:

- **The DB is already self-sufficient.** `entries` + `entry_fields`/`entry_sections`/
  `entry_relations`, `chapters.body`, `scenes`, `progressions`, `meta_pages`, `tasks`,
  `writing_log`, `sources`, plot board, notes — everything has a table. `api.php export`
  can regenerate the folders byte-for-byte via `codex_sync_lib` renderers. The folder
  adds no data; it only adds *a second copy*.
- **Per-user API tokens (Phase 20)** already make `api.php` act as a user with full
  membership/role scoping. The MCP just doesn't use them yet — `/mcp` still gates on
  the shared `API_KEY`.
- **Optimistic conflict checks + soft locks (Phase 21)** already protect concurrent
  saves *within the app*. Half of `write_chapter_file()`'s complexity exists only to
  also detect *folder* drift — which disappears with the folder.
- **TipTap + Vite pipeline (Phase 4/5)** exists, with live mentions, and the save
  contract (`editor → Markdown → md_parse_entry`) proven on entries.

What holds us back:

- `write_chapter_file()`, `create_chapter`, `create_book`, and both import paths
  **refuse to run without `CODEX_BOOKS_DIR`** (`src/repo.php:258–502`) — the app can't
  even be *installed* standalone today.
- The MCP tool surface reads via whole-snapshot `export` (no `codex_get_chapter` body
  at all), writes via the folder-shaped `push` verb, and authenticates every caller as
  the same unscoped service identity via token-in-URL.
- Chapters, `entry_new`, meta pages, and notes still edit raw Markdown in a textarea.
- The folder's git history is the de-facto undo/backup story; the DB has none.

---

## Target architecture

```
                     Debian VPS
  ┌─────────────────────────────────────────────────────────────────┐
  │  Caddy ── auto-HTTPS ──► <domain>                               │
  │    ├── /           → php-fpm → htdocs/   (the app)              │
  │    ├── /assets/app → static Vite bundle  (the editor)           │
  │    └── /mcp        → codex-mcp service   (per-user auth)        │
  │                                                                 │
  │  php-fpm ── PDO ──► MariaDB  ◄── THE single source of truth     │
  │                        │                                        │
  │                        ├── revisions tables (undo/history)      │
  │                        └── nightly export → /srv/codex/exports  │
  │                             (Markdown snapshot, off-box copy)   │
  │                                                                 │
  │  codex-mcp (Python) ── thin client of api.php on localhost      │
  │     └── per-user token / OAuth ─► acts as that app user         │
  └─────────────────────────────────────────────────────────────────┘
        ▲ HTTPS + per-user credential          ▲ HTTPS
   Alice's Claude connector               Bob's Claude connector
```

Gone from the old diagram: `/srv/codex/books` as canonical, the reconcile engine, the
`codex-sync.timer`, and the single shared MCP identity. Markdown import/export remains
forever — as **onboarding and backup**, not as live sync.

### The three design decisions (baked in)

1. **Markdown is the format; the DB is the truth.** We do *not* switch storage to
   ProseMirror JSON. Everything downstream — `md_parse_entry`, scene splitting,
   diagnostics, mentions, the MCP payloads, export — speaks the Codex dialect, and
   Claude is *good* at Markdown. WYSIWYG is a view over it.
2. **The safety guarantees survive the folder.** "Never auto-delete" becomes
   soft-delete + revisions (nothing new — chapters already archive instead of delete).
   "Conflicts are skipped, not merged" becomes the Phase 21 base-hash refusal, now the
   *only* conflict rule. "Commit only on confirmed write" becomes a DB transaction.
3. **One writer path stays.** MCP and web UI both write through the same PHP repo
   functions (MCP via `api.php` on localhost). No second implementation of any write.

---

## Track A — the DB-canonical flip

### A1 — Revisions & safety rails *(do first; everything else hides behind it)*

Before removing the folder's git history, replace it:

1. `ensure_revisions()`: `chapter_revisions` and `entry_revisions` (additive, lazy
   migration as always) — `(id, chapter_id/entry_id, body_md, saved_by, saved_via
   [web|mcp|import], base_hash, created_at)`. Every successful save through any path
   inserts one. Prune policy: keep everything for 90 days, then thin to
   one-per-day (writers keep everything; disk is cheap, prose is small).
2. **History UI:** a "Revisions" panel on chapter and entry pages — list, view, diff
   (server-side word diff is fine), one-click restore (restore = a new revision, never
   a rewind).
3. **Nightly export snapshot:** a small PHP CLI (`bin/export.php`) renders every book
   to Markdown folders under `/srv/codex/exports/<date>/` (reusing the existing
   render path), keeps N days, and the existing off-box backup picks it up alongside
   the `mysqldump`. This is the new "my prose is safe in plain files" answer — a
   generated artifact, not a live copy.

**Contract impact:** none. **Effort:** ~1 session.

### A2 — Un-gate the app from `CODEX_BOOKS_DIR`

Make the app fully functional with no books directory configured:

1. `write_chapter_file()` → `save_chapter()`: keep the base-hash optimistic check
   (against `chapters.body` only — the "on-disk drift" branch dies), keep CRLF
   canonicalisation, write DB + revision in one transaction. Delete the
   `books_dir` guard.
2. Same for `create_chapter`, `create_book`, and both import paths
   (`src/repo.php:395,432,462,502`) — they become pure DB operations.
3. **Transitional mirror mode:** while `CODEX_BOOKS_DIR` *is* still set, every save
   additionally writes the `.md` file (best-effort, after the DB commit, never
   blocking a save on file I/O). This keeps the folder usable during the cutover
   window and gives an instant rollback story. A config flag, deleted in A4.
4. Entry saves already go DB-first; verify no other surface (meta pages, notes,
   progressions, sources) touches the filesystem.

**Contract impact:** yes — the app becomes the writer of record. This is the flip.
**Effort:** ~1 session (the Phase 21 conflict machinery already exists).

### A3 — Granular REST for everything the MCP needs

`api.php` grows real object-level actions so nothing has to read whole-snapshot
`export` or speak the folder-shaped `push`:

- `get_chapter` (with body — missing today), `save_chapter` (with `base_hash`),
  `create_chapter`, `list_chapters` (already derivable), `archive_chapter`
- `get_entry` / `save_entry` / `create_entry` / `list_entries` (by db, paginated)
- `search` (server-side, with snippets — today the MCP greps a full export client-side)
- `scenes`/`acts` read + reorder/label; plot board cards; threads; sources
- `create_task` / `update_task` (Claude can leave work *for you*, not just take it)
- keep `export`/`import` (snapshot) and — during the window only — `push`/`pull`

All routed through the same `require_cap` role checks; per-user tokens already work
here. Each action is small because the repo functions all exist.

**Contract impact:** additive. **Effort:** ~1–1.5 sessions.

### A4 — Cutover and demolition

1. Run **mirror mode + parallel timer** for a week or two of real use; confirm the
   folder never disagrees with the DB except by its own staleness.
2. Final `bin/export.php` run into the books folder; `git tag codex-folder-final`
   there. That tag is the rollback artifact and the historical archive.
3. Disable `codex-sync.timer`; strip `codex_sync`, `codex_push_files`'s
   folder/reconcile semantics, and the reconcile module from the MCP service (Track B
   rebuilds the tools on A3 actions); delete mirror mode and `CODEX_BOOKS_DIR` from
   config; mark `sync_engine/reconcile.py`, `cycle.py`, `engine.py` historical.
   `codex_sync_lib.py` **survives** — it's the parser/renderer import/export uses.
4. README rewrite: installation = PHP + MariaDB + env file. No folders, no timers.

**Rollback:** re-enable the timer + mirror mode; the folder was kept warm the whole
window. **Effort:** ~0.5 session plus the observation window.

---

## Track B — MCP as the product surface

### B1 — Per-user MCP auth *(quick win; do immediately, even before A finishes)*

`mcp_server.py` currently accepts only the shared `API_KEY`. Change the auth
middleware to accept **any** presented token (`Authorization: Bearer` or `?k=`) and
pass it through as the `X-Codex-Token` on every `api.php` call instead of the
service key. `api.php` already resolves per-user tokens and scopes everything
(Phase 20) — the MCP just needs to stop laundering every caller into the service
identity. Users mint tokens at **Account → API tokens** and add a connector with
`https://<domain>/mcp?k=<personal token>`. The service `API_KEY` stays valid for
admin automation.

Every MCP write now lands in the Phase 19 activity log as the real person.
**Effort:** ~0.5 session. **Unblocks:** giving co-authors MCP access *today*.

### B2 — Tool surface v2 (thin, granular, scoped)

Rebuild `mcp_tools.py` as a thin client of the A3 actions. Target surface:

- **Read:** `list_books`, `list_chapters`, `get_chapter` (body at last), `get_entry`,
  `search` (server-side snippets), `get_scenes`, `get_tasks`, `get_writing_log`,
  `get_diagnostics` (the P7 analysis — Claude should see what the app sees)
- **Write:** `save_chapter` (base-hash guarded; a refused save returns the current
  hash + a diff so Claude can merge and retry — the old CONFLICT rule, now
  interactive), `create_chapter`, `save_entry`, `create_entry`, `complete_task`,
  `create_task`, `log_writing`, `update_thread`
- **Gone:** `codex_sync`, folder-path pushes, `reconcile_chapters` flags.
- **MCP resources:** expose books/entries/chapters as browsable resources; **MCP
  prompts:** ship "run my flagged tasks", "fill in the writing log", "continuity
  check this chapter" as server-side prompts, replacing most of what the
  `codex-webapp-sync` skill did procedurally.

Structured, paginated outputs throughout — no more whole-snapshot reads.
**Depends:** A3 (parts work off existing actions immediately). **Effort:** ~1–1.5 sessions.

### B3 — OAuth for Claude connectors *(the real multi-user story)*

Token-in-URL works but is a paste-a-secret experience and logs-adjacent. FastMCP
supports pluggable auth; implement **OAuth 2.1 + dynamic client registration** so
"Add custom connector → sign in with your StoryForgeHub account" is the whole flow:

1. Small OAuth endpoints in the PHP app (authorize + token + registration), issuing
   short-lived access tokens bound to the signed-in user; the MCP's
   `token_verifier` introspects against the app.
2. Personal API tokens remain for scripts/SDKs; OAuth becomes the recommended path.
3. Rate-limit `/mcp` per identity; keep Caddy `log_skip` and rotate the legacy key.

**Depends:** B1 shipped (it's the fallback), Track E accounts (done). **Effort:**
~1.5–2 sessions (the fiddly part is conformance with Claude's connector flow —
budget for testing against a real Claude org).

### B4 — Onboarding & the skill

1. **"Connect Claude" panel** on the Account page: connector URL, mint-token button
   (or OAuth explainer once B3 lands), copy-paste instructions, per-token last-used
   display, revoke.
2. Rewrite `codex-webapp-sync.skill` → **`storyforge.skill`**: no inbox/outbox, no
   sync vocabulary — it teaches Claude the tool surface and the house rules (never
   bulk-rewrite chapters unasked, log writing sessions after manuscript work, respect
   task scope). Ship it in-repo; reference it from the panel.
3. Docs page in-app ("Working with Claude") with worked examples.

**Effort:** ~1 session.

---

## Track C — true WYSIWYG

The principle stands: **every editor is a view; the save path is always
`editor → Markdown → existing parser`.** What changes is that "view" stops meaning
"textarea" anywhere, and the known lossiness is engineered away instead of avoided.

### C1 — Round-trip hardening *(the gate for everything else)*

The reason chapters stayed a textarea is real: stock TipTap + tiptap-markdown
destroys `<!-- comments -->`, escapes `[[brackets]]`, and can reflow constructs the
dialect depends on. Fix it at the schema level:

1. **Custom nodes/marks:**
   - `WikiLink` — atomic inline node for `[[slug]]` / `[[slug|text]]`; renders as a
     styled chip, serializes verbatim (kills the fragile regex un-escape in
     `main.js`), integrates with the P5 mention highlighter (click-to-link inserts a
     node, not text).
   - `HtmlComment` — preserved node for `<!-- ... -->`; renders as a subtle
     collapsible annotation pill in the editor (writers *see* their notes instead of
     losing them), serializes byte-identical.
   - `SceneBreak` — `***` as a first-class horizontal node (already meaningful to
     `md_split_scenes`).
   - Heading policy matching the dialect (`##` sections in entries, `###` scene
     titles in chapters).
2. **Golden corpus test:** a fixture suite of real entries and chapters (pulled from
   the export snapshot) round-tripped `md → editor doc → md` in Node/jsdom, asserting
   **canonical-byte stability** (same canonicalisation as `md.php:156`). This runs in
   CI/pre-deploy; a construct that can't round-trip must get a node or the corpus
   test fails. This is Track C's equivalent of the old reconcile parity bar.
3. **Runtime seatbelt:** on load, the editor serializes immediately and compares to
   the source; on mismatch it drops to the Markdown textarea with a notice instead
   of ever saving lossy output. (Same spirit as the existing no-JS-safe `#md-out`
   prefill.)

**Effort:** ~1.5 sessions. **Blocks:** C2/C3.

### C2 — Manuscript WYSIWYG (the Write mode, completed)

Swap the chapter textarea for the hardened TipTap in the P9 focused writing view —
this is the payoff feature:

1. Same conflict-guarded save (`base_hash` vs `chapters.body`; post-A2 there is no
   file branch), same autosave-on-refusal, same soft-lock presence banner.
2. The P5/P9 goodies move from "chapter page" to "in the editor": type-tinted live
   mention highlights (already built for entries), hover cards, the live "In this
   scene" rail recounting as you type, the ✦ Smart-editing slide-in.
3. Writer-grade chrome: floating toolbar (B/I/H, scene break, comment, wiki-link),
   word count + session delta in the top bar (feeds `writing_log`), focus/typewriter
   mode, `***` and `##`-style **Markdown input rules** so muscle-memory typing still
   works inside the WYSIWYG.
4. **Raw-Markdown toggle** (per-user preference): both views over the same buffer,
   switchable mid-edit. Writers who live in Markdown lose nothing; the toggle is
   also the pressure valve for any construct the corpus hasn't met yet.

**Depends:** C1, A2 (pure-DB saves). **Effort:** ~1.5–2 sessions incl. real browser
testing (the standing gap — several P9 slices shipped "not browser-tested").

### C3 — Every remaining textarea

Convert `entry_new` (reuse the entry_edit wiring), meta pages, notes, chapter notes,
task descriptions, progressions. One shared bundle, per-context config (which nodes,
which toolbar). Delete the "convert them next" debt from `editor/README.md`.

**Effort:** ~1 session.

### C4 — Editor niceties (post-parity polish, pick-and-choose)

Slash-command menu (insert scene break / comment / wiki-link / heading), paste-from-
Word/Docs cleanup into the dialect, smart-quotes/dashes as input rules honoring the
P7 em-dash diagnostics, drag-handle block reordering, and — behind an upload
endpoint + storage decision — images for entries. Each is additive on the C1 schema.

**Effort:** cafeteria-style, ~0.5 session each.

---

## What this unlocks later (explicitly out of scope)

- **Real-time co-editing (Yjs/CRDT):** the README already notes it was deferred
  pending "the DB-canonical flip." After A2+C2 the prerequisites exist — the doc
  model is ProseMirror, the truth is the DB, presence is built.
- **Compile/export to EPUB/DOCX** from the export renderer.
- **Public MCP onboarding** (invite a co-author, they connect Claude in minutes) as
  the growth loop — B3+B4 make this real.

---

## Sequencing & dependency map

```
A1 revisions/safety ──► A2 un-gate DB writes ──► A4 cutover (after window)
                              │                        ▲
B1 per-user MCP auth (now) ──►│                        │
                              ▼                        │
                        A3 granular REST ──► B2 tools v2 ──► B3 OAuth ──► B4 onboarding
                              │
C1 round-trip gate ──► C2 manuscript WYSIWYG ──► C3 remaining textareas ──► C4 polish
        (parallel with Track A; C2 needs A2)
```

- **Week 1 energy:** B1 (half-session, immediate value) + A1 + start C1.
- **The flip (A2)** lands early; mirror mode keeps it reversible until A4.
- **B2/B3** and **C2/C3** then run as independent lanes.
- Total ≈ **11–14 focused sessions**, comparable to the VPS plan.

## Cross-cutting guarantees (updated)

- **Markdown stays the dialect.** Save path is always `editor → Markdown → parser`;
  MCP payloads are Markdown; export regenerates clean folders on demand.
- **The DB is the only truth; revisions are the memory.** Every write path inserts a
  revision. Nothing is hard-deleted; archive/soft-delete everywhere.
- **Conflicts are refused, not merged** — base-hash checks on every prose save, web
  and MCP alike; refusals return enough context to resolve interactively.
- **Additive schema only** (`ensure_*` lazy migrations, as always).
- **One writer path:** MCP → `api.php` → the same repo functions as the UI.
- **Every request has a user** (service token = admin automation only); scoping and
  roles enforced server-side; activity log records web and MCP writes identically.

## Top risks

- **Round-trip lossiness (C1) is the new reconcile parity** — the golden corpus is
  the gate; no WYSIWYG surface ships for a construct the corpus can't round-trip.
  The runtime seatbelt + Markdown toggle bound the blast radius of misses.
- **Deleting the folder deletes the accidental backup.** A1 (revisions + nightly
  export + off-box copy) must land *before* A4, and a restore must be tested.
- **OAuth conformance (B3)** against Claude's connector flow is the least-charted
  work; B1's token path is the shipped fallback, so B3 can slip without blocking.
- **Browser-testing debt:** Track C is UI-heavy and prior slices shipped untested in
  a real browser; budget explicit manual passes (or Playwright smoke tests against a
  seeded instance) per C-phase.
- **MCP write surface grows** (create/update everywhere): rate-limit per token, keep
  refusal-by-default on destructive ops (archive needs explicit confirmation arg),
  and lean on revisions for undo.

## Recommended first move

Ship **B1** (per-user MCP auth pass-through — half a session, retires the shared-
identity wart while everything else is still planning) and **A1** (revisions +
nightly export). Then start **C1**'s golden corpus and **A2** in parallel: the corpus
proves WYSIWYG is safe the same way the fixture suite once proved sync was safe, and
A2 makes the app standalone. From there the tracks unroll independently.
