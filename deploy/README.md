# Codex VPS setup (MASTER-PLAN Phase 0)

Initial-setup automation for moving **Stephen's Codex** off Wasmer Edge onto a
self-managed **Debian 12 VPS**. Stands up the PHP app, MariaDB, the web server
(Caddy auto-HTTPS by default), env-file secrets, backups, and stages the
Phase-3/5 systemd units. Implements Phase 0 of `../MASTER-PLAN-vps-2026-06-28.md`.

## Quick start

On a fresh Debian 12 box, as a user with sudo (the scripts re-exec as root):

```bash
# 0. Get the repo onto the box (git clone or rsync), then:
cd codex-web-beacon

# 1. Configure
cp deploy/deploy.env.sample deploy/deploy.env
nano deploy/deploy.env          # set CODEX_DOMAIN, CODEX_ADMIN_USER, WEB_SERVER…

# 2. Point DNS: an A record for CODEX_DOMAIN -> this box's public IP
#    (required before TLS can be issued)

# 3. Run the full setup
sudo bash deploy/setup.sh

# 4. Smoke test
sudo bash deploy/verify.sh
```

`setup.sh` runs four idempotent steps in order. Re-run any time; re-running
changes nothing already correct.

## What each script does

| Script | MASTER-PLAN | Does |
|---|---|---|
| `setup.sh` | P0 | Orchestrator. Runs 01–04 in order. Flags: `--only N,N`, `--from N`, `--yes`, `--dry-run`. |
| `01-provision.sh` | P0.1 | Non-root sudo user, SSH key-only, `ufw` 22/80/443, `fail2ban`, unattended upgrades, hostname. |
| `02-install-stack.sh` | P0.2 | php-fpm 8.3 (+pdo_mysql, mbstring, xml, curl, gd, opcache), MariaDB (localhost-bound), Caddy/nginx. Dedicated php-fpm pool. |
| `03-setup-app.sh` | P0.3, P0.5 | Creates `codex` DB + least-priv `localhost` user, imports `schema.sql`, deploys the app to `/srv/codex/app` (web docroot = `/srv/codex/app/htdocs`; internals like `src/`/`config.php` sit above it), creates `/srv/codex/books`, **generates and stores DB password + API_KEY**. |
| `04-configure.sh` | P0.4, Security | Injects secrets into php-fpm via systemd `EnvironmentFile`, renders the Caddyfile/nginx vhost (with deny blocks + `/mcp` stub), installs the backup timer, **stages** the Phase-3/5 units. |
| `verify.sh` | — | Read-only smoke test: services, firewall, secrets, DB, HTTPS, deny rules. |
| `backup.sh` | Security | Nightly `mysqldump` + books tarball (installed to `/usr/local/sbin`, run by `codex-backup.timer`). |

## Config knobs (`deploy.env`)

`CODEX_DOMAIN`, `CODEX_ADMIN_USER`, `CODEX_APP_ROOT`, `CODEX_BOOKS_DIR`,
`CODEX_ENV_FILE`, `CODEX_DB_NAME`/`CODEX_DB_USER`, `PHP_VERSION`, `WEB_SERVER`
(`caddy`|`nginx`), `SYNC_INTERVAL_MIN`, `BACKUP_DIR`. Unset = defaults in
`lib.sh`.

## Secrets

Runtime secrets live in **`/etc/codex/codex.env`** (root:www-data, mode 640),
read by `config.php`'s existing `getenv()` calls — **no code change**. `setup.sh`
generates a fresh `DB_PASSWORD` and `API_KEY`. Per the plan's security section,
**rotate** any value that was ever committed in plaintext (API token, DB
password, app password) during the migration — these scripts do that for you for
the DB password and API key; set `APP_PASSWORD` by hand if you want the UI gate.

## After setup

1. Copy book folders: `rsync -av /local/Codex/ /srv/codex/books/`
2. Import data: `mysql codex < dump.sql` **or** app **Sync → Import snapshot.json** (runs `migrate()` first).
3. Confirm `https://CODEX_DOMAIN` serves with valid TLS.
4. **Decommission Wasmer**: repoint DNS, archive the Wasmer app; `app.yaml` / `wasmer.toml` become historical.

## Phases 2/3/5 (not enabled yet)

The MCP service, continuous-sync timer, and reindex timer are **installed but
disabled** — they need code from later phases (`sync_engine.py`, the MCP server,
`index_mentions()`). When that lands:

```bash
sudo systemctl enable --now codex-sync.timer codex-reindex.timer codex-mcp.service
# then uncomment the /mcp reverse-proxy block in the web server config and reload
```

## Notes / caveats

- **Debian 12 ships PHP 8.2.** `02-install-stack.sh` auto-adds the Sury repo to
  get 8.3. Set `PHP_VERSION=8.2` in `deploy.env` to use the distro PHP instead.
- **DNS must resolve before TLS.** Caddy issues certs on first serve; with nginx,
  run `certbot --nginx -d CODEX_DOMAIN` after setup.
- **`.htaccess` is Apache-only** and does not apply under Caddy/nginx — the deny
  rules are reimplemented in the web server config (verified by `verify.sh`).
- **Single box = single point of failure.** Configure the off-box copy in
  `backup.sh` (rclone/rsync block) and test a restore.
