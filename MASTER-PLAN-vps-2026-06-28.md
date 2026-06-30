# Stephen's Codex — master plan (Debian VPS)

The single, re-baselined plan now that the app is moving from Wasmer Edge to a **self-managed Debian VPS** that hosts everything: the PHP app, the database, the book folders, and a server-side MCP service.

**This document consolidates and replaces** the now-removed Wasmer-era docs (the Edge deployment notes, the P0 Wasmer-hardening runbook, and the unified implementation roadmap), plus the deployment half of `MCP-SYNC-PLAN-2026-06-27.md`. Three docs remain alongside it: `MCP-SYNC-PLAN-2026-06-27.md` (the MCP tool surface and reconcile guarantees, still accurate), `EXPANSION-PLAN-2026-06-27.md` (the feature design), and `DESIGN-PROMPT-codex-prototype.md` (the UI target). This plan changes the *platform, sync architecture, and editor tech* around them. The repo's `app.yaml` / `wasmer.toml` are **dead config on a VPS** (kept for reference / possible Wasmer fallback only).

*Plan only. Grounded in the app source (the web-exposed files live in `htdocs/`, which is the web docroot; app internals — `src/`, `config.php`, `schema.sql`, `bin/`, `sync/` — sit above the docroot), `sync-codex.ps1`, `codex_sync_lib.py`, and the existing docs as of 2026-06-28.*

---

## The short version

A VPS removes nearly every constraint the earlier plans were bent around. Wasmer Edge was stateless (ephemeral filesystem, no persistent processes, no build step, secrets-as-Edge-Secrets, volumes for any file write). A Debian box is the opposite: **persistent disk, long-lived processes, real cron, a full build toolchain, ordinary env-file secrets, and root.** Three consequences reshape the program:

1. **The MCP server stops being a local, on-demand stdio script and becomes a real service.** It runs as a **systemd unit on the VPS**, always-on, with **continuous sync on a systemd timer** and a **remote tool surface Claude connects to over HTTPS**. The PowerShell scheduled task and the bridge folder disappear entirely.
2. **Sync collapses from a network dance to a local reconcile.** With the app, the database, and the canonical book folders all on one box, "sync" is folder↔DB *on the same machine* — no PC, no HTTP hop, no plaintext token traveling the wire. The 3-way reconcile logic still matters and still gets fixture-tested, but it runs locally and writes through the app's own code path.
3. **The editor can be best-in-class.** With a build step allowed, the rich editor becomes **TipTap/ProseMirror** (bundled, served as static assets by the web server) with **live, as-you-type mention highlighting** — the tier the Edge plan had to defer. This closes most of the gap to the competitor prototype.

The feature sequence is otherwise intact: provision the platform, ship the Act→Chapter→Scene Grid (hero feature, contract-safe), migrate sync to the MCP service, then build the editor and the features that ride it, and isolate the one contract-breaking change (the app writing prose) for last.

---

## What the VPS changes (re-baseline)

| Concern | Wasmer Edge assumption (old plans) | Debian VPS reality (this plan) |
|---|---|---|
| Filesystem | Ephemeral; file writes need a Volume | **Persistent disk.** Mood-board uploads, caches, logs just work. No volume gymnastics. |
| Processes | Stateless workers only; no daemon | **Long-lived processes.** The MCP server runs as a `systemd` service. |
| Scheduling | Wasmer Jobs (cron via `fetch`/`execute`) | **`systemd` timers / crontab.** Real local cron for sync + reindex. |
| Build step | None on host; CDN JS only | **Full Node/npm toolchain.** Bundle TipTap; Vite asset pipeline. |
| Secrets | Wasmer Secrets (env injected) | **`EnvironmentFile` / `.env`** read by `getenv()` (config already does this — unchanged). |
| Database | External managed MySQL (volumes unfit) | **MariaDB/MySQL on the box** (or external if preferred). Local socket, no egress. |
| Web serving | shipit/phpix, `php -S` | **nginx + php-fpm** (PHP 8.3), or Caddy. opcache on; no cold starts (Instaboot irrelevant). |
| TLS | Automatic on `*.wasmer.app` | **Caddy auto-HTTPS** or **nginx + Certbot** on your domain. |
| Sync topology | PC folders ↔ Edge app over HTTP + PowerShell | **Everything on one box;** folders↔DB reconcile is local. PC optional. |
| Where you write | Local Cowork folders, synced up | VPS holds canonical folders; edit via the web app + **remote MCP**, with an optional local mirror (SSHFS/rsync). |

