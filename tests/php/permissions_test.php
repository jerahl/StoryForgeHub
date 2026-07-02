<?php
/**
 * permissions_test.php — Phase 19 fixture checks for the role matrix, IDOR-safe
 * book resolution, per-book invites, member guards, and the activity log.
 *
 *   php tests/php/permissions_test.php
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_perm_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function as_user($uid) { $_SESSION['uid'] = $uid === null ? null : (int)$uid; $GLOBALS['__current_user'] = null; if ($uid === null) unset($_SESSION['uid']); }
/** Mirrors the gate's decision: authoritative book resolved from a task id, then capability. */
function gate_task_ok($cap, $taskId) { return user_can(book_of('tasks', $taskId), $cap); }

migrate();

/* ---- role matrix ---- */
check('owner may edit',       role_allows('owner', 'edit'));
check('owner may manage',     role_allows('owner', 'manage'));
check('editor may edit',      role_allows('editor', 'edit'));
check('editor may NOT manage',!role_allows('editor', 'manage'));
check('editor may comment',   role_allows('editor', 'comment'));
check('viewer may comment',   role_allows('viewer', 'comment'));
check('viewer may NOT edit',  !role_allows('viewer', 'edit'));
check('viewer may view',      role_allows('viewer', 'view'));
check('non-member (null) may do nothing', !role_allows(null, 'view') && !role_allows(null, 'edit'));

/* ---- fixtures: two books, four users ---- */
save_book(['id'=>'A','folder'=>'a','title'=>'Book A','sort_order'=>1]);
save_book(['id'=>'B','folder'=>'b','title'=>'Book B','sort_order'=>2]);
$admin  = create_user('admin@x.com',  'Admin',  'password1', 1);
$editor = create_user('editor@x.com', 'Editor', 'password1', 0);
$viewer = create_user('viewer@x.com', 'Viewer', 'password1', 0);
$outsider = create_user('out@x.com',  'Outsider','password1', 0);
add_book_member('A', $admin,  'owner',  $admin);
add_book_member('A', $editor, 'editor', $admin);
add_book_member('A', $viewer, 'viewer', $admin);
add_book_member('B', $admin,  'owner',  $admin);

/* ---- user_can across roles/books ---- */
as_user($editor);
check('editor can edit book A',        user_can('A', 'edit'));
check('editor can comment book A',     user_can('A', 'comment'));
check('editor cannot manage book A',   !user_can('A', 'manage'));
check('editor cannot touch book B',    !user_can('B', 'edit') && !user_can('B', 'view'));

as_user($viewer);
check('viewer can view book A',        user_can('A', 'view'));
check('viewer can comment book A',     user_can('A', 'comment'));
check('viewer cannot edit book A',     !user_can('A', 'edit'));
check('viewer cannot manage book A',   !user_can('A', 'manage'));

as_user($outsider);
check('outsider cannot view book A',   !user_can('A', 'view'));
check('outsider cannot edit book A',   !user_can('A', 'edit'));

as_user($admin);
check('admin can manage any book',     user_can('A', 'manage') && user_can('B', 'manage'));

as_user(null);
check('unscoped (token/CLI) may edit',  user_can('A', 'edit') && user_can('B', 'manage'));

/* ---- IDOR: gate resolves the book from the subject id, not the posted book ---- */
$taskA = save_task(['id'=>null,'book_id'=>'A','title'=>'A task','status'=>'todo','for_claude'=>false]);
$taskB = save_task(['id'=>null,'book_id'=>'B','title'=>'B task','status'=>'todo','for_claude'=>false]);
check('book_of resolves a task to its book', book_of('tasks', $taskB) === 'B');
as_user($editor);
check('editor may delete a task in their book A',       gate_task_ok('edit', $taskA));
check('editor may NOT delete a task in book B via id',  !gate_task_ok('edit', $taskB));  // even if they post book=A
as_user($admin);
check('admin may act on a task in any book',            gate_task_ok('edit', $taskB));

/* ---- per-book invite grants membership on acceptance ---- */
$tok = create_invite('newpal@x.com', 'editor', 0, $admin, 14, 'A');
$inv = get_invite_by_token($tok);
check('invite carries its book', $inv['book_id'] === 'A' && $inv['role'] === 'editor');
$newId = accept_invite($tok, 'New Pal', 'password1');
check('accepting a per-book invite creates the membership', user_book_role($newId, 'A') === 'editor');
as_user($newId);
check('the invited user can now edit book A', user_can('A', 'edit'));

/* ---- owner guards (the logic the member_* handlers enforce) ---- */
check('book A has exactly one owner', count_book_owners('A') === 1);
add_book_member('A', $editor, 'owner', $admin);   // promote a second owner
check('now two owners', count_book_owners('A') === 2);
remove_book_member('A', $editor);                 // safe: one owner remains
check('removing a non-last owner leaves one', count_book_owners('A') === 1);

/* ---- activity log ---- */
as_user($admin);
log_activity('A', 'member_add', 'editor@x.com as editor');
log_activity('A', 'chapter_save', 'Ch 1');
$act = recent_activity('A', 10);
check('activity is recorded newest-first', count($act) >= 2 && $act[0]['action'] === 'chapter_save');
check('activity records the actor', (int)$act[0]['user_id'] === $admin);
check('activity is scoped per book', count(recent_activity('B', 10)) === 0);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
