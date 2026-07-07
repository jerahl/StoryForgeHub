# Novel's Codex — web app

A hosted PHP + MySQL version of your Codex (the same design as the `Stephen's Codex`
template): the library of three books, each with Characters, Locations, Factions,
Objects, Lore, plus Manuscript, Progressions, Open threads, Meta — and two extras the
template didn't have: **Tasks** (which you can flag for Claude) and a **Writing log**.

Everything runs on one **Debian VPS** (see `MASTER-PLAN-vps-2026-06-28.md` and its
successor `MASTER-PLAN-standalone-2026-07-06.md`): the PHP app and the database. Since
the **DB-canonical flip (Track A)** the database is the single source of truth — there
is no books-folder sync to run. Markdown remains the dialect everywhere: every save
records an attributed revision, `bin/export.php` regenerates clean book folders on
demand (the nightly backup keeps one), and import (zip / snapshot / api push) loads
them back. Claude connects per-user over the remote MCP (`codex-mcp`) using the
`storyforge` skill for *"check my Codex for tasks and run them"* or *"fill in the
writing log."*

```
        ┌─────────────── Debian VPS ────────────────────┐
        │  web app (PHP) ── PDO ──► MariaDB (the truth) │
        │        ▲                    │  ▲               │
        │        │                    │  └ revisions     │
        │  codex-mcp service ── api.php (one writer)    │── HTTPS ──► each user's Claude
        │        nightly: mysqldump + Markdown export    │            (OAuth / token)
        └────────────────────────────────────────────────┘
```

> Retired along the way: the PC↔host sync (`sync-codex.ps1` + bridge folder) with the
> VPS move, the folder↔DB reconcile with the DB-canonical flip, and finally the
> transitional mirror mode + reconcile engine at the **A4 cutover** — the app never
> touches book folders now. `bin/export.php` regenerates them on demand.

---

## Part A — Stand up the app on a Debian VPS (once)

The app runs on a self-managed **Debian 12 VPS**: **nginx + php-fpm** (or Caddy) in front
of `htdocs/`, and **MariaDB/MySQL** on the box — that's the whole install; there are no
book folders to provision. Config reads all secrets from environment variables (see
`config.php` / `config.sample.php`), so nothing sensitive is committed. Platform history:
`MASTER-PLAN-vps-2026-06-28.md`; current architecture:
`MASTER-PLAN-standalone-2026-07-06.md`.

**You need:** a Debian VPS, a domain pointed at it, PHP 8.3 (`php-fpm` + `pdo_mysql`,
`mbstring`, `xml`, `curl`, `gd`), and MariaDB.

1. **Web + PHP.** Install `php-fpm` and **Caddy** (auto-HTTPS) or nginx+Certbot; set the
   document root to this repo's `htdocs/`. Enable opcache. Deny web access to `src/`,
   `bin/`, `*.sql`, and `config.php` via server `location` blocks (the bundled `.htaccess`
   is Apache-only and does nothing under nginx/Caddy).
2. **Database.** Install MariaDB; create the `codex` DB + a least-privilege user bound to
   `localhost`.
3. **Secrets via env file.** Put these in a root-owned `/etc/codex/codex.env` (mode 600)
   and load it into the php-fpm pool (`EnvironmentFile=` / pool `env[...]`):
   ```
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_NAME=codex
   DB_USERNAME=codex
   DB_PASSWORD=…
   API_KEY=<long random string — the service API token>
   APP_PASSWORD=<first-run bootstrap gate — see below>
   ```
   `config.php` already reads these via `getenv()` — no code change.
4. **Create the tables:** open the site → **Sync → Import snapshot.json** (this calls
   `migrate()` first, creating the schema and seeding in one step), or run
   `php bin/seed.php --migrate` over SSH. (Creating the first admin account also
   lays the base tables, so a brand-new box works after setup even without this.)
5. **Sign in (accounts & invites, Phase 17):** the UI uses real per-user accounts,
   not a shared password. On first visit the site asks you to create the first
   **administrator** — if `APP_PASSWORD` is set you must enter it to prove you're
   the incumbent owner. From then on `APP_PASSWORD` is unused; add teammates from
   **Users & invites** (admin only), which generates a one-time invite link. There
   is no public signup. Admins can also issue single-use password-reset links.

## Part B — Load your three books (once)

- **Web:** open the site → **Sync → Import snapshot.json** → upload `sync/seed.json`
  (ships in this package; also runs the schema migration).
- **Over SSH:** `php bin/seed.php --json sync/seed.json`, or
  `php bin/seed.php --books /path/to/book/folders` to seed straight from a folder tree
  (a one-time import — the app never reads the folders again).

Open the site. You should see all three books with their entries, chapters, words, and
threads. (`seed.json` was generated from your live Codex on 2026-06-20.)

## Part C — Backups & export (sync is retired)

The DB is canonical (Track A), so there is nothing to sync. What replaces it:

- **Revisions:** every chapter and entry save — web, MCP, or import — records an
  attributed revision (who, and through which door). Chapters get a history panel in
  the editor; entries get a **History** panel with one-click restore (a restore is a
  new save on top, never a rewind).
- **Nightly export:** `bin/export.php --dir <target>` renders every book back to
  canonical Markdown folders (entries, chapters, notes, meta, progressions, sources,
  `book.json`) — the layout imports cleanly again. `deploy/backup.sh` tars one next to
  the `mysqldump` every night; copy both off-box.
