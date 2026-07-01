<?php
/** index.php — Stephen's Codex web app (front controller). */
session_start();
require_once dirname(__DIR__) . '/src/layout.php';  // src/ lives above the docroot

$CFG = cfg();

/* ---- optional shared-password gate ---- */
if (!empty($CFG['app_password'])) {
    if (isset($_POST['__login'])) {
        if (hash_equals($CFG['app_password'], (string)$_POST['password'])) { $_SESSION['auth'] = 1; header('Location: ?'); exit; }
        $login_err = 'Wrong password.';
    }
    if (empty($_SESSION['auth'])) {
        layout_head('Sign in', $CFG['accent'], $CFG['bodyType'], $CFG['density'], $_COOKIE['mode'] ?? ($CFG['mode'] ?? 'Beacon'));
        echo '<div class="content" style="max-width:360px;margin:8vh auto">';
        echo '<h1>Stephen\'s Codex</h1>';
        if (!empty($login_err)) echo '<div class="flash err">'.e($login_err).'</div>';
        echo '<form method="post"><label class="f">Password</label><input type="password" name="password" autofocus>'
           . '<input type="hidden" name="__login" value="1"><div class="toolbar"><button class="btn primary">Sign in</button></div></form>';
        echo '</div>'; layout_foot(); exit;
    }
}

/* ---- theme (persist via cookie) ---- */
foreach (['accent','bodyType','density','mode'] as $tk) {
    if (isset($_GET[$tk])) { setcookie($tk, $_GET[$tk], time()+31536000, '/'); $_COOKIE[$tk] = $_GET[$tk]; }
}
$accent   = $_COOKIE['accent']   ?? $CFG['accent'];
$bodyType = $_COOKIE['bodyType'] ?? $CFG['bodyType'];
$density  = $_COOKIE['density']  ?? $CFG['density'];
$mode     = $_COOKIE['mode']     ?? ($CFG['mode'] ?? 'Beacon');

function flash($msg, $type='ok') { $_SESSION['flash'] = [$msg, $type]; }
function redirect($params) { header('Location: ' . url($params)); exit; }

/* =========================================================== POST actions */
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $a = $_POST['action'] ?? '';
    $book = $_POST['book'] ?? '';
    if (strpos($a, 'canvas_') === 0) {   // plot board AJAX (JSON in/out, same-origin session auth)
        header('Content-Type: application/json; charset=utf-8');
        if ($a === 'canvas_add_card')    { echo json_encode(['ok'=>true,'id'=>add_canvas_card($book,(int)($_POST['x']??40),(int)($_POST['y']??40),'',$_POST['color']??'#7c8cff')]); exit; }
        if ($a === 'canvas_add_ref_card') {   // Phase 8: card bound to a Codex object
            $rt = $_POST['ref_type'] ?? ''; $ri = (string)($_POST['ref_id'] ?? '');
            if (!in_array($rt, ['entry','thread','progression','scene'], true)) { echo json_encode(['ok'=>false,'error'=>'bad ref_type']); exit; }
            $info = canvas_ref_resolve($book, $rt, $ri);
            if (!$info) { echo json_encode(['ok'=>false,'error'=>'target not found']); exit; }
            $color = $info['color'] ?? '#7c8cff';
            $id = add_canvas_card($book, (int)($_POST['x']??40), (int)($_POST['y']??40), '', $color, $rt, $ri);
            $info['href'] = url($info['p']); unset($info['p']);
            echo json_encode(['ok'=>true,'id'=>$id,'color'=>$color,'ref'=>$info]); exit;
        }
        if ($a === 'canvas_move_card')   { move_canvas_card((int)$_POST['id'],(int)$_POST['x'],(int)$_POST['y']); echo json_encode(['ok'=>true]); exit; }
        if ($a === 'canvas_text_card')   { text_canvas_card((int)$_POST['id'],$_POST['text']??''); echo json_encode(['ok'=>true]); exit; }
        if ($a === 'canvas_delete_card') { delete_canvas_card((int)$_POST['id']); echo json_encode(['ok'=>true]); exit; }
        if ($a === 'canvas_add_link')    { echo json_encode(['ok'=>true,'id'=>add_canvas_link($book,(int)$_POST['from'],(int)$_POST['to'])]); exit; }
        if ($a === 'canvas_delete_link') { delete_canvas_link((int)$_POST['id']); echo json_encode(['ok'=>true]); exit; }
        echo json_encode(['error'=>'unknown canvas action']); exit;
    }
    if ($a === 'reorder_chapters') {   // Grid drag-reorder (JSON in/out, same-origin session auth)
        header('Content-Type: application/json; charset=utf-8');
        $ids = array_map('intval', array_filter(explode(',', (string)($_POST['ids'] ?? '')), 'strlen'));
        reorder_chapters($book, $_POST['act_id'] ?? '', $ids);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'reorder_scenes') {     // planning-only scene reorder (JSON; prose untouched)
        header('Content-Type: application/json; charset=utf-8');
        $ids = array_map('intval', array_filter(explode(',', (string)($_POST['ids'] ?? '')), 'strlen'));
        reorder_scenes($book, (int)($_POST['cid'] ?? 0), $ids);
        echo json_encode(['ok'=>true]); exit;
    }
    if ($a === 'entry_save') {
        $db = $_POST['db']; $slug = $_POST['slug'];
        $e = md_parse_entry($_POST['md'], $db, $slug);
        if (!$e['slug']) $e['slug'] = $slug;
        save_entry($book, $db, $e);
        rebuild_threads($book);
        flash('Saved “' . $e['name'] . '”.');
        redirect(['p'=>'entry','book'=>$book,'db'=>$db,'slug'=>$e['slug']]);
    }
    if ($a === 'entry_new') {
        $db = $_POST['db']; $name = trim($_POST['name']);
        $slug = $_POST['slug'] ?: strtolower(preg_replace('/[^a-z0-9]+/i','-', $name));
        $slug = trim(preg_replace('/-+/', '-', $slug), '-');
        $meta = dbmeta($db, book_profile($book));
        // Profile field template prefills extra labels (fiction adds none, so its
        // stub stays byte-for-byte the legacy one). Skip the detail label / first
        // appearance, which the stub already prints.
        $skip = ['slug','status','type','first appearance', strtolower($meta['detailLabel'])];
        $tmpl = '';
        foreach (field_template_for(book_profile($book), $db) as $lbl)
            if (!in_array(strtolower($lbl), $skip, true)) $tmpl .= "- **$lbl:** \n";
        $stub = "# $name\n\n- **Slug:** $slug\n- **Status:** seed\n- **Type:** " . $meta['singular']
              . "\n- **" . $meta['detailLabel'] . ":** \n- **First appearance:** \n" . $tmpl . "\n## Overview\n\n";
        $e = md_parse_entry($stub, $db, $slug);
        save_entry($book, $db, $e);
        flash('Created “' . $name . '”. Now fill it in.');
        redirect(['p'=>'entry_edit','book'=>$book,'db'=>$db,'slug'=>$slug]);
    }
    if ($a === 'entry_delete') {
        delete_entry($book, $_POST['db'], $_POST['slug']);
        rebuild_threads($book);
        flash('Deleted entry.', 'ok');
        redirect(['p'=>'db','book'=>$book,'db'=>$_POST['db']]);
    }
    if ($a === 'meta_save') {
        save_meta_page($book, $_POST['slug'], $_POST['title'], $_POST['body']);
        flash('Meta page saved.');
        redirect(['p'=>'meta_page','book'=>$book,'slug'=>$_POST['slug']]);
    }
    if ($a === 'task_save') {
        save_task(['id'=>$_POST['id']?:null,'book_id'=>$book,'title'=>$_POST['title'],'body'=>$_POST['body'],
                   'status'=>$_POST['status']??'todo','for_claude'=>isset($_POST['for_claude']),
                   'target_db'=>$_POST['target_db']??'','target_slug'=>$_POST['target_slug']??'',
                   'priority'=>$_POST['priority']??'med','due'=>$_POST['due']??'today']);
        flash('Task saved.');
        redirect(['p'=>'tasks','book'=>$book]);
    }
    if ($a === 'task_status') { $t=get_task($_POST['id']); if($t){ $t['status']=$_POST['status']; save_task($t);} redirect(['p'=>'tasks','book'=>$book]); }
    if ($a === 'task_delete') { delete_task($_POST['id']); flash('Task deleted.'); redirect(['p'=>'tasks','book'=>$book]); }
    if ($a === 'task_cycle_due') {
        $t = get_task($_POST['id']);
        if ($t) { $o=['today','tomorrow','week','someday']; $i=array_search($t['due']??'someday',$o,true); q("UPDATE tasks SET due=? WHERE id=?",[$o[(($i===false?-1:$i)+1)%count($o)],$t['id']]); }
        redirect(['p'=>'tasks','book'=>$book]);
    }
    if ($a === 'task_cycle_priority') {
        $t = get_task($_POST['id']);
        if ($t) { $o=['low','med','high']; $i=array_search($t['priority']??'med',$o,true); q("UPDATE tasks SET priority=? WHERE id=?",[$o[(($i===false?-1:$i)+1)%count($o)],$t['id']]); }
        redirect(['p'=>'tasks','book'=>$book]);
    }
    if ($a === 'step_add')    { $txt=trim($_POST['text']??''); if($txt!=='') add_task_step((int)$_POST['task_id'],$txt); redirect(['p'=>'tasks','book'=>$book]); }
    if ($a === 'step_toggle') { toggle_task_step((int)$_POST['id']); redirect(['p'=>'tasks','book'=>$book]); }
    if ($a === 'step_delete') { delete_task_step((int)$_POST['id']); redirect(['p'=>'tasks','book'=>$book]); }
    if ($a === 'thread_status') { set_thread_status($_POST['id'], $_POST['status']); redirect(['p'=>'threads','book'=>$book]); }
    if ($a === 'log_add') {
        add_writing_log(['book_id'=>$book,'log_date'=>$_POST['log_date']?:date('Y-m-d'),
            'words_added'=>(int)$_POST['words_added'],'total_words'=>(int)$_POST['total_words'],
            'chapters'=>$_POST['chapters']??'','minutes'=>(int)$_POST['minutes'],'mood'=>$_POST['mood']??'',
            'note'=>$_POST['note']??'','source'=>'manual']);
        flash('Logged.');
        redirect(['p'=>'log','book'=>$book]);
    }
    if ($a === 'chapter_status') {
        set_chapter_status($_POST['id'], $_POST['status']);
        if (($_POST['return'] ?? '') === 'chapter') redirect(['p'=>'chapter','book'=>$book,'id'=>$_POST['id']]);
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'chapter_archive') {
        $c = get_chapter($_POST['id']);
        if ($c && $c['book_id'] === $book) { set_chapter_status($_POST['id'], 'archived'); flash('Chapter archived.'); }
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'chapter_restore') {
        $c = get_chapter($_POST['id']);
        if ($c && $c['book_id'] === $book) { set_chapter_status($_POST['id'], 'drafted'); flash('Chapter restored.'); }
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'chapter_delete') {
        $c = get_chapter($_POST['id']);
        if ($c && $c['book_id'] === $book) { delete_chapter($_POST['id']); flash('Chapter deleted.'); }
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'scene_label') {
        set_scene_label((int)($_POST['id'] ?? 0), $book, $_POST['label'] ?? '');
        redirect(['p'=>'manuscript','book'=>$book,'view'=>'grid']);
    }
    if ($a === 'progression_when') {             // Phase 6: app-only timeline placement for one beat
        set_progression_when($book, (int)($_POST['id'] ?? 0), $_POST['when_label'] ?? '', $_POST['when_order'] ?? '');
        redirect(['p'=> (($_POST['from'] ?? '')==='timeline' ? 'timeline' : 'progressions'),'book'=>$book]);
    }
    if ($a === 'reindex_mentions') {             // Phase 5: rebuild the mentions index for this book
        $n = index_mentions($book);
        flash("Reindexed mentions ($n found).");
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'chapter_save') {                 // Phase 9: write chapter prose back to disk + DB
        $r = write_chapter_file($book, (int)($_POST['cid'] ?? 0), $_POST['md'] ?? '', $_POST['base'] ?? '');
        flash($r['msg'], $r['status'] === 'ok' ? 'ok' : 'err');
        if ($r['status'] === 'conflict' || $r['status'] === 'error')
            redirect(['p'=>'chapter_edit','book'=>$book,'id'=>$_POST['cid']]);
        redirect(['p'=>'chapter','book'=>$book,'id'=>$_POST['cid']]);
    }
    if ($a === 'chapter_autosave') {   // Phase 15: debounced draft autosave (JSON; never touches the canonical .md)
        header('Content-Type: application/json; charset=utf-8');
        $cid = (int)($_POST['cid'] ?? 0);
        $c = get_chapter($cid);
        if (!$c || $c['book_id'] !== $book) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        $md = (string)($_POST['md'] ?? '');
        $base = (string)($_POST['base'] ?? '');
        // Early warning: the DB body moved under the editor (a sync). We still keep the
        // draft (never lose keystrokes) but flag it so the UI can warn before Save.
        $conflict = ($base !== '' && $base !== md_body_hash($c['body']));
        save_chapter_autosave_draft($book, $cid, $c['file'], $md);
        echo json_encode(['ok'=>true, 'saved_at'=>date('c'), 'conflict'=>$conflict, 'words'=>md_word_count($md)]); exit;
    }
    if ($a === 'chapter_revision_get') {   // Phase 15: fetch one snapshot's body for "load into editor" (JSON)
        header('Content-Type: application/json; charset=utf-8');
        $rev = get_chapter_revision($book, (int)($_POST['id'] ?? 0));
        if (!$rev) { echo json_encode(['ok'=>false,'error'=>'not found']); exit; }
        echo json_encode(['ok'=>true, 'body'=>$rev['body'], 'created_at'=>$rev['created_at'], 'kind'=>$rev['kind']]); exit;
    }
    if ($a === 'chapter_revision_discard_draft') {   // Phase 15: drop the recovered autosave draft
        discard_chapter_autosave($book, (int)($_POST['cid'] ?? 0));
        flash('Recovered draft discarded.');
        redirect(['p'=>'chapter_edit','book'=>$book,'id'=>$_POST['cid']]);
    }
    if ($a === 'dictionary_add') {   // Phase 15: add a term (AJAX from the editor, or form from the dictionary page)
        $ok = add_dictionary_term($book, $_POST['term'] ?? '');
        if (!empty($_POST['ajax'])) { header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>$ok, 'term'=>trim((string)($_POST['term'] ?? ''))]); exit; }
        flash($ok ? 'Added “'.trim((string)$_POST['term']).'” to the dictionary.' : 'Could not add that term (empty or already present).', $ok ? 'ok' : 'err');
        redirect(['p'=>'dictionary','book'=>$book]);
    }
    if ($a === 'dictionary_remove') {
        remove_dictionary_term($book, (int)($_POST['id'] ?? 0));
        flash('Removed from dictionary.');
        redirect(['p'=>'dictionary','book'=>$book]);
    }
    if ($a === 'dictionary_import_codex') {
        $n = import_codex_dictionary_terms($book);
        flash("Imported $n Codex name".($n === 1 ? '' : 's')." into the dictionary.");
        redirect(['p'=>'dictionary','book'=>$book]);
    }
    if ($a === 'book_profile') {
        set_book_profile($book, $_POST['profile'] ?? 'fiction');
        flash('Book profile set to “'.profile_label($_POST['profile'] ?? 'fiction').'”.');
        redirect(['p'=>'book','book'=>$book]);
    }
    if ($a === 'chapter_new') {   // Function 1 — seed a new Manuscript/*.md + DB row
        $r = create_chapter($book, $_POST['title'] ?? '', $_POST['num'] ?? '', $_POST['act_id'] ?? '');
        flash($r['msg'], $r['status'] === 'ok' ? 'ok' : 'err');
        if ($r['status'] === 'ok' && !empty($r['id'])) redirect(['p'=>'chapter_edit','book'=>$book,'id'=>$r['id']]);
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'chapter_import') {   // Function 3a — paste Markdown or upload one/more .md
        $done = 0; $errs = [];
        if (!empty($_FILES['files']['name'][0])) {
            foreach ($_FILES['files']['name'] as $i => $nm) {
                if (($_FILES['files']['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) continue;
                if (!is_uploaded_file($_FILES['files']['tmp_name'][$i])) continue;
                $r = import_chapter_md($book, $nm, file_get_contents($_FILES['files']['tmp_name'][$i]));
                if ($r['status'] === 'ok') $done++; else $errs[] = $nm.': '.$r['msg'];
            }
        }
        $paste = trim($_POST['md'] ?? '');
        if ($paste !== '') {
            $r = import_chapter_md($book, $_POST['filename'] ?? '', $paste);
            if ($r['status'] === 'ok') $done++; else $errs[] = $r['msg'];
        }
        if ($done === 0 && !$errs) flash('Nothing to import — paste Markdown or choose .md files.', 'err');
        elseif ($errs) flash('Imported '.$done.'; problems: '.implode(' · ', $errs), $done ? 'ok' : 'err');
        else flash('Imported '.$done.' chapter'.($done === 1 ? '' : 's').'.');
        redirect(['p'=>'manuscript','book'=>$book]);
    }
    if ($a === 'book_new') {   // Function 2 — create folder skeleton + book row
        $r = create_book([
            'title'=>$_POST['title'] ?? '', 'series'=>$_POST['series'] ?? '', 'num'=>$_POST['num'] ?? '',
            'logline'=>$_POST['logline'] ?? '', 'genre'=>$_POST['genre'] ?? '', 'dot'=>$_POST['dot'] ?? '#4A4391',
            'profile'=>$_POST['profile'] ?? 'fiction',
        ]);
        flash($r['msg'], $r['status'] === 'ok' ? 'ok' : 'err');
        if ($r['status'] === 'ok' && !empty($r['id'])) redirect(['p'=>'book','book'=>$r['id']]);
        redirect(['p'=>'library']);
    }
    if ($a === 'book_import') {   // Function 3b — upload a zipped book folder
        if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            flash('Choose a .zip file to import.', 'err');
            redirect(['p'=>'library']);
        }
        $r = import_book_zip($_FILES['file']['tmp_name'], ['profile'=>$_POST['profile'] ?? 'fiction', 'title'=>$_POST['title'] ?? '']);
        flash($r['msg'], $r['status'] === 'ok' ? 'ok' : 'err');
        if ($r['status'] === 'ok' && !empty($r['id'])) redirect(['p'=>'book','book'=>$r['id']]);
        redirect(['p'=>'library']);
    }
    if ($a === 'source_save') {
        $sid = save_source($book, [
            'id'=>$_POST['id']??'', 'cite_key'=>$_POST['cite_key']??'', 'type'=>$_POST['type']??'web',
            'author'=>$_POST['author']??'', 'title'=>$_POST['title']??'', 'year'=>$_POST['year']??'',
            'publisher'=>$_POST['publisher']??'', 'url'=>$_POST['url']??'', 'accessed'=>$_POST['accessed']??'',
            'locator'=>$_POST['locator']??'', 'note'=>$_POST['note']??'',
        ]);
        reconcile_citations($book);   // a new/edited source can resolve orphan cite keys
        $src = get_source($book, $sid);
        flash('Saved source “'.($src ? format_citation($src) : ($_POST['cite_key']??'')).'”.');
        redirect(['p'=>'references','book'=>$book]);
    }
    if ($a === 'source_delete') {
        delete_source($book, (int)($_POST['id']??0));
        reconcile_citations($book);
        flash('Source deleted; any [^cite:…] tokens now show as unresolved.');
        redirect(['p'=>'references','book'=>$book]);
    }
    if ($a === 'act_add')   { add_act($book, $_POST['title'] ?? ''); redirect(['p'=>'manuscript','book'=>$book,'view'=>'grid']); }
    if ($a === 'act_rename'){ rename_act((int)($_POST['id'] ?? 0), $book, $_POST['title'] ?? '', $_POST['subtitle'] ?? ''); redirect(['p'=>'manuscript','book'=>$book,'view'=>'grid']); }
    if ($a === 'act_delete'){ delete_act((int)($_POST['id'] ?? 0), $book); flash('Act deleted; its chapters are now unassigned.'); redirect(['p'=>'manuscript','book'=>$book,'view'=>'grid']); }
    if ($a === 'act_move')  { move_act((int)($_POST['id'] ?? 0), $book, ($_POST['dir'] ?? 'up') === 'down' ? 'down' : 'up'); redirect(['p'=>'manuscript','book'=>$book,'view'=>'grid']); }
    if ($a === 'chapter_act'){ set_chapter_act((int)($_POST['cid'] ?? 0), $book, $_POST['act_id'] ?? ''); redirect(['p'=>'manuscript','book'=>$book,'view'=>'grid']); }
    if ($a === 'chapter_note_add') {
        $note = trim($_POST['note'] ?? '');
        $quote = trim($_POST['quote'] ?? '');
        if ($note !== '' || $quote !== '') { add_chapter_note($book, $_POST['file'], $quote, $note); flash('Note added.'); }
        else flash('Nothing to save — add a note.', 'err');
        redirect(['p'=>'chapter','book'=>$book,'id'=>$_POST['cid']]);
    }
    if ($a === 'chapter_note_status') {
        set_chapter_note_status($_POST['id'], $_POST['status']);
        redirect(['p'=>'chapter','book'=>$book,'id'=>$_POST['cid']]);
    }
    if ($a === 'chapter_note_delete') {
        delete_chapter_note($_POST['id']);
        flash('Note deleted.');
        redirect(['p'=>'chapter','book'=>$book,'id'=>$_POST['cid']]);
    }
    if ($a === 'chapter_note_to_task') {
        $n = get_chapter_note($_POST['id']);
        if ($n) {
            $ch = one("SELECT title FROM chapters WHERE book_id=? AND file=?", [$book, $n['chapter_file']]);
            $where = $ch ? $ch['title'] : $n['chapter_file'];
            $snippet = mb_substr(trim($n['note']) !== '' ? $n['note'] : $n['quote'], 0, 60);
            $body = "Chapter: $where\n";
            if (trim($n['quote']) !== '') $body .= "\nFlagged passage:\n> " . str_replace("\n", "\n> ", $n['quote']) . "\n";
            if (trim($n['note'])  !== '') $body .= "\nChange note:\n" . $n['note'] . "\n";
            $tid = save_task(['id'=>null,'book_id'=>$book,'title'=>"Revise “$where”: $snippet",'body'=>$body,
                              'status'=>'todo','for_claude'=>true,'target_db'=>'','target_slug'=>'']);
            set_chapter_note_task($n['id'], $tid);
            flash('Sent to Tasks (flagged for Claude).');
        }
        redirect(['p'=>'chapter','book'=>$book,'id'=>$_POST['cid']]);
    }
    if ($a === 'capture_add') {
        $txt = trim($_POST['text'] ?? '');
        if ($txt !== '') { add_capture($_POST['book'] ?? '', $txt); flash('Captured.'); }
        $params = ['p' => $_POST['return_p'] ?: 'overview'];
        if (!empty($_POST['return_book'])) $params['book'] = $_POST['return_book'];
        redirect($params);
    }
    if ($a === 'capture_triage') {
        $c = get_capture($_POST['id']);
        if ($c) {
            $bid = $c['book_id'] ?: ($_POST['book'] ?? '');
            if (!$bid) { $bks = get_books(); $bid = $bks[0]['id'] ?? ''; }
            if ($bid) {
                save_task(['id'=>null,'book_id'=>$bid,'title'=>$c['text'],'body'=>'','status'=>'todo','for_claude'=>false]);
                set_capture_status($c['id'], 'triaged');
                flash('Sent to Tasks.');
            } else flash('No book to file this under yet.', 'err');
        }
        $rp=['p'=>$_POST['return_p']?:'overview']; if(!empty($_POST['return_book']))$rp['book']=$_POST['return_book'];
        redirect($rp);
    }
    if ($a === 'capture_dismiss') {
        set_capture_status($_POST['id'], 'dismissed');
        $rp=['p'=>$_POST['return_p']?:'overview']; if(!empty($_POST['return_book']))$rp['book']=$_POST['return_book'];
        redirect($rp);
    }
    if ($a === 'sprint_log') {
        $bid = $_POST['book'] ?? '';
        if (!$bid) { $bks = get_books(); $bid = $bks[0]['id'] ?? ''; }
        if ($bid) {
            $words = max(0, (int)($_POST['words_added'] ?? 0));
            $mins  = max(0, (int)($_POST['minutes'] ?? 0));
            $total = last_total_words($bid) + $words;
            add_writing_log(['book_id'=>$bid,'log_date'=>date('Y-m-d'),'words_added'=>$words,'total_words'=>$total,
                'chapters'=>'','minutes'=>$mins,'mood'=>$_POST['mood']??'','note'=>$_POST['note']??'','source'=>'sprint']);
            flash('Sprint logged'.($mins?' · '.$mins.' min':'').($words?' · +'.number_format($words).' words':'').'.');
        } else flash('No book to log against yet.', 'err');
        $params = ['p' => $_POST['return_p'] ?: 'overview'];
        if (!empty($_POST['return_book'])) $params['book'] = $_POST['return_book'];
        redirect($params);
    }
    if ($a === 'vision_add') {
        $caption = trim($_POST['caption'] ?? '');
        $url = trim($_POST['image_url'] ?? '');
        if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
            $dir = __DIR__ . '/assets/vision';
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png','gif','webp','avif'], true) && is_dir($dir) && is_writable($dir)) {
                $fn = 'v' . time() . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], "$dir/$fn")) $url = 'assets/vision/' . $fn;
                else flash('Upload failed.', 'err');
            } else flash('Could not store the upload (folder not writable or unsupported type). Paste an image URL instead.', 'err');
        }
        if ($url !== '') { add_vision($book, $caption, $url); flash('Added to the mood board.'); }
        else flash('Add an image URL or choose a file.', 'err');
        redirect(['p'=>'vision','book'=>$book]);
    }
    if ($a === 'vision_delete') { delete_vision((int)$_POST['id']); flash('Removed.'); redirect(['p'=>'vision','book'=>$book]); }
    if ($a === 'sync_import') {
        $snap = json_decode(file_get_contents($_FILES['file']['tmp_name']), true);
        if ($snap && isset($snap['books'])) { import_snapshot($snap); flash('Imported snapshot.'); }
        else flash('Could not read that file as a snapshot.', 'err');
        redirect(['p'=>'sync']);
    }
    redirect(['p'=>'library']);
}