The one thing that does **not** change: the app's internal contracts — Markdown as source of truth, the `ensure_*()` lazy-migration pattern, `[[wiki-link]]` resolution, and the never-delete/skip-conflicts/commit-on-confirm sync guarantees. Those carry through untouched.

---

## Target architecture (one box)

```
                    Debian VPS  (e.g. 2 vCPU / 4 GB, Debian 12)
   ┌───────────────────────────────────────────────────────────────────┐
   │  Caddy (or nginx) ── auto-HTTPS ──► storyforgehub.cloud             │
   │     ├── /            → php-fpm  →  /srv/codex/app/htdocs (docroot)  │
   │     ├── /assets/app  → static bundle (Vite build of the editor)    │
   │     └── /mcp         → reverse-proxy → codex-mcp service (HTTPS+token)
   │                                                                     │
   │  php-fpm (PHP 8.3, opcache)                                         │
   │     └── PDO ── localhost socket ──► MariaDB (codex DB)              │
   │                                                                     │
   │  codex-mcp  (Python, systemd service)                              │
   │     ├── reconcile engine (3-way) ── reuses codex_sync_lib.py        │
   │     ├── reads/writes  /srv/codex/books   (canonical folders)        │
   │     └── writes DB via api.php on localhost (one writer path)        │
   │                                                                     │
   │  systemd timer:  codex-sync.timer  → runs reconcile every N min     │
   │  systemd timer:  codex-reindex.timer → mentions/diagnostics nightly │
   └───────────────────────────────────────────────────────────────────┘
        ▲ HTTPS (bearer token / OAuth)                ▲ SSH (admin)
        │                                             │
   Claude (Cowork) ── remote MCP connector            You (optional SSHFS mirror)
```

- **Single source of truth** for prose: `/srv/codex/books` on the VPS. The MCP server and the web app both operate on it locally. An optional `rsync`/SSHFS mirror to your PC keeps a local copy if you want one, but it's no longer required.
- **One DB writer path.** The MCP server doesn't reimplement DB logic; it posts to `api.php` on `localhost`, so all writes still flow through the PHP repo (the `ensure_*` migrations, the `md` round-trip). Reads can go direct for speed.
- **The MCP endpoint is authenticated and TLS-fronted** (`/mcp` behind Caddy with a bearer token; OAuth later if you want per-client identity).

---

## Phase 0 — Provision the VPS and migrate off Wasmer

*Replaces the entire Wasmer "P0 hardening" runbook. Security-critical; unblocks everything.*

1. **Base box.** Debian 12, non-root sudo user, SSH key-only (disable password auth), `ufw` allowing 22/80/443, `fail2ban`, unattended-security-upgrades. Set the hostname/DNS A record for `storyforgehub.cloud`.
2. **Web stack.** Install `php-fpm` (8.3) + extensions (`pdo_mysql`, `mbstring`, `xml`, `curl`, `gd` for image handling), and **Caddy** (simplest auto-HTTPS) or nginx+Certbot. Deploy the app to `/srv/codex/app` and **point the docroot at the `htdocs/` subfolder** (`/srv/codex/app/htdocs`): only the web-exposed files (`index.php`, `api.php`, `assets/`) live there, while the app internals (`src/`, `config.php`, `schema.sql`, `bin/`, `sync/`) sit above the docroot and are physically unreachable over HTTP. Deploy excludes repo docs, the `deploy/` tooling, and any stray secrets. Turn on opcache.
3. **Database.** Install MariaDB; create the `codex` DB + a least-privilege user bound to `localhost`. Import `schema.sql` (or hit **Sync → Import snapshot.json**, which runs `migrate()` first). Keep daily `mysqldump` to an off-box location.
4. **Secrets via env file.** Put `DB_HOST=127.0.0.1`, `DB_PORT`, `DB_NAME`, `DB_USERNAME`, `DB_PASSWORD`, `API_KEY`, `APP_PASSWORD` in a root-owned `/etc/codex/codex.env` (mode 600), loaded into php-fpm's pool (`env[...]` or `EnvironmentFile`). `config.php` already reads these via `getenv()` — **no code change**. **Rotate** the API token, DB password, and app password during the move (they were previously committed in plaintext).
5. **Move the folders.** Place the canonical book folders at `/srv/codex/books` (owned by the deploy user, group-readable by php-fpm/mcp as needed). Decide the PC's role: pure remote (edit via web app + MCP) or keep a local mirror via SSHFS/rsync.
6. **Decommission Wasmer.** Once the VPS serves correctly over HTTPS and the data imports clean, repoint DNS, archive the Wasmer app, and treat `app.yaml`/`wasmer.toml`/`WASMER-EDGE-NOTES`/`P0-RUNBOOK` as historical.

