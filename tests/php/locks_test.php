<?php
/**
 * locks_test.php — Phase 21 fixture checks for soft edit locks / presence and
 * the optimistic conflict check (never clobber).
 *
 *   php tests/php/locks_test.php
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_lock_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

migrate();
$alice = create_user('alice@x.com', 'Alice', 'password1', 0);
$bob   = create_user('bob@x.com',   'Bob',   'password1', 0);
save_book(['id'=>'A','folder'=>'a','title'=>'Book A','sort_order'=>1]);
$cid = 501;   // a stand-in chapter id (locks key on the id; no chapter row needed here)

/* ---- presence ---- */
check('no editors initially', active_editors($cid) === []);
touch_edit_lock('A', $cid, $alice);
check('Alice registers as an editor', count(active_editors($cid)) === 1);
touch_edit_lock('A', $cid, $alice);   // heartbeat again
check('a repeat heartbeat does not duplicate the row', (int) val("SELECT COUNT(*) FROM editing_locks WHERE chapter_id=? AND user_id=?", [$cid, $alice]) === 1);

touch_edit_lock('A', $cid, $bob);
check('two editors now present', count(active_editors($cid)) === 2);
check('Alice sees only Bob as "other"', array_map(fn($r)=>(int)$r['user_id'], active_editors($cid, $alice)) === [$bob]);
check('other_editor_names returns display names', other_editor_names($cid, $alice) === ['Bob']);

/* ---- staleness: an old heartbeat drops out ---- */
q("UPDATE editing_locks SET heartbeat_at=? WHERE user_id=?", [date('Y-m-d H:i:s', time() - (EDIT_LOCK_TTL + 30)), $bob]);
check('a stale heartbeat is no longer an active editor', array_map(fn($r)=>(int)$r['user_id'], active_editors($cid)) === [$alice]);

/* ---- release ---- */
release_edit_lock($cid, $alice);
check('releasing removes the editor', active_editors($cid) === []);

/* ---- presence is scoped per chapter ---- */
touch_edit_lock('A', 777, $alice);
check('a lock on another chapter does not leak', active_editors($cid) === [] && count(active_editors(777)) === 1);

/* ---- optimistic conflict check (never clobber) — pure DB post-A4 ---- */
q("INSERT INTO chapters (id, book_id, num, title, body, file) VALUES (900, 'A', '1', 'One', ?, 'ch1.md')", ["Original body.\n"]);
$loadedHash = md5(md_body_norm("Original body.\n"));
// a co-author changes the DB body underneath us
q("UPDATE chapters SET body=? WHERE id=900", ["Body changed by a co-author.\n"]);
$r = write_chapter_file('A', 900, "My rewrite.\n", $loadedHash);
check('a stale save is refused as a conflict', $r['status'] === 'conflict');
check('the co-author\'s body is untouched (never clobber)', md_body_norm(val("SELECT body FROM chapters WHERE id=900")) === md_body_norm("Body changed by a co-author.\n"));
// a save from the CURRENT base is accepted
$freshHash = md5(md_body_norm("Body changed by a co-author.\n"));
$r2 = write_chapter_file('A', 900, "Body changed by a co-author.\nPlus my line.\n", $freshHash);
check('a save from the current base is accepted', $r2['status'] === 'ok');
check('the accepted save is persisted', strpos((string)val("SELECT body FROM chapters WHERE id=900"), 'Plus my line.') !== false);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
