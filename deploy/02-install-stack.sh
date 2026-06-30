#!/usr/bin/env bash
# 02-install-stack.sh — web + DB stack (MASTER-PLAN P0 step 2).
#
# php-fpm 8.3 (+ pdo_mysql, mbstring, xml, curl, gd, opcache), MariaDB, and the
# web server (Caddy by default; nginx+certbot if WEB_SERVER=nginx). Configures a
# dedicated php-fpm pool that reads secrets from $CODEX_ENV_FILE. Idempotent.
#
# Run as root:  sudo bash deploy/02-install-stack.sh
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
need_root
export DEBIAN_FRONTEND=noninteractive

step "PHP $PHP_VERSION (fpm) + extensions"
# Debian 12 ships PHP 8.2; pull 8.3 from Sury if the distro lacks the requested version.
if ! apt-cache show "php${PHP_VERSION}-fpm" >/dev/null 2>&1; then
  info "php${PHP_VERSION} not in distro repos; adding Sury (packages.sury.org)"
  curl -fsSL https://packages.sury.org/php/apt.gpg -o /usr/share/keyrings/sury-php.gpg
  echo "deb [signed-by=/usr/share/keyrings/sury-php.gpg] https://packages.sury.org/php/ $(. /etc/os-release && echo "$VERSION_CODENAME") main" \
    > /etc/apt/sources.list.d/sury-php.list
  apt-get update -qq
fi
apt-get install -y -qq \
  "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-mbstring" \
  "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-gd" \
  "php${PHP_VERSION}-opcache" >/dev/null
ok "php-fpm $PHP_VERSION installed"

step "opcache tuning"
OPCACHE_INI="/etc/php/${PHP_VERSION}/fpm/conf.d/99-codex-opcache.ini"
cat > "$OPCACHE_INI" <<'EOF'
; Managed by codex 02-install-stack.sh
opcache.enable=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=10000
opcache.validate_timestamps=1
opcache.revalidate_freq=2
EOF
ok "opcache enabled"

step "Dedicated php-fpm pool (reads $CODEX_ENV_FILE)"
# A dedicated pool lets us inject the codex.env secrets into PHP's getenv()
# via clear_env=no + EnvironmentFile-style injection from the unit (see 04).
# We also list the keys explicitly so getenv() sees them even with clear_env.
cat > "$PHP_FPM_POOL" <<EOF
; Managed by codex 02-install-stack.sh
[codex]
user = www-data
group = www-data
listen = /run/php/php${PHP_VERSION}-fpm-codex.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 4
; Secrets are injected by systemd from $CODEX_ENV_FILE (see codex-env.conf
; drop-in installed by 04-configure.sh). clear_env=no keeps the master env,
; and the explicit env[KEY] = \$KEY lines below force php-fpm to copy each one
; into the per-request environment so PHP's getenv() reliably sees them.
; (\$VAR is resolved by php-fpm from the EnvironmentFile-provided master env,
;  so real secret values never land in this pool file.)
clear_env = no
env[DB_HOST]         = \$DB_HOST
env[DB_PORT]         = \$DB_PORT
env[DB_NAME]         = \$DB_NAME
env[DB_USERNAME]     = \$DB_USERNAME
env[DB_PASSWORD]     = \$DB_PASSWORD
env[API_KEY]         = \$API_KEY
env[APP_PASSWORD]    = \$APP_PASSWORD
env[CODEX_BOOKS_DIR] = \$CODEX_BOOKS_DIR
EOF
rm -f "$PHP_FPM_POOL.tmp"
# Remove the default pool so only the codex socket serves the app.
if [[ -f "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" ]]; then
  mv "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf" \
     "/etc/php/${PHP_VERSION}/fpm/pool.d/www.conf.disabled"
  ok "default www pool disabled"
fi
ok "codex pool -> /run/php/php${PHP_VERSION}-fpm-codex.sock"

step "MariaDB server"
apt-get install -y -qq mariadb-server >/dev/null
systemctl enable --now mariadb >/dev/null
# Bind to localhost only — no network exposure.
BIND_CNF=/etc/mysql/mariadb.conf.d/99-codex-bind.cnf
cat > "$BIND_CNF" <<'EOF'
# Managed by codex 02-install-stack.sh
[mysqld]
bind-address = 127.0.0.1
EOF
systemctl restart mariadb
ok "MariaDB running, bound to 127.0.0.1"

if [[ "$WEB_SERVER" == "nginx" ]]; then
  step "nginx + certbot"
  apt-get install -y -qq nginx certbot python3-certbot-nginx >/dev/null
  systemctl enable --now nginx >/dev/null
  ok "nginx installed (vhost written by 04-configure.sh; run certbot after DNS resolves)"
else
  step "Caddy (auto-HTTPS)"
  if ! command -v caddy >/dev/null 2>&1; then
    curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/gpg.key \
      | gpg --dearmor -o /usr/share/keyrings/caddy-stable-archive-keyring.gpg
    curl -fsSL https://dl.cloudsmith.io/public/caddy/stable/debian.deb.txt \
      > /etc/apt/sources.list.d/caddy-stable.list
    apt-get update -qq
    apt-get install -y -qq caddy >/dev/null
  fi
  systemctl enable caddy >/dev/null 2>&1 || true
  ok "Caddy installed (Caddyfile written by 04-configure.sh)"
fi

systemctl restart "php${PHP_VERSION}-fpm"
ok "php-fpm restarted"

step "Done — stack installed"
info "Next: deploy/03-setup-app.sh (DB + app), then deploy/04-configure.sh (env, web server, timers)."
