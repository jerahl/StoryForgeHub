#!/bin/bash
# End-to-end gate for the per-user MCP auth (standalone plan, Track B1/B2).
# Boots the real stack on loopback — php -S over a seeded sqlite DB + the
# stateless MCP server under uvicorn — then drives it with the MCP client SDK
# as three identities (no token, the service key, a personal token) and checks
# scoping, refusals, and that interleaved sessions keep their identities.
#
# Needs: php-cli (with pdo_sqlite) and `pip install mcp uvicorn` (httpx comes
# with mcp). Run from anywhere:  bash sync_engine/tests/e2e/run.sh
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$DIR/../../.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

export REPO
export DB_DRIVER=sqlite DB_PATH="$WORK/e2e.sqlite" API_KEY=e2e-service-key
ALICE_TOKEN=$(php "$DIR/seed.php" | tail -1)

php -S 127.0.0.1:8081 -t "$REPO/htdocs" > "$WORK/php.log" 2>&1 &
PHP_PID=$!
python3 -c "
import sys; sys.path.insert(0, '$REPO/sync_engine')
import uvicorn
from mcp_server import build_app
app = build_app('e2e-service-key', '$WORK/no-books', 'http://127.0.0.1:8081/api.php',
                public_url='http://127.0.0.1:8081')
uvicorn.run(app, host='127.0.0.1', port=8765, log_level='warning')
" > "$WORK/mcp.log" 2>&1 &
MCP_PID=$!
trap 'kill $PHP_PID $MCP_PID 2>/dev/null; rm -rf "$WORK"' EXIT
for i in $(seq 1 30); do curl -s -o /dev/null http://127.0.0.1:8765/mcp && break; sleep 0.3; done
sleep 1

SERVICE_KEY=e2e-service-key ALICE_TOKEN="$ALICE_TOKEN" python3 "$DIR/e2e_client.py" || {
  echo "--- mcp.log ---"; tail -20 "$WORK/mcp.log"; echo "--- php.log ---"; tail -20 "$WORK/php.log"; exit 1; }
