#!/usr/bin/env bash
# diagnose.sh — figure out why the site is unreachable (ERR_CONNECTION_REFUSED etc.)
# Read-only; changes nothing. Run on the VPS:  sudo bash deploy/diagnose.sh
set -uo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

hr(){ echo; echo "── $* ─────────────────────────────────────────"; }

hr "Web server service ($WEB_SERVER)"
if [[ "$WEB_SERVER" == "nginx" ]]; then svc=nginx; else svc=caddy; fi
systemctl is-active "$svc" >/dev/null 2>&1 && ok "$svc is active" || {
  warn "$svc is NOT active — this is almost certainly the cause"
  systemctl --no-pager -l status "$svc" 2>/dev/null | sed -n '1,12p'
}

hr "Who is listening on :80 / :443"
ss -tlnp 2>/dev/null | grep -E ':80 |:443 ' || warn "NOTHING listening on 80/443 → connection refused"

hr "php-fpm + MariaDB"
systemctl is-active "php${PHP_VERSION}-fpm" >/dev/null 2>&1 && ok "php-fpm active" || warn "php-fpm not active"
systemctl is-active mariadb >/dev/null 2>&1 && ok "mariadb active" || warn "mariadb not active"

hr "Firewall (ufw)"
ufw status 2>/dev/null | grep -qi "Status: active" && { ok "ufw active"; ufw status | grep -E '80|443|22'; } \
  || info "ufw inactive (not blocking)"

hr "DNS vs this box"
myip=$(curl -fsS --max-time 5 https://ifconfig.me 2>/dev/null || curl -fsS --max-time 5 https://api.ipify.org 2>/dev/null || echo "?")
dnsip=$(getent hosts "$CODEX_DOMAIN" | awk '{print $1}' | head -1)
info "box public IP : ${myip:-unknown}"
info "$CODEX_DOMAIN → ${dnsip:-NOT RESOLVING}"
if [[ -n "$dnsip" && "$dnsip" == "$myip" ]]; then ok "DNS points here"
elif [[ -z "$dnsip" ]]; then warn "DNS does not resolve — add an A record for $CODEX_DOMAIN → $myip"
else warn "DNS points to $dnsip, not this box ($myip) — fix the A record (or wait for propagation)"; fi

hr "Local fetch (bypasses DNS/network)"
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 5 http://127.0.0.1/ 2>/dev/null || echo "000")
[[ "$code" != "000" ]] && ok "http://127.0.0.1 responds ($code) → server up locally; issue is DNS/firewall/cert" \
  || warn "http://127.0.0.1 refused → web server not serving locally (see service status/logs above)"

hr "Recent $svc errors"
journalctl -u "$svc" -n 20 --no-pager 2>/dev/null | tail -20

hr "Hint"
cat <<EOF
Most common fixes:
  • $svc not running:        sudo systemctl restart $svc ; then re-run this script
  • Caddy cert failed (DNS): point the A record first, then: sudo systemctl restart caddy
  • config error:            sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  • setup never ran:         sudo bash deploy/setup.sh
EOF
