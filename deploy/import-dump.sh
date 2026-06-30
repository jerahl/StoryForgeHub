#!/usr/bin/env bash
# import-dump.sh — import a MySQL dump into the Codex DB on MariaDB.
#
# Fixes the two problems you hit moving an Adminer/MySQL-8 dump onto MariaDB:
#   1. "ERROR 1273 Unknown collation 'utf8mb4_0900_ai_ci'" — MySQL-8 collations
#      don't exist in MariaDB (Debian 12 ships MariaDB 10.11). Rewritten to
#      utf8mb4_unicode_ci.
#   2. "ERROR 1064 ... near '' at line N" — the dump escapes quotes as \' and has
#      semicolons inside text (e.g. "...Veilcore\'s awakening...network; sealed").
#      Web importers (Adminer/phpMyAdmin SQL tab) and paste-into-a-box tools split
#      statements on ';' and miscount quotes around \', cutting an INSERT mid-row.
#      The mysql CLI parses \' correctly, so this script imports via the CLI.
#
# IMPORTANT: import via this script (the mysql CLI), NOT a web importer.
#
# USAGE:
#   sudo bash deploy/import-dump.sh /path/to/your-dump.sql
#   sudo bash deploy/import-dump.sh /path/to/your-dump.sql.gz   # gzip ok
set -euo pipefail
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/lib.sh"
need_root
need_cmd mysql

SRC="${1:-}"
[[ -n "$SRC" && -f "$SRC" ]] || die "usage: import-dump.sh /path/to/dump.sql[.gz]"

# Pull DB creds from the env file the rest of setup uses.
[[ -f "$CODEX_ENV_FILE" ]] || die "missing $CODEX_ENV_FILE (run 03-setup-app.sh first)"
# shellcheck disable=SC1090
set -a; source "$CODEX_ENV_FILE"; set +a
: "${DB_NAME:=$CODEX_DB_NAME}" "${DB_USERNAME:=$CODEX_DB_USER}" "${DB_HOST:=127.0.0.1}"

CLEAN="${SRC%.gz}"; CLEAN="${CLEAN%.sql}.mariadb.sql"

step "Sanitizing dump -> $CLEAN"
# Read (decompress if needed) -> normalize CRLF->LF -> rewrite MySQL-8-only bits.
reader() { if [[ "$SRC" == *.gz ]]; then zcat "$SRC"; else cat "$SRC"; fi; }
reader \
  | sed 's/\r$//' \
  | sed -E \
      -e 's/utf8mb4_0900_[a-zA-Z_]+/utf8mb4_unicode_ci/g' \
      -e 's/[[:space:]]*\/\*![0-9]+ +SET +@@SESSION\.sql_require_primary_key[^;]*;//g' \
  > "$CLEAN"
ok "normalized line endings + rewrote MySQL-8 collations (original untouched)"

step "Importing into '$DB_NAME' (via mysql CLI)"
MYSQL_PWD="${DB_PASSWORD:-}" mysql -h "$DB_HOST" -u "$DB_USERNAME" "$DB_NAME" < "$CLEAN"
ok "import complete"

step "Verify"
n=$(MYSQL_PWD="${DB_PASSWORD:-}" mysql -N -h "$DB_HOST" -u "$DB_USERNAME" "$DB_NAME" \
      -e "SELECT COUNT(*) FROM books;" 2>/dev/null || echo "?")
info "books rows: $n"
info "Sanitized file kept at: $CLEAN"