**Status:** the steps above are now automated in `deploy/` — `setup.sh` runs `01-provision.sh` → `02-install-stack.sh` → `03-setup-app.sh` → `04-configure.sh` (idempotent), with `verify.sh` as the smoke test and `enable-root-key.sh` + `MOBAXTERM-ROOT-KEY.md` for the SSH-key bootstrap. See `deploy/README.md`.

**Contract impact:** none. **Effort:** ~1 session of ops (most of it standard VPS setup).

---

## Phase 1 — Manuscript structure + Grid view (hero feature, unchanged)

*Expansion Phase 1. Contract-safe; the prototype's headline screen, built from data you already have.*

Unchanged from the prior plan — the VPS doesn't alter it:

1. `ensure_structure()` creates `acts`, `scenes`, `scene_labels`; `ALTER`s `chapters` to add `act_id` (lazy-migration pattern, no `schema.sql` re-import).
2. `md_split_scenes($body)` in `md.php` splits on `***`/`---` and optional `### scene-title` headings. **Must never lose prose**; single-scene chapter is the fallback.
3. Reconcile in `push_files`/`upsert_chapter_from_md` re-splits and reconciles scenes by stable key (file + ordinal/slug), with a per-scene `body_hash`. `acts`, `scenes.label`, `scenes.summary` are app-only and preserved across re-imports, like `chapters.status` today. Prose pull path untouched.
4. Read-only **Grid view** (`p=manuscript&view=grid`): Act bands → chapter columns → scene cards. Keep the table as `view=list`.
5. Then drag-reorder, Act CRUD, and scene **labels** (Draft 1 / Needs revision / Final / Alt version / Note; Note/Alt excluded from word counts + export).

**Dependency:** P0. **Contract impact:** none. **Effort:** ~2–3 sessions. **Build step:** none (or fold the Grid into the new asset bundle once P4 lands).

---

## Phase 2 — Reconcile engine (now local, intra-box)

*MCP plan, first half — the risky logic, isolated and fixture-tested before wiring the service.*

Port the 3-way diff from `sync-codex.ps1` into `sync_engine.py`, reusing `codex_sync_lib.py` for all parse/render. **On the VPS the engine reconciles `/srv/codex/books` against the DB locally** — the PowerShell-era `api_client` HTTP wrapper shrinks to a `localhost` call to `api.php` (still the single DB-writer path), and the PC-side bridge is gone.

Parity bar is exact, branch-for-branch: changed-in-folder→push, changed-in-app→write-down, changed-both→**CONFLICT (skip + report)**, missing-but-known→**DELETION (never auto-propagate)**, new-on-one-side→create, first-sight→folder-wins, and **commit `state.json` only on confirmed write**. Build a fixture suite over synthetic folder/DB/state combos asserting identical decisions; confirm it matches historical `sync.log`. *This is the gate before retiring PowerShell.*

**Dependency:** P0. Runs **in parallel with P1** (Python vs PHP/UI). **Contract impact:** none (read-validated). **Effort:** ~1 session.

---

## Phase 3 — MCP as a systemd service + cron (retire PowerShell)

*MCP plan, second half — fundamentally upgraded by the VPS.*

