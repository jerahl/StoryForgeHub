#!/usr/bin/env bash
# build-on-server.sh — install Node (if needed) and build the Codex editor bundle
# ON THE SERVER, writing the docroot's htdocs/assets/app/editor.js (+ editor.css).
# Re-run after editing anything under editor/src. Run as root:
#   sudo bash editor/build-on-server.sh
set -euo pipefail
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"          # .../editor
OUT_DIR="$(cd "$HERE/.." && pwd)/htdocs/assets/app"            # docroot asset dir

have_node() {
  command -v node >/dev/null 2>&1 || return 1
  local maj; maj="$(node -p 'process.versions.node.split(".")[0]' 2>/dev/null || echo 0)"
  [ "${maj:-0}" -ge 18 ]
}

if ! have_node; then
  echo "==> Installing Node.js 22 LTS (NodeSource)…"
  [ "$(id -u)" -eq 0 ] || { echo "ERROR: need root to install Node (re-run with sudo)."; exit 1; }
  export DEBIAN_FRONTEND=noninteractive
  curl -fsSL https://deb.nodesource.com/setup_22.x | bash -
  apt-get install -y nodejs
fi
echo "==> node $(node -v), npm $(npm -v)"

cd "$HERE"
echo "==> Installing dependencies…"
if [ -f package-lock.json ]; then
  npm ci --no-audit --no-fund || npm install --no-audit --no-fund
else
  npm install --no-audit --no-fund
fi

echo "==> Building bundle…"
npm run build

echo "==> Built into $OUT_DIR:"
ls -la "$OUT_DIR"/editor.js "$OUT_DIR"/editor.css 2>/dev/null || { echo "ERROR: build produced no editor.js/.css"; exit 1; }

# Match the app's deployed ownership/permissions (root:www-data, 640) so php-fpm can read them.
if id www-data >/dev/null 2>&1; then
  chown root:www-data "$OUT_DIR"/editor.js "$OUT_DIR"/editor.css 2>/dev/null || true
  chmod 640 "$OUT_DIR"/editor.js "$OUT_DIR"/editor.css 2>/dev/null || true
fi

echo "==> Done. (The page busts the asset cache via the file's mtime, so a hard refresh isn't needed.)"
echo "    Node can be removed afterward if you prefer:  sudo apt-get remove -y nodejs"