/* =========================================================== GET routing */
$p = $_GET['p'] ?? 'overview';
$book_id = $_GET['book'] ?? null;
$book = $book_id ? get_book($book_id) : null;
if (!$book && !in_array($p, ['library','sync','overview'], true)) { $bks = get_books(); $book = $bks[0] ?? null; $book_id = $book['id'] ?? null; }
$GLOBALS['__link_book'] = $book_id;

$titles = ['overview'=>'Overview','library'=>'Library','book'=>$book['title']??'Book','db'=>'Database','entry'=>'Entry',
           'manuscript'=>'Manuscript','chapter'=>'Chapter','chapter_edit'=>'Edit chapter','diagnostics'=>'Diagnostics','progressions'=>'Progressions','timeline'=>'Timeline',
           'threads'=>($book ? threads_label($book['profile'] ?? 'fiction')['title'] : 'Open threads'),
           'references'=>'References','exercises'=>'Exercises',
           'tasks'=>'Tasks','log'=>'Writing log','meta'=>'Meta','notes'=>'Notes','sync'=>'Sync','dictionary'=>'Dictionary',
           'plot'=>'Plot board','vision'=>'Mood board'];
layout_head($titles[$p] ?? 'Codex', $accent, $bodyType, $density, $mode);
echo '<div class="app">';
render_sidebar($book, $p, $_GET['db'] ?? null);
echo '<div class="main">';

/* breadcrumbs */
$crumbs = [];
if ($book) $crumbs[] = [$book['title'], url(['p'=>'book','book'=>$book['id']])];
render_topbar($book, $crumbs, $accent, $bodyType, $density, $mode);

$wideView = ($p === 'timeline') || ($p === 'manuscript' && ($_GET['view'] ?? '') === 'grid');  // wide boards fill the viewport
echo '<div class="content'.($wideView ? ' wide' : '').'">';
if (!empty($_SESSION['flash'])) { [$m,$t]=$_SESSION['flash']; echo '<div class="flash '.($t==='err'?'err':'').'">'.e($m).'</div>'; unset($_SESSION['flash']); }

/* helper renderers */
function db_chip($db){ $m=dbmeta($db); return '<span class="chip" style="background:'.$m['hue'].'">'.$m['letter'].'</span>'; }
function status_select($book_id, $c, $return){
    $opts = '';
    foreach (['outline','drafted','revised'] as $s)
        $opts .= '<option value="'.$s.'"'.($c['status']===$s?' selected':'').'>'.$s.'</option>';
    return '<form method="post" class="statusform"><input type="hidden" name="action" value="chapter_status">'
        .'<input type="hidden" name="book" value="'.e($book_id).'"><input type="hidden" name="id" value="'.(int)$c['id'].'">'
        .'<input type="hidden" name="return" value="'.e($return).'">'
        .'<select name="status" class="pill '.e($c['status']).'" onchange="this.form.submit()">'.$opts.'</select></form>';
}
function chapter_action_form($book_id, $id, $action, $label, $confirm = false){
    $oc = $confirm ? ' onsubmit="return confirm(\'Permanently delete this chapter from the app? Your folder file is not touched.\')"' : '';
    return '<form method="post" style="display:inline-block;margin:0"'.$oc.'>'
        .'<input type="hidden" name="action" value="'.e($action).'">'
        .'<input type="hidden" name="book" value="'.e($book_id).'">'
        .'<input type="hidden" name="id" value="'.(int)$id.'">'
        .'<button class="btn sm" type="submit">'.e($label).'</button></form>';
}

