#!/usr/bin/env bash
# 04-configure.sh — env injection, web server, timers (MASTER-PLAN P0 step 4 + Security).
#
# Renders the templates/ into place with deploy.env values substituted:
#   - php-fpm systemd drop-in -> injects $CODEX_ENV_FILE (secrets reach getenv())
#   - Caddyfile (or nginx vhost) rooted at the htdocs/ docroot, deny blocks + /mcp stub
#   - backup.sh -> /usr/local/sbin + codex-backup.timer (ENABLED now)
#   - codex-mcp / codex-sync / codex-reindex units (STAGED, not enabled — P3/P5)
# Idempotent.
#
# Run as root:  sudo bash deploy/04-configure.sh
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
need_root

TPL="$DEPLOY_DIR/templates"

# Render a template: substitute only our known vars (envsubst with an allow-list
# so literal $host / $uri in nginx configs survive).
render() {
  local src="$1" dst="$2"
  envsubst '${CODEX_DOMAIN} ${CODEX_APP_ROOT} ${CODEX_DOCROOT} ${CODEX_ENGINE_DIR} ${CODEX_BOOKS_DIR} ${CODEX_ENV_FILE} ${CODEX_ADMIN_USER} ${PHP_VERSION} ${SYNC_INTERVAL_MIN} ${BACKUP_DIR}' \
    < "$src" > "$dst"
}
need_cmd envsubst

step "php-fpm: inject secrets from $CODEX_ENV_FILE"
DROPIN_DIR="/etc/systemd/system/php${PHP_VERSION}-fpm.service.d"
install -d -m 755 "$DROPIN_DIR"
render "$TPL/php-fpm-env.conf.tmpl" "$DROPIN_DIR/codex-env.conf"
systemctl daemon-reload
systemctl restart "php${PHP_VERSION}-fpm"
ok "php-fpm now reads $CODEX_ENV_FILE"

if [[ "$WEB_SERVER" == "nginx" ]]; then
  step "nginx vhost"
  render "$TPL/nginx-codex.conf.tmpl" /etc/nginx/sites-available/codex
  ln -sf /etc/nginx/sites-available/codex /etc/nginx/sites-enabled/codex
  rm -f /etc/nginx/sites-enabled/default
  nginx -t && systemctl reload nginx
  ok "nginx configured (HTTP). Get TLS:  certbot --nginx -d $CODEX_DOMAIN"
else
  step "Caddyfile"
  # Caddy runs as the 'caddy' user; the log dir must be writable by it.
  install -d -o caddy -g caddy -m 750 /var/log/caddy
  render "$TPL/Caddyfile.tmpl" /etc/caddy/Caddyfile
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  systemctl reload caddy 2>/dev/null || systemctl restart caddy
  ok "Caddy serving $CODEX_DOMAIN with auto-HTTPS (docroot: $CODEX_DOCROOT)"
fi

step "Backups: install script + enable nightly timer"
install -m 750 "$DEPLOY_DIR/backup.sh" /usr/local/sbin/codex-backup.sh
render "$TPL/codex-backup.service.tmpl" /etc/systemd/system/codex-backup.service
render "$TPL/codex-backup.timer.tmpl"   /etc/systemd/system/codex-backup.timer
install -d -m 750 "$BACKUP_DIR"
systemctl daemon-reload
systemctl enable --now codex-backup.timer
ok "codex-backup.timer enabled (nightly 02:00)"

step "MCP venv (mcp + uvicorn) for the codex-mcp service"
REQ="$CODEX_ENGINE_DIR/requirements.txt"
VENV="$CODEX_ENGINE_DIR/.venv"
if [[ -f "$REQ" ]]; then
  # python3-venv carries ensurepip; without it `python3 -m venv` makes a venv
  # with NO pip. Install it (idempotent), then (re)create the venv if pip is absent.
  DEBIAN_FRONTEND=noninteractive apt-get install -y -qq python3-venv python3-pip >/dev/null 2>&1 || true
  if [[ ! -x "$VENV/bin/pip" ]]; then
    rm -rf "$VENV"
    python3 -m venv "$VENV"
  fi
  "$VENV/bin/python" -m pip install -q --upgrade pip >/dev/null 2>&1 || true
  "$VENV/bin/python" -m pip install -q -r "$REQ"
  chown -R "$CODEX_ADMIN_USER":www-data "$VENV" 2>/dev/null || true
  ok "MCP venv ready at $VENV"
else
  warn "no $REQ — skipping MCP venv (deploy sync_engine/ first)"
fi

step "Stage Phase-3/5 units (installed, NOT enabled)"
for f in codex-mcp.service codex-sync.service codex-sync.timer \
         codex-reindex.service codex-reindex.timer; do
  render "$TPL/${f}.tmpl" "/etc/systemd/system/$f"
done
systemctl daemon-reload
ok "codex-mcp / codex-sync / codex-reindex staged"
info "Continuous sync:  systemctl enable --now codex-sync.timer"
info "MCP server:       systemctl enable --now codex-mcp.service   (then connect Claude to https://$CODEX_DOMAIN/mcp)"

step "Done — configured"
info "Visit https://$CODEX_DOMAIN to confirm the app serves over TLS."
