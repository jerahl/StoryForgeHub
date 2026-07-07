<?php
/**
 * db_canonical_test.php — fixture checks for the DB-canonical flip (standalone
 * plan, Track A): the app works fully with NO books directory, the base-hash
 * conflict rule survives the flip, every save through any door records a
 * revision with attribution, entry history restores, and bin/export.php
 * regenerates clean Markdown folders (post-A4: the ONLY way prose becomes files).
 *
 *   php tests/php/db_canonical_test.php
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_flip_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
putenv('CODEX_BOOKS_DIR');   // explicitly NO books dir — the point of the flip
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
$work = sys_get_temp_dir() . '/codex_flip_' . getmypid();
register_shutdown_function(function () use ($tmp, $work) {
    @unlink($tmp);
    if (is_dir($work)) { exec('rm -rf ' . escapeshellarg($work)); }
});

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

migrate();
$alice = create_user('alice@x.com', 'Alice', 'password1', 0);
$_SESSION['uid'] = $alice; $GLOBALS['__current_user'] = null; $GLOBALS['__acting_user'] = null;

/* ---- create a book + chapters with NO books dir ---- */
$rb = create_book(['title' => 'Echoes', 'profile' => 'fiction']);
check('create_book works without CODEX_BOOKS_DIR', $rb['status'] === 'ok');
$bid = $rb['id'];
check('creator owns the book', user_can($bid, 'manage'));

$rc = create_chapter($bid, 'The Wall', '1');
check('create_chapter works without CODEX_BOOKS_DIR', $rc['status'] === 'ok' && $rc['file'] === 'ch-01-the-wall.md');
$cid = (int)$rc['id'];
check('created chapter has its heading in the DB', strpos((string)get_chapter($cid)['body'], '## Chapter 1 — The Wall') === 0);
$rc2 = create_chapter($bid, 'The Wall', '1');
check('same-name chapter gets a deduped filename', $rc2['status'] === 'ok' && $rc2['file'] === 'ch-01-the-wall-2.md');

$ri = import_chapter_md($bid, 'ch-02-the-gate.md', "## Chapter 2 — The Gate\n\nThe gate held.");
check('import_chapter_md works without CODEX_BOOKS_DIR', $ri['status'] === 'ok');

/* ---- base-hash conflict rule (the safety property, post-flip) ---- */
$base = md5(md_body_norm(get_chapter($cid)['body']));
$r = write_chapter_file($bid, $cid, "## Chapter 1 — The Wall\n\nSnow fell.", $base);
check('save with a fresh base hash lands', $r['status'] === 'ok');
check('DB body updated', strpos(get_chapter($cid)['body'], 'Snow fell') !== false);
$r = write_chapter_file($bid, $cid, "## Chapter 1 — The Wall\n\nClobber attempt.", $base);
check('save with a STALE base hash is refused', $r['status'] === 'conflict');
check('refused save did not touch the body', strpos(get_chapter($cid)['body'], 'Clobber') === false);
$cur = md5(md_body_norm(get_chapter($cid)['body']));
$r = write_chapter_file($bid, $cid, get_chapter($cid)['body'], $cur);
check('no-op save reports no changes', $r['status'] === 'ok' && $r['msg'] === 'No changes.');
$r = write_chapter_file($bid, 999999, '# x', '');
check('unknown chapter errors cleanly', $r['status'] === 'error');

/* ---- chapter revisions: every door, attributed ---- */
$revs = get_chapter_revisions($bid, $cid);
check('creates + saves recorded revisions', count($revs) >= 2);
$full = get_chapter_revision($bid, (int)$revs[0]['id']);
check('newest revision is the saved body', strpos((string)$full['body'], 'Snow fell') !== false);
check('revision attributes the user and the web door', (int)$full['saved_by'] === $alice && $full['saved_via'] === 'web');

/* ---- entry revisions + restore ---- */
save_entry($bid, 'characters', ['slug'=>'aria', 'name'=>'Aria', 'status'=>'seed', 'type'=>'Character',
    'fields'=>[['label'=>'Species','value'=>'Human']],
    'sections'=>[['h'=>'Overview','body'=>'First version.']]]);
save_entry($bid, 'characters', ['slug'=>'aria', 'name'=>'Aria', 'status'=>'canon', 'type'=>'Character',
    'fields'=>[['label'=>'Species','value'=>'Human']],
    'sections'=>[['h'=>'Overview','body'=>'Second version.']]]);
save_entry($bid, 'characters', ['slug'=>'aria', 'name'=>'Aria', 'status'=>'canon', 'type'=>'Character',
    'fields'=>[['label'=>'Species','value'=>'Human']],
    'sections'=>[['h'=>'Overview','body'=>'Second version.']]]);   // identical → deduped
$erevs = get_entry_revisions($bid, 'characters', 'aria');
check('entry saves record deduped revisions', count($erevs) === 2);
check('entry revision names the author', $erevs[0]['saved_by_name'] === 'Alice');
$old = null; foreach ($erevs as $rv) { $f = get_entry_revision($bid, (int)$rv['id']); if (strpos((string)$f['body'], 'First version') !== false) $old = $f; }
check('older entry revision holds the first body', $old !== null);
$e = md_parse_entry($old['body'], 'characters', 'aria');
save_entry($bid, 'characters', $e);
$now = get_entry($bid, 'characters', 'aria');
check('restore round-trips through md_parse_entry + save_entry',
      $now['sections'][0]['body'] === 'First version.' && $now['status'] === 'seed');
check('the restore itself became a new revision', count(get_entry_revisions($bid, 'characters', 'aria')) === 3);

delete_entry($bid, 'characters', 'aria');
$erevs = get_entry_revisions($bid, 'characters', 'aria');
check('delete records a final delete-kind revision', $erevs && $erevs[0]['kind'] === 'delete');

/* ---- export: regenerate canonical folders from the DB ---- */
save_entry($bid, 'characters', ['slug'=>'bram', 'name'=>'Bram', 'status'=>'seed', 'type'=>'Character',
    'fields'=>[], 'sections'=>[['h'=>'Overview','body'=>'A guard.']]]);
$exp = $work . '/export';
exec('DB_DRIVER=sqlite DB_PATH=' . escapeshellarg($tmp) . ' php ' . escapeshellarg(__DIR__ . '/../../bin/export.php')
     . ' --dir ' . escapeshellarg($exp) . ' 2>&1', $out, $code);
check('bin/export.php exits clean', $code === 0);
check('export wrote the chapter file', is_file($exp.'/echoes/Manuscript/ch-01-the-wall.md')
      && strpos((string)file_get_contents($exp.'/echoes/Manuscript/ch-01-the-wall.md'), 'Snow fell') !== false);
check('export wrote the entry as Codex markdown', is_file($exp.'/echoes/Codex/Characters/bram.md')
      && strpos((string)file_get_contents($exp.'/echoes/Codex/Characters/bram.md'), '# Bram') === 0);
check('export wrote book.json identity', is_file($exp.'/echoes/Codex/Meta/book.json'));
$reparsed = md_parse_entry(file_get_contents($exp.'/echoes/Codex/Characters/bram.md'), 'characters', 'bram');
check('exported entry re-parses cleanly (round-trip)', $reparsed['name'] === 'Bram' && $reparsed['sections'][0]['body'] === 'A guard.');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
