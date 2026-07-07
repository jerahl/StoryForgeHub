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

    // --- REST API service token ---
    // Sent as the X-Codex-Token header (or ?token=...). This is the *unscoped
    // service* token for the PowerShell sync + admin automation. Phase 20 also
    // adds revocable *per-user* tokens (Account → API tokens) that the MCP uses
    // to act as a specific user under the per-book permission checks.
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
];