Wrap the validated engine in an MCP server exposing the **Streamable-HTTP transport** (not stdio), run as a `systemd` service behind Caddy at `/mcp`, authenticated with a bearer token (the `API_KEY`, or OAuth later). Claude connects to it as a **remote MCP connector** — no local install.

Tool surface (as designed, now scene-aware from P1): `codex_status`, `codex_sync(mode, dry_run, book)`, tasks/log tools (`codex_get_tasks`, `codex_complete_task`, `codex_apply_results`, `codex_log_writing`), granular reads/writes (`codex_get_entry`, `codex_save_entry`, `codex_search`, `codex_get_chapter`/`codex_list_chapters`), and `codex_resolve_conflict`. Every write routes through the reconcile/confirm path.

**Continuous sync via `systemd` timer:** `codex-sync.timer` runs the reconcile every N minutes on the box — the real, always-on replacement for the Windows scheduled task, now with no plaintext token on the wire and nothing for you to keep running on your PC. The `codex-webapp-sync` skill drops its inbox/outbox file-shuffling and calls the remote tools (its folder-side intelligence unchanged). **Then delete the plaintext token and retire `sync-codex.ps1` + the bridge folder.**

**Dependency:** P2. **Contract impact:** none (same guarantees). **Effort:** ~1–1.5 sessions (service + transport + auth + timer).

---

## Phase 4 — Rich text editor (now TipTap, build step)

*Expansion Phase 2. The VPS lets us do this properly.*

Build a **TipTap/ProseMirror** editor (bundled with Vite, served as a static asset by the web server) as the WYSIWYG for entry/meta pages — and the foundation for live mentions (P5) and scene-prose editing (P9). Save flow stays **`editor → Markdown → existing `md_parse_entry``**, emitting the same dialect (`- **Label:** value`, `## Section`, `[[wiki-links]]`, `***`), so sync and the task runner are unaffected. The strict metadata block is a small structured form above the prose editor.

