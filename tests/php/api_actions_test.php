<?php
/**
 * api_actions_test.php — fixture checks for the granular API reads/writes
 * behind the MCP surface (standalone plan, Track A3): server-side search with
 * snippets, chapter_struct (metadata + body + body_hash), and the task
 * create/patch flow api.php's task_create/task_update actions use.
 *
 *   php tests/php/api_actions_test.php
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_api_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }

migrate();
save_book(['id'=>'echo', 'folder'=>'echo', 'title'=>'Echo', 'sort_order'=>1]);
save_book(['id'=>'alien', 'folder'=>'alien', 'title'=>'Alien', 'sort_order'=>2]);

save_entry('echo', 'characters', ['slug'=>'aria', 'name'=>'Aria', 'status'=>'canon', 'type'=>'Character',
    'fields'=>[['label'=>'Species','value'=>'Human']],
    'sections'=>[['h'=>'Overview','body'=>"Aria commands the northern watchtower and distrusts the council."]]]);
save_entry('echo', 'locations', ['slug'=>'wall', 'name'=>'The Wall', 'status'=>'seed', 'type'=>'Location',
    'fields'=>[], 'sections'=>[['h'=>'Overview','body'=>'Cold. 100% granite_and_ice.']]]);
save_entry('alien', 'characters', ['slug'=>'zeta', 'name'=>'Zeta', 'status'=>'seed', 'type'=>'Character',
    'fields'=>[], 'sections'=>[['h'=>'Overview','body'=>'Zeta guards the watchtower on the far side.']]]);

q("INSERT INTO chapters (book_id,num,title,file,status,words,body) VALUES
   ('echo','01','The Wall','Manuscript/ch01.md','drafted','12','# Chapter 1\n\nSnow fell on the watchtower all night.')");
q("INSERT INTO chapters (book_id,num,title,file,status,words,body) VALUES
   ('echo','02','The Gatehouse','ch02.md','drafted','8','# Chapter 2\n\nThe gate held.')");
q("INSERT INTO chapters (book_id,num,title,file,status,words,body) VALUES
   ('echo','03','Cut scene','ch99.md','archived','5','The watchtower burns.')");
ensure_note_pages();
q("INSERT INTO note_pages (book_id,slug,title,body) VALUES ('echo','outline','Outline','Act one ends at the watchtower siege.')");

/* ---- search: kinds, scoping, snippets ---- */
$hits = search_codex(['echo'], 'watchtower');
$kinds = array_count_values(array_column($hits, 'kind'));
check('search finds the entry, chapter, and note', ($kinds['entry'] ?? 0) === 1 && ($kinds['chapter'] ?? 0) === 1 && ($kinds['note'] ?? 0) === 1);
check('archived chapters stay out of search', !in_array('99', array_column($hits, 'num'), true));
check('search respects the book scope', array_column(search_codex(['echo'], 'Zeta'), 'kind') === []);
check('multi-book search crosses books', count(search_codex(['echo', 'alien'], 'watchtower')) === 4);
$entryHit = null; foreach ($hits as $h) if ($h['kind'] === 'entry') $entryHit = $h;
check('entry hit carries db/slug/name', $entryHit && $entryHit['db'] === 'characters' && $entryHit['slug'] === 'aria');
check('entry snippet quotes the section body', strpos($entryHit['snippet'], 'northern watchtower') !== false);
$titleHits = search_codex(['echo'], 'Gatehouse');
check('title-only match returns an empty snippet', count($titleHits) === 1 && $titleHits[0]['snippet'] === '');

/* LIKE metacharacters are literals, not wildcards */
check('percent is literal in search', count(search_codex(['echo'], '100%')) === 1);
check('underscore is literal in search', count(search_codex(['echo'], 'granite_and_ice')) === 1);
check('lone underscore does not match everything', search_codex(['echo'], 'x_z') === []);

/* limit */
check('limit caps the hit list', count(search_codex(['echo'], 'the', 2)) === 2);
check('empty query returns nothing', search_codex(['echo'], '  ') === []);
check('no books returns nothing', search_codex([], 'watchtower') === []);

/* ---- chapter_struct: by id, by file, hash ---- */
$cid = (int) val("SELECT id FROM chapters WHERE book_id='echo' AND num='01'");
$c = chapter_struct('echo', $cid);
check('chapter by id returns the body', strpos($c['body'], 'Snow fell') !== false);
check('chapter body_hash matches md_body_hash', $c['body_hash'] === md_body_hash($c['body']));
$c2 = chapter_struct('echo', null, 'ch01.md');
check('chapter by bare filename matches a pathed file column', $c2 && $c2['id'] === $cid);
check('chapter lookup is book-scoped', chapter_struct('alien', $cid) === null);
check('missing chapter returns null', chapter_struct('echo', 999999) === null);
check('no id and no file returns null', chapter_struct('echo') === null);

/* ---- task create + patch merge (the api.php task_create/task_update flow) ---- */
$tid = save_task(['book_id'=>'echo', 'title'=>'Check continuity', 'body'=>'ch01 vs aria',
                  'status'=>'todo', 'for_claude'=>true, 'priority'=>'high']);
$t = get_task($tid);
check('created task is flagged for claude', (int)$t['for_claude'] === 1 && $t['priority'] === 'high');
$patch = array_intersect_key(['status'=>'done', 'result'=>'all clear', 'junk'=>'x'],
                             array_flip(['status','result','title','body']));
save_task(array_merge($t, $patch, ['id'=>(int)$t['id']]));
$t = get_task($tid);
check('patch updates status and result', $t['status'] === 'done' && $t['result'] === 'all clear');
check('patch preserves unpatched fields', $t['title'] === 'Check continuity' && (int)$t['for_claude'] === 1 && $t['priority'] === 'high');

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
