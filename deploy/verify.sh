#!/usr/bin/env bash
# verify.sh — post-setup smoke test. Read-only; changes nothing.
# Run as root:  sudo bash deploy/verify.sh
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

PASS=0; FAIL=0
check() { # check "label" command...
  local label="$1"; shift
  if "$@" >/dev/null 2>&1; then ok "$label"; ((PASS++)); else warn "$label"; ((FAIL++)); fi
}

step "Services"
check "php${PHP_VERSION}-fpm active"  systemctl is-active --quiet "php${PHP_VERSION}-fpm"
check "mariadb active"                systemctl is-active --quiet mariadb
if [[ "$WEB_SERVER" == "nginx" ]]; then
  check "nginx active"                systemctl is-active --quiet nginx
else
  check "caddy active"                systemctl is-active --quiet caddy
fi
check "codex-backup.timer enabled"    systemctl is-enabled --quiet codex-backup.timer

step "Firewall"
check "ufw active"                    bash -c "ufw status | grep -q 'Status: active'"

step "Secrets file"
check "$CODEX_ENV_FILE exists"        test -f "$CODEX_ENV_FILE"
check "env file mode 640"             bash -c "[[ \$(stat -c '%a' '$CODEX_ENV_FILE') == 640 ]]"
check "API_KEY is not placeholder"    bash -c "! grep -q '^API_KEY=CHANGE_ME' '$CODEX_ENV_FILE'"
check "DB_PASSWORD is not placeholder" bash -c "! grep -q '^DB_PASSWORD=CHANGE_ME' '$CODEX_ENV_FILE'"

step "Database"
# shellcheck disable=SC1090
set -a; source "$CODEX_ENV_FILE" 2>/dev/null; set +a
check "DB connects + books table"     bash -c "MYSQL_PWD='${DB_PASSWORD:-}' mysql -h '${DB_HOST:-127.0.0.1}' -u '${DB_USERNAME:-codex}' '${DB_NAME:-codex}' -e 'SELECT 1 FROM books LIMIT 1;'"

step "App over HTTPS"
check "api.php ping (200, authorized)" bash -c "curl -fsS -H 'X-Codex-Token: ${API_KEY:-}' 'https://$CODEX_DOMAIN/api.php?action=ping' | grep -q '\"ok\":true'"
check "config.php is denied (404)"     bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' 'https://$CODEX_DOMAIN/config.php') == 404 ]]"
check "/src is denied (404)"           bash -c "[[ \$(curl -s -o /dev/null -w '%{http_code}' 'https://$CODEX_DOMAIN/src/repo.php') == 404 ]]"

echo
if (( FAIL == 0 )); then
  echo "${C_GREEN}${C_BOLD}All $PASS checks passed.${C_RESET}"
else
  echo "${C_YELLOW}${C_BOLD}$PASS passed, $FAIL failed.${C_RESET} Review the WARN lines above."
  exit 1
fi
