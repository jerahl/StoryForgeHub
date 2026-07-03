<?php
// Copy this file to config.php (or just use it as-is — config.php already reads
// these). All secrets come from environment variables so nothing sensitive is
// committed. On Wasmer Edge, set them as app secrets:
//
//   wasmer app secrets create DB_HOST     "db.…wasmernet.com"
//   wasmer app secrets create DB_PORT     "10272"
//   wasmer app secrets create DB_NAME     "codex"
//   wasmer app secrets create DB_USERNAME "user_…"
//   wasmer app secrets create DB_PASSWORD "…"
//   wasmer app secrets create API_KEY     "<long random string>"
//   wasmer app secrets create APP_PASSWORD "<first-run bootstrap gate; see below>"
//
// (DB_* are typically provisioned for you by the Wasmer-managed database.)
// For local dev, export the same names in your shell before running.
$env = function ($key, $default = '') {
    $v = getenv($key);
    return ($v === false || $v === '') ? $default : $v;
};

return [
    'db' => [
        'host'    => $env('DB_HOST', '127.0.0.1'),
        'port'    => (int) $env('DB_PORT', '3306'),
        'name'    => $env('DB_NAME', 'stephens_codex'),
        'user'    => $env('DB_USERNAME', 'codex'),
        'pass'    => $env('DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
    ],

    'api_token'    => $env('API_KEY', ''),       // X-Codex-Token for the sync client
    // Phase 17: the UI now uses real per-user accounts + invites, not a shared
    // password. APP_PASSWORD is only the one-time bootstrap gate: on a live
    // install with no accounts yet, whoever creates admin #1 must know it. Once
    // the first admin exists it is no longer used for login (invites take over).
    'app_password' => $env('APP_PASSWORD', ''),

    'accent'   => 'Indigo',        // Indigo | Teal | Burgundy
    'density'  => 'Comfortable',   // Comfortable | Compact
    'bodyType' => 'Sans',          // Sans | Serif

    'sync_dir' => __DIR__ . '/sync',
];
