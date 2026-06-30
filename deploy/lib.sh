#!/usr/bin/env bash
# lib.sh — shared helpers for the Codex VPS setup scripts.
# Sourced by every 0N-*.sh script and by setup.sh. Not meant to run on its own.

set -euo pipefail

# ---- pretty output ---------------------------------------------------------
if [[ -t 1 ]]; then
  C_BOLD=$'\033[1m'; C_GREEN=$'\033[32m'; C_YELLOW=$'\033[33m'
  C_RED=$'\033[31m'; C_BLUE=$'\033[34m'; C_RESET=$'\033[0m'
else
  C_BOLD=''; C_GREEN=''; C_YELLOW=''; C_RED=''; C_BLUE=''; C_RESET=''
fi

step() { echo; echo "${C_BLUE}${C_BOLD}==>${C_RESET} ${C_BOLD}$*${C_RESET}"; }
info() { echo "    $*"; }
ok()   { echo "    ${C_GREEN}OK${C_RESET}  $*"; }
warn() { echo "    ${C_YELLOW}WARN${C_RESET} $*" >&2; }
die()  { echo "${C_RED}${C_BOLD}ERROR${C_RESET} $*" >&2; exit 1; }

# ---- guards ----------------------------------------------------------------
need_root() {
  [[ "${EUID:-$(id -u)}" -eq 0 ]] || die "This step must run as root (use sudo)."
}

need_cmd() { command -v "$1" >/dev/null 2>&1 || die "Required command not found: $1"; }

confirm() {
  # confirm "Question?"  -> respects ASSUME_YES=1 for non-interactive runs
  local prompt="${1:-Continue?}"
  if [[ "${ASSUME_YES:-0}" == "1" ]]; then info "$prompt (auto-yes)"; return 0; fi
  read -r -p "    $prompt [y/N] " ans
  [[ "$ans" =~ ^[Yy]$ ]]
}

# ---- config ----------------------------------------------------------------
# Resolve the deploy/ directory regardless of where the script is invoked from.
DEPLOY_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$DEPLOY_DIR/.." && pwd)"

# Load deploy.env if present (created from deploy.env.sample). Every tunable
# below can be overridden there or via the environment.
if [[ -f "$DEPLOY_DIR/deploy.env" ]]; then
  # shellcheck disable=SC1091
  set -a; source "$DEPLOY_DIR/deploy.env"; set +a
fi

# Defaults — override in deploy.env or the environment.
: "${CODEX_DOMAIN:=codex.example.com}"        # public hostname (DNS A record -> this box)
: "${CODEX_ADMIN_USER:=codex}"                 # non-root sudo user to create/use
: "${CODEX_APP_ROOT:=/srv/codex/app}"          # app root (internals: src/, config.php, schema.sql, bin/, sync/)
: "${CODEX_DOCROOT:=${CODEX_APP_ROOT}/htdocs}" # web docroot — ONLY web-exposed files live here
: "${CODEX_BOOKS_DIR:=/srv/codex/books}"       # canonical Markdown book folders
: "${CODEX_ENGINE_DIR:=${CODEX_APP_ROOT}/sync_engine}"  # where the Python reconcile engine lives (cycle.py)
: "${CODEX_ENV_FILE:=/etc/codex/codex.env}"    # runtime secrets (php-fpm + mcp)
: "${CODEX_DB_NAME:=codex}"
: "${CODEX_DB_USER:=codex}"
: "${PHP_VERSION:=8.3}"
: "${PHP_FPM_POOL:=/etc/php/${PHP_VERSION}/fpm/pool.d/codex.conf}"
: "${WEB_SERVER:=caddy}"                        # caddy | nginx
: "${BACKUP_DIR:=/var/backups/codex}"
: "${SYNC_INTERVAL_MIN:=5}"                     # codex-sync.timer cadence (minutes)

export DEPLOY_DIR REPO_DIR CODEX_DOMAIN CODEX_ADMIN_USER CODEX_APP_ROOT CODEX_DOCROOT \
       CODEX_BOOKS_DIR CODEX_ENGINE_DIR CODEX_ENV_FILE CODEX_DB_NAME CODEX_DB_USER PHP_VERSION \
       PHP_FPM_POOL WEB_SERVER BACKUP_DIR SYNC_INTERVAL_MIN

rand_secret() { head -c 48 /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c 48; }
