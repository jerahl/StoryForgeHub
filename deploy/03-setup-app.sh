#!/usr/bin/env bash
# 03-setup-app.sh — database + app deploy (MASTER-PLAN P0 steps 3 & 5).
#
# Creates the codex DB and a least-privilege localhost user, imports schema.sql,
# deploys the app to $CODEX_APP_ROOT (docroot = $CODEX_APP_ROOT/htdocs; internals
# above it), and creates the canonical book folder at
# $CODEX_BOOKS_DIR. Writes the generated DB password into $CODEX_ENV_FILE.
# Idempotent: existing DB/user/files are reused, not clobbered.
#
# Run as root:  sudo bash deploy/03-setup-app.sh
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
need_root
need_cmd mysql

# --- ensure env file exists so we can stash the DB password -----------------
install -d -m 750 -o root -g www-data "$(dirname "$CODEX_ENV_FILE")"
if [[ ! -f "$CODEX_ENV_FILE" ]]; then
  step "Creating $CODEX_ENV_FILE from sample"
  install -m 640 -o root -g www-data "$DEPLOY_DIR/codex.env.sample" "$CODEX_ENV_FILE"
fi

# Helper: set or replace KEY=value in the env file.
set_env() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$CODEX_ENV_FILE" 2>/dev/null; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$CODEX_ENV_FILE"
  else
    echo "${key}=${val}" >> "$CODEX_ENV_FILE"
  fi
}
get_env() { grep "^$1=" "$CODEX_ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2-; }

step "Database: $CODEX_DB_NAME + user '$CODEX_DB_USER'@'localhost'"
DB_PASS="$(get_env DB_PASSWORD)"
if [[ -z "$DB_PASS" || "$DB_PASS" == "CHANGE_ME" ]]; then
  DB_PASS="$(rand_secret)"
  info "generated a new DB password (stored in $CODEX_ENV_FILE)"
else
  info "reusing existing DB password from $CODEX_ENV_FILE"
fi

mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${CODEX_DB_NAME}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${CODEX_DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
ALTER USER '${CODEX_DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES
  ON \`${CODEX_DB_NAME}\`.* TO '${CODEX_DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL
ok "database + least-privilege user ready"

step "Import schema.sql"
if mysql "$CODEX_DB_NAME" -e "SHOW TABLES LIKE 'books';" | grep -q books; then
  ok "tables already present — skipping import (schema uses CREATE TABLE IF NOT EXISTS)"
else
  mysql "$CODEX_DB_NAME" < "$REPO_DIR/schema.sql"
  ok "schema imported"
fi

step "Persist DB connection settings into $CODEX_ENV_FILE"
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_NAME "$CODEX_DB_NAME"
set_env DB_USERNAME "$CODEX_DB_USER"
set_env DB_PASSWORD "$DB_PASS"
# Generate the API token + app password if still placeholders (rotation on move).
[[ "$(get_env API_KEY)" == "CHANGE_ME" || -z "$(get_env API_KEY)" ]] && \
  { set_env API_KEY "$(rand_secret)"; info "generated API_KEY"; }
chmod 640 "$CODEX_ENV_FILE"; chown root:www-data "$CODEX_ENV_FILE"
ok "secrets written (root:www-data, mode 640)"

step "Deploy app to $CODEX_APP_ROOT (docroot: $CODEX_DOCROOT)"
install -d -m 755 "$CODEX_APP_ROOT"
# Secure-docroot layout: only the files under htdocs/ are web-served; the app
# internals (src/, config.php, schema.sql, bin/) sit ABOVE the docroot where the
# web server can't reach them. We deploy the whole app tree but EXCLUDE repo
# docs, the deploy/ tooling, and any secrets so they never land on the box.
rsync -a --delete \
  --exclude '/deploy' \
  --exclude '/.git' \
  --exclude '*.md' \
  --exclude 'deploy.env' \
  --exclude '*.ppk' --exclude '*.key' --exclude '*.pem' \
  --exclude '/config.php' --exclude '/config.sample.php' \
  --exclude 'sync/seed.json' \
  --exclude '*.bak' --exclude '*.bak-*' \
  --exclude 'node_modules' \
  --exclude '/skill-codex-webapp-sync' --exclude '*.skill' --exclude '/sync_engine/.venv' \
  "$REPO_DIR/" "$CODEX_APP_ROOT/"
# config.php already reads getenv(); ship the env-driven one verbatim (above docroot).
install -m 640 -o root -g www-data "$REPO_DIR/config.php" "$CODEX_APP_ROOT/config.php"
chown -R root:www-data "$CODEX_APP_ROOT"
find "$CODEX_APP_ROOT" -type d -exec chmod 750 {} \;
find "$CODEX_APP_ROOT" -type f -exec chmod 640 {} \;
# Paths the app writes to need www-data write access: image uploads + sync bridge.
install -d -o www-data -g www-data -m 2775 "$CODEX_DOCROOT/assets/vision"
install -d -o www-data -g www-data -m 2775 "$CODEX_APP_ROOT/sync"
[[ -d "$CODEX_DOCROOT" ]] || die "expected docroot $CODEX_DOCROOT after deploy (is htdocs/ present in the repo?)"
ok "app deployed; web root = $CODEX_DOCROOT, internals above it (root:www-data)"

step "Canonical book folders: $CODEX_BOOKS_DIR"
install -d -o "$CODEX_ADMIN_USER" -g www-data -m 2770 "$CODEX_BOOKS_DIR"
ok "ready (owner $CODEX_ADMIN_USER, group www-data, setgid)"
info "Copy your Codex book folders here, e.g.:"
info "  rsync -av /path/to/local/Codex/ $CODEX_BOOKS_DIR/"

step "Done — DB + app deployed"
info "Import existing data either way:"
info "  • mysql $CODEX_DB_NAME < your-dump.sql"
info "  • or via the app: Sync -> Import snapshot.json (runs migrate() first)"