switch ($p) {

case 'overview':
    $books = get_books();
    $inbox = get_captures(null, 'inbox');
    $tWords=$tCh=$tEntries=$tThreads=$tTasks=0;
    foreach ($books as $b) { $tWords+=$b['wordCount']; $tCh+=$b['chapterCount']; $tEntries+=$b['entryCount']; $tThreads+=$b['threadCount']; $tTasks+=$b['taskCount']; }
    $h = (int)date('G');
    $greet = $h<5?'Late night':($h<12?'Good morning':($h<18?'Good afternoon':'Good evening'));
    $streak = writing_streak();

    echo '<div class="ov-strip"><div><div class="ov-hello">'.e($greet).', Stephen</div><div class="ov-date">'.e(date('l, M j')).'</div></div><div class="ov-aggs">';
    foreach ([[number_format($tWords),'words'],[$tCh,'chapters'],[$tEntries,'entries'],[$tThreads,'open threads'],[$tTasks,'open tasks'],[$streak,'day streak']] as $ag)
        echo '<div class="ov-agg"><b>'.$ag[0].'</b><span>'.$ag[1].'</span></div>';
    echo '</div></div>';

    echo '<div class="ov-inbox"><div class="ov-inbox-h"><span>Brain dump</span><span class="count">'.count($inbox).'</span></div>';
    if (!$inbox) echo '<div class="empty" style="padding:8px 0;margin:0">Inbox zero. Capture a thought up top anytime.</div>';
    foreach ($inbox as $cap) {
        echo '<div class="ov-cap"><span class="ov-cap-dot"></span><span class="ov-cap-t">'.e($cap['text']).'</span>';
        echo '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="capture_triage"><input type="hidden" name="id" value="'.(int)$cap['id'].'"><button class="btn sm">&rarr; Task</button></form> ';
        echo '<form method="post" style="display:inline;margin:0" onsubmit="return confirm(\'Dismiss this thought?\')"><input type="hidden" name="action" value="capture_dismiss"><input type="hidden" name="id" value="'.(int)$cap['id'].'"><button class="btn sm ghost" title="Dismiss">&times;</button></form>';
        echo '</div>';
    }
    echo '</div>';

    echo '<h2 style="font-size:15px;margin-top:26px">Your books</h2>';
    if (!$books) { echo '<p class="empty">No books yet. Run a sync to bring them in.</p>'; break; }
    echo '<div class="ov-grid">';
    foreach ($books as $b) {
        $target = (int)preg_replace('/[^0-9]/','', (string)($b['word_target'] ?? ''));
        $pct = $target>0 ? min(100, (int)round($b['wordCount']/$target*100)) : null;
        $top = get_tasks($b['id'], ['status'=>'todo']);
        $topTask = $top[0]['title'] ?? null;
        $prog = get_progressions($b['id']); $lastProg = $prog ? end($prog) : null;
        echo '<div class="ov-book">';
        echo '<div class="ov-book-h"><span class="dot" style="background:'.e($b['dot']).'"></span><a class="ov-book-title" href="'.url(['p'=>'book','book'=>$b['id']]).'">'.e($b['title']).'</a>'.status_pill($b['status']).'</div>';
        if ($pct !== null) {
            echo '<div class="ov-prog"><div class="ov-prog-bar"><i style="width:'.$pct.'%;background:'.e($b['dot']).'"></i></div>'
               . '<div class="ov-prog-l"><span>'.number_format($b['wordCount']).' / '.number_format($target).' words</span><span>'.$pct.'%</span></div></div>';
        } else {
            echo '<div class="ov-prog-l" style="margin-top:12px"><span>'.number_format($b['wordCount']).' words</span><span class="muted">no target set</span></div>';
        }
        echo '<div class="ov-stats"><span><b>'.$b['chapterCount'].'</b> ch</span><span><b>'.$b['entryCount'].'</b> entries</span><span><b>'.$b['threadCount'].'</b> threads</span><span><b>'.$b['taskCount'].'</b> tasks</span></div>';
        echo '<div class="ov-next"><div class="ov-next-l">Up next</div>';
        if ($topTask) echo '<a class="ov-next-t" href="'.url(['p'=>'tasks','book'=>$b['id']]).'">'.e($topTask).'</a>';
        else echo '<div class="ov-next-t muted">All clear — nothing open.</div>';
        echo '</div>';
        echo '<div class="ov-book-act"><button type="button" class="btn primary sm" onclick="openSprint(\''.e($b['id']).'\', \''.e($b['title']).'\')">&#9654; Start sprint</button></div>';
        if ($lastProg) echo '<div class="ov-prog-note"><span class="mono">'.e($lastProg['chapter']).'</span> '.inline_md($lastProg['what']).'</div>';
        echo '</div>';
    }
    echo '</div>';
    break;

case 'library':
    $books = get_books();
    echo '<div class="pagehead"><div><h1>The library</h1><p class="desc">Three books, one structure. Each book carries the same Codex: five entry databases plus manuscript, progressions, and open threads.</p></div></div>';
    echo '<div class="booktiles">';
    foreach ($books as $b) {
        echo '<a class="booktile" href="'.url(['p'=>'book','book'=>$b['id']]).'">';
        echo '<span class="bigdot" style="background:'.e($b['dot']).'"></span><div style="flex:1">';
        echo '<div class="bt-series">'.e($b['series']).' · Book '.e($b['num']).'</div>';
        echo '<h2>'.e($b['title']).' '.status_pill($b['status']).'</h2>';
        echo '<div class="bt-log">'.e($b['logline'] ?: '—').'</div>';
        echo '<div class="bt-stats"><span><b>'.$b['entryCount'].'</b> entries</span><span><b>'.$b['chapterCount'].'</b> chapters</span><span><b>'.number_format($b['wordCount']).'</b> words</span><span><b>'.$b['threadCount'].'</b> threads</span></div>';
        echo '</div></a>';
    }
    echo '</div>';

    // ---- New book / Import book (Markdown-canonical; gated on CODEX_BOOKS_DIR) ----
    if (books_dir_set()) {
        $profOpts = '';
        foreach (profile_ids() as $pid) $profOpts .= '<option value="'.e($pid).'">'.e(profile_label($pid)).'</option>';
        echo '<div class="toolbar" style="margin-top:22px">';
        echo '<details class="notewrap" style="flex:1;min-width:280px"><summary style="cursor:pointer;font-weight:600">+ New book</summary>';
        echo '<form method="post" style="margin-top:12px"><input type="hidden" name="action" value="book_new">';
        echo '<label class="f">Title</label><input type="text" name="title" required placeholder="Untitled book">';
        echo '<div class="formrow"><div><label class="f">Series (optional)</label><input type="text" name="series"></div>'
           . '<div><label class="f">Book #</label><input type="text" name="num" placeholder="1"></div></div>';
        echo '<label class="f">Logline (optional)</label><input type="text" name="logline">';
        echo '<div class="formrow"><div><label class="f">Profile</label><select name="profile">'.$profOpts.'</select></div>'
           . '<div><label class="f">Dot colour</label><input type="color" name="dot" value="#4A4391" style="width:64px;padding:2px;height:36px"></div></div>';
        echo '<div class="toolbar"><button class="btn primary">Create book</button></div>';
        echo '<p class="muted" style="font-size:12px">Creates the folder skeleton on disk (Manuscript/ + Codex/) and the book row. Markdown stays canonical.</p></form></details>';
        echo '<details class="notewrap" style="flex:1;min-width:280px"><summary style="cursor:pointer;font-weight:600">Import book (.zip)</summary>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-top:12px"><input type="hidden" name="action" value="book_import">';
        echo '<label class="f">Zipped book folder</label><input type="file" name="file" accept=".zip" required>';
        echo '<label class="f" style="margin-top:8px">Profile</label><select name="profile">'.$profOpts.'</select>';
        echo '<div class="toolbar"><button class="btn">Import .zip</button></div>';
        echo '<p class="muted" style="font-size:12px">Upload a zip containing a book folder with <span class="mono">Codex/</span> and <span class="mono">Manuscript/</span>. It unzips under your books root (a fresh folder — never over an existing book) and reflects into the app.</p></form></details>';
        echo '</div>';
    } else {
        echo '<p class="muted" style="margin-top:22px">Set <span class="mono">CODEX_BOOKS_DIR</span> to enable creating and importing books in the app (Markdown stays the source of truth).</p>';
    }
    break;

case 'book':
    $bprofile = $book['profile'] ?? 'fiction';
    echo '<div class="pagehead"><div><h1>'.e($book['title']).'</h1>';
    echo '<p class="desc"><strong>'.e($book['series']).' · Book '.e($book['num']).'</strong> — '.e($book['logline'] ?: 'No logline yet.').'</p></div></div>';
    echo '<div class="bt-stats" style="margin:6px 0 4px"><span><b>'.number_format($book['wordCount']).'</b> words</span><span><b>'.$book['chapterCount'].'</b> chapters</span><span><b>'.$book['entryCount'].'</b> entries</span><span><b>'.$book['threadCount'].'</b> open threads</span></div>';
    // Profile picker — selects the codex taxonomy (databases, field templates,
    // manuscript band labels, diagnostics) for this book. Fiction is the default.
    echo '<form method="post" class="profileform" style="margin:10px 0 2px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
       . '<input type="hidden" name="action" value="book_profile"><input type="hidden" name="book" value="'.e($book['id']).'">'
       . '<label class="f" style="margin:0">Book profile</label><select name="profile" onchange="this.form.submit()">';
    foreach (profile_ids() as $pid)
        echo '<option value="'.e($pid).'"'.($pid===$bprofile?' selected':'').'>'.e(profile_label($pid)).'</option>';
    echo '</select><span class="muted" style="font-size:12px">'.e(profile_desc($bprofile)).'</span>'
       . '<noscript><button class="btn sm">Set</button></noscript></form>';
    echo '<h2 style="font-size:15px;margin-top:26px">Databases in this book</h2><div class="cards">';
    foreach (db_keys_for($bprofile) as $k) { $m=dbmeta($k,$bprofile); $c=(int)val("SELECT COUNT(*) FROM entries WHERE book_id=? AND db_key=?",[$book['id'],$k]);
        echo '<a class="card" href="'.url(['p'=>'db','book'=>$book['id'],'db'=>$k]).'"><div class="cmeta">'.db_chip($k).'<span class="ctitle" style="margin:0">'.$m['title'].'</span><span class="count" style="margin-left:auto">'.$c.'</span></div><div class="clog">'.e($m['desc']).'</div></a>';
    }
    echo '</div>';
    echo '<h2 style="font-size:15px;margin-top:26px">Manuscript &amp; tracking</h2><div class="cards">';
    $progN = (int)val("SELECT COUNT(*) FROM progressions WHERE book_id=?", [$book['id']]);
    $logN  = (int)val("SELECT COUNT(*) FROM writing_log WHERE book_id=?", [$book['id']]);
    $metaN = count(get_meta($book['id']));
    $notesN = count(get_notes($book['id']));
    $sections = [
        ['manuscript','Manuscript','#8A6A3E',$book['chapterCount'],'Read chapters, set status, track word counts.'],
        ['progressions','Progressions','#C9933A',$progN,'Confirmed story movement, chapter by chapter.'],
        ['threads',threads_label($bprofile)['title'],'#C25A6E',$book['threadCount'],threads_label($bprofile)['desc']],
        ['tasks','Tasks','#5b54b8',$book['taskCount'],'To-dos — flag any for Claude to run.'],
        ['log','Writing log','#4F7A52',$logN,'Writing sessions and word deltas.'],
        ['meta','Meta','#7A715F',$metaN,'Workspace rules and notes for this book.'],
        ['notes','Notes','#6E8A6A',$notesN,'Planning docs — outline, beats, research.'],
    ];
    foreach ($sections as $sx) {
        echo '<a class="card" href="'.url(['p'=>$sx[0],'book'=>$book['id']]).'"><div class="cmeta">'
           . '<span class="sq" style="width:16px;height:16px;border-radius:5px;background:'.$sx[2].'"></span>'
           . '<span class="ctitle" style="margin:0">'.$sx[1].'</span>'
           . '<span class="count" style="margin-left:auto">'.$sx[3].'</span></div>'
           . '<div class="clog">'.e($sx[4]).'</div></a>';
    }
    echo '</div>';
    $prog = get_progressions($book['id']);
    if ($prog) { echo '<h2 style="font-size:15px;margin-top:26px">Latest progressions</h2><table class="grid"><tr><th>Chapter</th><th>What happened</th></tr>';
        foreach (array_slice($prog, -8) as $pr) echo '<tr><td class="mono">'.e($pr['chapter']).'</td><td>'.inline_md($pr['what']).'</td></tr>';
        echo '</table>'; }
    break;

case 'references':
    // Phase 12: the References view — sources for the book + a per-source "cited in"
    // list, plus an add/edit form. Cite a passage in prose with [^cite:key]; the
    // key resolves against a source's cite_key.
    $refs = get_references($book['id']);
    $orphans = get_orphan_citations($book['id']);
    $edit = isset($_GET['edit']) ? get_source($book['id'], (int)$_GET['edit']) : null;
    $prefillKey = $edit ? $edit['cite_key'] : ($_GET['key'] ?? '');
    echo '<div class="pagehead"><div><h1>References</h1><p class="desc">Sources behind this book\'s claims. Cite a passage in your prose with <code>[^cite:key]</code> — the key resolves to a source here, and the folder file stays the source of truth.</p></div></div>';

    if ($orphans) {
        echo '<div class="flash err" style="text-align:left">Cited in prose but not yet defined as a source: ';
        $bits = [];
        foreach ($orphans as $o) $bits[] = '<a href="'.url(['p'=>'references','book'=>$book['id'],'key'=>$o['cite_key']]).'#addsource"><code>'.e($o['cite_key']).'</code></a> <span class="muted">('.(int)$o['chapters'].' ch)</span>';
        echo implode(' · ', $bits).'. Add each below to resolve it.</div>';
    }

    if ($refs) {
        echo '<table class="grid"><tr><th>Reference</th><th>Type</th><th>Key</th><th>Cited in</th><th></th></tr>';
        foreach ($refs as $s) {
            echo '<tr id="src-'.e($s['cite_key']).'"><td><strong>'.e(format_citation($s)).'</strong>'
               . ($s['url']!==''?' <a class="muted mono" href="'.e($s['url']).'" target="_blank" rel="noopener">link</a>':'')
               . ($s['note']!==''?'<div class="clog">'.inline_md($s['note']).'</div>':'').'</td>';
            echo '<td><span class="pill">'.e($s['type']).'</span></td><td class="mono">'.e($s['cite_key']).'</td>';
            $apps = get_source_appearances($book['id'], $s['id']);
            echo '<td>';
            if ($apps) { $links=[]; foreach ($apps as $ap) { $lab = $ap['chapter_id'] ? url(['p'=>'chapter','book'=>$book['id'],'id'=>$ap['chapter_id']]) : ''; $t = 'Ch '.e($ap['num']).' ×'.(int)$ap['hits']; $links[] = $lab ? '<a href="'.$lab.'">'.$t.'</a>' : $t; } echo implode(', ', $links); }
            else echo '<span class="muted">—</span>';
            echo '</td>';
            echo '<td class="mono"><a class="btn sm" href="'.url(['p'=>'references','book'=>$book['id'],'edit'=>$s['id']]).'#addsource">Edit</a> '
               . '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this source?\')"><input type="hidden" name="action" value="source_delete"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="id" value="'.(int)$s['id'].'"><button class="btn sm danger">×</button></form></td></tr>';
        }
        echo '</table>';
    } else echo '<p class="empty">No sources yet. Add one below, or author them as <span class="mono">Codex/Sources/&lt;key&gt;.md</span> and sync.</p>';

    // add / edit form
    echo '<div class="notewrap" id="addsource" style="margin-top:22px"><form method="post"><input type="hidden" name="action" value="source_save"><input type="hidden" name="book" value="'.e($book['id']).'">';
    if ($edit) echo '<input type="hidden" name="id" value="'.(int)$edit['id'].'">';
    echo '<h2 style="font-size:15px;margin:0 0 8px">'.($edit?'Edit source':'Add a source').'</h2>';
    echo '<div class="formrow"><div><label class="f">Cite key</label><input type="text" name="cite_key" placeholder="keynes-1936" value="'.e($edit['cite_key'] ?? $prefillKey).'"></div>';
    echo '<div><label class="f">Type</label><select name="type">';
    foreach (source_types() as $t) echo '<option value="'.$t.'"'.((($edit['type'] ?? 'web')===$t)?' selected':'').'>'.ucfirst($t).'</option>';
    echo '</select></div></div>';
    echo '<label class="f">Author</label><input type="text" name="author" value="'.e($edit['author'] ?? '').'">';
    echo '<label class="f">Title</label><input type="text" name="title" value="'.e($edit['title'] ?? '').'">';
    echo '<div class="formrow"><div><label class="f">Year</label><input type="text" name="year" value="'.e($edit['year'] ?? '').'"></div>';
    echo '<div><label class="f">Publisher / journal</label><input type="text" name="publisher" value="'.e($edit['publisher'] ?? '').'"></div></div>';
    echo '<label class="f">URL / DOI</label><input type="text" name="url" value="'.e($edit['url'] ?? '').'">';
    echo '<div class="formrow"><div><label class="f">Accessed</label><input type="text" name="accessed" placeholder="2026-07-01" value="'.e($edit['accessed'] ?? '').'"></div>';
    echo '<div><label class="f">Locator (page / timestamp)</label><input type="text" name="locator" value="'.e($edit['locator'] ?? '').'"></div></div>';
    echo '<label class="f">Note (optional)</label><textarea name="note" style="min-height:56px">'.e($edit['note'] ?? '').'</textarea>';
    echo '<div class="toolbar"><button class="btn primary">'.($edit?'Save changes':'Add source').'</button>';
    if ($edit) echo '<a class="btn" href="'.url(['p'=>'references','book'=>$book['id']]).'">Cancel</a>';
    echo '</div></form></div>';
    break;

case 'exercises':
    // Phase 13: the self-help Workbook — takeaways + exercises per chapter, all
    // derived from prose. index_exercises() backfills chapters synced before P13.
    index_exercises($book['id']);
    $wb = get_workbook($book['id']);
    $exN = count_exercises($book['id']);
    echo '<div class="pagehead"><div><h1>Exercises &amp; workbook</h1><p class="desc">'.$exN.' exercise'.($exN==1?'':'s').' across the book, plus each chapter\'s takeaways. Authored in your prose as <code>## Exercise</code> blocks and <code>## Takeaways</code> lists — the folder stays the source of truth.</p></div></div>';
    if (!$wb) { echo '<p class="empty">No exercises or takeaways found yet. Add a <span class="mono">## Exercise</span> section or a <span class="mono">## Takeaways</span> list to a chapter, then sync.</p>'; break; }
    foreach ($wb as $w) {
        $ch = $w['chapter'];
        echo '<div class="entrybody" style="margin-bottom:18px"><h2 style="margin-top:0"><a href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$ch['id']]).'"><span class="mono">'.e($ch['num']).'</span> '.e($ch['title']).'</a></h2>';
        if ($w['takeaways']) {
            echo '<div class="sp-label">Takeaways</div><ul>';
            foreach ($w['takeaways'] as $t) echo '<li>'.inline_md($t).'</li>';
            echo '</ul>';
        }
        foreach ($w['exercises'] as $ex) {
            echo '<div class="exercise" style="border:1px solid var(--accent-soft,#ddd);border-radius:8px;padding:10px 12px;margin:10px 0">';
            echo '<div class="cmeta"><strong>'.e($ex['title']).'</strong>';
            if ($ex['type'] !== '') echo ' <span class="pill">'.e($ex['type']).'</span>';
            if ($ex['est_time'] !== '') echo ' <span class="muted mono">'.e($ex['est_time']).'</span>';
            if ($ex['operationalizes'] !== '') { $op=one("SELECT db_key,name FROM entries WHERE book_id=? AND slug=?",[$book['id'],$ex['operationalizes']]);
                echo ' <span class="muted">→ '.($op?'<a href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$op['db_key'],'slug'=>$ex['operationalizes']]).'">'.e($op['name']).'</a>':e($ex['operationalizes'])).'</span>'; }
            echo '</div>';
            if (trim((string)$ex['prompt']) !== '') echo '<div class="clog">'.md_to_html($ex['prompt'], $book['id']).'</div>';
            echo '</div>';
        }
        echo '</div>';
    }
    break;

case 'db':
    $db = $_GET['db']; $m = dbmeta($db, $book['profile'] ?? 'fiction');
    $rows = get_entries($book['id'], $db);
    echo '<div class="pagehead">'.db_chip($db).'<div><h1>'.$m['title'].'</h1><p class="desc">'.e($m['desc']).'</p></div></div>';
    echo '<div class="toolbar"><a class="btn primary sm" href="'.url(['p'=>'entry_new','book'=>$book['id'],'db'=>$db]).'">+ New '.strtolower($m['singular']).'</a></div>';
    if (!$rows) { echo '<p class="empty">No entries yet.</p>'; break; }
    echo '<table class="grid"><tr><th>Name</th><th>Status</th><th>'.e($m['detailLabel']).'</th><th>First app.</th><th>Links</th></tr>';
    foreach ($rows as $r) {
        $links = (int)val("SELECT COUNT(*) FROM entry_relations er JOIN entries e ON e.id=er.entry_id WHERE e.id=?",[$r['id']]);
        echo '<tr><td><a href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$db,'slug'=>$r['slug']]).'"><strong>'.e($r['name']).'</strong></a></td>';
        echo '<td>'.status_pill($r['status']).'</td><td>'.e($r['detail']).'</td><td class="muted">'.e($r['first_app']).'</td><td class="mono">'.$links.'</td></tr>';
    }
    echo '</table>';
    break;

case 'entry':
    $db = $_GET['db']; $slug = $_GET['slug'];
    $e = get_entry($book['id'], $db, $slug);
    if (!$e) { echo '<p class="empty">Entry not found.</p>'; break; }
    echo '<div class="pagehead">'.db_chip($db).'<div><h1>'.e($e['name']).' '.status_pill($e['status']).'</h1>';
    $emeta = dbmeta($db, $book['profile'] ?? 'fiction');
    echo '<p class="desc"><span class="tag" style="color:'.$emeta['hue'].';background:'.$emeta['hue'].'18">'.e($e['type']).'</span> '.($e['firstApp']?'· first appearance '.e($e['firstApp']):'').'</p></div></div>';
    echo '<div class="toolbar"><a class="btn sm" href="'.url(['p'=>'entry_edit','book'=>$book['id'],'db'=>$db,'slug'=>$slug]).'">Edit</a>'
       . '<a class="btn sm" href="'.url(['p'=>'entry_md','book'=>$book['id'],'db'=>$db,'slug'=>$slug]).'">View .md</a></div>';
    echo '<div class="entrybody">';
    if ($e['fields']) { echo '<div class="fieldtable">';
        foreach ($e['fields'] as $f) echo '<div class="row"><div class="lbl">'.e($f['label']).'</div><div class="v">'.inline_md($f['value']).'</div></div>';
        echo '</div>'; }
    if ($e['related']) { echo '<h2>Related</h2><div class="relchips">';
        foreach ($e['related'] as $r) { $row=one("SELECT db_key,name FROM entries WHERE book_id=? AND slug=?",[$book['id'],$r]);
            if ($row) echo '<a class="relchip" href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$row['db_key'],'slug'=>$r]).'">'.e($row['name']).'</a>';
            else echo '<span class="relchip">'.e($r).'</span>'; }
        echo '</div>'; }
    foreach ($e['sections'] as $s) { echo '<h2>'.e($s['h']).'</h2>'.md_to_html($s['body'], $book['id']); }
    echo '</div>';
    // --- Arc (Phase 6): this entity's progression beats, in timeline order ---
    $arc = get_entity_arc($book['id'], $slug);
    if ($arc) {
        echo '<div class="entrybody"><h2>Arc <span class="muted mono">'.count($arc).'</span></h2>'
           . '<p class="desc">Confirmed beats touching '.e($e['name']).', in timeline order. From the <a href="'.url(['p'=>'timeline','book'=>$book['id']]).'">Timeline</a>.</p>';
        echo '<ol class="arc">';
        foreach ($arc as $pr) {
            $when = '<span class="arc-when mono">'.e($pr['_bucket']).'</span>'.($pr['_when'] ? ' <span class="pill" title="manual timeline order">pinned</span>' : '');
            echo '<li><span class="tag" style="color:#6B6253;background:#6B625318">'.e($pr['type']).'</span> '.$when.'<div class="arc-what">'.inline_md($pr['what']).'</div></li>';
        }
        echo '</ol></div>';
    }
    // --- Appearances (Phase 5): where this entry is mentioned ---
    $apps = get_appearances($book['id'], $slug);
    if ($apps) {
        echo '<div class="entrybody"><h2>Appearances</h2><div class="appears">';
        foreach ($apps as $ap) {
            $cnt = (int)$ap['total'];
            $badge = ($ap['kind'] === 'link') ? ' <span class="pill canon" title="explicit [[link]]">link</span>' : '';
            if ($ap['src_type'] === 'chapter') {
                $file = explode('#', $ap['src_ref'])[0];
                $cid = val("SELECT id FROM chapters WHERE book_id=? AND file=?", [$book['id'], $file]);
                $href = $cid ? url(['p'=>'chapter','book'=>$book['id'],'id'=>$cid]) : '';
            } else {
                $row = one("SELECT db_key FROM entries WHERE book_id=? AND slug=?", [$book['id'], $ap['src_ref']]);
                $href = $row ? url(['p'=>'entry','book'=>$book['id'],'db'=>$row['db_key'],'slug'=>$ap['src_ref']]) : '';
            }
            $inner = e($ap['src_label']).' <span class="muted mono">×'.$cnt.'</span>'.$badge;
            echo $href ? '<a class="appear" href="'.$href.'">'.$inner.'</a>' : '<span class="appear">'.$inner.'</span>';
        }
        echo '</div></div>';
    }
    break;

case 'entry_md':
    $e = get_entry($book['id'], $_GET['db'], $_GET['slug']);
    echo '<div class="pagehead"><div><h1>'.e($e['name']).' · source</h1></div></div>';
    echo '<div class="codeblock">'.e(md_render_entry($e)).'</div>';
    echo '<div class="toolbar"><a class="btn" href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$_GET['db'],'slug'=>$_GET['slug']]).'">Back</a></div>';
    break;

case 'entry_edit':
    $db = $_GET['db']; $slug = $_GET['slug'];
    $e = get_entry($book['id'], $db, $slug);
    if (!$e) { echo '<p class="empty">Entry not found.</p>'; break; }
    $md = md_render_entry($e);                       // full markdown — prefilled as the safe fallback
    // sections-only markdown for the prose editor (metadata is the form below)
    $secMd = '';
    foreach (($e['sections'] ?? []) as $sec) {
        $secMd .= '## ' . $sec['h'] . "\n\n";
        if (!empty($sec['body'])) $secMd .= $sec['body'] . "\n\n";
    }
    $secMd = preg_replace('#</script#i', '<\\/script', rtrim($secMd) . "\n");
    $relRaw = $e['relatedRaw'] ?? '';
    if ($relRaw === '' && !empty($e['related'])) $relRaw = implode(', ', array_map(function($r){return '[[' . $r . ']]';}, $e['related']));

    echo '<div class="pagehead">'.db_chip($db).'<div><h1>Edit · '.e($e['name']).'</h1><p class="desc">Edit metadata in the fields; write prose in the editor. Saving assembles the Codex markdown and re-parses it; the next sync writes it back to your folder.</p></div></div>';
    echo '<form method="post" id="entry-form"><input type="hidden" name="action" value="entry_save"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="db" value="'.e($db).'"><input type="hidden" name="slug" value="'.e($slug).'">';
    // hidden field actually submitted; prefilled with current md so a no-JS submit is a safe no-op
    echo '<textarea id="md-out" name="md" style="display:none">'.e($md).'</textarea>';
    // --- structured metadata form ---
    echo '<div class="codex-meta">';
    echo '<div class="row"><label>Name</label><input id="f-name" style="flex:1" value="'.e($e['name']).'"></div>';
    echo '<div class="row"><label>Slug</label><input id="f-slug" value="'.e($e['slug']).'">';
    echo '<label>Status</label><select id="f-status">';
    foreach (STATUS_VALUES as $sv) echo '<option value="'.e($sv).'"'.(($e['status'] ?? 'seed')===$sv?' selected':'').'>'.e($sv).'</option>';
    echo '</select>';
    echo '<label>Type</label><input id="f-type" value="'.e($e['type'] ?? '').'"></div>';
    echo '<div id="meta-fields">';
    foreach (($e['fields'] ?? []) as $f)
        echo '<div class="field-row"><input class="fk" value="'.e($f['label']).'"><input class="fv" value="'.e($f['value']).'"><button type="button" class="btn sm rm-field" title="Remove">×</button></div>';
    echo '</div>';
    echo '<div class="row"><button type="button" id="add-field" class="btn sm">+ Field</button></div>';
    echo '<div class="row"><label>Related</label><input id="f-related" style="flex:1" placeholder="[[slug]], [[other-slug]]" value="'.e($relRaw).'"></div>';
    echo '</div>';
    // --- prose editor mount + initial section markdown ---
    echo '<div id="codex-prose"></div>';
    echo '<script type="text/plain" id="codex-initial-md">'.$secMd.'</script>';
    // --- live mention targets (Phase 5): names/aliases the editor highlights + click-links ---
    if (function_exists('build_mention_targets')) {
        $mt = [];
        foreach (build_mention_targets($book['id']) as $t) {
            if ($t['slug'] === $slug) continue;   // never self-mention the entry being edited
            $mt[] = ['phrase' => $t['phrase'], 'slug' => $t['slug']];
        }
        // JSON inside <script> is a raw-text element: HTML-escaping would corrupt it.
        // Instead let json_encode escape "/" (so </script> can't close the tag) and
        // neutralize "<" as < (covers </script> and <!-- breakout). JSON.parse restores both.
        $mtJson = str_replace('<', '\\u003c', json_encode($mt, JSON_UNESCAPED_UNICODE));
        echo '<script type="application/json" id="codex-mention-targets">'.$mtJson.'</script>';
    }
    echo '<div class="toolbar"><button class="btn primary">Save</button><a class="btn" href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$db,'slug'=>$slug]).'">Cancel</a></div></form>';
    echo '<form method="post" onsubmit="return confirm(\'Delete this entry?\')" style="margin-top:18px"><input type="hidden" name="action" value="entry_delete"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="db" value="'.e($db).'"><input type="hidden" name="slug" value="'.e($slug).'"><button class="btn danger sm">Delete entry</button></form>';
    $ev = @filemtime(__DIR__ . '/assets/app/editor.js') ?: time();   // bust cache on rebuild
    echo '<link rel="stylesheet" href="assets/app/editor.css?v='.$ev.'"><script src="assets/app/editor.js?v='.$ev.'" defer></script>';
    break;

case 'entry_new':
    $db = $_GET['db']; $m = dbmeta($db, $book['profile'] ?? 'fiction');
    echo '<div class="pagehead">'.db_chip($db).'<div><h1>New '.strtolower($m['singular']).'</h1></div></div>';
    echo '<form method="post" style="max-width:520px"><input type="hidden" name="action" value="entry_new"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="db" value="'.e($db).'">';
    echo '<label class="f">Name</label><input type="text" name="name" required autofocus>';
    echo '<label class="f">Slug (optional — auto from name)</label><input type="text" name="slug" placeholder="kebab-case">';
    echo '<div class="toolbar"><button class="btn primary">Create</button><a class="btn" href="'.url(['p'=>'db','book'=>$book['id'],'db'=>$db]).'">Cancel</a></div></form>';
    break;

case 'manuscript':
    $ch = get_chapters($book['id']);
    $arch = get_archived_chapters($book['id']);
    $view = (($_GET['view'] ?? '') === 'grid') ? 'grid' : 'list';
    echo '<div class="pagehead"><div><h1>Manuscript</h1><p class="desc">'.count($ch).' chapters · '.number_format($book['wordCount']).' words. Click a chapter to read it; set its status below. Prose is authored in your folders and synced in.</p></div></div>';
    echo '<div class="toolbar"><a class="btn sm'.($view==='list'?' primary':'').'" href="'.url(['p'=>'manuscript','book'=>$book['id']]).'">List</a>'
       . '<a class="btn sm'.($view==='grid'?' primary':'').'" href="'.url(['p'=>'manuscript','book'=>$book['id'],'view'=>'grid']).'">Grid</a>'
       . '<form method="post" style="display:inline;margin-left:8px"><input type="hidden" name="action" value="reindex_mentions"><input type="hidden" name="book" value="'.e($book['id']).'"><button class="btn sm" title="Rebuild the name/alias mention index for this book">Reindex mentions</button></form>'
       . '<a class="btn sm" style="margin-left:8px" href="'.url(['p'=>'dictionary','book'=>$book['id']]).'" title="Custom spell-check dictionary">Dictionary</a></div>';

    // ---- New chapter / Import chapter(s) (Markdown-canonical; gated on CODEX_BOOKS_DIR) ----
    if (books_dir_set()) {
        $mb = bands_for($book['profile'] ?? 'fiction');   // "Chapter" copy; act/part-aware assign label
        $acts = get_acts($book['id']);
        $bh = '<input type="hidden" name="book" value="'.e($book['id']).'">';
        echo '<div class="toolbar">';
        echo '<details class="notewrap" style="flex:1;min-width:280px"><summary style="cursor:pointer;font-weight:600">+ New chapter</summary>';
        echo '<form method="post" style="margin-top:12px"><input type="hidden" name="action" value="chapter_new">'.$bh;
        echo '<label class="f">Title</label><input type="text" name="title" required placeholder="Chapter title">';
        echo '<div class="formrow"><div><label class="f">Number (optional)</label><input type="text" name="num" placeholder="auto"></div>';
        if ($acts) {
            echo '<div><label class="f">'.e($mb['actSingular']).' (optional)</label><select name="act_id"><option value="">— none —</option>';
            foreach ($acts as $a) echo '<option value="'.(int)$a['id'].'">'.e($a['title']).'</option>';
            echo '</select></div>';
        }
        echo '</div><div class="toolbar"><button class="btn primary">Create &amp; edit</button></div>';
        echo '<p class="muted" style="font-size:12px">Seeds <span class="mono">Manuscript/ch-NN-title.md</span> with a heading, then opens the editor. Never overwrites an existing file.</p></form></details>';
        echo '<details class="notewrap" style="flex:1;min-width:280px"><summary style="cursor:pointer;font-weight:600">Import chapter(s)</summary>';
        echo '<form method="post" enctype="multipart/form-data" style="margin-top:12px"><input type="hidden" name="action" value="chapter_import">'.$bh;
        echo '<label class="f">Upload .md file(s)</label><input type="file" name="files[]" accept=".md,.markdown,.txt" multiple>';
        echo '<label class="f" style="margin-top:8px">…or paste Markdown</label><textarea name="md" style="min-height:120px" placeholder="## Chapter 1 — Title&#10;&#10;Prose…"></textarea>';
        echo '<label class="f">Filename for pasted chapter (optional)</label><input type="text" name="filename" placeholder="derived from the heading">';
        echo '<div class="toolbar"><button class="btn">Import</button></div>';
        echo '<p class="muted" style="font-size:12px">Each file (and any paste) becomes a chapter under <span class="mono">Manuscript/</span>. Filenames are deduped — imports never overwrite.</p></form></details>';
        echo '</div>';
    }

    if (!$ch && !$arch) { echo '<p class="empty">No chapters synced yet.'.(books_dir_set() ? ' Create one above.' : '').'</p>'; break; }
    if ($view === 'grid' && $ch) {
        // Act bands → chapter columns → scene cards (read-only). Acts have no CRUD
        // yet, so chapters with no act_id fall into a single "Chapters" band.
        $bandLbl = bands_for($book['profile'] ?? 'fiction');   // Act/Acts vs Part/Parts per profile
        $actS = $bandLbl['actSingular']; $actP = $bandLbl['actPlural'];
        $acts = get_acts($book['id']);
        $byAct = [];
        foreach ($ch as $c) { $k = ($c['act_id'] !== null && $c['act_id'] !== '') ? (int)$c['act_id'] : 0; $byAct[$k][] = $c; }
        // within each band, honour the manual drag order (grid_seq); chapters never
        // dragged (grid_seq NULL) fall back to chapter-number order.
        foreach ($byAct as $k => &$list) {
            usort($list, function ($x, $y) {
                $hx = ($x['grid_seq'] !== null && $x['grid_seq'] !== '');
                $hy = ($y['grid_seq'] !== null && $y['grid_seq'] !== '');
                if ($hx && $hy) return (int)$x['grid_seq'] <=> (int)$y['grid_seq'];
                if ($hx !== $hy) return $hx ? -1 : 1;
                return [intval($x['num']), $x['num'], $x['file']] <=> [intval($y['num']), $y['num'], $y['file']];
            });
        }
        unset($list);
        $bands = [];
        foreach ($acts as $a) if (!empty($byAct[(int)$a['id']])) $bands[] = [$a, $byAct[(int)$a['id']]];
        if (!empty($byAct[0])) $bands[] = [null, $byAct[0]];
        // ---- Acts manager: create / rename / reorder / delete ----
        $bh = '<input type="hidden" name="book" value="'.e($book['id']).'">';
        echo '<div class="acts-mgr"><div class="acts-mgr-h">'.e($actP).'</div>';
        $nacts = count($acts);
        foreach ($acts as $i => $a) {
            echo '<div class="act-row">';
            echo '<form method="post" class="act-edit"><input type="hidden" name="action" value="act_rename">'.$bh.'<input type="hidden" name="id" value="'.(int)$a['id'].'">'
               . '<input class="act-title-in" name="title" value="'.e($a['title']).'" placeholder="'.e($actS).' title">'
               . '<input class="act-sub-in" name="subtitle" value="'.e($a['subtitle']).'" placeholder="Subtitle (optional)">'
               . '<button class="btn sm">Save</button></form>';
            if ($i > 0)          echo '<form method="post"><input type="hidden" name="action" value="act_move">'.$bh.'<input type="hidden" name="id" value="'.(int)$a['id'].'"><input type="hidden" name="dir" value="up"><button class="btn sm" title="Move up">↑</button></form>';
            if ($i < $nacts - 1) echo '<form method="post"><input type="hidden" name="action" value="act_move">'.$bh.'<input type="hidden" name="id" value="'.(int)$a['id'].'"><input type="hidden" name="dir" value="down"><button class="btn sm" title="Move down">↓</button></form>';
            echo '<form method="post" onsubmit="return confirm(\'Delete this act? Its chapters become unassigned (prose untouched).\')"><input type="hidden" name="action" value="act_delete">'.$bh.'<input type="hidden" name="id" value="'.(int)$a['id'].'"><button class="btn sm danger">Delete</button></form>';
            echo '</div>';
        }
        echo '<form method="post" class="act-add"><input type="hidden" name="action" value="act_add">'.$bh.'<input name="title" placeholder="New '.e(strtolower($actS)).' title" required><button class="btn sm primary">+ Add '.e(strtolower($actS)).'</button></form>';
        echo '</div>';
        echo '<p class="grid-hint">Drag a chapter\'s ⠿ handle to reorder it or move it between '.e(strtolower($actP)).'. Drag a scene\'s ⠿ handle to reorder scenes within a chapter — <strong>planning only</strong>; your prose isn\'t changed.</p>';
        echo '<div class="mgrid">';
        foreach ($bands as $bd) {
            list($a, $cols) = $bd;
            $bandHead = $a ? e($a['title']).($a['subtitle'] !== '' ? ' <span class="mband-sub">'.e($a['subtitle']).'</span>' : '') : ($acts ? 'Unassigned' : 'Chapters');
            $actId = $a ? (int)$a['id'] : 0;
            echo '<div class="mband"><div class="mband-h">'.$bandHead.'</div><div class="mcols" data-act="'.$actId.'">';
            foreach ($cols as $c) {
                $scenes = get_scenes($c['id']);
                $counted = 0;
                foreach ($scenes as $s2) if (!scene_label_excluded($s2['label'])) $counted += (int)$s2['word_count'];
                echo '<div class="mcol" data-cid="'.(int)$c['id'].'">'
                   . '<span class="mcol-handle" draggable="true" title="Drag to reorder / move between '.e(strtolower($actP)).'">⠿</span>'
                   . '<a class="mcol-h" href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$c['id']]).'">'
                   . '<span class="mcol-t"><span class="mono">'.e($c['num']).'</span> '.e($c['title']).'</span>'
                   . '<span class="mcol-wc">'.number_format($counted).' words</span></a>';
                if ($acts) {
                    echo '<form method="post" class="mcol-act"><input type="hidden" name="action" value="chapter_act">'.$bh.'<input type="hidden" name="cid" value="'.(int)$c['id'].'">'
                       . '<select name="act_id" onchange="this.form.submit()"><option value="">— no '.e(strtolower($actS)).' —</option>';
                    foreach ($acts as $aopt)
                        echo '<option value="'.(int)$aopt['id'].'"'.(((int)($c['act_id'] ?? 0)) === (int)$aopt['id'] ? ' selected' : '').'>'.e($aopt['title']).'</option>';
                    echo '</select></form>';
                }
                echo '<div class="scards" data-cid="'.(int)$c['id'].'">';
                if (!$scenes) echo '<div class="scard empty">No scenes</div>';
                foreach ($scenes as $sc) {
                    $excl = scene_label_excluded($sc['label']);
                    $st = $sc['title'] !== '' ? e($sc['title']) : 'Scene '.(int)$sc['ordinal'];
                    $wc = number_format((int)$sc['word_count']).' words';
                    echo '<div class="scard'.($excl ? ' excluded' : '').'" data-sid="'.(int)$sc['id'].'">';
                    echo '<span class="scard-handle" draggable="true" title="Drag to reorder scenes (planning only — your prose is not changed)">⠿</span>';
                    echo '<div class="scard-t">'.$st.'</div>';
                    echo '<div class="scard-m">'.($excl ? '<s>'.$wc.'</s> · not counted' : $wc).'</div>';
                    // editable label (auto-submits)
                    echo '<form method="post" class="scard-lbl"><input type="hidden" name="action" value="scene_label">'
                       . '<input type="hidden" name="book" value="'.e($book['id']).'">'
                       . '<input type="hidden" name="id" value="'.(int)$sc['id'].'">'
                       . '<select name="label" onchange="this.form.submit()"><option value="">— label —</option>';
                    foreach (SCENE_LABELS as $lbl)
                        echo '<option value="'.e($lbl).'"'.($sc['label'] === $lbl ? ' selected' : '').'>'.e($lbl).'</option>';
                    echo '</select></form>';
                    if (!empty($sc['note'])) echo '<div class="scard-note" title="Author note (from a comment in the prose)">'.nl2br(e($sc['note'])).'</div>';
                    echo '</div>';
                }
                echo '</div>';   // .scards
                echo '</div>';   // .mcol
            }
            echo '</div></div>';
        }
        echo '</div>';
        $bid = json_encode($book['id']);
        echo <<<JS
<script>
(function(){
  var grid=document.querySelector('.mgrid'); if(!grid) return;
  var BOOK=$bid, dragged=null, mode=null, origin=null, srcAct=null;
  function afterEl(container,sel,coord,axis){
    var els=[].slice.call(container.querySelectorAll(sel+':not(.dragging)'));
    var best=null,bestOff=-Infinity;
    els.forEach(function(el){
      var b=el.getBoundingClientRect();
      var off=(axis==='x')?coord-(b.left+b.width/2):coord-(b.top+b.height/2);
      if(off<0 && off>bestOff){bestOff=off;best=el;}
    });
    return best;
  }
  grid.addEventListener('dragstart',function(e){
    var sh=e.target.closest('.scard-handle');
    if(sh){ dragged=sh.closest('.scard'); if(!dragged) return; mode='scene'; origin=dragged.closest('.scards'); }
    else { var ch=e.target.closest('.mcol-handle'); if(!ch) return; dragged=ch.closest('.mcol'); if(!dragged) return; mode='chapter'; origin=dragged.closest('.mcols'); srcAct=origin.dataset.act; }
    e.dataTransfer.effectAllowed='move';
    try{e.dataTransfer.setData('text/plain','1');}catch(_){}
    try{e.dataTransfer.setDragImage(dragged,14,14);}catch(_){}
    setTimeout(function(){dragged&&dragged.classList.add('dragging');},0);
  });
  grid.addEventListener('dragend',function(){ if(dragged) dragged.classList.remove('dragging'); dragged=null; mode=null; origin=null; });
  grid.addEventListener('dragover',function(e){
    if(!dragged) return;
    if(mode==='chapter'){
      var cols=e.target.closest('.mcols'); if(!cols) return;
      e.preventDefault(); e.dataTransfer.dropEffect='move';
      var after=afterEl(cols,'.mcol',e.clientX,'x');
      if(after==null) cols.appendChild(dragged); else cols.insertBefore(dragged,after);
    } else {
      var sc=e.target.closest('.scards'); if(sc!==origin) return;  // scenes stay in their chapter
      e.preventDefault(); e.dataTransfer.dropEffect='move';
      var a2=afterEl(origin,'.scard',e.clientY,'y');
      if(a2==null) origin.appendChild(dragged); else origin.insertBefore(dragged,a2);
    }
  });
  grid.addEventListener('drop',function(e){
    if(!dragged) return; e.preventDefault();
    if(mode==='chapter'){
      var cols=dragged.closest('.mcols'); if(!cols) return;
      var act=cols.dataset.act;
      var ids=[].slice.call(cols.querySelectorAll('.mcol')).map(function(c){return c.dataset.cid;}).join(',');
      post('reorder_chapters','act_id='+encodeURIComponent(act)+'&ids='+encodeURIComponent(ids),(srcAct!==act));
    } else {
      if(!origin) return;
      var cid=origin.dataset.cid;
      var sids=[].slice.call(origin.querySelectorAll('.scard[data-sid]')).map(function(s){return s.dataset.sid;}).join(',');
      post('reorder_scenes','cid='+encodeURIComponent(cid)+'&ids='+encodeURIComponent(sids),false);
    }
  });
  function post(action,extra,reload){
    var body='action='+action+'&book='+encodeURIComponent(BOOK)+'&'+extra;
    fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body,credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(){ if(reload) window.location.reload(); })
      .catch(function(){ window.location.reload(); });
  }
})();
</script>
JS;
        break;
    }
    if ($ch) {
        echo '<table class="grid"><tr><th>#</th><th>Title</th><th>Status</th><th>Notes</th><th>Words</th><th></th></tr>';
        foreach ($ch as $c) {
            $nOpen = count_chapter_notes($book['id'], $c['file'], 'open');
            echo '<tr><td class="mono">'.e($c['num']).'</td>';
            echo '<td><a href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$c['id']]).'"><strong>'.e($c['title']).'</strong></a></td>';
            echo '<td>'.status_select($book['id'], $c, 'manuscript').'</td>';
            echo '<td>'.($nOpen ? '<a href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$c['id']]).'"><span class="pill doing">'.$nOpen.'</span></a>' : '<span class="muted">—</span>').'</td>';
            echo '<td class="mono">'.e($c['words']).'</td>';
            echo '<td>'.chapter_action_form($book['id'], $c['id'], 'chapter_archive', 'Archive').'</td></tr>';
        }
        echo '</table>';
    }
    if ($arch) {
        echo '<h2 style="font-size:15px;margin-top:26px">Archived <span class="muted">('.count($arch).')</span></h2>';
        echo '<p class="desc">Chapters no longer in the manuscript folder (e.g. moved to <span class="mono">Manuscript/_archive/</span>) or archived here. <strong>Restore</strong> brings one back into the manuscript; <strong>Delete</strong> removes it from the app permanently (your folder files are never touched).</p>';
        echo '<table class="grid"><tr><th>#</th><th>Title</th><th>Words</th><th></th></tr>';
        foreach ($arch as $c) {
            echo '<tr class="muted"><td class="mono">'.e($c['num']).'</td>';
            echo '<td><a href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$c['id']]).'">'.e($c['title']).'</a></td>';
            echo '<td class="mono">'.e($c['words']).'</td>';
            echo '<td>'.chapter_action_form($book['id'], $c['id'], 'chapter_restore', 'Restore')
                .' '.chapter_action_form($book['id'], $c['id'], 'chapter_delete', 'Delete', true).'</td></tr>';
        }
        echo '</table>';
    }
    break;

case 'chapter':
    $c = get_chapter($_GET['id']);
    if (!$c || $c['book_id'] !== $book['id']) { echo '<p class="empty">Chapter not found.</p>'; break; }
    $prev = one("SELECT id,title FROM chapters WHERE book_id=? AND (num+0)<(SELECT (num+0) FROM chapters WHERE id=?) ORDER BY (num+0) DESC LIMIT 1", [$book['id'],$c['id']]);
    $next = one("SELECT id,title FROM chapters WHERE book_id=? AND (num+0)>(SELECT (num+0) FROM chapters WHERE id=?) ORDER BY (num+0) ASC LIMIT 1", [$book['id'],$c['id']]);
    echo '<div class="pagehead"><div><h1>'.e($c['title']).'</h1><p class="desc">Chapter '.e($c['num']).' · '.e($c['words']).' words</p></div></div>';
    echo '<div class="toolbar"><a class="btn sm" href="'.url(['p'=>'manuscript','book'=>$book['id']]).'">← All chapters</a>';
    if (cfg()['books_dir'] ?? '') echo '<a class="btn sm" href="'.url(['p'=>'chapter_edit','book'=>$book['id'],'id'=>$c['id']]).'">Edit prose</a>';
    echo '<a class="btn sm" href="'.url(['p'=>'diagnostics','book'=>$book['id'],'id'=>$c['id']]).'">Diagnostics</a>';
    if (trim((string)$c['body']) !== '') echo '<button type="button" class="btn sm primary" id="smartToggle">✦ Smart editing</button>';
    echo '<span style="margin-left:6px">Status:</span> '.status_select($book['id'], $c, 'chapter');
    echo '</div>';
    /* ---- Phase 9c: slide-in Smart-editing panel (renders the cached diagnostics) ---- */
    if (trim((string)$c['body']) !== '') try {
        $dg = get_chapter_diagnostics($book['id'], $c['id']);
        if ($dg) {
            $dd = $dg['data'];
            // normalize shape so an older cached analysis can never break the panel
            $dd['usage']    = array_merge(['overused'=>[],'repeated_phrases'=>[],'words'=>0], is_array($dd['usage'] ?? null) ? $dd['usage'] : []);
            $dd['patterns'] = is_array($dd['patterns'] ?? null) ? $dd['patterns'] : [];
            $dd['dialogue'] = array_merge(['quotes'=>0,'bookisms'=>[],'tagged'=>0,'tags_per_speaker'=>[],'adverb_examples'=>[]], is_array($dd['dialogue'] ?? null) ? $dd['dialogue'] : []);
            $uFlags = count($dd['usage']['overused']) + count($dd['usage']['repeated_phrases']);
            $pFlags = count($dd['patterns']);
            $diaFlags = count($dd['dialogue']['bookisms']) + count($dd['dialogue']['adverb_examples']);
            $bsev = function($n){ return $n>=15?'high':($n>=8?'med':'low'); };
            $maxc = 1; foreach ($dd['usage']['overused'] as $o) $maxc = max($maxc,(int)$o['count']);
            $maxt = 1; foreach ($dd['dialogue']['tags_per_speaker'] as $t) $maxt = max($maxt,(int)$t['count']);
            echo '<aside class="smartpanel" id="smartPanel" hidden><div class="sp-head"><span>✦ Smart editing</span><button type="button" class="sp-close" id="smartClose" title="Close">×</button></div>';
            echo '<div class="sp-sub">Diagnostics for <em>'.e($c['title']).'</em> — review prompts, not verdicts.</div>';
            // Usage frequency
            echo '<section class="sp-group"><div class="sp-gh">Usage frequency<span class="sp-flags">'.$uFlags.' flags</span></div>';
            if ($dd['usage']['overused']) { echo '<div class="sp-label">Overused words</div>';
                foreach ($dd['usage']['overused'] as $o) { $w = max(6,(int)round($o['count']/$maxc*100)); echo '<div class="sp-bar"><span class="sp-bar-k">'.e($o['phrase']).'</span><span class="sp-bar-track"><i class="sev-'.$bsev((int)$o['count']).'" style="width:'.$w.'%"></i></span><span class="sp-bar-n">'.(int)$o['count'].'</span></div>'; }
            }
            if ($dd['usage']['repeated_phrases']) { echo '<div class="sp-label">Repeated phrases</div>';
                foreach ($dd['usage']['repeated_phrases'] as $p) echo '<div class="sp-rep"><span>“'.e($p['phrase']).'”</span><span class="sp-bar-n">×'.(int)$p['count'].'</span></div>';
            }
            if (!$uFlags) echo '<div class="sp-empty">No notable repetition.</div>';
            echo '</section>';
            // Patterns to review
            echo '<section class="sp-group"><div class="sp-gh">Patterns to review<span class="sp-flags">'.$pFlags.' flags</span></div>';
            if ($dd['patterns']) foreach ($dd['patterns'] as $f) { $sv = strtolower($f['sev'] ?? 'low'); echo '<div class="sp-card sev-'.$sv.'"><div class="sp-card-h">'.e($f['kind']).' <span class="diag-sev sev-'.$sv.'">'.e(strtoupper($sv)).'</span></div><div class="sp-card-b">'.e($f['detail']).'</div></div>'; }
            else echo '<div class="sp-empty">Nothing flagged.</div>';
            echo '</section>';
            // Dialogue
            echo '<section class="sp-group"><div class="sp-gh">Dialogue<span class="sp-flags">'.$diaFlags.' flags</span></div>';
            if ($dd['dialogue']['tags_per_speaker']) { echo '<div class="sp-label">Tags per speaker</div>';
                foreach ($dd['dialogue']['tags_per_speaker'] as $t) { $w = max(6,(int)round($t['count']/$maxt*100)); echo '<div class="sp-bar"><span class="sp-bar-k">'.e($t['speaker']).'</span><span class="sp-bar-track"><i class="sev-low" style="width:'.$w.'%"></i></span><span class="sp-bar-n">'.(int)$t['count'].' tags</span></div>'; }
            }
            if ($dd['dialogue']['bookisms']) { echo '<div class="sp-label">Said-bookisms</div><div class="diag-chips">';
                foreach ($dd['dialogue']['bookisms'] as $b) echo '<span class="diag-chip">'.e($b['verb']).' <span class="muted mono">×'.(int)$b['count'].'</span></span>';
                echo '</div>';
            }
            if ($dd['dialogue']['adverb_examples']) echo '<div class="sp-card sev-med"><div class="sp-card-h">Adverb-laden tags</div><div class="sp-card-b">'.e(implode(', ', $dd['dialogue']['adverb_examples'])).' — consider trimming the adverb or letting the line carry the tone.</div></div>';
            if (!$diaFlags && !$dd['dialogue']['tags_per_speaker']) echo '<div class="sp-empty">No attribution flags.</div>';
            echo '</section></aside>';
            echo '<div class="sp-backdrop" id="smartBackdrop" hidden></div>';
            echo '<script>(function(){var p=document.getElementById("smartPanel"),b=document.getElementById("smartBackdrop"),t=document.getElementById("smartToggle"),c=document.getElementById("smartClose");'
               . 'function open(){p.hidden=false;b.hidden=false;requestAnimationFrame(function(){p.classList.add("open")});}'
               . 'function close(){p.classList.remove("open");b.hidden=true;setTimeout(function(){p.hidden=true;},220);}'
               . 'if(t)t.addEventListener("click",function(){p.classList.contains("open")?close():open();});'
               . 'if(c)c.addEventListener("click",close);if(b)b.addEventListener("click",close);'
               . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"&&p.classList.contains("open"))close();});})();</script>';
        }
    } catch (\Throwable $e) { /* diagnostics panel is optional — never block the chapter body */ }
    $file = $c['file'];
    $openNotes = get_chapter_notes($book['id'], $file, 'open');
    $resNotes  = get_chapter_notes($book['id'], $file, 'resolved');
    if (count($openNotes)) echo '<div class="toolbar"><span class="pill doing">'.count($openNotes).' open note'.(count($openNotes)==1?'':'s').'</span></div>';
    if (trim((string)$c['body']) === '') {
        echo '<p class="empty">No text stored yet. Run a sync (or seed) so the chapter prose is pulled in.</p>';
    } else {
        echo '<div class="chapterwrap"><div class="chaptermain">';
        echo '<p class="muted" style="font-size:13px;margin:0 0 6px">Select any passage to flag it, or add a general note below.</p>';
        echo '<div class="entrybody chapterbody" id="chapterbody">'.md_to_html($c['body'], $book['id']).'</div>';
        echo '</div>';
        // --- Phase 9b: live "In this scene" rail (counts computed client-side from the prose) ---
        echo '<aside class="scenerail" id="sceneRail"><div class="sr-head">In this scene</div><div class="sr-list" id="srList"></div>'
           . '<div class="sr-empty" id="srEmpty">No Codex names detected yet.</div>'
           . '<div class="sr-note">Mentions update live as you write. Names from your Codex auto-link.</div></aside>';
        echo '</div>';
        // --- Phase 12: citations in this chapter (from [^cite:key] tokens) ---
        $cites = get_chapter_citations($book['id'], $c['file']);
        if ($cites) {
            echo '<div class="entrybody"><h2>Citations <span class="muted mono">'.count($cites).'</span></h2><ol class="refs">';
            foreach ($cites as $ci) {
                $hits = (int)$ci['hits'] > 1 ? ' <span class="muted mono">×'.(int)$ci['hits'].'</span>' : '';
                if ($ci['source_id']) {
                    echo '<li><a href="'.url(['p'=>'references','book'=>$book['id']]).'#src-'.rawurlencode($ci['cite_key']).'">'.e(format_citation($ci)).'</a>'.$hits.'</li>';
                } else {
                    echo '<li><code>'.e($ci['cite_key']).'</code> <span class="pill err">unresolved</span> — <a href="'.url(['p'=>'references','book'=>$book['id'],'key'=>$ci['cite_key']]).'#addsource">add source</a>'.$hits.'</li>';
                }
            }
            echo '</ol></div>';
        }
        // --- Phase 13: takeaways + exercises for this chapter (derived from prose) ---
        $takeaways = get_chapter_takeaways($c['body']);
        if ($takeaways) {
            echo '<div class="entrybody"><h2>Takeaways</h2><ul>';
            foreach ($takeaways as $t) echo '<li>'.inline_md($t).'</li>';
            echo '</ul></div>';
        }
        reconcile_exercises($book['id'], (int)$c['id'], $c['body']);   // keep in sync on view
        $chEx = get_chapter_exercises((int)$c['id']);
        if ($chEx) {
            echo '<div class="entrybody"><h2>Exercises <span class="muted mono">'.count($chEx).'</span></h2>';
            foreach ($chEx as $ex) {
                echo '<div class="exercise" style="border:1px solid var(--accent-soft,#ddd);border-radius:8px;padding:10px 12px;margin:10px 0">';
                echo '<div class="cmeta"><strong>'.e($ex['title']).'</strong>';
                if ($ex['type'] !== '') echo ' <span class="pill">'.e($ex['type']).'</span>';
                if ($ex['est_time'] !== '') echo ' <span class="muted mono">'.e($ex['est_time']).'</span>';
                if ($ex['operationalizes'] !== '') { $op=one("SELECT db_key,name FROM entries WHERE book_id=? AND slug=?",[$book['id'],$ex['operationalizes']]);
                    echo ' <span class="muted">→ '.($op?'<a href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$op['db_key'],'slug'=>$ex['operationalizes']]).'">'.e($op['name']).'</a>':e($ex['operationalizes'])).'</span>'; }
                echo '</div>';
                if (trim((string)$ex['prompt']) !== '') echo '<div class="clog">'.md_to_html($ex['prompt'], $book['id']).'</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        // data for the client-side tally: targets (longest-first), entity map, db colours
        if (function_exists('build_mention_targets')) {
            $emeta = []; $dbcolor = [];
            foreach (all("SELECT slug,name,db_key,detail,type FROM entries WHERE book_id=?", [$book['id']]) as $r)
                $emeta[$r['slug']] = ['name'=>$r['name'],'db'=>$r['db_key'],'detail'=>trim((string)($r['detail'] ?: $r['type']))];
            foreach (dbmeta_for($book['profile'] ?? 'fiction') as $k => $m) $dbcolor[$k] = ['label'=>$m['singular'] ?? $k, 'color'=>$m['hue'] ?? '#888'];
            $tg = array_map(function($t){ return ['phrase'=>$t['phrase'],'slug'=>$t['slug']]; }, build_mention_targets($book['id']));
            echo '<script>window.__scene='.json_encode(['book'=>$book['id'],'targets'=>$tg,'meta'=>$emeta,'db'=>$dbcolor], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';</script>';
            echo <<<'JS'
<script>(function(){
  var S=window.__scene; if(!S) return;
  var body=document.getElementById('chapterbody'), list=document.getElementById('srList'), empty=document.getElementById('srEmpty');
  if(!body||!list) return;
  function reEsc(s){ return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
  var compiled=(S.targets||[]).map(function(t){ return {slug:t.slug, re:new RegExp('(?<!\\w)'+reEsc(t.phrase)+'(?!\\w)','giu')}; });
  function tally(text){
    text=text.replace(/\[\[[^\]]*\]\]/g,'   ');           // drop explicit wiki-link syntax if present
    var used=new Array(text.length).fill(false), counts={};
    function over(s,e){ for(var k=s;k<e;k++) if(used[k]) return true; return false; }
    function mark(s,e){ for(var k=s;k<e;k++) used[k]=true; }
    compiled.forEach(function(c){ c.re.lastIndex=0; var m; while((m=c.re.exec(text))!==null){ var s=m.index,e=s+m[0].length; if(e===s){c.re.lastIndex++;continue;} if(over(s,e)) continue; mark(s,e); counts[c.slug]=(counts[c.slug]||0)+1; } });
    return counts;
  }
  function render(){
    var counts=tally(body.textContent||'');
    var rows=Object.keys(counts).map(function(slug){ var me=S.meta[slug]||{}; var d=S.db[me.db]||{}; return {slug:slug,name:me.name||slug,db:me.db||'',label:(d.label||'').toUpperCase(),color:d.color||'#888',n:counts[slug]}; });
    rows.sort(function(a,b){ return b.n-a.n || a.name.localeCompare(b.name); });
    list.innerHTML='';
    rows.forEach(function(r){
      var a=document.createElement('a'); a.className='sr-row'; a.href='?p=entry&book='+encodeURIComponent(S.book||'')+'&db='+encodeURIComponent(r.db)+'&slug='+encodeURIComponent(r.slug);
      a.innerHTML='<span class="sr-dot" style="background:'+r.color+'"></span><span class="sr-name">'+r.name.replace(/[&<>]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[m];})+'<span class="sr-db">'+r.label+'</span></span><span class="sr-n">'+r.n+'</span>';
      list.appendChild(a);
    });
    if(empty) empty.style.display=rows.length?'none':'block';
  }
  window.__sceneRender=render;   // exposed so the editor (9a) can recompute on input
  render();

  // --- Phase 9a: hover card on a Codex mention ---
  var card=null, hideT=null;
  function esch(s){ return String(s==null?'':s).replace(/[&<>]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[m]; }); }
  function ensureCard(){ if(card) return card; card=document.createElement('div'); card.className='mention-card'; card.style.display='none'; document.body.appendChild(card); return card; }
  function showCard(a){
    var slug=a.getAttribute('data-slug'); if(!slug) return; var me=S.meta[slug]; if(!me) return;
    var d=S.db[me.db]||{}; var c=ensureCard();
    c.innerHTML='<div class="mc-h"><span class="mc-dot" style="background:'+(d.color||'#888')+'"></span><span class="mc-name">'+esch(me.name)+'</span><span class="mc-badge" style="color:'+(d.color||'#888')+';border-color:'+(d.color||'#888')+'55">'+esch((d.label||'').toString())+'</span></div>'
            +(me.detail?'<div class="mc-detail">'+esch(me.detail)+'</div>':'')
            +'<a class="mc-open" href="'+a.getAttribute('href')+'">Open entry →</a>';
    var r=a.getBoundingClientRect(); c.style.display='block';
    var top=r.bottom+window.scrollY+6, left=r.left+window.scrollX;
    left=Math.min(left, window.scrollX+document.documentElement.clientWidth-c.offsetWidth-12);
    c.style.top=top+'px'; c.style.left=Math.max(8,left)+'px';
  }
  function hideCard(){ if(card) card.style.display='none'; }
  body.addEventListener('mouseover',function(e){ var a=e.target.closest&&e.target.closest('a.mention'); if(a){ clearTimeout(hideT); showCard(a); } });
  body.addEventListener('mouseout',function(e){ var a=e.target.closest&&e.target.closest('a.mention'); if(a){ hideT=setTimeout(hideCard,160); } });
  document.addEventListener('mouseover',function(e){ if(card&&e.target.closest&&e.target.closest('.mention-card')) clearTimeout(hideT); });
  document.addEventListener('mouseout',function(e){ if(card&&e.target.closest&&e.target.closest('.mention-card')) hideT=setTimeout(hideCard,160); });
})();</script>
JS;
        }
    }

    /* ---- revision notes ---- */
    $noteFn = function($n) use ($book) {
        $hasTask = !empty($n['task_id']);
        echo '<div class="cnote '.($n['status']==='resolved'?'done':'').'">';
        if (trim((string)$n['quote']) !== '') echo '<blockquote class="cnote-q">'.nl2br(e($n['quote'])).'</blockquote>';
        if (trim((string)$n['note'])  !== '') echo '<div class="cnote-n">'.nl2br(e($n['note'])).'</div>';
        echo '<div class="cnote-meta"><span class="muted mono">'.e(substr((string)$n['created_at'],0,10)).'</span>';
        $base = '<input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="cid" value="'.(int)$_GET['id'].'"><input type="hidden" name="id" value="'.(int)$n['id'].'">';
        if ($n['status']==='open') {
            echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="chapter_note_status"><input type="hidden" name="status" value="resolved">'.$base.'<button class="btn sm">Resolve</button></form> ';
            if ($hasTask) echo '<span class="pill doing" title="Already sent to Tasks">→ task</span> ';
            else echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="chapter_note_to_task">'.$base.'<button class="btn sm">Send to Tasks</button></form> ';
        } else {
            echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="chapter_note_status"><input type="hidden" name="status" value="open">'.$base.'<button class="btn sm">Reopen</button></form> ';
        }
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Delete this note?\')"><input type="hidden" name="action" value="chapter_note_delete">'.$base.'<button class="btn danger sm">×</button></form>';
        echo '</div></div>';
    };

    echo '<div class="notewrap" style="margin-top:22px"><h2 style="margin-top:0;font-size:15px">Flag a passage / add a note</h2>';
    echo '<form method="post"><input type="hidden" name="action" value="chapter_note_add"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="cid" value="'.(int)$c['id'].'"><input type="hidden" name="file" value="'.e($file).'">';
    echo '<label class="f">Flagged passage (optional — fills in when you select text above)</label><textarea name="quote" id="noteQuote" style="min-height:54px" placeholder="Select text in the chapter, or paste/quote it here."></textarea>';
    echo '<label class="f">Change note</label><textarea name="note" id="noteNote" style="min-height:70px" placeholder="What needs to change here?"></textarea>';
    echo '<div class="toolbar"><button class="btn primary">Add note</button></div></form></div>';

    if ($openNotes) { echo '<h2 style="font-size:15px;margin-top:24px">Open notes ('.count($openNotes).')</h2>'; foreach ($openNotes as $n) $noteFn($n); }
    if ($resNotes)  { echo '<h2 style="font-size:15px;margin-top:24px" class="muted">Resolved ('.count($resNotes).')</h2>'; foreach ($resNotes as $n) $noteFn($n); }
    if (!$openNotes && !$resNotes) echo '<p class="empty">No notes on this chapter yet.</p>';

    echo '<div class="toolbar" style="margin-top:24px">';
    if ($prev) echo '<a class="btn sm" href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$prev['id']]).'">← '.e($prev['title']).'</a>';
    if ($next) echo '<a class="btn sm" href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$next['id']]).'">'.e($next['title']).' →</a>';
    echo '</div>';

    /* select-to-flag UI + note styles */
    echo <<<'HTML'
<style>
  #flagbtn{position:absolute;z-index:50;display:none;border:0;border-radius:6px;padding:5px 10px;
    font:500 12px/1 var(--body-font);color:#fff;background:var(--accent);cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.22)}
  .cnote{border:1px solid var(--line,#e4e0d8);border-radius:9px;padding:11px 13px;margin:10px 0}
  .cnote.done{opacity:.6}
  .cnote-q{margin:0 0 7px;padding:6px 11px;border-left:3px solid var(--accent);background:var(--accent-soft);
    border-radius:0 6px 6px 0;font-style:italic;color:var(--ink,#2c2a26)}
  .cnote-n{margin:0 0 9px;white-space:pre-wrap}
  .cnote-meta{display:flex;gap:7px;align-items:center;flex-wrap:wrap}
  .chapterbody ::selection{background:var(--accent-soft)}
</style>
<script>
(function(){
  var body=document.getElementById('chapterbody'); if(!body) return;
  var q=document.getElementById('noteQuote'), n=document.getElementById('noteNote');
  var btn=document.createElement('button'); btn.id='flagbtn'; btn.type='button'; btn.textContent='⚑ Flag this';
  document.body.appendChild(btn);
  function hide(){ btn.style.display='none'; }
  function place(){
    var sel=window.getSelection();
    if(!sel||sel.isCollapsed||!sel.rangeCount){ hide(); return; }
    var r=sel.getRangeAt(0);
    if(!body.contains(r.commonAncestorContainer)){ hide(); return; }
    var txt=sel.toString().trim(); if(!txt){ hide(); return; }
    var rect=r.getBoundingClientRect();
    btn.style.top=(window.scrollY+rect.top-38)+'px';
    btn.style.left=(window.scrollX+rect.left)+'px';
    btn.style.display='block';
  }
  document.addEventListener('mouseup', function(){ setTimeout(place,1); });
  document.addEventListener('scroll', hide, true);
  btn.addEventListener('mousedown', function(ev){
    ev.preventDefault();
    var txt=window.getSelection().toString().trim();
    if(txt){ q.value=txt; n.focus(); n.scrollIntoView({block:'center',behavior:'smooth'}); }
    hide(); window.getSelection().removeAllRanges();
  });
})();
</script>
HTML;
    break;

case 'chapter_edit':
    $c = get_chapter($_GET['id']);
    if (!$c || $c['book_id'] !== $book['id']) { echo '<p class="empty">Chapter not found.</p>'; break; }
    if (!(cfg()['books_dir'] ?? '')) { echo '<p class="empty">Chapter editing is disabled on this server.</p>'; break; }
    echo '<div class="pagehead"><div><h1>Edit · '.e($c['title']).'</h1><p class="desc">Edit the chapter prose (Markdown). Saving writes it back to <span class="mono">Manuscript/'.e($c['file']).'</span> and the database. A timestamped backup is kept in <span class="mono">Manuscript/_backups/</span>; if the file changed on disk since you opened it, the save is refused (no overwrite). Keystrokes autosave to a recoverable draft; spell check uses your browser plus this book’s <a href="'.url(['p'=>'dictionary','book'=>$book['id']]).'">custom dictionary</a>.</p></div></div>';

    echo <<<'CSS'
<style>
  .ed-bar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 8px}
  .ed-bar .ed-sep{flex:0 0 1px;align-self:stretch;background:var(--line,#e4e0d8);margin:0 4px}
  .ed-status{font-size:12px;color:var(--muted,#8a8378);margin-left:auto}
  .ed-status.ok{color:#2E6E6E}.ed-status.busy{color:#8A6A3E}.ed-status.warn{color:#8A3F4B;font-weight:600}
  .ed-wc{font-size:12px;color:var(--muted,#8a8378);font-family:var(--mono)}
  .ed-find{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin:0 0 8px;padding:8px;border:1px solid var(--line,#e4e0d8);border-radius:8px;background:var(--accent-soft,#f6f3ee)}
  .ed-find input[type=text]{padding:5px 8px;border:1px solid var(--line,#d8d3c8);border-radius:6px;font-size:13px}
  .ed-find .ed-case{font-size:12px;display:flex;align-items:center;gap:3px;color:var(--muted,#8a8378)}
  .ed-find .ed-count{font-size:12px;font-family:var(--mono);color:var(--muted,#8a8378);min-width:42px;text-align:center}
  .ed-style{margin:10px 0;border:1px solid var(--line,#e4e0d8);border-radius:8px;padding:10px 12px}
  .ed-style-h{font-weight:600;font-size:13px;margin-bottom:6px}
  .ed-style-row{display:block;width:100%;text-align:left;background:none;border:0;border-bottom:1px solid var(--line,#eee);padding:5px 2px;cursor:pointer;font-size:12.5px}
  .ed-style-row:hover{background:var(--accent-soft,#f6f3ee)}
  .ed-style-kind{font-weight:600;color:var(--accent)}
  .ed-style-empty{font-size:12.5px;color:var(--muted,#8a8378)}
  .rev-row{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:5px 0;border-bottom:1px solid var(--line,#eee)}
  .rev-at{font-size:12px;font-family:var(--mono)}.rev-w{font-size:11px}
  .ed-notes{margin-top:6px}
  .ed-note{border:1px solid var(--line,#e4e0d8);border-radius:7px;padding:7px 9px;margin:6px 0;font-size:12px}
  .ed-note-q{font-style:italic;color:var(--ink,#2c2a26);margin-bottom:3px}
  .ed-note-n{color:var(--muted,#6b6253)}
  .md-toolbar{display:flex;gap:2px;align-items:center;flex-wrap:wrap;margin:0 0 6px;padding:4px;border:1px solid var(--line,#e4e0d8);border-radius:8px}
  .md-toolbar button{min-width:30px;height:30px;padding:0 8px;border:0;border-radius:6px;background:none;cursor:pointer;
    font:600 14px/1 var(--body-font);color:var(--ink,#2c2a26)}
  .md-toolbar button:hover{background:var(--accent-soft,#f6f3ee)}
  .md-toolbar .md-i{font-style:italic}.md-toolbar .md-u{text-decoration:underline}.md-toolbar .md-s{text-decoration:line-through}
  .md-toolbar .md-code{font-family:var(--mono);font-size:12px}
  .md-toolbar .md-div{width:1px;align-self:stretch;background:var(--line,#e4e0d8);margin:2px 4px}
</style>
CSS;

    $cbase       = md_body_hash($c['body']);
    $draftRow    = get_chapter_autosave_draft($book['id'], (int)$c['id']);
    $recoverDraft = ($draftRow && $draftRow['body_hash'] !== $cbase) ? $draftRow : null;
    $revs        = get_chapter_revisions($book['id'], (int)$c['id']);
    $dictWords   = get_dictionary_words($book['id']);
    $editNotes   = get_chapter_notes($book['id'], $c['file'], 'open');

    if ($recoverDraft) {
        echo '<div class="flash" id="draftBanner" style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">';
        echo '<span>A more recent <strong>unsaved draft</strong> ('.number_format((int)$recoverDraft['word_count']).' words, autosaved '.e(substr((string)$recoverDraft['created_at'],0,16)).') was found. It was never written to the file.</span>';
        echo '<span class="toolbar" style="margin:0"><button type="button" class="btn sm primary" id="draftRestore">Load draft into editor</button>';
        echo '<form method="post" style="display:inline" onsubmit="return confirm(\'Discard the recovered draft? This cannot be undone.\')"><input type="hidden" name="action" value="chapter_revision_discard_draft"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="cid" value="'.(int)$c['id'].'"><button class="btn sm">Discard</button></form></span>';
        echo '</div>';
    }

    echo '<form method="post" id="entry-form"><input type="hidden" name="action" value="chapter_save"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="cid" value="'.(int)$c['id'].'"><input type="hidden" name="base" value="'.$cbase.'">';

    // Editor toolbar (word count + save status + tool toggles) — sits above the textarea
    echo '<div class="ed-bar">';
    echo '<button class="btn primary" type="submit">Save prose</button>';
    echo '<a class="btn" href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$c['id']]).'">Cancel</a>';
    echo '<span class="ed-sep"></span>';
    echo '<button type="button" class="btn sm" id="btnFind" title="Find &amp; replace (Ctrl/⌘-F)">Find</button>';
    echo '<button type="button" class="btn sm" id="btnStyle" title="Local style check">Style</button>';
    if ($revs) echo '<button type="button" class="btn sm" id="btnRevs">History ('.count($revs).')</button>';
    echo '<span class="ed-status" id="edStatus">Saved</span>';
    echo '<span class="ed-wc" id="edWC"></span>';
    echo '</div>';

    // Find & replace bar (hidden until toggled)
    echo '<div class="ed-find" id="edFind" hidden>'
       . '<input type="text" id="fFind" placeholder="Find" autocomplete="off">'
       . '<input type="text" id="fRepl" placeholder="Replace with" autocomplete="off">'
       . '<label class="ed-case"><input type="checkbox" id="fCase"> Aa</label>'
       . '<span class="ed-count" id="fCount">0/0</span>'
       . '<button type="button" class="btn sm" id="fPrev">↑</button>'
       . '<button type="button" class="btn sm" id="fNext">↓</button>'
       . '<button type="button" class="btn sm" id="fRep">Replace</button>'
       . '<button type="button" class="btn sm" id="fRepAll">All</button>'
       . '<button type="button" class="btn sm" id="fClose">✕</button>'
       . '</div>';

    echo '<div class="chapterwrap"><div class="chaptermain">';
    // Markdown formatting toolbar — wraps/prefixes the textarea selection (Ctrl/⌘-B/I/U)
    echo '<div class="md-toolbar" id="mdToolbar">'
       . '<button type="button" data-md="bold" title="Bold (Ctrl/⌘-B)" style="font-weight:800">B</button>'
       . '<button type="button" data-md="italic" title="Italic (Ctrl/⌘-I)" class="md-i">I</button>'
       . '<button type="button" data-md="underline" title="Underline (Ctrl/⌘-U)" class="md-u">U</button>'
       . '<button type="button" data-md="strike" title="Strikethrough" class="md-s">S</button>'
       . '<button type="button" data-md="code" title="Inline code" class="md-code">&lt;/&gt;</button>'
       . '<span class="md-div"></span>'
       . '<button type="button" data-md="h2" title="Heading">H</button>'
       . '<button type="button" data-md="quote" title="Blockquote">&ldquo;</button>'
       . '<button type="button" data-md="ul" title="Bulleted list">&bull;</button>'
       . '<button type="button" data-md="ol" title="Numbered list" style="font-size:12px">1.</button>'
       . '<button type="button" data-md="link" title="Link">&#128279;</button>'
       . '</div>';
    echo '<textarea id="chapter-md" name="md" spellcheck="true" style="width:100%;min-height:62vh;font-family:var(--mono);font-size:13px;line-height:1.5">'.e($c['body']).'</textarea>';
    // Local style-check results (hidden until toggled)
    echo '<div class="ed-style" id="edStyle" hidden><div class="ed-style-h">Style check <span class="muted" id="edStyleN"></span></div><div id="edStyleList"></div></div>';
    echo '</div>';
    // Right rail: scene mentions + revision history + open notes
    echo '<aside class="scenerail" id="sceneRail"><div class="sr-head">In this scene</div><div class="sr-list" id="srList"></div>'
       . '<div class="sr-empty" id="srEmpty">No Codex names detected yet.</div>'
       . '<div class="sr-note">Counts update live as you type. Names from your Codex auto-link.</div>';
    if ($revs) {
        echo '<div class="sr-head" id="revsHead" hidden style="margin-top:16px">Revision history</div><div id="revsList" hidden></div>';
    }
    if ($editNotes) {
        echo '<div class="sr-head" style="margin-top:16px">Open notes ('.count($editNotes).')</div><div class="ed-notes">';
        foreach ($editNotes as $n) {
            $clip = function ($s, $n) { $s = (string)$s; return function_exists('mb_strimwidth') ? mb_strimwidth($s, 0, $n, '…') : (strlen($s) > $n ? substr($s, 0, $n - 1).'…' : $s); };
            echo '<div class="ed-note">';
            if (trim((string)$n['quote']) !== '') echo '<div class="ed-note-q">“'.e($clip($n['quote'],90)).'”</div>';
            if (trim((string)$n['note'])  !== '') echo '<div class="ed-note-n">'.e($clip($n['note'],120)).'</div>';
            echo '</div>';
        }
        echo '<a class="sr-note" href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$c['id']]).'">Manage notes on the chapter page →</a></div>';
    }
    echo '</aside>';
    echo '</div></form>';

    // Floating "add to dictionary" button (created by JS) needs the endpoint + book
    $emeta = []; $dbcolor = [];
    foreach (all("SELECT slug,name,db_key FROM entries WHERE book_id=?", [$book['id']]) as $r) $emeta[$r['slug']] = ['name'=>$r['name'],'db'=>$r['db_key']];
    foreach (dbmeta_for($book['profile'] ?? 'fiction') as $k => $m) $dbcolor[$k] = ['label'=>$m['singular'] ?? $k, 'color'=>$m['hue'] ?? '#888'];
    $tg = function_exists('build_mention_targets')
        ? array_map(function($t){ return ['phrase'=>$t['phrase'],'slug'=>$t['slug']]; }, build_mention_targets($book['id']))
        : [];
    $edcfg = [
        'book' => $book['id'], 'cid' => (int)$c['id'], 'base' => $cbase,
        'targets' => $tg, 'meta' => $emeta, 'db' => $dbcolor,
        'dict' => array_values($dictWords),
        'revs' => array_map(function($r){ return ['id'=>(int)$r['id'],'words'=>(int)$r['word_count'],'at'=>substr((string)$r['created_at'],0,16)]; }, $revs),
        'draftBody' => $recoverDraft ? md_body_norm($recoverDraft['body']) : null,
    ];
    echo '<script>window.__scene='.json_encode(['book'=>$book['id'],'targets'=>$tg,'meta'=>$emeta,'db'=>$dbcolor], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';</script>';
    echo '<script>window.__ed='.json_encode($edcfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';</script>';

    // Scene rail (Phase 9e) — recount live as you type
    echo <<<'JS'
<script>(function(){
  var S=window.__scene; if(!S) return;
  var ta=document.getElementById('chapter-md'), list=document.getElementById('srList'), empty=document.getElementById('srEmpty');
  if(!ta||!list) return;
  function reEsc(s){ return s.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
  var compiled=(S.targets||[]).map(function(t){ return {slug:t.slug, re:new RegExp('(?<!\\w)'+reEsc(t.phrase)+'(?!\\w)','giu')}; });
  function tally(text){
    text=text.replace(/\[\[[^\]]*\]\]/g,'   ');
    var used=new Array(text.length).fill(false), counts={};
    function over(s,e){ for(var k=s;k<e;k++) if(used[k]) return true; return false; }
    function mark(s,e){ for(var k=s;k<e;k++) used[k]=true; }
    compiled.forEach(function(c){ c.re.lastIndex=0; var m; while((m=c.re.exec(text))!==null){ var s=m.index,e=s+m[0].length; if(e===s){c.re.lastIndex++;continue;} if(over(s,e)) continue; mark(s,e); counts[c.slug]=(counts[c.slug]||0)+1; } });
    return counts;
  }
  function render(){
    var counts=tally(ta.value||'');
    var rows=Object.keys(counts).map(function(slug){ var me=S.meta[slug]||{}; var d=S.db[me.db]||{}; return {slug:slug,name:me.name||slug,db:me.db||'',label:(d.label||'').toUpperCase(),color:d.color||'#888',n:counts[slug]}; });
    rows.sort(function(a,b){ return b.n-a.n || a.name.localeCompare(b.name); });
    list.innerHTML='';
    rows.forEach(function(r){
      var a=document.createElement('a'); a.className='sr-row'; a.href='?p=entry&book='+encodeURIComponent(S.book||'')+'&db='+encodeURIComponent(r.db)+'&slug='+encodeURIComponent(r.slug);
      a.innerHTML='<span class="sr-dot" style="background:'+r.color+'"></span><span class="sr-name">'+r.name.replace(/[&<>]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[m];})+'<span class="sr-db">'+r.label+'</span></span><span class="sr-n">'+r.n+'</span>';
      list.appendChild(a);
    });
    if(empty) empty.style.display=rows.length?'none':'block';
  }
  var t=null; ta.addEventListener('input',function(){ clearTimeout(t); t=setTimeout(render,250); });
  window.__sceneRender=render; render();
})();</script>
JS;

    // Phase 15 — autosave, find/replace, style check, revisions, add-to-dictionary, word count
    echo <<<'JS'
<script>(function(){
  var E=window.__ed; if(!E) return;
  var ta=document.getElementById('chapter-md'), form=document.getElementById('entry-form');
  if(!ta||!form) return;
  function post(data){ var b=new URLSearchParams(); Object.keys(data).forEach(function(k){ b.append(k,data[k]); }); return fetch('', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:b}); }
  function pad(n){ return (n<10?'0':'')+n; }
  function words(s){ var m=(s||'').replace(/```[\s\S]*?```/g,' ').match(/[\w'’-]+/g); return m?m.length:0; }

  /* ---- word count + dirty/save status ---- */
  var startWords=words(ta.value), status=document.getElementById('edStatus'), wc=document.getElementById('edWC');
  var dirty=false, saving=false, submitted=false;
  function renderWC(){ var n=words(ta.value), d=n-startWords; wc.textContent=n.toLocaleString()+' words'+(d?' ('+(d>0?'+':'')+d.toLocaleString()+')':''); }
  function setStatus(txt,cls){ status.textContent=txt; status.className='ed-status'+(cls?' '+cls:''); }
  renderWC();

  /* ---- debounced autosave (draft only; never writes the .md) ---- */
  var atimer=null;
  function scheduleAutosave(){
    clearTimeout(atimer);
    atimer=setTimeout(function(){
      if(!dirty||saving) return;
      saving=true; setStatus('Saving…','busy');
      post({action:'chapter_autosave', book:E.book, cid:E.cid, base:E.base, md:ta.value})
        .then(function(r){ return r.json(); })
        .then(function(j){
          saving=false;
          if(j && j.ok){ dirty=false; var d=new Date();
            setStatus('Draft saved '+pad(d.getHours())+':'+pad(d.getMinutes())+':'+pad(d.getSeconds()), 'ok');
            if(j.conflict) setStatus('Draft saved — but this chapter changed on the server; saving may be refused', 'warn');
          } else setStatus('Autosave failed', 'warn');
        })
        .catch(function(){ saving=false; setStatus('Autosave failed (offline?)', 'warn'); });
    }, 1500);
  }
  ta.addEventListener('input', function(){ dirty=true; setStatus('Unsaved changes','busy'); renderWC(); scheduleAutosave(); });
  form.addEventListener('submit', function(){ submitted=true; });
  window.addEventListener('beforeunload', function(e){ if(dirty && !submitted){ e.preventDefault(); e.returnValue=''; } });

  /* ---- recover draft ---- */
  var dr=document.getElementById('draftRestore');
  if(dr && E.draftBody!=null){ dr.addEventListener('click', function(){
    ta.value=E.draftBody; dirty=true; renderWC(); setStatus('Draft loaded — review, then Save prose','busy');
    if(window.__sceneRender)window.__sceneRender(); var b=document.getElementById('draftBanner'); if(b)b.style.display='none'; ta.focus();
  }); }

  /* ---- find & replace ---- */
  var fbar=document.getElementById('edFind'), fFind=document.getElementById('fFind'), fRepl=document.getElementById('fRepl'),
      fCase=document.getElementById('fCase'), fCount=document.getElementById('fCount');
  var matches=[], cur=-1;
  function scan(){
    matches=[]; cur=-1; var q=fFind.value; if(!q){ fCount.textContent='0/0'; return; }
    var hay=ta.value, needle=q, i=0;
    if(!fCase.checked){ hay=hay.toLowerCase(); needle=needle.toLowerCase(); }
    if(!needle){ fCount.textContent='0/0'; return; }
    while(true){ var idx=hay.indexOf(needle,i); if(idx<0)break; matches.push(idx); i=idx+needle.length; }
    fCount.textContent=(matches.length?'1/':'0/')+matches.length; if(matches.length)cur=0;
  }
  function selectMatch(){ if(cur<0||!matches.length)return; var s=matches[cur], len=fFind.value.length;
    ta.focus(); ta.setSelectionRange(s,s+len);
    // scroll caret into view
    var before=ta.value.substr(0,s), lines=before.split('\n').length, lh=parseFloat(getComputedStyle(ta).lineHeight)||18;
    ta.scrollTop=Math.max(0,(lines-4)*lh); fCount.textContent=(cur+1)+'/'+matches.length;
  }
  function step(d){ if(!matches.length){ scan(); if(!matches.length)return; } cur=(cur+d+matches.length)%matches.length; selectMatch(); }
  function openFind(){ fbar.hidden=false; var sel=ta.value.substring(ta.selectionStart,ta.selectionEnd); if(sel && sel.length<80)fFind.value=sel; fFind.focus(); fFind.select(); scan(); if(matches.length)selectMatch(); }
  function closeFind(){ fbar.hidden=true; ta.focus(); }
  document.getElementById('btnFind').addEventListener('click', function(){ fbar.hidden?openFind():closeFind(); });
  document.getElementById('fClose').addEventListener('click', closeFind);
  document.getElementById('fNext').addEventListener('click', function(){ step(1); });
  document.getElementById('fPrev').addEventListener('click', function(){ step(-1); });
  fFind.addEventListener('input', function(){ scan(); if(matches.length)selectMatch(); });
  fCase.addEventListener('change', function(){ scan(); if(matches.length)selectMatch(); });
  fFind.addEventListener('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); step(e.shiftKey?-1:1); } if(e.key==='Escape'){ closeFind(); } });
  function replaceOne(){
    if(cur<0||!matches.length){ scan(); if(!matches.length)return; }
    var s=matches[cur], len=fFind.value.length;
    ta.value=ta.value.substr(0,s)+fRepl.value+ta.value.substr(s+len);
    dirty=true; setStatus('Unsaved changes','busy'); renderWC(); scheduleAutosave(); if(window.__sceneRender)window.__sceneRender();
    scan(); if(matches.length){ cur=Math.min(cur,matches.length-1); selectMatch(); }
  }
  document.getElementById('fRep').addEventListener('click', replaceOne);
  document.getElementById('fRepAll').addEventListener('click', function(){
    var q=fFind.value; if(!q)return; scan(); var n=matches.length; if(!n)return;
    if(!confirm('Replace all '+n+' occurrence'+(n===1?'':'s')+'?'))return;
    var out='', hay=ta.value, needle=q, i=0, cmpHay=fCase.checked?hay:hay.toLowerCase(), cmpN=fCase.checked?needle:needle.toLowerCase();
    while(true){ var idx=cmpHay.indexOf(cmpN,i); if(idx<0){ out+=hay.substr(i); break; } out+=hay.substring(i,idx)+fRepl.value; i=idx+needle.length; }
    ta.value=out; dirty=true; setStatus('Replaced '+n,'busy'); renderWC(); scheduleAutosave(); if(window.__sceneRender)window.__sceneRender(); scan();
  });
  document.addEventListener('keydown', function(e){ if((e.ctrlKey||e.metaKey) && (e.key==='f'||e.key==='F') && (document.activeElement===ta||fbar.contains(document.activeElement)||!fbar.hidden)){ e.preventDefault(); openFind(); } });

  /* ---- local style check (repeated words, doubled punctuation, long sentences) ---- */
  var stylePanel=document.getElementById('edStyle'), styleList=document.getElementById('edStyleList'), styleN=document.getElementById('edStyleN');
  function esch(s){ return String(s==null?'':s).replace(/[&<>]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;'}[m]; }); }
  function styleScan(){
    var text=ta.value, hits=[], m, re;
    re=/(?<!\w)(\w+)(\s+)(\1)(?!\w)/giu;      // repeated word: "the the"
    while((m=re.exec(text))!==null){ hits.push({s:m.index, e:m.index+m[0].length, kind:'Repeated word', detail:'“'+m[1]+' '+m[3]+'”'}); }
    re=/([!?,;:])\1+/g;                        // doubled punctuation
    while((m=re.exec(text))!==null){ hits.push({s:m.index, e:m.index+m[0].length, kind:'Doubled punctuation', detail:'“'+m[0]+'”'}); }
    re=/  +/g;                                 // multiple spaces
    while((m=re.exec(text))!==null){ hits.push({s:m.index, e:m.index+m[0].length, kind:'Extra spaces', detail:m[0].length+' spaces'}); }
    // long sentences (>40 words)
    var sre=/[^.!?\n]+[.!?]+/g;
    while((m=sre.exec(text))!==null){ var w=(m[0].match(/[\w'’-]+/g)||[]).length; if(w>40) hits.push({s:m.index, e:m.index+m[0].length, kind:'Long sentence', detail:w+' words'}); }
    hits.sort(function(a,b){ return a.s-b.s; });
    styleN.textContent=hits.length?('· '+hits.length+' flag'+(hits.length===1?'':'s')):'· nothing flagged';
    styleList.innerHTML='';
    if(!hits.length){ styleList.innerHTML='<div class="ed-style-empty">No repeated words, doubled punctuation, or overlong sentences.</div>'; return; }
    hits.slice(0,120).forEach(function(h){
      var row=document.createElement('button'); row.type='button'; row.className='ed-style-row';
      row.innerHTML='<span class="ed-style-kind">'+esch(h.kind)+'</span> <span class="muted">'+esch(h.detail)+'</span>';
      row.addEventListener('click', function(){ ta.focus(); ta.setSelectionRange(h.s,h.e);
        var lines=ta.value.substr(0,h.s).split('\n').length, lh=parseFloat(getComputedStyle(ta).lineHeight)||18; ta.scrollTop=Math.max(0,(lines-4)*lh); });
      styleList.appendChild(row);
    });
  }
  document.getElementById('btnStyle').addEventListener('click', function(){ stylePanel.hidden=!stylePanel.hidden; if(!stylePanel.hidden)styleScan(); });
  ta.addEventListener('input', function(){ if(!stylePanel.hidden){ clearTimeout(styleScan._t); styleScan._t=setTimeout(styleScan,400); } });

  /* ---- revision history panel ---- */
  var btnRevs=document.getElementById('btnRevs'), revsHead=document.getElementById('revsHead'), revsList=document.getElementById('revsList');
  if(btnRevs && revsList){
    function renderRevs(){
      revsList.innerHTML='';
      (E.revs||[]).forEach(function(r){
        var row=document.createElement('div'); row.className='rev-row';
        row.innerHTML='<span class="rev-at">'+esch(r.at)+'</span><span class="rev-w muted">'+r.words.toLocaleString()+'w</span>';
        var b=document.createElement('button'); b.type='button'; b.className='btn sm'; b.textContent='Load';
        b.addEventListener('click', function(){
          if(dirty && !confirm('Load this version? Your current unsaved changes in the editor will be replaced (the draft on the server is kept).'))return;
          post({action:'chapter_revision_get', book:E.book, id:r.id}).then(function(x){return x.json();}).then(function(j){
            if(j&&j.ok){ ta.value=j.body; dirty=true; renderWC(); setStatus('Loaded snapshot — review, then Save prose','busy'); if(window.__sceneRender)window.__sceneRender(); scheduleAutosave(); }
          });
        });
        row.appendChild(b); revsList.appendChild(row);
      });
    }
    btnRevs.addEventListener('click', function(){ var h=revsHead.hidden; revsHead.hidden=!h; revsList.hidden=!h; if(h)renderRevs(); });
  }

  /* ---- add-to-dictionary from a selection ---- */
  var known={}; (E.dict||[]).forEach(function(w){ known[w.toLowerCase()]=true; });
  var addBtn=document.createElement('button'); addBtn.type='button'; addBtn.id='dictAdd'; addBtn.textContent='+ Add to dictionary';
  addBtn.style.cssText='position:absolute;z-index:60;display:none;border:0;border-radius:6px;padding:5px 10px;font:500 12px/1 var(--body-font);color:#fff;background:var(--accent);cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,.22)';
  document.body.appendChild(addBtn);
  function selWord(){ var s=ta.value.substring(ta.selectionStart,ta.selectionEnd).trim(); return (/^[\p{L}][\p{L}'’-]{1,60}$/u.test(s))?s:''; }
  function placeAdd(){
    var w=selWord();
    if(!w || known[w.toLowerCase()]){ addBtn.style.display='none'; return; }
    // position near the selection using a mirror measurement is heavy; anchor to caret via textarea rect + rough offset
    var rect=ta.getBoundingClientRect();
    addBtn.textContent='+ Add “'+w+'” to dictionary'; addBtn.dataset.w=w;
    addBtn.style.top=(window.scrollY+rect.top+8)+'px'; addBtn.style.left=(window.scrollX+rect.left+8)+'px'; addBtn.style.display='block';
  }
  ta.addEventListener('mouseup', function(){ setTimeout(placeAdd,1); });
  ta.addEventListener('keyup', function(e){ if(e.shiftKey||e.key==='Shift')setTimeout(placeAdd,1); });
  ta.addEventListener('scroll', function(){ addBtn.style.display='none'; });
  addBtn.addEventListener('mousedown', function(ev){ ev.preventDefault();
    var w=addBtn.dataset.w; if(!w)return; addBtn.style.display='none';
    post({action:'dictionary_add', ajax:'1', book:E.book, term:w}).then(function(r){return r.json();}).then(function(j){
      if(j&&j.ok){ known[w.toLowerCase()]=true; setStatus('Added “'+w+'” to dictionary','ok'); setTimeout(function(){ if(!dirty)setStatus('Saved'); },1800); }
    });
  });
})();</script>
JS;

    // Phase 15 — Markdown formatting toolbar (wrap/prefix the textarea selection)
    echo <<<'JS'
<script>(function(){
  var ta=document.getElementById('chapter-md'), bar=document.getElementById('mdToolbar');
  if(!ta||!bar) return;
  function fire(){ ta.dispatchEvent(new Event('input',{bubbles:true})); }   // drive autosave/word-count/scene/style
  function wrap(before, after){
    after = (after===undefined) ? before : after;
    var s=ta.selectionStart, e=ta.selectionEnd, v=ta.value, sel=v.slice(s,e);
    // toggle off: markers inside the selection …
    if(sel.length>=before.length+after.length && sel.slice(0,before.length)===before && sel.slice(sel.length-after.length)===after){
      var inner=sel.slice(before.length, sel.length-after.length);
      ta.setRangeText(inner, s, e, 'select'); ta.focus(); fire(); return;
    }
    // … or markers just outside it
    if(v.slice(Math.max(0,s-before.length),s)===before && v.slice(e,e+after.length)===after){
      ta.setRangeText(sel, s-before.length, e+after.length, 'select'); ta.focus(); fire(); return;
    }
    ta.setRangeText(before+sel+after, s, e, 'end');
    if(s===e){ var p=s+before.length; ta.setSelectionRange(p,p); }        // empty: caret between markers
    else ta.setSelectionRange(s+before.length, e+before.length);          // keep the text selected
    ta.focus(); fire();
  }
  function prefixLines(prefix, numbered){
    var s=ta.selectionStart, e=ta.selectionEnd, v=ta.value;
    var ls=v.lastIndexOf('\n', s-1)+1, le=v.indexOf('\n', e); if(le<0)le=v.length;
    var lines=v.slice(ls,le).split('\n');
    var re=numbered ? /^\d+[.)]\s+/ : new RegExp('^'+prefix.replace(/[.*+?^${}()|[\]\\]/g,'\\$&'));
    var all=lines.every(function(l){ return l==='' || re.test(l); });
    var n=0, out=lines.map(function(l){
      if(all) return l.replace(re,'');
      if(l==='') return l;
      n++; return (numbered ? (n+'. ') : prefix) + l;
    }).join('\n');
    ta.setRangeText(out, ls, le, 'select'); ta.focus(); fire();
  }
  function link(){
    var s=ta.selectionStart, e=ta.selectionEnd, sel=ta.value.slice(s,e), text=sel||'text';
    var md='['+text+'](https://)';
    ta.setRangeText(md, s, e, 'end');
    var us=s+md.indexOf('(')+1; ta.setSelectionRange(us, us+8); ta.focus(); fire();  // select "https://"
  }
  var actions={
    bold:function(){wrap('**');}, italic:function(){wrap('*');}, underline:function(){wrap('<u>','</u>');},
    strike:function(){wrap('~~');}, code:function(){wrap('`');},
    h2:function(){prefixLines('## ');}, quote:function(){prefixLines('> ');},
    ul:function(){prefixLines('- ');}, ol:function(){prefixLines('',true);}, link:link
  };
  bar.addEventListener('mousedown', function(ev){ var b=ev.target.closest('button[data-md]'); if(!b)return; ev.preventDefault(); (actions[b.dataset.md]||function(){})(); });
  ta.addEventListener('keydown', function(e){
    if(!(e.ctrlKey||e.metaKey)||e.altKey) return;
    var k=e.key.toLowerCase();
    if(k==='b'){ e.preventDefault(); actions.bold(); }
    else if(k==='i'){ e.preventDefault(); actions.italic(); }
    else if(k==='u'){ e.preventDefault(); actions.underline(); }
  });
})();</script>
JS;
    break;

case 'dictionary':
    $terms = get_dictionary_terms($book['id']);
    $nUser = 0; $nCodex = 0; foreach ($terms as $tt) { if (($tt['source'] ?? '') === 'codex') $nCodex++; else $nUser++; }
    echo '<div class="pagehead"><div><h1>Custom dictionary</h1><p class="desc">Words this book’s prose editor should treat as correctly spelled — invented names, jargon, coined terms. Add your Codex entry names and aliases in one click so proper nouns stop underlining. Spelling itself is underlined by your browser; this list records the terms you’ve marked valid (and feeds future editor tooling). Nothing here touches your Markdown files.</p></div></div>';

    echo '<div class="toolbar" style="gap:8px">';
    echo '<form method="post" style="display:inline"><input type="hidden" name="action" value="dictionary_import_codex"><input type="hidden" name="book" value="'.e($book['id']).'"><button class="btn">Import all Codex names</button></form>';
    echo '<form method="post" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap"><input type="hidden" name="action" value="dictionary_add"><input type="hidden" name="book" value="'.e($book['id']).'">';
    echo '<input type="text" name="term" placeholder="Add a term…" maxlength="190" style="padding:6px 9px;border:1px solid var(--line,#d8d3c8);border-radius:6px" required><button class="btn primary">Add</button></form>';
    echo '</div>';

    echo '<p class="muted" style="font-size:13px">'.count($terms).' term'.(count($terms)===1?'':'s').' · '.$nCodex.' from Codex · '.$nUser.' added by you.</p>';

    if (!$terms) {
        echo '<p class="empty">No terms yet. Click <strong>Import all Codex names</strong> to seed it from your entries and aliases, or add words one at a time.</p>';
    } else {
        echo '<div class="dict-grid" style="display:flex;flex-wrap:wrap;gap:8px;margin-top:10px">';
        foreach ($terms as $tt) {
            $isCodex = ($tt['source'] ?? '') === 'codex';
            echo '<span class="dict-term" style="display:inline-flex;align-items:center;gap:7px;border:1px solid var(--line,#e4e0d8);border-radius:20px;padding:4px 6px 4px 12px'.($isCodex?';background:var(--accent-soft,#f6f3ee)':'').'">';
            echo e($tt['term']);
            if ($isCodex) echo ' <span class="muted mono" style="font-size:10px">codex</span>';
            echo '<form method="post" style="display:inline;margin:0" title="Remove"><input type="hidden" name="action" value="dictionary_remove"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="id" value="'.(int)$tt['id'].'"><button class="btn sm" style="padding:1px 7px;border-radius:50%;line-height:1">×</button></form>';
            echo '</span>';
        }
        echo '</div>';
    }
    echo '<div class="toolbar" style="margin-top:20px"><a class="btn sm" href="'.url(['p'=>'manuscript','book'=>$book['id']]).'">← Manuscript</a></div>';
    break;

case 'progressions':
    $prog = get_progressions($book['id']);
    echo '<div class="pagehead"><div><h1>Progressions log</h1><p class="desc">Confirmed story movement, chapter by chapter. The beats come from <span class="mono">Codex/Meta/progressions.md</span>; the <strong>When</strong> override is app-only (for flashbacks / parallel arcs) and survives sync. See the <a href="'.url(['p'=>'timeline','book'=>$book['id']]).'">Timeline</a>.</p></div></div>';
    if (!$prog) { echo '<p class="empty">No progressions yet.</p>'; break; }
    echo '<table class="grid"><tr><th>Chapter</th><th>Type</th><th>What happened</th><th>When (timeline)</th><th>Order</th></tr>';
    foreach ($prog as $pr) {
        echo '<tr><td class="mono" style="white-space:nowrap">'.e($pr['chapter']).'</td>'
           . '<td><span class="tag" style="color:#6B6253;background:#6B625318">'.e($pr['type']).'</span></td>'
           . '<td>'.inline_md($pr['what']).'</td>'
           . '<td colspan="2"><form method="post" class="when-edit">'
           . '<input type="hidden" name="action" value="progression_when"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="id" value="'.(int)$pr['id'].'">'
           . '<input class="when-label" name="when_label" placeholder="label (optional)" value="'.e($pr['when_label'] ?? '').'">'
           . '<input class="when-order" name="when_order" type="number" step="1" placeholder="order" value="'.e(($pr['when_order'] ?? '') === null ? '' : ($pr['when_order'] ?? '')).'">'
           . '<button class="btn sm">Set</button></form></td></tr>';
    }
    echo '</table>';
    break;

case 'timeline':
    $tl = get_progressions_timeline($book['id']);
    echo '<div class="pagehead"><div><h1>Timeline</h1><p class="desc">One lane per entity, story-time left-to-right. Tick the entities you want to see — empty chapter columns drop out automatically. Set a <a href="'.url(['p'=>'progressions','book'=>$book['id']]).'">When override</a> to pull flashbacks/parallel arcs into place.</p></div></div>';
    if (!$tl) { echo '<p class="empty">No progressions yet.</p>'; break; }
    // Buckets = ordered distinct _bucket labels (in first-appearance order along the sorted timeline).
    $buckets = []; foreach ($tl as $pr) { if (!in_array($pr['_bucket'], $buckets, true)) $buckets[] = $pr['_bucket']; }
    // Entities = every slug that appears in any related_csv, mapped to its display name + db.
    $lanes = [];           // slug => ['name'=>, 'db'=>, 'cells'=>[bucketIndex => [beats]], 'n'=>count]
    foreach ($tl as $pr) {
        $bidx = array_search($pr['_bucket'], $buckets, true);
        foreach (array_filter(array_map('trim', explode(',', (string)($pr['related_csv'] ?? '')))) as $sg) {
            if (!isset($lanes[$sg])) {
                $row = one("SELECT db_key,name FROM entries WHERE book_id=? AND slug=?", [$book['id'], $sg]);
                $lanes[$sg] = ['name'=>$row['name'] ?? $sg, 'db'=>$row['db_key'] ?? '', 'cells'=>[], 'n'=>0];
            }
            $lanes[$sg]['cells'][$bidx][] = $pr;
            $lanes[$sg]['n']++;
        }
    }
    $lanes = array_filter($lanes, function ($l) { return $l['n'] > 0; });   // drop empty lanes
    $unattr = array_values(array_filter($tl, function($pr){ return trim((string)($pr['related_csv'] ?? '')) === ''; }));
    uasort($lanes, function($a,$b){ return strcmp($a['name'],$b['name']); });
    // filter toolbar: one checkbox per lane (busiest entities tend to matter most)
    echo '<div class="tl-filter"><span class="muted">Lanes:</span> <button type="button" class="btn sm" id="tlf-all">All</button> <button type="button" class="btn sm" id="tlf-none">None</button>';
    foreach ($lanes as $sg => $lane)
        echo '<label class="tlf-item"><input type="checkbox" value="'.e($sg).'" checked> '.e($lane['name']).' <span class="muted mono">'.(int)$lane['n'].'</span></label>';
    echo '</div>';
    echo '<div class="tl-wrap"><table class="tl"><thead><tr><th class="tl-lane-h">Entity</th>';
    foreach ($buckets as $bi => $bk) echo '<th class="tl-bucket tlc-'.$bi.'">'.e($bk).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($lanes as $sg => $lane) {
        $nm = $lane['db'] ? '<a href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$lane['db'],'slug'=>$sg]).'">'.e($lane['name']).'</a>' : e($lane['name']);
        echo '<tr data-lane="'.e($sg).'"><th class="tl-lane">'.$nm.'</th>';
        foreach ($buckets as $bi => $bk) {
            echo '<td class="tl-cell tlc-'.$bi.'">';
            $seen = [];
            foreach (($lane['cells'][$bi] ?? []) as $pr) {
                $key = $pr['type'].'|'.$pr['what'];
                if (isset($seen[$key])) continue;          // collapse exact-duplicate beats in a cell
                $seen[$key] = true;
                $full = trim(strip_tags(inline_md($pr['what'])));   // plain-text tooltip
                echo '<div class="tl-beat tl-'.e($pr['type']).'" title="'.e($full).'"><span class="tl-type">'.e($pr['type']).'</span><div class="tl-what">'.inline_md($pr['what']).'</div></div>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
    // client-side lane filter + auto-hide of empty chapter columns; remembers your picks per book
    $nb = count($buckets); $bkey = 'tlf-'.$book['id'];
    echo '<script>(function(){var KEY='.json_encode($bkey).',NB='.$nb.';'
       . 'var boxes=[].slice.call(document.querySelectorAll(".tl-filter input"));'
       . 'var rows=[].slice.call(document.querySelectorAll("table.tl tbody tr"));'
       . 'function load(){try{return JSON.parse(localStorage.getItem(KEY)||"null")}catch(e){return null}}'
       . 'function apply(){var sel={};boxes.forEach(function(b){sel[b.value]=b.checked});'
       . 'rows.forEach(function(r){r.style.display=sel[r.getAttribute("data-lane")]===false?"none":""});'
       . 'for(var c=0;c<NB;c++){(function(c){var has=rows.some(function(r){if(r.style.display==="none")return false;var cell=r.querySelector(".tl-cell.tlc-"+c);return cell&&cell.children.length>0});'
       . 'document.querySelectorAll(".tlc-"+c).forEach(function(el){el.style.display=has?"":"none"})})(c)}'
       . 'try{localStorage.setItem(KEY,JSON.stringify(sel))}catch(e){}}'
       . 'var saved=load();if(saved)boxes.forEach(function(b){if(b.value in saved)b.checked=!!saved[b.value]});'
       . 'boxes.forEach(function(b){b.addEventListener("change",apply)});'
       . 'var a=document.getElementById("tlf-all"),n=document.getElementById("tlf-none");'
       . 'if(a)a.addEventListener("click",function(){boxes.forEach(function(b){b.checked=true});apply()});'
       . 'if(n)n.addEventListener("click",function(){boxes.forEach(function(b){b.checked=false});apply()});'
       . 'apply();})();</script>';
    if ($unattr) {
        echo '<h2 style="font-size:15px;margin-top:22px">Unattributed beats</h2><p class="desc">Beats with no <span class="mono">[[links]]</span> in <span class="mono">progressions.md</span> — add a link to give them a lane.</p><table class="grid"><tr><th>When</th><th>Type</th><th>What</th></tr>';
        foreach ($unattr as $pr) echo '<tr><td class="mono" style="white-space:nowrap">'.e($pr['_bucket']).'</td><td><span class="tag" style="color:#6B6253;background:#6B625318">'.e($pr['type']).'</span></td><td>'.inline_md($pr['what']).'</td></tr>';
        echo '</table>';
    }
    break;

case 'diagnostics':
    $cid = (int)($_GET['id'] ?? 0);
    $dgFlags = diagnostics_for($book['profile'] ?? 'fiction');
    $nfDiag = !empty($dgFlags['readability']) || !empty($dgFlags['citations']) || !empty($dgFlags['jargon']) || !empty($dgFlags['repetition']);
    if (!$cid && $nfDiag) {   // ---- non-fiction book-level battery (Phase 14) ----
        $D = get_nonfiction_diagnostics($book['id']);
        echo '<div class="pagehead"><div><h1>Diagnostics</h1><p class="desc">Non-fiction battery — citation coverage, readability, cross-chapter repetition, undefined terms'.(!empty($dgFlags['promises'])?', reader promises':'').'. These are <strong>patterns to review</strong>, never auto-applied. Click a chapter for detail.</p></div></div>';
        if (!$D['chapters']) { echo '<p class="empty">No chapters to analyze yet.</p>'; break; }
        echo '<table class="grid"><tr><th>Chapter</th><th>Words</th><th>Readability (FK grade)</th><th>Claims to source</th><th>Patterns</th></tr>';
        foreach ($D['chapters'] as $r) {
            $ch = $r['chapter']; $label = trim('Ch. '.$ch['num'].' '.($ch['title'] ? '— '.$ch['title'] : ''));
            $grade = $r['readability']['grade'] ?? null;
            $uns = $r['citations']['unsupported'] ?? 0;
            echo '<tr><td><a href="'.url(['p'=>'diagnostics','book'=>$book['id'],'id'=>$ch['id']]).'">'.e($label).'</a></td>'
               . '<td class="mono">'.number_format((int)$r['words']).'</td>'
               . '<td class="mono">'.($grade===null?'—':e((string)$grade).(($grade>14)?' <span class="diag-sev sev-med">dense</span>':'')).'</td>'
               . '<td class="mono">'.((int)$uns ? '<strong>'.(int)$uns.'</strong>' : '0').'</td>'
               . '<td class="mono">'.(int)$r['patterns'].'</td></tr>';
        }
        echo '</table>';
        // Citation coverage (orphan cite keys, book-wide) — from P12
        if (!empty($dgFlags['citations']) && $D['orphans']) {
            echo '<div class="diag-sec"><h2>Claims to source <span class="muted mono">'.count($D['orphans']).'</span></h2><p class="muted">Cited in prose with no matching source. <a href="'.url(['p'=>'references','book'=>$book['id']]).'">References →</a></p><div class="diag-chips">';
            foreach ($D['orphans'] as $o) echo '<span class="diag-chip"><code>'.e($o['cite_key']).'</code> <span class="muted mono">'.(int)$o['chapters'].' ch</span></span>';
            echo '</div></div>';
        }
        // Cross-chapter repetition
        if (!empty($dgFlags['repetition'])) {
            echo '<div class="diag-sec"><h2>Cross-chapter repetition <span class="muted mono">'.count($D['repetition']).'</span></h2>';
            if ($D['repetition']) { echo '<ul class="diag-list">';
                foreach ($D['repetition'] as $r) echo '<li><span class="diag-detail">“'.e($r['phrase']).'”</span><span class="muted mono">'.implode(', ', array_map('htmlspecialchars', $r['chapters'])).'</span></li>';
                echo '</ul>';
            } else echo '<p class="muted">No phrases repeated across chapters.</p>';
            echo '</div>';
        }
        // Undefined terms
        if (!empty($dgFlags['jargon'])) {
            echo '<div class="diag-sec"><h2>Undefined terms <span class="muted mono">'.count($D['undefined']).'</span></h2>';
            if ($D['undefined']) { echo '<p class="muted">Concepts referenced in prose that still have no definition.</p><div class="diag-chips">';
                foreach ($D['undefined'] as $u) echo '<a class="diag-chip" href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>'concepts','slug'=>$u['slug']]).'">'.e($u['name']).' <span class="muted mono">×'.(int)$u['mentions'].'</span></a>';
                echo '</div>';
            } else echo '<p class="muted">Every referenced concept has a definition.</p>';
            echo '</div>';
        }
        // Promise / payoff coverage (self-help)
        if (!empty($dgFlags['promises'])) {
            $tl = threads_label($book['profile'] ?? 'fiction');
            echo '<div class="diag-sec"><h2>'.e($tl['title']).' <span class="muted mono">'.count($D['promises']).' open</span></h2>';
            if ($D['promises']) { echo '<p class="muted">Open promises with no confirmed payoff yet — resolve each when the book delivers it. <a href="'.url(['p'=>'threads','book'=>$book['id']]).'">Tracker →</a></p><ul class="diag-list">';
                foreach ($D['promises'] as $t) echo '<li><span class="diag-kind">'.e($t['entry_name']).'</span><span class="diag-detail">'.inline_md($t['text']).'</span></li>';
                echo '</ul>';
            } else echo '<p class="muted">No open reader-promises.</p>';
            echo '</div>';
        }
        break;
    }
    if (!$cid) {   // ---- fiction book-level summary ----
        $rows = get_book_diagnostics($book['id']);
        echo '<div class="pagehead"><div><h1>Diagnostics</h1><p class="desc">Lexical analysis per chapter. These are <strong>patterns to review</strong> — stylistic tells and repetition, <em>not</em> a judgment of provenance or quality. Click a chapter for detail.</p></div></div>';
        if (!$rows) { echo '<p class="empty">No chapters to analyze yet.</p>'; break; }
        echo '<table class="grid"><tr><th>Chapter</th><th>Words</th><th>Patterns</th><th>Repeated phrases</th><th>Dialogue flags</th><th>Total</th></tr>';
        foreach ($rows as $r) {
            $ch = $r['chapter'];
            $label = trim('Ch. '.$ch['num'].' '.($ch['title'] ? '— '.$ch['title'] : ''));
            echo '<tr><td><a href="'.url(['p'=>'diagnostics','book'=>$book['id'],'id'=>$ch['id']]).'">'.e($label).'</a></td>'
               . '<td class="mono">'.number_format((int)$r['words']).'</td>'
               . '<td class="mono">'.(int)$r['patterns'].'</td><td class="mono">'.(int)$r['repeats'].'</td><td class="mono">'.(int)$r['bookisms'].'</td>'
               . '<td class="mono">'.($r['flags'] ? '<strong>'.(int)$r['flags'].'</strong>' : '0').'</td></tr>';
        }
        echo '</table>';
        break;
    }
    // ---- per-chapter detail ----
    $d = get_chapter_diagnostics($book['id'], $cid);
    if (!$d) { echo '<p class="empty">Chapter not found.</p>'; break; }
    $ch = $d['chapter']; $data = $d['data'];
    $label = trim('Ch. '.$ch['num'].' '.($ch['title'] ? '— '.$ch['title'] : ''));
    echo '<div class="pagehead"><div><h1>Diagnostics · '.e($label).'</h1><p class="desc">'.number_format((int)$data['words']).' words. <strong>Patterns to review</strong>, not a verdict on the writing. <a href="'.url(['p'=>'chapter','book'=>$book['id'],'id'=>$ch['id']]).'">Open chapter</a> · <a href="'.url(['p'=>'diagnostics','book'=>$book['id']]).'">All chapters</a></p></div></div>';

    // Patterns to review
    echo '<div class="diag-sec"><h2>Patterns to review</h2>';
    if ($data['patterns']) { echo '<ul class="diag-list">';
        foreach ($data['patterns'] as $f) { $sv = $f['sev'] ?? ''; echo '<li><span class="diag-kind">'.e($f['kind']).($sv?' <span class="diag-sev sev-'.strtolower($sv).'">'.e($sv).'</span>':'').'</span><span class="diag-detail">'.e($f['detail']).'</span></li>'; }
        echo '</ul>';
    } else echo '<p class="muted">Nothing flagged.</p>';
    echo '</div>';

    // Usage frequency
    echo '<div class="diag-sec"><h2>Usage frequency</h2>';
    if ($data['usage']['overused']) { echo '<p class="muted">Overused words</p><div class="diag-chips">';
        foreach ($data['usage']['overused'] as $o) echo '<span class="diag-chip">'.e($o['phrase']).' <span class="muted mono">×'.(int)$o['count'].'</span></span>';
        echo '</div>';
    }
    if ($data['usage']['repeated_phrases']) { echo '<p class="muted" style="margin-top:10px">Repeated phrases</p><ul class="diag-list">';
        foreach ($data['usage']['repeated_phrases'] as $p) echo '<li><span class="diag-detail">“'.e($p['phrase']).'”</span><span class="muted mono">×'.(int)$p['count'].'</span></li>';
        echo '</ul>';
    }
    if (!$data['usage']['overused'] && !$data['usage']['repeated_phrases']) echo '<p class="muted">No notable repetition.</p>';
    echo '</div>';

    // Readability (Phase 14, non-fiction)
    if (!empty($dgFlags['readability']) && !empty($data['readability']) && $data['readability']['grade'] !== null) {
        $rd = $data['readability'];
        echo '<div class="diag-sec"><h2>Readability</h2><div class="diag-chips">';
        echo '<span class="diag-chip">FK grade <span class="muted mono">'.e((string)$rd['grade']).'</span></span>';
        echo '<span class="diag-chip">Reading ease <span class="muted mono">'.e((string)$rd['ease']).'</span></span>';
        echo '<span class="diag-chip">Words/sentence <span class="muted mono">'.e((string)$rd['wps']).'</span></span>';
        echo '<span class="diag-chip">Long sentences (&gt;30w) <span class="muted mono">'.(int)$rd['long_sentences'].'</span></span>';
        echo '</div>';
        if ($rd['grade'] > 14) echo '<p class="muted">Grade '.e((string)$rd['grade']).' reads dense — consider shorter sentences and plainer words for a general audience.</p>';
        echo '</div>';
    }

    // Citation coverage (Phase 14, non-fiction)
    if (!empty($dgFlags['citations']) && isset($data['citations'])) {
        $cv = $data['citations'];
        echo '<div class="diag-sec"><h2>Citation coverage</h2>';
        echo '<p class="muted">'.(int)$cv['claims'].' claim-like sentence(s) · '.(int)$cv['supported'].' cited · <strong>'.(int)$cv['unsupported'].'</strong> to source.</p>';
        if (!empty($cv['examples'])) { echo '<ul class="diag-list">';
            foreach ($cv['examples'] as $ex) echo '<li><span class="diag-detail">'.e(mb_strimwidth($ex, 0, 180, '…')).'</span></li>';
            echo '</ul>';
        }
        echo '</div>';
    }

    // Dialogue control (fiction only)
    if (!empty($dgFlags['dialogue'])) {
    $dl = $data['dialogue'];
    echo '<div class="diag-sec"><h2>Dialogue control</h2>';
    echo '<p class="muted">'.(int)$dl['quotes'].' quoted span(s)'.(!empty($dl['tagged'])?', '.(int)$dl['tagged'].' attributed':'').'.</p>';
    if (!empty($dl['tags_per_speaker'])) { echo '<p class="muted" style="margin-top:8px">Tags per speaker</p><div class="diag-chips">';
        foreach ($dl['tags_per_speaker'] as $t) echo '<span class="diag-chip">'.e($t['speaker']).' <span class="muted mono">'.(int)$t['count'].'</span></span>';
        echo '</div>';
    }
    if ($dl['bookisms']) { echo '<p class="muted" style="margin-top:8px">Said-bookisms (consider “said/asked”)</p><div class="diag-chips">';
        foreach ($dl['bookisms'] as $b) echo '<span class="diag-chip">'.e($b['verb']).' <span class="muted mono">×'.(int)$b['count'].'</span></span>';
        echo '</div>';
    }
    if (!empty($dl['adverb_examples'])) { echo '<p class="muted" style="margin-top:8px">Adverb-laden tags</p><div class="diag-chips">';
        foreach ($dl['adverb_examples'] as $a) echo '<span class="diag-chip">'.e($a).'</span>';
        echo '</div>';
    }
    if (!$dl['bookisms'] && empty($dl['adverb_examples'])) echo '<p class="muted">No attribution flags.</p>';
    echo '</div>';
    }
    break;

case 'threads':
    $open = get_threads($book['id'], 'open'); $res = get_threads($book['id'], 'resolved');
    $tl = threads_label($book['profile'] ?? 'fiction');
    echo '<div class="pagehead"><div><h1>'.e($tl['title']).'</h1><p class="desc">'.count($open).' open · '.count($res).' resolved. '.e($tl['desc']).' Pulled from each entry’s “Open Threads” section.</p></div></div>';
    echo '<table class="grid"><tr><th>Entry</th><th>Thread</th><th></th></tr>';
    foreach ($open as $t) {
        echo '<tr><td><a href="'.url(['p'=>'entry','book'=>$book['id'],'db'=>$t['db_key'],'slug'=>$t['entry_slug']]).'">'.e($t['entry_name']).'</a></td><td>'.inline_md($t['text']).'</td>';
        echo '<td><form method="post"><input type="hidden" name="action" value="thread_status"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="id" value="'.$t['id'].'"><input type="hidden" name="status" value="resolved"><button class="btn sm">Resolve</button></form></td></tr>';
    }
    if (!$open) echo '<tr><td colspan="3" class="empty">No open threads.</td></tr>';
    echo '</table>';
    if ($res) { echo '<h2 style="font-size:15px;margin-top:24px">Resolved</h2><table class="grid"><tr><th>Entry</th><th>Thread</th><th></th></tr>';
        foreach ($res as $t) echo '<tr><td class="muted">'.e($t['entry_name']).'</td><td class="muted">'.inline_md($t['text']).'</td><td><form method="post"><input type="hidden" name="action" value="thread_status"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="id" value="'.$t['id'].'"><input type="hidden" name="status" value="open"><button class="btn sm">Reopen</button></form></td></tr>';
        echo '</table>'; }
    break;

case 'tasks':
    $allTasks = get_tasks($book['id']);
    $inbox = get_captures($book['id'], 'inbox');
    $bid = $book['id'];
    echo '<div class="pagehead"><div><h1>Tasks</h1><p class="desc">Capture, triage, then work top-down. Break anything big into tiny steps. Flag a task <strong>for Claude</strong> and say “check the web app for tasks and run them.”</p></div></div>';

    echo '<div class="notewrap"><form method="post"><input type="hidden" name="action" value="task_save"><input type="hidden" name="book" value="'.e($bid).'">';
    echo '<label class="f">New task</label><input type="text" name="title" placeholder="e.g. Draft a seed entry for the Arrowhead staging ground" required>';
    echo '<label class="f">Details / instructions (optional)</label><textarea name="body" style="min-height:64px"></textarea>';
    echo '<div class="formrow">';
    echo '<div><label class="f">Due</label><select name="due">';
    foreach (['today'=>'Today','tomorrow'=>'Tomorrow','week'=>'This week','someday'=>'Someday'] as $k=>$lab) echo '<option value="'.$k.'"'.($k==='today'?' selected':'').'>'.$lab.'</option>';
    echo '</select></div>';
    echo '<div><label class="f">Priority</label><select name="priority">';
    foreach (['high'=>'High','med'=>'Medium','low'=>'Low'] as $k=>$lab) echo '<option value="'.$k.'"'.($k==='med'?' selected':'').'>'.$lab.'</option>';
    echo '</select></div>';
    echo '<div><label class="f">Target database (optional)</label><select name="target_db"><option value="">—</option>';
    $tprofile = $book['profile'] ?? 'fiction';
    foreach (db_keys_for($tprofile) as $k) echo '<option value="'.$k.'">'.e(dbmeta($k,$tprofile)['title']).'</option>';
    echo '</select></div><div><label class="f">Target slug (optional)</label><input type="text" name="target_slug" placeholder="kebab-case"></div>';
    echo '</div>';
    echo '<label class="f" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="for_claude" value="1" style="width:auto" checked> Flag for Claude</label>';
    echo '<div class="toolbar"><button class="btn primary">Add task</button></div></form></div>';

    if ($inbox) {
        echo '<div class="triage"><div class="triage-h">Inbox to triage <span class="count">'.count($inbox).'</span></div>';
        foreach ($inbox as $cap) {
            $rb = '<input type="hidden" name="return_p" value="tasks"><input type="hidden" name="return_book" value="'.e($bid).'">';
            echo '<div class="triage-row"><span class="ov-cap-dot"></span><span class="triage-t">'.e($cap['text']).'</span>';
            echo '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="capture_triage"><input type="hidden" name="id" value="'.(int)$cap['id'].'">'.$rb.'<button class="btn sm">&rarr; Today</button></form> ';
            echo '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="capture_dismiss"><input type="hidden" name="id" value="'.(int)$cap['id'].'">'.$rb.'<button class="btn sm ghost">&times;</button></form>';
            echo '</div>';
        }
        echo '</div>';
    }

    $dueMeta = ['today'=>['Today','due-today'],'tomorrow'=>['Tomorrow','due-soon'],'week'=>['This week','due-soon'],'someday'=>['Someday','due-far']];
    $priMeta = ['high'=>['High','pri-high'],'med'=>['Med','pri-med'],'low'=>['Low','pri-low']];
    $cycleForm = function($action,$id,$label,$cls) use ($bid) {
        return '<form method="post" class="cycle"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="book" value="'.e($bid).'"><input type="hidden" name="id" value="'.(int)$id.'"><button class="taskpill '.$cls.'" title="Click to change">'.e($label).'</button></form>';
    };
    $renderTask = function($t) use ($bid,$dueMeta,$priMeta,$cycleForm) {
        $due = $t['due'] ?? 'someday'; $pri = $t['priority'] ?? 'med';
        $dm = $dueMeta[$due] ?? $dueMeta['someday']; $pm = $priMeta[$pri] ?? $priMeta['med'];
        $steps = get_task_steps($t['id']); $sdone = 0; foreach ($steps as $s) if ($s['done']) $sdone++;
        $done = $t['status']==='done';
        echo '<div class="task'.($done?' done':'').'"><div class="task-top">';
        echo '<form method="post" class="task-check"><input type="hidden" name="action" value="task_status"><input type="hidden" name="book" value="'.e($bid).'"><input type="hidden" name="id" value="'.$t['id'].'"><input type="hidden" name="status" value="'.($done?'todo':'done').'"><button class="checkbox'.($done?' on':'').'" title="'.($done?'Reopen':'Mark done').'">'.($done?'&#10003;':'').'</button></form>';
        echo '<div class="task-main"><div class="task-title">'.e($t['title']).'</div>';
        if (trim((string)$t['body'])!=='') echo '<div class="task-body">'.e($t['body']).'</div>';
        if ($t['target_slug']) echo '<div class="mono task-target">&rarr; '.e($t['target_db']).'/'.e($t['target_slug']).'</div>';
        if ($t['for_claude'] && trim((string)$t['result'])!=='') echo '<div class="task-result"><span class="pill doing">claude</span> '.e($t['result']).'</div>';
        echo '</div><div class="task-pills">';
        echo $cycleForm('task_cycle_due',$t['id'],$dm[0],$dm[1]);
        echo $cycleForm('task_cycle_priority',$t['id'],$pm[0],$pm[1]);
        if ($t['for_claude']) echo '<span class="taskpill claudeflag" title="Flagged for Claude">Claude</span>';
        echo '<form method="post" class="cycle" onsubmit="return confirm(\'Delete task?\')"><input type="hidden" name="action" value="task_delete"><input type="hidden" name="book" value="'.e($bid).'"><input type="hidden" name="id" value="'.$t['id'].'"><button class="taskpill del" title="Delete">&times;</button></form>';
        echo '</div></div>';
        echo '<div class="task-steps">';
        if ($steps) {
            echo '<div class="steps-prog">Steps '.$sdone.'/'.count($steps).'</div>';
            foreach ($steps as $s) {
                echo '<div class="step'.($s['done']?' done':'').'">';
                echo '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="step_toggle"><input type="hidden" name="book" value="'.e($bid).'"><input type="hidden" name="id" value="'.(int)$s['id'].'"><button class="checkbox sm'.($s['done']?' on':'').'">'.($s['done']?'&#10003;':'').'</button></form>';
                echo '<span class="step-t">'.e($s['text']).'</span>';
                echo '<form method="post" style="display:inline;margin:0"><input type="hidden" name="action" value="step_delete"><input type="hidden" name="book" value="'.e($bid).'"><input type="hidden" name="id" value="'.(int)$s['id'].'"><button class="step-x" title="Remove step">&times;</button></form>';
                echo '</div>';
            }
        }
        echo '<form method="post" class="step-add"><input type="hidden" name="action" value="step_add"><input type="hidden" name="book" value="'.e($bid).'"><input type="hidden" name="task_id" value="'.(int)$t['id'].'"><input type="text" name="text" placeholder="+ break into a step&hellip;" autocomplete="off"></form>';
        echo '</div></div>';
    };

    $openTasks = array_filter($allTasks, function($t){ return $t['status']!=='done'; });
    $doneTasks = array_filter($allTasks, function($t){ return $t['status']==='done'; });
    $groups = [
        'Today'    => function($t){ return ($t['due']??'someday')==='today'; },
        'Upcoming' => function($t){ $d=$t['due']??'someday'; return $d==='tomorrow'||$d==='week'; },
        'Someday'  => function($t){ return ($t['due']??'someday')==='someday'; },
    ];
    $any = false;
    foreach ($groups as $label=>$pred) {
        $rows = array_values(array_filter($openTasks, $pred));
        if (!$rows) continue;
        $any = true;
        usort($rows, function($a,$b){ $o=['high'=>0,'med'=>1,'low'=>2]; return ($o[$a['priority']??'med']??1) <=> ($o[$b['priority']??'med']??1); });
        echo '<div class="task-group"><div class="task-group-h"><span>'.$label.'</span><span class="count">'.count($rows).'</span></div>';
        foreach ($rows as $t) $renderTask($t);
        echo '</div>';
    }
    if (!$any) echo '<p class="empty">No open tasks. Capture something above, or enjoy the quiet.</p>';
    if ($doneTasks) {
        echo '<div class="task-group"><div class="task-group-h muted"><span>Done</span><span class="count">'.count($doneTasks).'</span></div>';
        foreach (array_values($doneTasks) as $t) $renderTask($t);
        echo '</div>';
    }
    break;

case 'log':
    $log = get_writing_log($book['id']);
    echo '<div class="pagehead"><div><h1>Writing log</h1><p class="desc">Sessions and word deltas. Claude can fill this automatically from your manuscript word counts during sync.</p></div></div>';
    echo '<div class="notewrap"><form method="post"><input type="hidden" name="action" value="log_add"><input type="hidden" name="book" value="'.e($book['id']).'">';
    echo '<div class="formrow"><div><label class="f">Date</label><input type="date" name="log_date" value="'.date('Y-m-d').'"></div>';
    echo '<div><label class="f">Words added</label><input type="number" name="words_added" value="0"></div>';
    echo '<div><label class="f">Minutes</label><input type="number" name="minutes" value="0"></div>';
    echo '<div><label class="f">Mood</label><input type="text" name="mood"></div></div>';
    echo '<label class="f">Note</label><input type="text" name="note">';
    echo '<div class="toolbar"><button class="btn primary">Log session</button></div></form></div>';
    echo '<table class="grid"><tr><th>Date</th><th>Words +</th><th>Total</th><th>Chapters</th><th>Min</th><th>Mood</th><th>Note</th><th>By</th></tr>';
    foreach ($log as $l) echo '<tr><td class="mono">'.e($l['log_date']).'</td><td class="mono">'.($l['words_added']>0?'+':'').number_format($l['words_added']).'</td><td class="mono">'.number_format($l['total_words']).'</td><td class="muted">'.e($l['chapters']).'</td><td class="mono">'.($l['minutes']?:'').'</td><td>'.e($l['mood']).'</td><td class="muted">'.e($l['note']).'</td><td>'.($l['source']==='claude'?'<span class="pill doing">claude</span>':($l['source']==='sprint'?'<span class="pill canon">sprint</span>':'<span class="muted">you</span>')).'</td></tr>';
    if (!$log) echo '<tr><td colspan="8" class="empty">No sessions logged yet.</td></tr>';
    echo '</table>';
    break;

case 'meta':
    $pages = get_meta($book['id']);
    echo '<div class="pagehead"><div><h1>Meta</h1><p class="desc">Workspace rules and notes that travel with the book.</p></div></div>';
    echo '<div class="cards">';
    foreach ($pages as $mp) echo '<a class="card" href="'.url(['p'=>'meta_page','book'=>$book['id'],'slug'=>$mp['slug']]).'"><div class="ctitle">'.e($mp['title']).'</div><div class="clog">'.e(mb_substr(trim(strip_tags($mp['body'])),0,120)).'…</div></a>';
    if (!$pages) echo '<p class="empty">No meta pages.</p>';
    echo '</div>';
    break;

case 'meta_page':
    $mp = get_meta_page($book['id'], $_GET['slug']);
    if (!$mp) { echo '<p class="empty">Not found.</p>'; break; }
    $edit = isset($_GET['edit']);
    echo '<div class="pagehead"><div><h1>'.e($mp['title']).'</h1></div></div>';
    if ($edit) {
        echo '<form method="post"><input type="hidden" name="action" value="meta_save"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="slug" value="'.e($mp['slug']).'">';
        echo '<label class="f">Title</label><input type="text" name="title" value="'.e($mp['title']).'">';
        echo '<label class="f">Body (markdown)</label><textarea name="body">'.e($mp['body']).'</textarea>';
        echo '<div class="toolbar"><button class="btn primary">Save</button><a class="btn" href="'.url(['p'=>'meta_page','book'=>$book['id'],'slug'=>$mp['slug']]).'">Cancel</a></div></form>';
    } else {
        echo '<div class="toolbar"><a class="btn sm" href="'.url(['p'=>'meta_page','book'=>$book['id'],'slug'=>$mp['slug'],'edit'=>1]).'">Edit</a></div>';
        echo '<div class="entrybody">'.md_to_html($mp['body'], $book['id']).'</div>';
    }
    break;

case 'notes':
    $pages = get_notes($book['id']);
    echo '<div class="pagehead"><div><h1>Notes</h1><p class="desc">Planning docs from your Codex <span class="kbd">Notes</span> folder — outline, beats, research. Read-only here; authored in your folders and synced in.</p></div></div>';
    if (!$pages) { echo '<p class="empty">No notes synced yet. Add markdown under <span class="mono">Codex/Notes/</span> and run a sync.</p>'; break; }
    echo '<div class="cards">';
    foreach ($pages as $np) echo '<a class="card" href="'.url(['p'=>'note_page','book'=>$book['id'],'slug'=>$np['slug']]).'"><div class="ctitle">'.e($np['title']).'</div><div class="clog mono" style="font-size:12px">Codex/Notes/'.e($np['slug']).'.md</div><div class="clog">'.e(mb_substr(trim(strip_tags($np['body'])),0,120)).'…</div></a>';
    echo '</div>';
    break;

case 'note_page':
    $np = get_note_page($book['id'], $_GET['slug']);
    if (!$np) { echo '<p class="empty">Not found.</p>'; break; }
    echo '<div class="pagehead"><div><h1>'.e($np['title']).'</h1><p class="desc mono" style="font-size:12px">Codex/Notes/'.e($np['slug']).'.md · folder-authored (read-only)</p></div></div>';
    echo '<div class="toolbar"><a class="btn sm" href="'.url(['p'=>'notes','book'=>$book['id']]).'">← All notes</a></div>';
    echo '<div class="entrybody">'.md_to_html($np['body'], $book['id']).'</div>';
    break;

case 'plot':
    $cards = get_canvas_cards($book['id']);
    $links = get_canvas_links($book['id']);
    foreach ($cards as &$c) {   // Phase 8: attach live ref info to bound cards
        if (!empty($c['ref_type'])) {
            $info = canvas_ref_resolve($book['id'], $c['ref_type'], $c['ref_id']);
            if ($info) { $info['href'] = url($info['p']); unset($info['p']); $c['ref'] = $info; }
            else { $c['ref'] = ['missing'=>true]; }
        }
    }
    unset($c);
    echo '<div class="pagehead"><div><h1>Plot board</h1><p class="desc">A spatial corkboard. Drag cards around, drag from a card’s dot to another to connect related beats. Add free-text cards, or pull in a real character, location, thread, beat, or scene with <strong>+ Add from Codex</strong> — those cards show its live title and status.</p></div></div>';
    echo '<div class="toolbar"><button class="btn primary sm" id="plotAdd">+ New card</button><button class="btn sm" id="plotAddRef">+ Add from Codex</button><span class="muted" id="plotCount" style="margin-left:8px">'.count($cards).(count($cards)==1?' card':' cards').'</span></div>';
    echo '<div class="plot-surface" id="plotSurface"><svg id="plotSvg"></svg></div>';
    echo '<div class="refpick" id="refPick" hidden><div class="refpick-box"><div class="refpick-head"><input type="text" id="refPickSearch" placeholder="Search characters, locations, threads, beats, scenes…" autocomplete="off"><button type="button" class="btn sm" id="refPickClose">Close</button></div><div class="refpick-list" id="refPickList"></div></div></div>';
    echo '<script>window.__plot='.json_encode(['book'=>$book['id'],'cards'=>$cards,'links'=>$links], JSON_UNESCAPED_UNICODE).';'
       . 'window.__plotRefs='.json_encode(canvas_ref_options($book['id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';</script>';
    echo <<<'JS'
<script>
(function(){
  var data=window.__plot||{cards:[],links:[],book:''};
  var surface=document.getElementById('plotSurface'), svg=document.getElementById('plotSvg');
  if(!surface) return;
  var book=data.book, W=212, H=120, colors=['#7c8cff','#5ec98a','#e0a05a','#c98ad6','#e07a8a'];
  var cards=(data.cards||[]).map(function(c){return {id:+c.id,x:+c.x,y:+c.y,text:c.text||'',color:c.color||'#7c8cff',ref:c.ref||null};});
  var links=(data.links||[]).map(function(l){return {id:+l.id,from:+l.from_id,to:+l.to_id};});
  function post(action,p){ p.action=action; p.book=book; return fetch('?',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams(p)}).then(function(r){return r.json();}); }
  function byId(id){ for(var i=0;i<cards.length;i++) if(cards[i].id===id) return cards[i]; return null; }
  function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(m){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m];}); }
  function center(c){ return {x:c.x+W/2,y:c.y+H/2}; }
  function svgLine(a){ return document.createElementNS('http://www.w3.org/2000/svg',a); }
  function drawLinks(){
    svg.innerHTML='';
    links.forEach(function(l){
      var a=byId(l.from), b=byId(l.to); if(!a||!b) return;
      var p1=center(a), p2=center(b);
      var hit=svgLine('line'); hit.setAttribute('x1',p1.x);hit.setAttribute('y1',p1.y);hit.setAttribute('x2',p2.x);hit.setAttribute('y2',p2.y);
      hit.setAttribute('stroke','transparent');hit.setAttribute('stroke-width','16');hit.style.cursor='pointer';hit.style.pointerEvents='stroke';
      hit.addEventListener('click',function(){ if(confirm('Remove this connection?')){ post('canvas_delete_link',{id:l.id}); links=links.filter(function(x){return x.id!==l.id;}); drawLinks(); } });
      var ln=svgLine('line'); ln.setAttribute('x1',p1.x);ln.setAttribute('y1',p1.y);ln.setAttribute('x2',p2.x);ln.setAttribute('y2',p2.y);
      ln.setAttribute('stroke',a.color);ln.setAttribute('stroke-width','2.5');ln.setAttribute('stroke-opacity','0.6');ln.setAttribute('stroke-linecap','round');ln.style.pointerEvents='none';
      svg.appendChild(hit); svg.appendChild(ln);
    });
  }
  function updateCount(){ var el=document.getElementById('plotCount'); if(el) el.textContent=cards.length+(cards.length===1?' card':' cards'); }
  function renderCard(c){
    var el=document.createElement('div'); el.className='plot-card'; el.style.left=c.x+'px'; el.style.top=c.y+'px'; el.setAttribute('data-id',c.id);
    var head=document.createElement('div'); head.className='plot-card-h'; head.style.background=c.color+'22';
    var dot=document.createElement('span'); dot.className='plot-dot'; dot.style.background=c.color;
    var grab=document.createElement('span'); grab.className='plot-grab'; grab.textContent='drag';
    var link=document.createElement('span'); link.className='plot-link'; link.style.borderColor=c.color; link.title='Drag to connect'; link.innerHTML='<i style="background:'+c.color+'"></i>';
    var del=document.createElement('span'); del.className='plot-del'; del.innerHTML='&times;'; del.title='Delete';
    head.appendChild(dot); head.appendChild(grab); head.appendChild(link); head.appendChild(del);
    var ta=null;
    if(c.ref){   // Phase 8: a card bound to a Codex object — read-only, shows live title/status
      el.classList.add('plot-card-ref');
      var body=document.createElement('div'); body.className='plot-ref';
      if(c.ref.missing){ body.innerHTML='<div class="plot-ref-missing">Linked item was removed.</div>'; }
      else { body.innerHTML='<div class="plot-ref-kind" style="color:'+(c.color)+'">'+esc(c.ref.kind)+'</div>'
              +'<div class="plot-ref-title">'+esc(c.ref.title)+'</div>'
              +'<div class="plot-ref-foot">'+(c.ref.status?'<span class="plot-ref-status">'+esc(c.ref.status)+'</span>':'<span></span>')
              +'<a class="plot-ref-open" href="'+esc(c.ref.href)+'">Open →</a></div>'; }
      el.appendChild(head); el.appendChild(body);
    } else {
      ta=document.createElement('textarea'); ta.className='plot-text'; ta.value=c.text; ta.placeholder='Type a beat…';
      el.appendChild(head); el.appendChild(ta);
    }
    surface.appendChild(el);
    head.addEventListener('pointerdown',function(e){ if(e.target===link||e.target===del||link.contains(e.target)) return; e.preventDefault();
      var sx=e.clientX, sy=e.clientY, ox=c.x, oy=c.y; head.setPointerCapture(e.pointerId);
      function mv(ev){ c.x=Math.max(0,ox+(ev.clientX-sx)); c.y=Math.max(0,oy+(ev.clientY-sy)); el.style.left=c.x+'px'; el.style.top=c.y+'px'; drawLinks(); }
      function up(){ head.removeEventListener('pointermove',mv); head.removeEventListener('pointerup',up); post('canvas_move_card',{id:c.id,x:Math.round(c.x),y:Math.round(c.y)}); }
      head.addEventListener('pointermove',mv); head.addEventListener('pointerup',up);
    });
    if(ta){
      ta.addEventListener('change',function(){ c.text=ta.value; post('canvas_text_card',{id:c.id,text:ta.value}); });
      ta.addEventListener('blur',function(){ if(c.text!==ta.value){ c.text=ta.value; post('canvas_text_card',{id:c.id,text:ta.value}); } });
    }
    del.addEventListener('click',function(){ if(confirm('Delete this card?')){ post('canvas_delete_card',{id:c.id}); cards=cards.filter(function(x){return x.id!==c.id;}); links=links.filter(function(x){return x.from!==c.id&&x.to!==c.id;}); el.remove(); drawLinks(); updateCount(); } });
    link.addEventListener('pointerdown',function(e){ e.preventDefault(); e.stopPropagation();
      var rect=surface.getBoundingClientRect(); var p1=center(c);
      var tmp=svgLine('line'); tmp.setAttribute('x1',p1.x);tmp.setAttribute('y1',p1.y);tmp.setAttribute('x2',p1.x);tmp.setAttribute('y2',p1.y);
      tmp.setAttribute('stroke',c.color);tmp.setAttribute('stroke-width','2.5');tmp.setAttribute('stroke-dasharray','6 6');tmp.style.pointerEvents='none'; svg.appendChild(tmp);
      link.setPointerCapture(e.pointerId);
      function mv(ev){ tmp.setAttribute('x2',ev.clientX-rect.left+surface.scrollLeft); tmp.setAttribute('y2',ev.clientY-rect.top+surface.scrollTop); }
      function up(ev){ link.removeEventListener('pointermove',mv); link.removeEventListener('pointerup',up); tmp.remove();
        var t=document.elementFromPoint(ev.clientX,ev.clientY); var ce=t&&t.closest?t.closest('.plot-card'):null;
        if(ce){ var toId=+ce.getAttribute('data-id'); if(toId&&toId!==c.id&&!links.some(function(x){return (x.from===c.id&&x.to===toId)||(x.from===toId&&x.to===c.id);})){ post('canvas_add_link',{from:c.id,to:toId}).then(function(res){ if(res&&res.id){ links.push({id:+res.id,from:c.id,to:toId}); drawLinks(); } }); } }
      }
      link.addEventListener('pointermove',mv); link.addEventListener('pointerup',up);
    });
  }
  cards.forEach(renderCard); drawLinks();
  var addBtn=document.getElementById('plotAdd');
  if(addBtn) addBtn.addEventListener('click',function(){ var n=cards.length, color=colors[n%colors.length], x=40+(n%4)*60, y=40+(n%3)*50;
    post('canvas_add_card',{x:x,y:y,color:color}).then(function(res){ if(res&&res.id){ var c={id:+res.id,x:x,y:y,text:'',color:color}; cards.push(c); renderCard(c); updateCount(); } }); });

  // --- Phase 8: "+ Add from Codex" picker ---
  var refs=window.__plotRefs||[];
  var pick=document.getElementById('refPick'), pickList=document.getElementById('refPickList'), pickSearch=document.getElementById('refPickSearch');
  function nextPos(){ var n=cards.length; return {x:60+(n%5)*40, y:60+(n%4)*40}; }
  function addRef(type,id){
    var p=nextPos();
    post('canvas_add_ref_card',{x:p.x,y:p.y,ref_type:type,ref_id:id}).then(function(res){
      if(res&&res.ok&&res.id){ var c={id:+res.id,x:p.x,y:p.y,text:'',color:res.color||'#7c8cff',ref:res.ref}; cards.push(c); renderCard(c); updateCount(); closePick(); }
      else { alert((res&&res.error)||'Could not add that.'); }
    });
  }
  function renderList(filter){
    filter=(filter||'').toLowerCase().trim();
    pickList.innerHTML='';
    var shown=0;
    refs.forEach(function(o){
      var hay=(o.label+' '+o.sub+' '+o.type).toLowerCase();
      if(filter && hay.indexOf(filter)<0) return;
      if(shown>=200) return; shown++;
      var row=document.createElement('button'); row.type='button'; row.className='refpick-row';
      row.innerHTML='<span class="refpick-type rt-'+esc(o.type)+'">'+esc(o.type)+'</span><span class="refpick-label">'+esc(o.label)+'</span><span class="refpick-sub">'+esc(o.sub)+'</span>';
      row.addEventListener('click',function(){ addRef(o.type,o.id); });
      pickList.appendChild(row);
    });
    if(!shown){ var e=document.createElement('div'); e.className='refpick-empty'; e.textContent='No matches.'; pickList.appendChild(e); }
  }
  function openPick(){ if(!pick) return; pick.hidden=false; pickSearch.value=''; renderList(''); pickSearch.focus(); }
  function closePick(){ if(pick) pick.hidden=true; }
  var addRefBtn=document.getElementById('plotAddRef'); if(addRefBtn) addRefBtn.addEventListener('click',openPick);
  var closeBtn=document.getElementById('refPickClose'); if(closeBtn) closeBtn.addEventListener('click',closePick);
  if(pickSearch) pickSearch.addEventListener('input',function(){ renderList(pickSearch.value); });
  if(pick) pick.addEventListener('click',function(e){ if(e.target===pick) closePick(); });
  document.addEventListener('keydown',function(e){ if(e.key==='Escape') closePick(); });
})();
</script>
JS;
    break;

case 'vision':
    $items = get_vision($book['id']);
    echo '<div class="pagehead"><div><h1>Mood board</h1><p class="desc">Inspiration for this book — character art, settings, objects, a feeling to write toward.</p></div></div>';
    echo '<div class="notewrap"><form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="vision_add"><input type="hidden" name="book" value="'.e($book['id']).'">';
    echo '<label class="f">Image URL</label><input type="text" name="image_url" placeholder="https://…  (or choose a file below)">';
    echo '<label class="f">…or upload an image</label><input type="file" name="image" accept="image/*" style="width:auto;display:inline-block">';
    echo '<label class="f">Caption (optional)</label><input type="text" name="caption" placeholder="What this is / why it matters">';
    echo '<div class="toolbar"><button class="btn primary">Add to board</button></div></form></div>';
    if (!$items) { echo '<p class="empty">Nothing pinned yet. Add an image above.</p>'; break; }
    echo '<div class="vision-grid">';
    foreach ($items as $v) {
        echo '<div class="vision-tile">';
        echo '<div class="vision-img" style="background-image:url(\''.e($v['image_url']).'\')"></div>';
        if (trim((string)$v['caption']) !== '') echo '<div class="vision-cap">'.e($v['caption']).'</div>';
        echo '<form method="post" class="vision-del" onsubmit="return confirm(\'Remove this image?\')"><input type="hidden" name="action" value="vision_delete"><input type="hidden" name="book" value="'.e($book['id']).'"><input type="hidden" name="id" value="'.(int)$v['id'].'"><button class="btn sm" title="Remove">&times;</button></form>';
        echo '</div>';
    }
    echo '</div>';
    break;

case 'sync':
    $token = $CFG['api_token'];
    $base = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'?'https':'http').'://'.($_SERVER['HTTP_HOST']??'your-host').dirname($_SERVER['SCRIPT_NAME']);
    echo '<div class="pagehead"><div><h1>Sync</h1><p class="desc">Two-way sync between this app and your Cowork book folders. A PowerShell scheduled task on your PC moves the data; the Claude skill runs flagged tasks and fills the writing log.</p></div></div>';
    echo '<div class="notewrap"><h2 style="margin-top:0;font-size:15px">How it flows</h2><ol style="color:var(--muted);line-height:1.7">'
       . '<li><strong>Folder &rarr; app:</strong> the scheduled task reads your <span class="kbd">Codex</span> markdown and POSTs it here (non-destructive upsert).</li>'
       . '<li><strong>App &rarr; folder:</strong> it pulls entries you edited here and writes them back as markdown.</li>'
       . '<li><strong>Tasks &amp; log:</strong> it pulls tasks flagged for Claude into a local sync folder; you say &ldquo;check the web app for tasks and run them&rdquo;; Claude runs them and the next sync posts the results + writing-log rows back.</li></ol></div>';
    echo '<div class="notewrap"><h2 style="margin-top:0;font-size:15px">Connection</h2>';
    echo '<div class="fieldtable"><div class="row"><div class="lbl">API base URL</div><div class="v mono">'.e($base).'/api.php</div></div>';
    echo '<div class="row"><div class="lbl">API token</div><div class="v mono">'.(($token==='CHANGE_ME_TO_A_LONG_RANDOM_STRING')?'<span style="color:#A8485A">set api_token in config.php</span>':e(substr($token,0,4).'&hellip;'.substr($token,-4))).'</div></div>';
    echo '<div class="row"><div class="lbl">Endpoints</div><div class="v mono">GET /api.php?action=ping &middot; pull &middot; tasks &middot; writing-log<br>POST /api.php?action=push &middot; apply &middot; import</div></div></div></div>';
    echo '<div class="notewrap"><h2 style="margin-top:0;font-size:15px">Manual import / export</h2>';
    echo '<p class="muted">No automation yet? Import a snapshot.json produced by the sync skill, or grab the current data as a snapshot.</p>';
    echo '<form method="post" enctype="multipart/form-data"><input type="hidden" name="action" value="sync_import"><input type="file" name="file" accept=".json" style="width:auto;display:inline-block"> <button class="btn">Import snapshot.json</button></form>';
    echo '<div class="toolbar"><a class="btn" href="api.php?action=export&token='.e($token).'" target="_blank">Download current snapshot.json</a></div></div>';
    break;

default:
    echo '<p class="empty">Page not found.</p>';
}

echo '</div></div></div>';
render_sprint_overlay();
layout_foot();