- **A4 cutover: done.** Mirror mode and the reconcile engine are deleted; the app has
  no `CODEX_BOOKS_DIR` at all. Your folder history remains wherever you archived it
  (the runbook's tagged final export).

The old guarantees survive the flip: nothing is auto-deleted (archives + revisions),
and concurrent edits are refused with context rather than merged or clobbered — in the
app *and* over MCP (`codex_save_chapter` requires the `body_hash` you read).

## Part D — Install the Claude skill (once)

Install `storyforge.skill` via **Settings → Capabilities** (source in
`skill-storyforge/`). It teaches Claude the tool surface and the house rules —
per-user identity, read-before-write chapter saves, never inventing canon. The old
`codex-webapp-sync.skill` is superseded (its SKILL.md says so) but still in the repo
for reference.

---

## Daily use

- **Browse / edit** anything in the web app — including chapters, in a true rich-text
  editor (Track C): wiki-links render as chips, author notes (`<!-- … -->`) as visible
  pills, scene breaks as ornaments, and a toggle flips to raw Markdown any time. The
  round-trip seatbelt guarantees rich mode can never mangle the dialect; every save is
  a restorable revision.
- **Flag work for Claude:** web app → **Tasks** → write a task, tick *Flag for Claude*.
  Then tell Claude: **"check my Codex for tasks and run them."** Claude works each task
  through the MCP tools and marks it done — every change lands instantly, as Claude
  acting with your account.
- **Writing log:** add sessions by hand on the **Writing log** page, or say **"fill in
  the writing log"** and Claude logs the word delta from your manuscript automatically.

## Good to know
- **Nothing is ever auto-deleted.** Chapters archive instead of deleting, entry deletes
  record a final restorable revision, and every save through any door is in History.
- **Conflicts are refused, not merged:** if two writers (or a writer and Claude) race on
  the same chapter, the second save is refused with the current version attached — in
  the app and over MCP alike. Nothing is clobbered.
- **Security:** all credentials live in the env file (`/etc/codex/codex.env`), never in
  source — set `API_KEY` long and random; Caddy serves HTTPS automatically. The UI is gated by
  per-user accounts (Phase 17): `APP_PASSWORD` is only the one-time secret for creating the
  first admin, then unused. Passwords are stored as `password_hash()` bcrypt hashes; sessions
  use an HttpOnly, SameSite cookie (Secure over HTTPS) and regenerate on login. Rotate
  `API_KEY`, `DB_PASSWORD`, and `APP_PASSWORD` if they've ever been committed in plaintext,
  and update any automation's token to match.
- **Accounts, invites & resets** (Phase 17) live under **Users & invites** (admins only) and
  **Account** (everyone). Onboarding is invite-only — no public registration endpoint exists.
- **Book ownership & scoping** (Phase 18): the unit of ownership is the *book*, not the user —
  a `book_members` row (owner/editor/viewer) says who can touch which book. The web library
  shows each member exactly the books they belong to; creating or importing a book makes you
  its owner. Admins and the token REST API see every book (per-user MCP auth is a later phase).
  On upgrade, your existing books are backfilled to the first admin as owner.
- **Roles & permissions** (Phase 19): every book-scoped write is checked server-side against
  the caller's role — **owner** (full control + manage members + delete), **editor** (read/write
  prose, entries, sources, tasks, plot board), **viewer** (read + comments/notes, no edits). The
  target book is resolved from the object being changed (not the submitted form field), so you
  can't reach another book's data by id. Owners manage collaborators on each book's **Members**
  page (add existing accounts, invite new ones at a role, change roles, revoke), and a per-book
  **activity log** records who changed what. The Sync page and snapshot import are admin-only.
- **Concurrent editing & presence** (Phase 21): when two people (or a person and Claude) touch the
  same chapter, an **optimistic conflict check** on save compares the body you loaded against the
  current one — if it moved underneath you, the save is refused rather than clobbering, and your
  draft is kept as an autosave. On top of that, **soft locks** show "Alice is editing this chapter"
  in the editor and on the chapter page (a heartbeat-kept presence row, advisory only). Real-time
  Google-Docs-style co-editing is intentionally deferred (it needs the DB-canonical flip).
- **Per-user API tokens** (Phase 20): the REST/MCP surface (`api.php`) takes two kinds of token.
  The shared **service token** (`API_KEY`) stays unscoped for admin
  automation. Each user can also mint **personal tokens** from **Account → API tokens** — Claude
  or the MCP presents one and the request *acts as that user*, so every read/write routes through
  the same membership scoping and role checks (reach only your books, at your role; snapshot
  import and cross-book access are refused). Only a hash of each token is stored, they're shown
  once, and they're revocable. Per-user spell-check dictionary words no longer leak between
  co-authors, and chapter notes record their author.
- **Per-user MCP connectors** (standalone plan, Tracks B1–B4): each writer connects
  their own Claude to `https://<domain>/mcp` — just paste the URL and **sign in with
  your account** when Claude asks (OAuth 2.1 with consent, revocable under
  **Account → Connected apps**), or use the `?k=<personal token>` fallback from
  **Account → API tokens**. Every tool call acts as that user: their books, their
  role, their name in the activity log. The tool surface includes granular reads
  (`codex_get_chapter` with the full body, server-side `codex_search` with snippets,
  `codex_get_diagnostics`) and task create/update, so "check my Codex for tasks and
  run them" works per-user with no folder sync involved. In-app guide: **Working
  with Claude** (`?p=claude`); Claude-side skill: `storyforge.skill`; details:
  `sync_engine/MCP-SERVER.md`.