Going TipTap (vs the Edge plan's CDN Toast UI) buys custom nodes for `[[links]]` and mentions, and a real document model — worth the build step now that we have one.

**Dependency:** P1, plus the Vite build set up in P0/here. **Contract impact:** none. **Effort:** ~1.5 sessions (editor + build pipeline).

---

## Phase 5 — Automatic mentions + Aliases (with live highlighting)

*Expansion Phase 3. The build step unlocks the tier the Edge plan deferred.*

Add an `Aliases` metadata field (round-trips through the parser). Build the per-book alias index and `index_mentions($book_id)`: word-boundary, longest-match-first scan over scene-granular `chapters.body`, entry sections, captures, notes → a `mentions` table. Rules: longest alias wins, manual `[[links]]` win, per-entry opt-out for noisy short names. Server-rendered prose uses `inline_md_with_mentions()`; entry pages get an **Appearances** panel (a query over `mentions`).

**Now also ship the live tier:** a TipTap mention extension highlights names **as you type** and offers click-to-link — the prototype's signature interaction, feasible because P4 gave us a real editor.

**Reindex on a `systemd` timer** (`codex-reindex.timer`, nightly) instead of Wasmer Jobs — refreshes the index server-side beyond the on-save pass.

**Dependency:** P1 (scenes), P4 (editor). **Contract impact:** none (derived index; Aliases round-trips). **Effort:** ~1.5–2 sessions.

---

## Phase 6 — Character arcs & timeline progressions

*Expansion Phase 4. Unchanged.*

Add optional `when_label`/`when_order` to `progressions` (app-side ordering; empty → chapter order, handling flashbacks/parallel arcs). A **Timeline** view renders lanes per entity; each entry page gets an **Arc** tab via the existing `progressions.related_csv`. Relationship/politics shifts ride the same model, rendering on both linked entries. `Codex/Meta/progressions.md` stays the source.

**Dependency:** P1, P5. **Contract impact:** none. **Effort:** ~1 session.

**Status: DONE (server-side; pending browser check).** Lazy-migration `ensure_progress_cols()` adds `when_label`/`when_order` (app-only, preserved across the `import_progressions_md` DELETE+re-INSERT via a chapter+what identity key). `get_progressions_timeline()` orders by `when_order` (else parsed chapter number — prologue→0, epilogue→last — else `sort_order`); `prog_chapter_num()` does the parse; `get_entity_arc()` filters by `related_csv`. UI: an **Arc** block on every entry page (renders on each linked entity, so relationship beats show on both); a **Timeline** page (`p=timeline`, nav link added) with time-buckets as columns and one lane per entity, plus an Unattributed table; inline **When** editing on the Progressions page via the `progression_when` action / `set_progression_when()`. Ordering + sync-preservation logic ported to Python and unit-tested. Files: `src/repo.php`, `htdocs/index.php`, `src/layout.php` (nav), `htdocs/assets/style.css`. Not browser-tested (no PHP/browser in sandbox).

---

## Phase 7 — Smart-editing diagnostics

*Expansion Phase 5. Unchanged logic; cron-refreshed.*

Three lexical analyzers in PHP sharing one tokenizer, over `chapters.body`/scene spans: **usage frequency** (n-gram counts minus stopwords, repeated-phrase windows), **patterns to review** (em-dash density, "it's not just X, it's Y", tapestry/testament/delve lexicon, uniform sentence length, hedging clusters — labeled "patterns to review," **never** "AI-written," since it catches stylistic tells, not provenance), and **dialogue control** (quoted-span + attribution-verb extraction, said-bookism/adverb-tag flags, speaker attribution via the P5 index). Cache in `prose_analysis` keyed by `body_hash`. Findings deep-link and highlight the offending span (in the TipTap doc).

Extend `codex-reindex.timer` to refresh these caches alongside mentions.

**Dependency:** P1 (`body_hash`), P5 (attribution). **Contract impact:** none. **Effort:** ~1.5 sessions.

**Status: DONE (server-rendered report; in-editor highlight deferred).** All three analyzers share one tokenizer (`diag_plain_text`/`diag_words`/`diag_sentences`): `diag_usage_frequency` (overused content words + repeated 2–4-gram windows minus stopwords), `diag_patterns` (em-dash density, "it's not just X" construction, flagged lexicon, uniform sentence-length CV, hedging density — labeled "patterns to review", never "AI-written"), `diag_dialogue` (quote spans, said-bookisms, adverb tags). `analyze_prose()` aggregates; results cache in `prose_analysis` (lazy-migration `ensure_prose_analysis()`, one row/chapter keyed by `md5(body)`) via `get_chapter_diagnostics()`; `get_book_diagnostics()` gives the per-chapter summary. UI: a **Diagnostics** page (`p=diagnostics`) — book summary table + per-chapter detail grouped by analyzer — nav link + a chapter-page button. `bin/reindex.php` now also calls `reindex_prose()` so the nightly timer warms the caches. Analyzer logic ported from a Python reference and unit-tested (overused words, repeated phrases, em-dash density, uniform length, lexicon, hedging, bookisms, adverb tags all fire correctly). Files: `src/repo.php`, `htdocs/index.php`, `src/layout.php`, `htdocs/assets/style.css`, `bin/reindex.php`. The in-editor TipTap span-highlight is deferred (browser-only). Not browser-tested.

---

## Phase 8 — Plot-board drops

*Expansion Phase 6. Lowest risk, app-only.*

`ALTER canvas_cards ADD ref_type, ref_id`; a "+ Add from Codex" picker binds a card to a real entry / open thread / progression / scene, rendering its live title+status and linking to it. Free-text cards still work; drag/connect already exists.

**Dependency:** P1, P5/P6. **Contract impact:** none (plot board never syncs). **Effort:** ~1 session.

**Status: DONE (pending browser check).** `ensure_spatial()` lazily adds `ref_type`/`ref_id` to `canvas_cards`. `canvas_ref_resolve()` turns a ref into live `{kind,title,status,color,href}` (entry → "db/slug"; thread/progression/scene → row id), returning null → a "removed" placeholder if the target is gone. `canvas_ref_options()` feeds a searchable **+ Add from Codex** modal (characters/locations/factions/objects/lore entries, open threads, progression beats, scenes). New AJAX action `canvas_add_ref_card`; bound cards render read-only (kind label, title, status, Open → link), type-coloured, and still drag/connect/delete like free-text cards. Resolved ref info travels with each card in the page payload (and is re-attached on load). Logic ported to Python + unit-tested; plot JS node-syntax-checked. Files: `src/repo.php`, `htdocs/index.php`, `htdocs/assets/style.css`. Not browser-tested.

---

## Phase 9 — Scene-prose editing (capstone contract change — simpler on a VPS)

*Expansion Phase 2a — the only contract-breaking step, deliberately last.*

Let the TipTap editor save Markdown back into the correct span of `chapters.body`, making the app a **writer of `Manuscript/*.md`**. On the VPS this is cleaner than the Edge design: the app/MCP writes the folder **directly on local disk** (`/srv/codex/books`) — no "MCP writes files on your PC" indirection, no volume needed. It still extends the sync contract (today entries-only) and still needs a real conflict rule (file edited *and* scene edited), which is exactly why it depends on P3's `codex_resolve_conflict` and the CONFLICT-not-overwrite philosophy, and on stable scene structure from P1. Test in isolation with its own conflict fixtures.

**Dependency:** P1, P3. **Contract impact:** **YES — the only one.** **Effort:** ~1 session.

### P9 addition — the focused writing view (target design, 2026-06-30)

A dedicated **Write** mode for one scene/chapter (distinct from the read-only chapter page), matching the two reference mockups. Reuses existing server data (P5 mentions, P7 diagnostics); the new work is presentation + a few analyzer enrichments.

**Screen 1 — prose with live Codex context.**
- Centered manuscript column (serif, generous leading), with the TipTap editor toolbar (B · I · H · Scene break · link) floating above it. Top bar: ← Grid, `CH.01 · SCENE 1`, `DRAFT` status chip, `1,140 words · 6 min read`, and a **✦ Smart editing** toggle (top-right).
- **Inline Codex highlights:** recognized entry names auto-link in the prose, tinted by entity type (character / location / faction / object / lore each get a hue) — extends the P5 inline-mention linker with per-DB color classes.
- **Hover card** on a mention: entity name + type badge, one-line detail/summary, and **Open entry →**. (Data: entry name/type/detail; card is client-rendered from a small JSON map already available to the page.)
- **"In this scene" rail** (right): every entity mentioned in the current scene, with its type-color dot, db label, and a **per-scene mention count**, sorted by count. Footnote: "Mentions update live as you write. Names from your Codex auto-link." Counts recompute client-side as the text changes.

**Screen 2 — Smart editing panel (inline).** The P7 diagnostics, surfaced as a slide-in right panel in this view (not a separate page), styled as cards: header "Diagnostics for <scene> — review prompts, not verdicts." Three collapsible groups with flag counts:
- **Usage frequency** — *Overused words* as horizontal bars scaled to count, colored by severity (red/amber/neutral); *Repeated phrases* with ×counts.
- **Patterns to review** — one card per finding with a severity badge (HIGH / MED / LOW) and a one-line explanation. Needs P7 enrichments: a **book-average baseline** ("~2× your book average" for em-dash density), **paragraph spread** for hedging ("6× across 3 paragraphs"), and a new **sentence-rhythm** check ("5 consecutive sentences open with subject + past-tense verb") beyond the current uniform-length CV.
- **Dialogue** — *Tags per speaker* bars (speaker attribution via the P5 index), plus an *Adverb-laden tags* card quoting the offending tags ("said quietly", "swore quietly").

**Build order (proposed slices):** (9a) Write-mode shell + type-colored inline highlights + hover cards; (9b) "In this scene" rail with live client-side counts; (9c) Smart-editing slide-in panel rendering P7 data in the carded style; (9d) P7 analyzer enrichments (severity levels, book-average baseline, paragraph spread, sentence-rhythm, per-speaker tags + example snippets); (9e) the original P9 capstone — TipTap saves scene Markdown back to disk with the CONFLICT rule. Most of 9a–9c is browser-side (author-verified); 9d is server-side and unit-testable; 9e is the contract change.

**Contract impact:** only 9e (writeback). 9a–9d are additive/presentational.

**9d — DONE (server-side, tested).** P7 analyzers enriched: every patterns finding now carries a `sev` (HIGH/MED/LOW via `diag_sev()`); em-dash density compares to a book-wide baseline (`book_em_baseline()`, "~N× your book average"); hedging reports paragraph spread ("\"perhaps\", … appear N× across M paragraphs"); a new `diag_rhythm_run()` flags runs of sentences opening "subject + past-tense verb"; dialogue gains per-speaker tag attribution (`book_speakers()` → "Name said"/"said Name" matching, longest-name-first) and adverb-tag example snippets. `analyze_prose($md,$ctx)` threads `em_baseline`+`speakers` from `get_chapter_diagnostics`. Diagnostics page shows severity badges, tags-per-speaker, and adverb examples. All new logic Python-prototyped + unit-tested. Files: `src/repo.php`, `htdocs/index.php`, `htdocs/assets/style.css`. (Cached findings bake in the baseline at analysis time; the nightly reindex refreshes them.) **9e — DONE. Phase 9 complete.** The contract-changing writeback (`write_chapter_file()` → `Manuscript/*.md` on local disk, +DB, +`reconcile_scenes`) was built in P9/#44–46; its CONFLICT-not-overwrite rule is now verified with 7 Python fixtures (base-stamp mismatch → refuse; on-disk drift from an external edit/sync → refuse; no-op when unchanged; new-file create; no-stamp path; CRLF normalisation). The `chapter_edit` editor now carries the **live "In this scene" rail** that recounts as you type (debounced `input`), fulfilling the mockup's "mentions update live as you write." Editing stays on a **Markdown textarea, not TipTap, by design** — TipTap destroys the `<!-- comments -->` chapters use; the textarea round-trips losslessly and the save path is the same conflict-guarded `chapter_save` → `write_chapter_file`. Files: `htdocs/index.php` (+ existing `src/repo.php` writeback). Conflict logic Python-tested; rail JS syntax-checked; not browser-tested.

**9a — DONE (pending browser check).** Codex mention links are now tinted by entity type (`mention-<db>` class + per-type colours matching DBMETA: characters #5b54b8, locations #4F7A52, factions #A85648, objects #3D7D80, lore #7A5AA0) and carry `data-slug`. A client-side **hover card** on any `.mention` in the chapter prose shows the entity name, a type badge, its one-line detail, and an "Open entry →" link (data from the same per-book entity map the 9b rail embeds, extended with `detail`/`type`). Chapter prose is capped to a readable ~46rem column. Together with the 9b rail and 9c panel, the chapter page is now the mockup's writing view (sans live editing, which is 9e). JS syntax-checked. Files: `src/layout.php`, `htdocs/index.php`, `htdocs/assets/style.css`. Not browser-tested.

**9b — DONE (pending browser check).** The chapter page is now two-column: prose on the left, a sticky **In this scene** rail on the right. Counts are computed **client-side** — `build_mention_targets` (phrase→slug, longest-first), an entity map (slug→name/db) and the DBMETA colours are embedded as JSON; JS tallies real occurrences over the prose text (word-boundary, longest-match-first, overlap-guarded, `[[links]]` ignored), then renders type-colour dot + db label + count, sorted desc, each linking to the entry. `window.__sceneRender` is exposed so the 9a editor can recompute on input. Note line: "Mentions update live as you write." JS node-syntax-checked + tally behaviour tested. Files: `htdocs/index.php`, `htdocs/assets/style.css`. Not browser-tested.

**9c — DONE (pending browser check).** A **✦ Smart editing** toggle on the chapter page opens a right slide-in panel (with backdrop, Esc/click-out to close) rendering the cached 9d diagnostics in the mockup's carded style: Usage-frequency overused-word bars (width∝count, colour by magnitude) + repeated-phrase rows; Patterns-to-review cards with HIGH/MED/LOW severity badges + coloured left border; Dialogue tags-per-speaker bars + said-bookism chips + an adverb-laden-tags card. Server-rendered from `get_chapter_diagnostics()`; toggle JS node-syntax-checked. Files: `htdocs/index.php`, `htdocs/assets/style.css`. Not browser-tested.

---

## Sequencing & dependency map

```
P0  VPS provisioning + migrate off Wasmer ─┬─────────────────────────► (unblocks all)
                                           │
P1  Structure + Grid (hero) ───────────────┼─► P4 Editor(TipTap) ─► P5 Mentions ─► P6 Arcs
                                           │            │              │   │
P2  Reconcile engine (∥ P1) ───────────────┤            │              │   └─► P7 Diagnostics
        │                                  │            │              │
P3  MCP systemd service + cron;            │            │              └─► P8 Plot-board drops
    retire PowerShell ────────────────────┘            │
        │                                              │
        └──────────────────┬───────────────────────────┘
                           ▼
            P9  Scene-prose editing  (needs P1 + P3) ── CONTRACT CHANGE, last

Cron (systemd timers): codex-sync (P3), codex-reindex (P5, extended P7).
Contract-safe: P0–P8.   Contract change: P9 only.
```

**Parallelization:** P2 runs alongside P1. P4→P9 is a clean chain through the prototype's screens. Total ≈ 11–13 focused sessions (platform ops in P0 replaces the Edge hardening, similar size).

---

## Cross-cutting guarantees

- **Markdown stays the source of truth.** Every editor is a view; save path is always `editor → Markdown → existing parser`. Sync and the task runner keep working.
- **Additive schema only.** Every new table via an `ensure_*()` helper; no `schema.sql` re-import on existing installs.
- **Folder-owned prose, except by deliberate upgrade.** Structure, mentions, timeline, diagnostics, plot cards all layer on top of files as derived/rebuildable state. Only P9 changes who writes prose, reusing the CONFLICT-not-overwrite rule.
- **Never auto-delete; conflicts are skipped, not merged; commit state only on confirmed write.** The reconcile port must reproduce these branch-for-branch (P2 fixture suite is the gate).
- **One DB writer.** The MCP server writes through `api.php` on localhost so the PHP repo stays the sole owner of DB mutations and the `md` round-trip.

## Security (VPS-specific — replaces the Wasmer hardening runbook)

- **Box:** SSH key-only, `ufw` (22/80/443), `fail2ban`, automatic security updates, non-root sudo user.
- **Secrets:** in `/etc/codex/codex.env` (root, mode 600), injected into php-fpm and the MCP service; **rotate** the API token, DB password, and app password during the migration (they were committed in plaintext). Nothing secret in tracked source — `config.php` already reads `getenv()`.
- **MCP endpoint:** TLS via Caddy, **authenticated** (bearer token now; OAuth per-client later). Never expose the reconcile tools unauthenticated.
- **DB:** bound to `localhost`, least-privilege user, daily `mysqldump` off-box.
- **App:** with secrets in env, a direct request to `config.php` leaks nothing; still, configure the web server to deny `src/`, `bin/`, `*.sql`, and `config.php` (the old `.htaccess` is Apache-only and won't apply under nginx/Caddy — set equivalent location blocks).
- **Backups:** nightly DB dump + a snapshot of `/srv/codex/books` (the prose). Test a restore.

## Top risks

- **Reconcile parity (P2/P3)** is the whole ballgame for sync trust — gate PowerShell retirement on the fixture suite.
- **Scene parsing (P1)** depends on consistent `***`/heading breaks; never lose prose (single-scene fallback; `chapters.body` authoritative).
- **MCP exposure (P3)** — a remote, internet-reachable tool surface that can write your manuscript. Auth + TLS + least privilege are non-negotiable; consider IP allowlisting or OAuth.
- **Single box = single point of failure.** Backups + a tested restore matter more now than on managed Edge.
- **Short/common aliases (P5)** cause false mentions — longest-match + manual-link-wins + per-entry opt-out, then a tuning pass.
- **"AI detection" framing (P7)** must say "patterns to review," never "AI-written."
- **P9** is the only file/app conflict surface — isolate and test with its own fixtures.

## Recommended first move

Do **P0** (stand up the VPS: nginx/Caddy + php-fpm + MariaDB, env-file secrets, import the data, rotate the token, place the folders at `/srv/codex/books`) and confirm the site serves over HTTPS with the data intact. Then start **P1's** smallest slice — `ensure_structure()` + `md_split_scenes()` + the `push_files` reconcile + a read-only Grid view — while building the **P2** reconcile engine in parallel. That puts the hero screen on screen and proves the riskiest sync logic before anything touches the sync contract, on a platform that no longer fights you.
