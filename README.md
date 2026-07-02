# Novel's Codex — web app

A hosted PHP + MySQL version of your Codex (the same design as the `Stephen's Codex`
template): the library of three books, each with Characters, Locations, Factions,
Objects, Lore, plus Manuscript, Progressions, Open threads, Meta — and two extras the
template didn't have: **Tasks** (which you can flag for Claude) and a **Writing log**.

Everything runs on one **Debian VPS** (see `MASTER-PLAN-vps-2026-06-28.md`): the PHP app,
the database, and the canonical book folders at `/srv/codex/books`. Sync is a **local
folder↔DB reconcile** on the box, run on a schedule and exposed to Claude as a remote MCP
service (`codex-mcp`, in build — Master Plan Phases 2–3). The `codex-webapp-sync` skill is
what Claude uses when you say *"check the web app for tasks and run them"* or *"fill in the
writing log."*

```
        ┌─────────────── Debian VPS ───────────────┐
        │  web app (PHP) ── PDO ──► MariaDB         │
        │        ▲                                  │
        │        │ local reconcile (systemd timer)  │
        │        ▼                                  │
        │  /srv/codex/books  ◄──► codex-mcp service │── HTTPS ──► Claude (remote MCP)
        └───────────────────────────────────────────┘
```

> The earlier PC↔host sync (`sync-codex.ps1` + a bridge folder) has been retired with the
> move to the single-box VPS.

---

## Part A — Stand up the app on a Debian VPS (once)

The app runs on a self-managed **Debian 12 VPS**: **nginx + php-fpm** (or Caddy) in front
of `htdocs/`, **MariaDB/MySQL** on the box, and the canonical book folders at
`/srv/codex/books`. Config reads all secrets from environment variables (see `config.php`
/ `config.sample.php`), so nothing sensitive is committed. The full platform/sync/editor
plan is in **`MASTER-PLAN-vps-2026-06-28.md`** (Phase 0 = this section).

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
   API_KEY=<long random string — the sync token>
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
  `php bin/seed.php --books /srv/codex/books` to seed straight from the folders.

Open the site. You should see all three books with their entries, chapters, words, and
threads. (`seed.json` was generated from your live Codex on 2026-06-20.)

## Part C — Sync (on the VPS)

With the app, database, and book folders all on the VPS, sync becomes a **local
folder↔DB reconcile on the box**, not a PC-to-host job. The target is a small
**`codex-mcp` service** (Python, reusing `codex_sync_lib.py`) run as a `systemd` unit,
driven on a **`systemd` timer** for continuous sync and exposed to Claude as a **remote
MCP** over HTTPS — replacing the Windows scheduled task and the bridge folder entirely.
See `MASTER-PLAN-vps-2026-06-28.md` Phases 2–3 for the build, and
`MCP-SYNC-PLAN-2026-06-27.md` for the tool surface.

The old Windows `sync-codex.ps1` scheduled task and its bridge folder are retired — they
belonged to the PC↔host model and don't fit the single-box VPS. The reconcile guarantees
(never auto-delete, skip conflicts, commit only on confirmed write) carry over into the
`codex-mcp` service unchanged.

## Part D — Install the Claude skill (once)

Install `codex-webapp-sync.skill` via **Settings → Capabilities**, *or* it auto-loads
because a copy lives at `projects/books/.claude/skills/codex-webapp-sync/`.

---

## Daily use

- **Browse / edit** anything in the web app. Entries are edited as Codex markdown (the
  app shows a clean view and an Edit screen); saving re-parses and the next sync writes
  it back to the right folder verbatim.
- **Flag work for Claude:** web app → **Tasks** → write a task, tick *Flag for Claude*.
  Then tell Claude: **"check the web app for tasks and run them."** Claude runs each task
  against the Codex, and the next sync marks it done and uploads the changes.
- **Writing log:** add sessions by hand on the **Writing log** page, or say **"fill in
  the writing log"** and Claude logs the word delta from your manuscript automatically.

## Good to know
- **Nothing is ever auto-deleted.** If an entry disappears on one side, the sync reports
  it and leaves the other side alone.
- **Conflicts** (same entry changed in the app *and* the folder before a sync) are
  skipped and listed in `sync.log`; edit one side and re-run.
- **Security:** all credentials live in Wasmer **secrets** (env vars), never in source —
  set `API_KEY` long and random; Edge serves over HTTPS automatically. The UI is gated by
  per-user accounts (Phase 17): `APP_PASSWORD` is only the one-time secret for creating the
  first admin, then unused. Passwords are stored as `password_hash()` bcrypt hashes; sessions
  use an HttpOnly, SameSite cookie (Secure over HTTPS) and regenerate on login. Rotate
  `API_KEY`, `DB_PASSWORD`, and `APP_PASSWORD` if they've ever been committed in plaintext,
  and update the sync client's token to match.
- **Accounts, invites & resets** (Phase 17) live under **Users & invites** (admins only) and
  **Account** (everyone). Onboarding is invite-only — no public registration endpoint exists.
- **Book ownership & scoping** (Phase 18): the unit of ownership is the *book*, not the user —
  a `book_members` row (owner/editor/viewer) says who can touch which book. The web library
  shows each member exactly the books they belong to; creating or importing a book makes you
  its owner. Admins and the token REST API see every book (per-user MCP auth is a later phase).
  On upgrade, your existing books are backfilled to the first admin as owner.
