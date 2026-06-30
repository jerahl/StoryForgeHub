<?php
/** api.php — token-protected REST API for the PowerShell sync script + automation. */
require_once dirname(__DIR__) . '/src/repo.php';  // src/ lives above the docroot
header('Content-Type: application/json; charset=utf-8');

$CFG = cfg();
function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

/* ---- auth ---- */
$hdr = $_SERVER['HTTP_X_CODEX_TOKEN'] ?? ($_GET['token'] ?? '');
if (empty($CFG['api_token']) || $CFG['api_token'] === 'CHANGE_ME_TO_A_LONG_RANDOM_STRING')
    out(['error' => 'API token not configured on the server (set api_token in config.php).'], 500);
if (!hash_equals($CFG['api_token'], (string)$hdr)) out(['error' => 'unauthorized'], 401);

$action = $_GET['action'] ?? '';
$body = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    if ($raw && $body === null) out(['error' => 'invalid JSON body'], 400);
}

switch ($action) {

case 'ping':
    out(['ok' => true, 'app' => "Stephen's Codex", 'time' => date('c'), 'books' => count(get_books())]);

case 'push':            // folder -> web   body: {books:[{folder,files:{relpath:content}}]}
    if (!$body || !isset($body['books'])) out(['error' => 'expected {books:[...]}'], 400);
    out(['ok' => true, 'report' => push_files($body)]);

case 'pull':            // web -> folder   ?book=ID
    out(['ok' => true] + pull_files($_GET['book'] ?? null));

case 'tasks':           // ?book=ID&for_claude=1&status=todo
    $filters = [];
    if (isset($_GET['for_claude'])) $filters['for_claude'] = (int)$_GET['for_claude'];
    if (isset($_GET['status']))     $filters['status'] = $_GET['status'];
    out(['ok' => true, 'tasks' => get_tasks($_GET['book'] ?? null, $filters)]);

case 'apply':           // skill outbox -> web   body: {task_results:[],writing_log:[],thread_status:[]}
    if (!$body) out(['error' => 'expected JSON body'], 400);
    out(['ok' => true, 'report' => apply_results($body)]);

case 'writing-log':
    out(['ok' => true, 'writing_log' => get_writing_log($_GET['book'] ?? null)]);

case 'export':          // full canonical snapshot (debug / manual seeding)
    $books = [];
    foreach (get_books() as $b) {
        $entries = [];
        foreach (get_entries($b['id']) as $row) $entries[] = entry_to_struct($row);
        $books[] = [
            'book' => $b,
            'entries' => $entries,
            'chapters' => get_chapters($b['id']),
            'progressions' => get_progressions($b['id']),
            'threads' => get_threads($b['id']),
            'meta' => get_meta($b['id']),
            'notes' => get_notes($b['id']),
        ];
    }
    out(['generated' => date('c'), 'dbmeta' => DBMETA, 'books' => $books]);

case 'import':          // load a canonical snapshot
    if (!$body || !isset($body['books'])) out(['error' => 'expected snapshot {books:[...]}'], 400);
    import_snapshot($body);
    out(['ok' => true, 'books' => count($body['books'])]);

default:
    out(['error' => 'unknown action', 'actions' => ['ping','push','pull','tasks','apply','writing-log','export','import']], 400);
}
