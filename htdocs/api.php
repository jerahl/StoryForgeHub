<?php
/** api.php — token-protected REST API for the sync client, automation, and the MCP.
 *
 *  Phase 20 — two token modes:
 *    • the shared SERVICE token (config api_token) → unscoped, full access
 *      (the PowerShell sync + admin automation; backward compatible);
 *    • a per-user API token → the request ACTS AS that user, and every
 *      book-scoped read/write routes through the same P18 scoping + P19 role
 *      checks, so the MCP can reach exactly the books that user could.
 */
require_once dirname(__DIR__) . '/src/repo.php';  // src/ lives above the docroot
header('Content-Type: application/json; charset=utf-8');

$CFG = cfg();
function out($data, $code = 200) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

/* ---- auth: service token OR per-user token ---- */
$hdr = $_SERVER['HTTP_X_CODEX_TOKEN'] ?? ($_GET['token'] ?? '');
$svc = (string)($CFG['api_token'] ?? '');
$IS_SERVICE = false;
if ($svc !== '' && $svc !== 'CHANGE_ME_TO_A_LONG_RANDOM_STRING' && hash_equals($svc, (string)$hdr)) {
    $IS_SERVICE = true;                         // shared service token — unscoped
} elseif ($u = user_for_api_token((string)$hdr)) {
    act_as_user($u);                            // per-user token — scoped to that user
} else {
    out(['error' => 'unauthorized'], 401);
}

/* ---- authorization helpers (no-ops for the service token / admins) ---- */
function require_cap($book, $cap) { if (!user_can($book, $cap)) out(['error' => 'forbidden'], 403); }
function scoped_ids() { return array_map(function ($b) { return $b['id']; }, get_books()); }  // scoped to the acting user

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
    if (!$IS_SERVICE) {
        // A per-user token may only push to existing books it can edit; creating
        // a new book that way is not allowed (make it in the app, then push).
        foreach ($body['books'] as $bk) {
            $bid = book_id_for_folder($bk['folder'] ?? '');
            if (!$bid) out(['error' => 'creating a new book via a personal token is not allowed'], 403);
            if (!user_can($bid, 'edit')) out(['error' => 'forbidden: no edit access to book ' . $bid], 403);
        }
    }
    out(['ok' => true, 'report' => push_files($body)]);

case 'pull':            // web -> folder   ?book=ID   (get_book(s) is already scoped)
    if (!$IS_SERVICE && isset($_GET['book'])) require_cap($_GET['book'], 'view');
    out(['ok' => true] + pull_files($_GET['book'] ?? null));

case 'tasks':           // ?book=ID&for_claude=1&status=todo
    $filters = [];
    if (isset($_GET['for_claude'])) $filters['for_claude'] = (int)$_GET['for_claude'];
    if (isset($_GET['status']))     $filters['status'] = $_GET['status'];
    $bookParam = $_GET['book'] ?? null;
    if (!$IS_SERVICE && $bookParam) require_cap($bookParam, 'view');
    $tasks = get_tasks($bookParam, $filters);
    if (!$IS_SERVICE && !$bookParam) {          // no book given → limit to the user's books
        $allowed = array_flip(scoped_ids());
        $tasks = array_values(array_filter($tasks, function ($t) use ($allowed) { return isset($allowed[$t['book_id']]); }));
    }
    out(['ok' => true, 'tasks' => $tasks]);

case 'apply':           // skill/MCP outbox -> web   body: {task_results:[],writing_log:[],thread_status:[]}
    if (!$body) out(['error' => 'expected JSON body'], 400);
    if (!$IS_SERVICE) {
        // Every referenced object must live in a book the caller can edit.
        foreach (($body['task_results'] ?? []) as $tr) {
            $t = get_task($tr['id'] ?? 0); if (!$t) continue;
            require_cap($t['book_id'], 'edit');
        }
        foreach (($body['writing_log'] ?? []) as $wl) require_cap($wl['book_id'] ?? '', 'edit');
        foreach (($body['thread_status'] ?? []) as $ts) require_cap(book_of('threads', $ts['id'] ?? 0), 'edit');
    }
    out(['ok' => true, 'report' => apply_results($body)]);

case 'writing-log':
    $bookParam = $_GET['book'] ?? null;
    if (!$IS_SERVICE && $bookParam) require_cap($bookParam, 'view');
    $log = get_writing_log($bookParam);
    if (!$IS_SERVICE && !$bookParam) {
        $allowed = array_flip(scoped_ids());
        $log = array_values(array_filter($log, function ($r) use ($allowed) { return isset($allowed[$r['book_id']]); }));
    }
    out(['ok' => true, 'writing_log' => $log]);

case 'export':          // full canonical snapshot — get_books() is scoped to the caller
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

case 'import':          // load a canonical snapshot — global op, admin/service only
    if (!$IS_SERVICE && !user_is_admin()) out(['error' => 'forbidden: snapshot import requires an administrator'], 403);
    if (!$body || !isset($body['books'])) out(['error' => 'expected snapshot {books:[...]}'], 400);
    import_snapshot($body);
    out(['ok' => true, 'books' => count($body['books'])]);

default:
    out(['error' => 'unknown action', 'actions' => ['ping','push','pull','tasks','apply','writing-log','export','import']], 400);
}
