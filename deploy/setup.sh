#!/usr/bin/env bash
# setup.sh — one-shot initial server setup for Stephen's Codex on a Debian 12 VPS.
#
# Runs the numbered steps in order (MASTER-PLAN Phase 0):
#   01-provision.sh    base box hardening (SSH, ufw, fail2ban, sudo user)
#   02-install-stack.sh php-fpm + MariaDB + Caddy/nginx
#   03-setup-app.sh     DB + least-priv user, schema import, app deploy, env secrets
#   04-configure.sh     secret injection, web server, backups, staged P3/P5 timers
#
# USAGE (as root on a fresh box):
#   1. cp deploy/deploy.env.sample deploy/deploy.env   # then edit CODEX_DOMAIN etc.
#   2. sudo bash deploy/setup.sh                       # full run
#
# Options:
#   --only N[,N...]   run only the listed steps (e.g. --only 3,4)
#   --from N          run from step N to the end (e.g. --from 2)
#   --yes             non-interactive (assume yes to prompts)
#   --dry-run         print what would run; change nothing
#
# Re-running is safe: every step is idempotent.
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"

STEPS=(01-provision.sh 02-install-stack.sh 03-setup-app.sh 04-configure.sh)
ONLY=""; FROM=1; DRY=0

while [[ $# -gt 0 ]]; do
  case "$1" in
    --only)    ONLY="$2"; shift 2 ;;
    --from)    FROM="$2"; shift 2 ;;
    --yes|-y)  export ASSUME_YES=1; shift ;;
    --dry-run) DRY=1; shift ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) die "unknown option: $1" ;;
  esac
done

need_root

# Preflight checks.
step "Preflight"
[[ -f "$DEPLOY_DIR/deploy.env" ]] || warn "no deploy.env found — using defaults (CODEX_DOMAIN=$CODEX_DOMAIN)"
. /etc/os-release 2>/dev/null || true
[[ "${ID:-}" == "debian" ]] || warn "expected Debian; found '${ID:-unknown}' — proceeding anyway"
info "Domain:      $CODEX_DOMAIN"
info "App root:    $CODEX_APP_ROOT"
info "Books dir:   $CODEX_BOOKS_DIR"
info "Web server:  $WEB_SERVER"
info "DB:          $CODEX_DB_NAME (user $CODEX_DB_USER @ localhost)"
echo
if ! confirm "Proceed with initial server setup?"; then die "aborted by user"; fi

# Decide which steps to run.
run_index() {
  local i="$1"
  if [[ -n "$ONLY" ]]; then [[ ",$ONLY," == *",$i,"* ]]; return; fi
  (( i >= FROM ))
}

for i in 1 2 3 4; do
  script="${STEPS[$((i-1))]}"
  if run_index "$i"; then
    step "[$i/4] $script"
    if [[ "$DRY" == "1" ]]; then info "(dry-run) would execute: bash $DEPLOY_DIR/$script"; continue; fi
    bash "$DEPLOY_DIR/$script"
  else
    info "[$i/4] $script — skipped"
  fi
done

if [[ "$DRY" == "1" ]]; then step "Dry run complete"; exit 0; fi

step "Initial setup complete"
cat <<EOF
    Next steps:
      1. Copy your book folders:   rsync -av /local/Codex/ $CODEX_BOOKS_DIR/
      2. Import data:              mysql $CODEX_DB_NAME < dump.sql
                                   (or app Sync -> Import snapshot.json)
      3. Confirm TLS:              https://$CODEX_DOMAIN
      4. Smoke test:               sudo bash $DEPLOY_DIR/verify.sh
      5. Decommission Wasmer once the site serves clean and data verifies.

    Secrets live in $CODEX_ENV_FILE (rotate any value ever committed in plaintext).
EOF
