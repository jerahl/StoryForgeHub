<?php
/**
 * membership_test.php — Phase 18 fixture checks for book ownership & library scoping.
 *
 *   php tests/php/membership_test.php
 *
 * Runs against a throwaway SQLite database. It drives the scoping through the
 * real current_user() path by setting $_SESSION['uid'] the way the web gate does.
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_mem_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; }
}
/** Impersonate a user the way run_auth_gate()/login_user() does for the request. */
function as_user($uid) { $_SESSION['uid'] = $uid === null ? null : (int)$uid; $GLOBALS['__current_user'] = null; if ($uid === null) unset($_SESSION['uid']); }
function book_ids() { return array_map(fn($b) => $b['id'], get_books()); }

migrate();
ensure_users();

/* seed two pre-existing books (as the folder sync / import would have) */
save_book(['id'=>'echo','folder'=>'echo','title'=>'Echo','sort_order'=>1]);
save_book(['id'=>'alien','folder'=>'alien','title'=>'Alien','sort_order'=>2]);

/* create the admin + a plain writer */
$adminId  = create_user('admin@example.com', 'Admin', 'password1', 1, 'active');
$writerId = create_user('writer@example.com', 'Writer', 'password1', 0, 'active');

/* ---- backfill: existing books become the admin's (the gate runs this per request) ---- */
backfill_book_members();
check('backfill made admin owner of echo', user_book_role($adminId, 'echo') === 'owner');
check('backfill made admin owner of alien', user_book_role($adminId, 'alien') === 'owner');
check('writer is a member of nothing yet', user_book_role($writerId, 'echo') === null);
check('backfill is idempotent (still one owner)', count_book_owners('echo') === 1);
backfill_book_members();
check('re-running backfill adds nothing', count_book_owners('echo') === 1);

/* ---- unscoped context (token API / CLI) sees all ---- */
as_user(null);
check('unscoped get_books returns all', count(book_ids()) === 2);
check('unscoped get_book resolves any book', get_book('echo') !== null);

/* ---- admin is an unscoped superuser view ---- */
as_user($adminId);
check('admin sees all books', count(book_ids()) === 2);
check('admin can load a book', get_book('alien') !== null);

/* ---- writer is scoped to their memberships (none yet) ---- */
as_user($writerId);
check('writer sees no books', count(book_ids()) === 0);
check('writer cannot load a book they are not in', get_book('echo') === null);

/* grant the writer editor access to echo */
add_book_member('echo', $writerId, 'editor', $adminId);
as_user($writerId);
check('writer now sees exactly the shared book', book_ids() === ['echo']);
check('writer can load the shared book', get_book('echo') !== null);
check('writer still cannot load the other book', get_book('alien') === null);
check('writer role reads back as editor', user_book_role($writerId, 'echo') === 'editor');

/* role upsert (not duplicate) */
add_book_member('echo', $writerId, 'viewer', $adminId);
check('re-adding a member updates the role in place', user_book_role($writerId, 'echo') === 'viewer');
check('membership stays unique per book+user', (int) val("SELECT COUNT(*) FROM book_members WHERE book_id='echo' AND user_id=?", [$writerId]) === 1);

/* member listing */
$members = get_book_members('echo');
check('echo has two members (admin + writer)', count($members) === 2);
check('owner is listed first', $members[0]['role'] === 'owner');

/* revoke */
remove_book_member('echo', $writerId);
as_user($writerId);
check('revoked writer loses visibility', count(book_ids()) === 0);
check('revoked writer cannot load the book', get_book('echo') === null);

/* ---- ownership on create: a writer who makes a book owns it ---- */
// create_book needs a books_dir; simulate the row+membership the handler produces.
as_user($writerId);
save_book(['id'=>'wbook','folder'=>'wbook','title'=>'Writer Book','sort_order'=>3]);
add_book_member('wbook', $writerId, 'owner', $writerId);   // mirrors create_book()'s tail
check('creator owns their new book', user_book_role($writerId, 'wbook') === 'owner');
check('writer now sees only their own book', book_ids() === ['wbook']);
as_user($adminId);
check('admin still sees every book incl. the writer\'s', count(book_ids()) === 3);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
