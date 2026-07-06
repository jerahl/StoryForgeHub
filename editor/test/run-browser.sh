#!/bin/bash
# Boots php -S over a seeded sqlite DB and runs the headless-Chromium smoke
# (browser-smoke.mjs) against the real app. Needs php-cli and `npm i` in
# editor/ (playwright is a devDependency; the browser binary comes from
# PLAYWRIGHT_BROWSERS_PATH — nothing is downloaded).
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$DIR/../.." && pwd)"
WORK="$(mktemp -d)"

export REPO
export DB_DRIVER=sqlite DB_PATH="$WORK/smoke.sqlite" API_KEY=smoke-key
php "$REPO/sync_engine/tests/e2e/seed.php" > /dev/null   # alice / echo / ch01 / aria / outline note

php -S 127.0.0.1:8083 -t "$REPO/htdocs" > "$WORK/php.log" 2>&1 &
PHP_PID=$!
trap 'kill $PHP_PID 2>/dev/null; rm -rf "$WORK"' EXIT
sleep 1

cd "$DIR/.." && APP_URL=http://127.0.0.1:8083 node test/browser-smoke.mjs || {
  echo "--- php.log ---"; tail -20 "$WORK/php.log"; exit 1; }
