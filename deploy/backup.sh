#!/usr/bin/env bash
# backup.sh — nightly DB dump + books snapshot (MASTER-PLAN Security section).
# Installed to /usr/local/sbin/codex-backup.sh by 04-configure.sh and run by
# codex-backup.timer. Reads DB_* + BACKUP_DIR + CODEX_BOOKS_DIR from the unit's
# EnvironmentFile (/etc/codex/codex.env). Keeps 14 days of backups.
#
# OFF-BOX: this writes locally. Add an rsync/rclone push to remote storage at
# the end (see the commented block) so a lost box is not a lost manuscript.
set -euo pipefail

BACKUP_DIR="${BACKUP_DIR:-/var/backups/codex}"
CODEX_BOOKS_DIR="${CODEX_BOOKS_DIR:-/srv/codex/books}"
DB_NAME="${DB_NAME:-codex}"
DB_USERNAME="${DB_USERNAME:-codex}"
DB_PASSWORD="${DB_PASSWORD:-}"
DB_HOST="${DB_HOST:-127.0.0.1}"
RETAIN_DAYS="${RETAIN_DAYS:-14}"

ts="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$BACKUP_DIR"

# --- database ---
db_dump="$BACKUP_DIR/db-$ts.sql.gz"
MYSQL_PWD="$DB_PASSWORD" mysqldump --single-transaction --quick \
  -h "$DB_HOST" -u "$DB_USERNAME" "$DB_NAME" | gzip > "$db_dump"
echo "DB dump:    $db_dump ($(du -h "$db_dump" | cut -f1))"

# --- canonical Markdown export (A1: the DB is the truth; this is the plain-
# files snapshot of it — the prose safety net after the folder sync retires) ---
CODEX_APP_DIR="${CODEX_APP_DIR:-/srv/codex/app}"
if [[ -f "$CODEX_APP_DIR/bin/export.php" ]] && command -v php >/dev/null; then
  export_dir="$BACKUP_DIR/export-$ts"
  if php "$CODEX_APP_DIR/bin/export.php" --dir "$export_dir" >/dev/null; then
    tar -czf "$BACKUP_DIR/export-$ts.tar.gz" -C "$BACKUP_DIR" "export-$ts"
    rm -rf "$export_dir"
    echo "Export:     $BACKUP_DIR/export-$ts.tar.gz ($(du -h "$BACKUP_DIR/export-$ts.tar.gz" | cut -f1))"
  else
    echo "Export:     FAILED (bin/export.php returned non-zero)" >&2
  fi
fi

# --- books folder (mirror mode only; retires with the A4 cutover) ---
books_tar="$BACKUP_DIR/books-$ts.tar.gz"
if [[ -d "$CODEX_BOOKS_DIR" ]]; then
  tar -czf "$books_tar" -C "$(dirname "$CODEX_BOOKS_DIR")" "$(basename "$CODEX_BOOKS_DIR")"
  echo "Books:      $books_tar ($(du -h "$books_tar" | cut -f1))"
fi

# --- prune old local backups ---
find "$BACKUP_DIR" -name 'db-*.sql.gz'    -mtime +"$RETAIN_DAYS" -delete
find "$BACKUP_DIR" -name 'books-*.tar.gz'  -mtime +"$RETAIN_DAYS" -delete
find "$BACKUP_DIR" -name 'export-*.tar.gz' -mtime +"$RETAIN_DAYS" -delete

# --- OFF-BOX COPY (recommended; configure and uncomment) ---
# rclone copy "$db_dump"   remote:codex-backups/
# rclone copy "$books_tar" remote:codex-backups/

echo "Backup complete: $ts"
