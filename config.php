<?php
// Runtime configuration. On Wasmer Edge these come from app secrets / environment
// variables (set via `wasmer app secrets create …` or the dashboard), so NO
// credentials live in source. For local dev, export the same vars in your shell
// before running. Missing secrets fall back to harmless local defaults.
$env = function ($key, $default = '') {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

return [
    // --- DB --- defaults to Wasmer-managed MySQL. Set DB_DRIVER=sqlite (+ DB_PATH)
    // for local dev/tests; db.php already speaks both. Production is unaffected.
    'db' => [
        'driver'  => $env('DB_DRIVER', 'mysql'),
        'path'    => $env('DB_PATH', __DIR__ . '/codex.sqlite'),
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'codex'),
        'user'    => $env('DB_USERNAME', 'codex'),
        'pass'    => $env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],

    // --- REST API token ---
    // The sync client (PowerShell today, MCP server later) must send this as the
    // X-Codex-Token header (or ?token=...). Set the API_KEY secret on Edge.
    'api_token' => $env('API_KEY', ''),

    // --- First-run bootstrap gate (Phase 17). The UI now uses real per-user
    // accounts + invites; this is only the secret that whoever creates the
    // first admin account must know on a live install. Unused once admin #1
    // exists. Empty on a brand-new box = the first-admin setup is ungated. ---
    'app_password' => $env('APP_PASSWORD', ''),

    // --- Display defaults (overridable per session in the UI) ---
    'accent'   => 'Indigo',        // Indigo | Teal | Burgundy
    'density'  => 'Comfortable',   // Comfortable | Compact
    'bodyType' => 'Sans',          // Sans | Serif

    // Where exported .md / bridge files are written on the server (optional).
    'sync_dir' => __DIR__ . '/sync',

    // Canonical books root on the VPS. Required for in-app chapter editing
    // (Phase 9: the app writes Manuscript/*.md back to disk). Empty = chapter
    // editing disabled (chapters stay read-only / folder-owned).
    'books_dir' => $env('CODEX_BOOKS_DIR', ''),
];
