<?php
/** repo.php — data access + sync logic (import / push / pull / apply). */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/md.php';
require_once __DIR__ . '/profiles.php';   // book profiles (Phase 10): db sets, templates, band labels

/* ----------------------------------------------------------------- books */
/** Lazy-migration for the Phase 10 book profile. Additive column; existing books
 *  default to 'fiction' so they behave byte-for-byte as before. */
function ensure_book_profile() {
    static $done = false; if ($done) return; $done = true;
    try { db()->exec("ALTER TABLE books ADD COLUMN profile VARCHAR(20) DEFAULT 'fiction'"); } catch (Exception $e) {}
    try { db()->exec("UPDATE books SET profile='fiction' WHERE profile IS NULL OR profile=''"); } catch (Exception $e) {}
}
function get_books() {
    ensure_book_profile();
    $rows = all("SELECT * FROM books ORDER BY sort_order, title");
    foreach ($rows as &$b) $b = decorate_book($b);
    return $rows;
}
function get_book($id) {
    ensure_book_profile();
    $b = one("SELECT * FROM books WHERE id=?", [$id]);
    return $b ? decorate_book($b) : null;
}
/** Raw profile id for a book (default 'fiction'). Cheap; used where only the
 *  profile is needed (e.g. the POST handlers that work from a book id string). */
function book_profile($book_id) {
    ensure_book_profile();
    return normalize_profile(val("SELECT profile FROM books WHERE id=?", [$book_id]) ?: 'fiction');
}
/** Set a book's profile (validated to a known profile id). */
function set_book_profile($book_id, $profile) {
    ensure_book_profile();
    q("UPDATE books SET profile=? WHERE id=?", [normalize_profile($profile), $book_id]);
}
function decorate_book($b) {
    $id = $b['id'];
    $b['profile'] = normalize_profile($b['profile'] ?? 'fiction');
    $b['entryCount']   = (int) val("SELECT COUNT(*) FROM entries WHERE book_id=?", [$id]);
    $b['chapterCount'] = (int) val("SELECT COUNT(*) FROM chapters WHERE book_id=? AND status<>'archived'", [$id]);
    $b['wordCount']    = (int) val("SELECT COALESCE(SUM(word_count),0) FROM chapters WHERE book_id=? AND status<>'archived'", [$id]);
    $b['archivedCount']= (int) val("SELECT COUNT(*) FROM chapters WHERE book_id=? AND status='archived' AND LOWER(file) NOT LIKE '%readme.md'", [$id]);
    $b['threadCount']  = (int) val("SELECT COUNT(*) FROM threads WHERE book_id=? AND status='open'", [$id]);
    $b['taskCount']    = (int) val("SELECT COUNT(*) FROM tasks WHERE book_id=? AND status<>'done'", [$id]);
    return $b;
}
function save_book($d) {
    ensure_book_profile();
    $exists = one("SELECT id FROM books WHERE id=?", [$d['id']]);
    if ($exists) {
        q("UPDATE books SET folder=?,title=?,series=?,num=?,status=?,logline=?,genre=?,word_target=?,dot=?,sort_order=?
           WHERE id=?",
          [$d['folder'],$d['title'],$d['series']??'',$d['num']??'',$d['status']??'planning',
           $d['logline']??'',$d['genre']??'',$d['word_target']??'',$d['dot']??'#4A4391',$d['sort_order']??0,$d['id']]);
    } else {
        q("INSERT INTO books (id,folder,title,series,num,status,logline,genre,word_target,dot,sort_order,profile)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [$d['id'],$d['folder'],$d['title'],$d['series']??'',$d['num']??'',$d['status']??'planning',
           $d['logline']??'',$d['genre']??'',$d['word_target']??'',$d['dot']??'#4A4391',$d['sort_order']??0,
           normalize_profile($d['profile'] ?? 'fiction')]);
    }
    // Profile is only written when explicitly supplied, so a folder sync (which
    // never sends a profile) can't reset an author's choice back to fiction.
    if (isset($d['profile'])) set_book_profile($d['id'], $d['profile']);
}
function book_id_for_folder($folder) {
    return val("SELECT id FROM books WHERE folder=?", [$folder]);
}

/* --------------------------------------------------------------- entries */
function get_entries($book_id, $db = null) {
    if ($db) return all("SELECT * FROM entries WHERE book_id=? AND db_key=? ORDER BY name", [$book_id, $db]);
    return all("SELECT * FROM entries WHERE book_id=? ORDER BY db_key, name", [$book_id]);
}
function get_entry($book_id, $db, $slug) {
    $row = one("SELECT * FROM entries WHERE book_id=? AND db_key=? AND slug=?", [$book_id, $db, $slug]);
    if (!$row) return null;
    return entry_to_struct($row);
}
function get_entry_by_id($id) {
    $row = one("SELECT * FROM entries WHERE id=?", [$id]);
    return $row ? entry_to_struct($row) : null;
}
function entry_to_struct($row) {
    $eid = $row['id'];
    $fields = all("SELECT label,value FROM entry_fields WHERE entry_id=? ORDER BY sort_order", [$eid]);
    $secs   = all("SELECT heading AS h, body FROM entry_sections WHERE entry_id=? ORDER BY sort_order", [$eid]);
    $rels   = array_column(all("SELECT target_slug FROM entry_relations WHERE entry_id=? ORDER BY sort_order", [$eid]), 'target_slug');
    $threads = []; $sources = [];
    foreach ($secs as $s) {
        $hl = strtolower($s['h']);
        if ($hl === 'open threads' || $hl === 'threads') {
            foreach (explode("\n", $s['body']) as $b) {
                $b = trim($b);
                if ($b !== '' && ($b[0] === '-' || $b[0] === '*')) $threads[] = trim(preg_replace('/^[-*]\s+/', '', $b));
            }
        }
    }
    return [
        'id' => $eid, 'slug' => $row['slug'], 'name' => $row['name'], 'db' => $row['db_key'],
        'status' => $row['status'], 'type' => $row['type'],
        'detail' => $row['detail'], 'detailLabel' => $row['detail_label'], 'firstApp' => $row['first_app'],
        'fields' => $fields, 'related' => $rels, 'relatedRaw' => $row['related_raw'],
        'sections' => $secs, 'threads' => $threads, 'sources' => $sources,
    ];
}
function save_entry($book_id, $db, $e) {
    $meta = dbmeta($db);
    $detail_label = $meta['detailLabel'];
    $detail = ''; $first_app = '';
    foreach (($e['fields'] ?? []) as $f) {
        if (strtolower($f['label']) === strtolower($detail_label)) $detail = $f['value'];
        if (strtolower($f['label']) === 'first appearance') $first_app = $f['value'];
    }
    $pdo = db(); $pdo->beginTransaction();
    try {
        $row = one("SELECT id FROM entries WHERE book_id=? AND db_key=? AND slug=?", [$book_id, $db, $e['slug']]);
        if ($row) {
            $eid = $row['id'];
            q("UPDATE entries SET name=?,status=?,type=?,detail=?,detail_label=?,first_app=?,related_raw=? WHERE id=?",
              [$e['name'], $e['status'] ?? 'seed', $e['type'] ?? $meta['singular'],
               $detail, $detail_label, $first_app, $e['relatedRaw'] ?? null, $eid]);
        } else {
            q("INSERT INTO entries (book_id,db_key,slug,name,status,type,detail,detail_label,first_app,related_raw)
               VALUES (?,?,?,?,?,?,?,?,?,?)",
              [$book_id, $db, $e['slug'], $e['name'], $e['status'] ?? 'seed', $e['type'] ?? $meta['singular'],
               $detail, $detail_label, $first_app, $e['relatedRaw'] ?? null]);
            $eid = last_id();
        }
        q("DELETE FROM entry_fields WHERE entry_id=?", [$eid]);
        q("DELETE FROM entry_sections WHERE entry_id=?", [$eid]);
        q("DELETE FROM entry_relations WHERE entry_id=?", [$eid]);
        $i = 0;
        foreach (($e['fields'] ?? []) as $f)
            q("INSERT INTO entry_fields (entry_id,label,value,sort_order) VALUES (?,?,?,?)",
              [$eid, $f['label'], $f['value'], $i++]);
        $i = 0;
        foreach (($e['sections'] ?? []) as $s)
            q("INSERT INTO entry_sections (entry_id,heading,body,sort_order) VALUES (?,?,?,?)",
              [$eid, $s['h'], $s['body'], $i++]);
        $i = 0;
        foreach (($e['related'] ?? []) as $r)
            q("INSERT INTO entry_relations (entry_id,target_slug,sort_order) VALUES (?,?,?)", [$eid, $r, $i++]);
        $pdo->commit();
        return $eid;
    } catch (Exception $ex) { $pdo->rollBack(); throw $ex; }
}
function delete_entry($book_id, $db, $slug) {
    $row = one("SELECT id FROM entries WHERE book_id=? AND db_key=? AND slug=?", [$book_id, $db, $slug]);
    if (!$row) return;
    $eid = $row['id'];
    foreach (['entry_fields','entry_sections','entry_relations'] as $t) q("DELETE FROM $t WHERE entry_id=?", [$eid]);
    q("DELETE FROM entries WHERE id=?", [$eid]);
}
function entry_render_md($book_id, $db, $slug) {
    $e = get_entry($book_id, $db, $slug);
    return $e ? md_render_entry($e) : null;
}

/* ------------------------------------------------------ chapters / prog */
/** Live chapters (archived excluded by default). Pass true to include archived. */
function get_chapters($book_id, $includeArchived = false) {
    ensure_structure();   // guarantees the act_id column exists before we select it
    $w = "book_id=? AND LOWER(file) NOT LIKE '%readme.md'";
    if (!$includeArchived) $w .= " AND status<>'archived'";
    return all("SELECT id,book_id,num,title,pov,status,words,word_count,summary,file,sort_order,act_id,grid_seq FROM chapters WHERE $w ORDER BY (num+0), num, file", [$book_id]);
}
/** Chapters that have been archived (folder-removed or archived in the app). */
function get_archived_chapters($book_id) {
    return all("SELECT id,book_id,num,title,pov,status,words,word_count,summary,file,sort_order FROM chapters WHERE book_id=? AND status='archived' AND LOWER(file) NOT LIKE '%readme.md' ORDER BY (num+0), num, file", [$book_id]);
}
function get_chapter($id) { return one("SELECT * FROM chapters WHERE id=?", [$id]); }
function set_chapter_status($id, $status) {
    $ok = ['outline','drafted','revised','archived'];
    if (in_array($status, $ok, true)) q("UPDATE chapters SET status=? WHERE id=?", [$status, $id]);
}
/** Hard-delete a single chapter row (used by the explicit "Delete" control). */
function delete_chapter($id) { q("DELETE FROM chapters WHERE id=?", [$id]); }

/** Phase 9 — write edited chapter prose back to the folder .md AND the DB.
 *  The ONLY place the app writes manuscript prose to disk. Implements the
 *  CONFLICT-not-overwrite rule: if the on-disk file differs from what the DB last
 *  synced (chapters.body), the folder changed since this edit was loaded, so we
 *  REFUSE and report a conflict instead of clobbering. Backs the file up first.
 *  Returns ['ok'|'conflict'|'error', 'msg'=>...]. */
function write_chapter_file($book_id, $chapter_id, $new_md, $base = '') {
    $books = cfg()['books_dir'] ?? '';
    if (!$books) return ['status'=>'error', 'msg'=>'Chapter editing is disabled (CODEX_BOOKS_DIR not set).'];
    $c = get_chapter($chapter_id);
    if (!$c || $c['book_id'] !== $book_id) return ['status'=>'error', 'msg'=>'Chapter not found.'];
    $b = get_book($book_id);
    if (!$b) return ['status'=>'error', 'msg'=>'Book not found.'];

    $rel = ltrim(str_replace('\\', '/', (string)$c['file']), '/');
    if ($rel === '' || strpos($rel, '..') !== false) return ['status'=>'error', 'msg'=>'Bad chapter path.'];
    $path = rtrim($books, '/').'/'.$b['folder'].'/Manuscript/'.$rel;

    $norm = function ($s) { return str_replace(["\r\n", "\r", "\x00"], ["\n", "\n", ''], (string)$s); };
    $new_md = $norm($new_md);
    $dbBody = $norm($c['body']);

    // Guard against a sync updating this chapter between open and save: the edit
    // page stamps md5() of the body it loaded; refuse if the DB no longer matches.
    if ($base !== '' && $base !== md5($dbBody))
        return ['status'=>'conflict', 'msg'=>'This chapter changed since you opened the editor (a sync updated it). Re-open the chapter and reapply your changes.'];

    if (is_file($path)) {
        $onDisk = $norm(@file_get_contents($path));
        if ($onDisk !== $dbBody)
            return ['status'=>'conflict', 'msg'=>'The chapter file changed on disk since you opened it (an external edit or sync). Re-open the chapter and reapply your changes.'];
        if ($onDisk === $new_md)
            return ['status'=>'ok', 'msg'=>'No changes.'];
    }

    // backup the current file before overwriting (ignored by sync: not *.md at top level)
    $dir = dirname($path);
    if (is_file($path)) {
        $bdir = $dir.'/_backups';
        @mkdir($bdir, 0775, true);
        @copy($path, $bdir.'/'.basename($path).'.'.date('Ymd-His').'.bak');
    } else {
        @mkdir($dir, 0775, true);
    }

    if (@file_put_contents($path, $new_md) === false)
        return ['status'=>'error', 'msg'=>'Could not write '.$path.' (check php-fpm write permission on the books folder).'];

    // update the DB to match (so the next sync sees folder == DB → no clobber)
    $wc = md_word_count($new_md);
    q("UPDATE chapters SET body=?, word_count=?, words=? WHERE id=?", [$new_md, $wc, number_format($wc), (int)$chapter_id]);
    reconcile_scenes($book_id, (int)$chapter_id, $new_md);
    return ['status'=>'ok', 'msg'=>'Saved to the manuscript file and database.'];
}

/* ----------------------------------------------- manuscript structure (P1) */
/** acts + scenes layer app-side structure on folder-owned prose. Scenes are
 *  DERIVED from chapters.body via md_split_scenes(); the app-only acts and
 *  scenes.label/summary are preserved across re-imports (like chapters.status).
 *  Lazy-migrated (ensure_structure) so no schema.sql re-import is needed. */
function ensure_structure() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    foreach ([
        "CREATE TABLE IF NOT EXISTS acts ( id $pk, book_id VARCHAR(40) NOT NULL, title VARCHAR(255) DEFAULT '', subtitle VARCHAR(255) DEFAULT '', sort_order INT DEFAULT 0, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP )",
        "CREATE TABLE IF NOT EXISTS scenes ( id $pk, book_id VARCHAR(40) NOT NULL, chapter_id INT NOT NULL, ordinal INT DEFAULT 1, title VARCHAR(255) DEFAULT '', word_count INT DEFAULT 0, body_hash VARCHAR(40) DEFAULT '', label VARCHAR(40) DEFAULT '', summary TEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP )",
    ] as $s) { try { db()->exec($s); } catch (Exception $e) {} }
    try { db()->exec("ALTER TABLE chapters ADD COLUMN act_id INT DEFAULT NULL"); } catch (Exception $e) {}
    try { db()->exec("ALTER TABLE chapters ADD COLUMN grid_seq INT DEFAULT NULL"); } catch (Exception $e) {}  // app-only manual order for the Grid; survives sync
    try { db()->exec("ALTER TABLE scenes ADD COLUMN note TEXT"); } catch (Exception $e) {}   // author notes (<!-- ... -->) lifted out of prose
    try { db()->exec("ALTER TABLE scenes ADD COLUMN seq INT DEFAULT NULL"); } catch (Exception $e) {}  // app-only planning order; does NOT change prose
    try { db()->exec("CREATE INDEX k_scenes_ch ON scenes (chapter_id)"); } catch (Exception $e) {}
}
function get_acts($book_id) { ensure_structure(); return all("SELECT * FROM acts WHERE book_id=? ORDER BY sort_order, id", [$book_id]); }
/* ---- Act CRUD (Phase 1). Acts group chapters in the Grid; all book-scoped. ---- */
function add_act($book_id, $title, $subtitle = '') {
    ensure_structure();
    $title = trim((string)$title); if ($title === '') $title = 'New Act';
    $n = (int) val("SELECT COALESCE(MAX(sort_order),-1)+1 FROM acts WHERE book_id=?", [$book_id]);
    q("INSERT INTO acts (book_id,title,subtitle,sort_order) VALUES (?,?,?,?)", [$book_id, $title, trim((string)$subtitle), $n]);
    return last_id();
}
function rename_act($act_id, $book_id, $title, $subtitle = '') {
    ensure_structure();
    $title = trim((string)$title); if ($title === '') return;
    q("UPDATE acts SET title=?,subtitle=? WHERE id=? AND book_id=?", [$title, trim((string)$subtitle), (int)$act_id, $book_id]);
}
/** Delete an act; its chapters revert to unassigned (act_id NULL) — never deleted. */
function delete_act($act_id, $book_id) {
    ensure_structure();
    q("UPDATE chapters SET act_id=NULL WHERE act_id=? AND book_id=?", [(int)$act_id, $book_id]);
    q("DELETE FROM acts WHERE id=? AND book_id=?", [(int)$act_id, $book_id]);
}
/** Reorder by swapping sort_order with the neighbour in $dir ('up'|'down'). */
function move_act($act_id, $book_id, $dir) {
    ensure_structure();
    $acts = all("SELECT id,sort_order FROM acts WHERE book_id=? ORDER BY sort_order, id", [$book_id]);
    $i = null; foreach ($acts as $k => $a) if ((int)$a['id'] === (int)$act_id) { $i = $k; break; }
    if ($i === null) return;
    $j = $dir === 'up' ? $i - 1 : $i + 1;
    if ($j < 0 || $j >= count($acts)) return;
    q("UPDATE acts SET sort_order=? WHERE id=? AND book_id=?", [(int)$acts[$j]['sort_order'], (int)$acts[$i]['id'], $book_id]);
    q("UPDATE acts SET sort_order=? WHERE id=? AND book_id=?", [(int)$acts[$i]['sort_order'], (int)$acts[$j]['id'], $book_id]);
}
/** Assign a chapter to an act (or unassign with empty/0). Validates the act is
 *  in the same book; otherwise unassigns. */
function set_chapter_act($chapter_id, $book_id, $act_id) {
    ensure_structure();
    $aid = ($act_id === '' || $act_id === null || (int)$act_id === 0) ? null : (int)$act_id;
    if ($aid !== null && !val("SELECT id FROM acts WHERE id=? AND book_id=?", [$aid, $book_id])) $aid = null;
    q("UPDATE chapters SET act_id=? WHERE id=? AND book_id=?", [$aid, (int)$chapter_id, $book_id]);
}
/** Persist a Grid drag: assign every chapter in $ids (the target band's new order)
 *  to act $act_id and a sequential grid_seq. grid_seq is app-only and untouched by
 *  sync, so manual order survives re-imports. Book-scoped. */
function reorder_chapters($book_id, $act_id, array $ids) {
    ensure_structure();
    $aid = ($act_id === '' || $act_id === null || (int)$act_id === 0) ? null : (int)$act_id;
    if ($aid !== null && !val("SELECT id FROM acts WHERE id=? AND book_id=?", [$aid, $book_id])) $aid = null;
    $seq = 0;
    foreach ($ids as $cid) {
        $cid = (int)$cid; if ($cid <= 0) continue;
        q("UPDATE chapters SET act_id=?, grid_seq=? WHERE id=? AND book_id=?", [$aid, $seq++, $cid, $book_id]);
    }
}
/** Planning-only scene reorder: store a display sequence on scenes. Does NOT
 *  touch prose — the chapter .md is unchanged. seq is preserved across re-syncs
 *  by ordinal in reconcile_scenes. Scoped by book + chapter. */
function reorder_scenes($book_id, $chapter_id, array $ids) {
    ensure_structure();
    $seq = 0;
    foreach ($ids as $sid) {
        $sid = (int)$sid; if ($sid <= 0) continue;
        q("UPDATE scenes SET seq=? WHERE id=? AND chapter_id=? AND book_id=?", [$seq++, $sid, (int)$chapter_id, $book_id]);
    }
}
/** Reconcile a chapter's scenes from its body. Delete + reinsert, preserving the
 *  app-only label/summary by ordinal (the stable key for now). Returns count. */
function reconcile_scenes($book_id, $chapter_id, $body) {
    ensure_structure();
    $scenes = md_split_scenes($body);
    $prev = [];
    foreach (all("SELECT ordinal,label,summary,seq FROM scenes WHERE chapter_id=?", [$chapter_id]) as $r) $prev[(int)$r['ordinal']] = $r;
    q("DELETE FROM scenes WHERE chapter_id=?", [$chapter_id]);
    foreach ($scenes as $s) {
        $o = (int)$s['ordinal'];
        $note = $s['note'] ?? '';
        $seq = (isset($prev[$o]['seq']) && $prev[$o]['seq'] !== null && $prev[$o]['seq'] !== '') ? (int)$prev[$o]['seq'] : null;
        q("INSERT INTO scenes (book_id,chapter_id,ordinal,title,word_count,body_hash,label,summary,note,seq) VALUES (?,?,?,?,?,?,?,?,?,?)",
          [$book_id, (int)$chapter_id, $o, $s['title'], (int)$s['wordCount'], md5($s['body'] . "\x00" . $note),
           $prev[$o]['label'] ?? '', $prev[$o]['summary'] ?? '', $note, $seq]);
    }
    return count($scenes);
}
/** Scenes for a chapter, lazily backfilled from the chapter body on first view
 *  so already-imported chapters get scenes without waiting for a re-sync. */
function get_scenes($chapter_id) {
    ensure_structure();
    $rows = all("SELECT * FROM scenes WHERE chapter_id=? ORDER BY (seq IS NULL), seq, ordinal", [$chapter_id]);
    if (!$rows) {
        $c = one("SELECT id,book_id,body FROM chapters WHERE id=?", [$chapter_id]);
        if ($c && trim((string)$c['body']) !== '') {
            reconcile_scenes($c['book_id'], (int)$c['id'], $c['body']);
            $rows = all("SELECT * FROM scenes WHERE chapter_id=? ORDER BY (seq IS NULL), seq, ordinal", [$chapter_id]);
        }
    }
    return $rows;
}
/** Set an app-only scene label (Phase 1). Scoped by book_id so a stray id can't
 *  touch another book's scenes. Empty string clears the label. Preserved across
 *  re-imports by reconcile_scenes (matched on ordinal). */
function set_scene_label($scene_id, $book_id, $label) {
    ensure_structure();
    $allowed = array_merge([''], SCENE_LABELS);
    if (!in_array((string)$label, $allowed, true)) return;
    q("UPDATE scenes SET label=? WHERE id=? AND book_id=?", [(string)$label, (int)$scene_id, $book_id]);
}
/* ---- progressions: arcs & timeline (Phase 6) ----
 * when_label / when_order are APP-ONLY overrides for timeline placement. They are
 * NOT in progressions.md (which stays the source of the beats themselves), so they
 * must survive the DELETE+re-INSERT that import_progressions_md does on every sync.
 * Lazy-migrated so no schema.sql re-import is needed. */
function ensure_progress_cols() {
    static $done = false; if ($done) return; $done = true;
    try { db()->exec("ALTER TABLE progressions ADD COLUMN when_label VARCHAR(80) DEFAULT ''"); } catch (Exception $e) {}
    try { db()->exec("ALTER TABLE progressions ADD COLUMN when_order INT DEFAULT NULL"); } catch (Exception $e) {}
}
function get_progressions($book_id) { ensure_progress_cols(); return all("SELECT * FROM progressions WHERE book_id=? ORDER BY sort_order, id", [$book_id]); }

/** Parse a leading chapter number out of a progressions.chapter label like
 *  "Ch. 12", "Ch. 12 - Title", "Chapter 3.5", "Prologue". Returns a float used
 *  only for ordering (prologue -> 0, unknown -> large), never displayed. */
function prog_chapter_num($chapter) {
    $c = strtolower(trim((string)$chapter));
    if ($c === '') return 1e9;
    if (strpos($c, 'prologue') !== false) return 0.0;
    if (strpos($c, 'epilogue') !== false) return 1e8;   // after numbered chapters, before "unknown"
    if (preg_match('/(\d+(?:\.\d+)?)/', $c, $m)) return (float)$m[1];
    return 1e9;
}

/** Progressions ordered for the timeline: explicit when_order wins; otherwise
 *  chapter order; ties broken by sort_order. Each row gets a computed:
 *    _ord    = the numeric sort key used
 *    _bucket = display label for its time-slot (when_label, else the chapter). */
function get_progressions_timeline($book_id) {
    $rows = get_progressions($book_id);
    foreach ($rows as &$r) {
        $hasWhen = isset($r['when_order']) && $r['when_order'] !== null && $r['when_order'] !== '';
        $r['_ord']    = $hasWhen ? (float)$r['when_order'] : prog_chapter_num($r['chapter']);
        $r['_bucket'] = trim((string)($r['when_label'] ?? '')) !== '' ? trim($r['when_label']) : (trim((string)$r['chapter']) !== '' ? trim($r['chapter']) : 'Unplaced');
        $r['_when']   = $hasWhen;
    }
    unset($r);
    usort($rows, function ($a, $b) {
        if ($a['_ord'] !== $b['_ord']) return $a['_ord'] <=> $b['_ord'];
        return ((int)$a['sort_order']) <=> ((int)$b['sort_order']);
    });
    return $rows;
}

/** Progressions touching one entity (its slug appears in related_csv), timeline-ordered. */
function get_entity_arc($book_id, $slug) {
    $slug = trim((string)$slug);
    if ($slug === '') return [];
    $out = [];
    foreach (get_progressions_timeline($book_id) as $r) {
        $rel = array_filter(array_map('trim', explode(',', (string)($r['related_csv'] ?? ''))));
        if (in_array($slug, $rel, true)) $out[] = $r;
    }
    return $out;
}

/** App-only timeline override for one progression beat. when_order '' clears it (back to chapter order). */
function set_progression_when($book_id, $id, $when_label, $when_order) {
    ensure_progress_cols();
    $wl = trim((string)$when_label);
    $wo = ($when_order === '' || $when_order === null) ? null : (int)$when_order;
    q("UPDATE progressions SET when_label=?, when_order=? WHERE id=? AND book_id=?", [$wl, $wo, (int)$id, $book_id]);
}
function get_threads($book_id, $status = null) {
    if ($status) return all("SELECT * FROM threads WHERE book_id=? AND status=? ORDER BY db_key, entry_name", [$book_id, $status]);
    return all("SELECT * FROM threads WHERE book_id=? ORDER BY status, db_key, entry_name", [$book_id]);
}
function set_thread_status($id, $status) { q("UPDATE threads SET status=? WHERE id=?", [$status, $id]); }

/* rebuild threads from entries' Open Threads sections */
function rebuild_threads($book_id) {
    // preserve manually-resolved threads by text
    $resolved = array_column(all("SELECT text FROM threads WHERE book_id=? AND status='resolved'", [$book_id]), 'text');
    q("DELETE FROM threads WHERE book_id=?", [$book_id]);
    $i = 0;
    foreach (get_entries($book_id) as $row) {
        $e = entry_to_struct($row);
        foreach ($e['threads'] as $t) {
            $st = in_array($t, $resolved) ? 'resolved' : 'open';
            q("INSERT INTO threads (book_id,entry_slug,entry_name,db_key,status,text,sort_order) VALUES (?,?,?,?,?,?,?)",
              [$book_id, $e['slug'], $e['name'], $e['db'], $st, $t, $i++]);
        }
    }
}

/* --------------------------------------------------------------- meta */
function get_meta($book_id) { return all("SELECT * FROM meta_pages WHERE book_id=? ORDER BY title", [$book_id]); }
function get_meta_page($book_id, $slug) { return one("SELECT * FROM meta_pages WHERE book_id=? AND slug=?", [$book_id, $slug]); }
function save_meta_page($book_id, $slug, $title, $body) {
    $e = get_meta_page($book_id, $slug);
    if ($e) q("UPDATE meta_pages SET title=?,body=? WHERE book_id=? AND slug=?", [$title, $body, $book_id, $slug]);
    else    q("INSERT INTO meta_pages (book_id,slug,title,body) VALUES (?,?,?,?)", [$book_id, $slug, $title, $body]);
}

/* --------------------------------------------------------------- notes */
/** Planning notes from Codex/Notes/*.md — folder-authored, synced in for reading. */
function ensure_note_pages() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec("CREATE TABLE IF NOT EXISTS note_pages (
        id $pk, book_id VARCHAR(40) NOT NULL, slug VARCHAR(160) NOT NULL,
        title VARCHAR(255) DEFAULT '', body MEDIUMTEXT )"); } catch (Exception $e) {}
}
function get_notes($book_id) { ensure_note_pages(); return all("SELECT * FROM note_pages WHERE book_id=? ORDER BY slug", [$book_id]); }
function get_note_page($book_id, $slug) { ensure_note_pages(); return one("SELECT * FROM note_pages WHERE book_id=? AND slug=?", [$book_id, $slug]); }
function save_note_page($book_id, $slug, $title, $body) {
    ensure_note_pages();
    $e = get_note_page($book_id, $slug);
    if ($e) q("UPDATE note_pages SET title=?,body=? WHERE book_id=? AND slug=?", [$title, $body, $book_id, $slug]);
    else    q("INSERT INTO note_pages (book_id,slug,title,body) VALUES (?,?,?,?)", [$book_id, $slug, $title, $body]);
}

/* ------------------------------------------------------------- captures */
/** Brain-dump inbox. App-only — NOT part of the folder sync contract.
 *  status: inbox -> triaged (became a task) | dismissed. book_id may be '' (global). */
function ensure_captures() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec("CREATE TABLE IF NOT EXISTS captures (
        id $pk, book_id VARCHAR(40) DEFAULT '', text TEXT,
        status VARCHAR(20) DEFAULT 'inbox', created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"); } catch (Exception $e) {}
}
function get_captures($book_id = null, $status = 'inbox') {
    ensure_captures();
    $w = []; $p = [];
    if ($status !== null)  { $w[] = 'status=?';  $p[] = $status; }
    if (!empty($book_id))  { $w[] = 'book_id=?'; $p[] = $book_id; }
    $sql = "SELECT * FROM captures" . ($w ? " WHERE " . implode(' AND ', $w) : "") . " ORDER BY id DESC";
    return all($sql, $p);
}
function count_captures($status = 'inbox') {
    ensure_captures();
    return (int) val("SELECT COUNT(*) FROM captures WHERE status=?", [$status]);
}
function get_capture($id) { ensure_captures(); return one("SELECT * FROM captures WHERE id=?", [$id]); }
function add_capture($book_id, $text) {
    ensure_captures();
    q("INSERT INTO captures (book_id, text, status) VALUES (?,?, 'inbox')", [$book_id ?: '', $text]);
    return last_id();
}
function set_capture_status($id, $status) {
    $ok = ['inbox','triaged','dismissed'];
    if (in_array($status, $ok, true)) q("UPDATE captures SET status=? WHERE id=?", [$status, $id]);
}
function delete_capture($id) { q("DELETE FROM captures WHERE id=?", [$id]); }

/* ----------------------------------------------------- spatial (Phase 5) */
/** Plot board (canvas_cards/links) + mood board (vision_items). App-only, NOT synced. */
function ensure_spatial() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    foreach ([
        "CREATE TABLE IF NOT EXISTS canvas_cards ( id $pk, book_id VARCHAR(40) NOT NULL, x INT DEFAULT 40, y INT DEFAULT 40, text TEXT, color VARCHAR(10) DEFAULT '#7c8cff', updated_at DATETIME DEFAULT CURRENT_TIMESTAMP )",
        "CREATE TABLE IF NOT EXISTS canvas_links ( id $pk, book_id VARCHAR(40) NOT NULL, from_id INT NOT NULL, to_id INT NOT NULL )",
        "CREATE TABLE IF NOT EXISTS vision_items ( id $pk, book_id VARCHAR(40) NOT NULL, caption VARCHAR(255) DEFAULT '', image_url TEXT, sort_order INT DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP )",
    ] as $s) { try { db()->exec($s); } catch (Exception $e) {} }
    // Phase 8: a card may be bound to a real Codex object (entry/thread/progression/scene).
    try { db()->exec("ALTER TABLE canvas_cards ADD COLUMN ref_type VARCHAR(16) DEFAULT ''"); } catch (Exception $e) {}
    try { db()->exec("ALTER TABLE canvas_cards ADD COLUMN ref_id VARCHAR(120) DEFAULT ''"); } catch (Exception $e) {}
}
function get_canvas_cards($book_id) { ensure_spatial(); return all("SELECT * FROM canvas_cards WHERE book_id=? ORDER BY id", [$book_id]); }
function get_canvas_links($book_id) { ensure_spatial(); return all("SELECT * FROM canvas_links WHERE book_id=? ORDER BY id", [$book_id]); }
function add_canvas_card($book_id,$x,$y,$text='',$color='#7c8cff',$ref_type='',$ref_id='') { ensure_spatial(); q("INSERT INTO canvas_cards (book_id,x,y,text,color,ref_type,ref_id) VALUES (?,?,?,?,?,?,?)", [$book_id,(int)$x,(int)$y,$text,$color,(string)$ref_type,(string)$ref_id]); return last_id(); }

/* ---- Phase 8: bind plot-board cards to live Codex objects ---- */
/** Resolve a card's ref to its current title/status/link/colour. NULL if the
 *  target was deleted (caller renders a "removed" placeholder). ref_id encoding:
 *  entry → "db/slug"; thread/progression/scene → row id. */
function canvas_ref_resolve($book_id, $ref_type, $ref_id) {
    switch ($ref_type) {
        case 'entry':
            list($db, $slug) = array_pad(explode('/', (string)$ref_id, 2), 2, '');
            $row = one("SELECT name,status,db_key FROM entries WHERE book_id=? AND db_key=? AND slug=?", [$book_id, $db, $slug]);
            if (!$row) return null;
            return ['kind'=>DBMETA[$db]['singular'] ?? 'Entry', 'title'=>$row['name'], 'status'=>$row['status'],
                    'color'=>DBMETA[$db]['hue'] ?? '#7c8cff', 'p'=>['p'=>'entry','book'=>$book_id,'db'=>$db,'slug'=>$slug]];
        case 'thread':
            $row = one("SELECT text,status,entry_name FROM threads WHERE id=? AND book_id=?", [(int)$ref_id, $book_id]);
            if (!$row) return null;
            return ['kind'=>'Open thread', 'title'=>mb_substr(trim(strip_tags((string)$row['text'])), 0, 120), 'status'=>$row['status'],
                    'color'=>'#C25A6E', 'p'=>['p'=>'threads','book'=>$book_id]];
        case 'progression':
            $row = one("SELECT what,type,chapter FROM progressions WHERE id=? AND book_id=?", [(int)$ref_id, $book_id]);
            if (!$row) return null;
            return ['kind'=>'Beat', 'title'=>mb_substr(trim((string)$row['what']), 0, 120), 'status'=>$row['chapter'],
                    'color'=>'#C9933A', 'p'=>['p'=>'timeline','book'=>$book_id]];
        case 'scene':
            $row = one("SELECT s.title,s.ordinal,s.label,c.num,c.id AS cid FROM scenes s JOIN chapters c ON c.id=s.chapter_id WHERE s.id=? AND s.book_id=?", [(int)$ref_id, $book_id]);
            if (!$row) return null;
            return ['kind'=>'Scene', 'title'=>($row['title'] !== '' ? $row['title'] : 'Scene '.$row['ordinal']),
                    'status'=>'Ch. '.$row['num'].($row['label'] ? ' · '.$row['label'] : ''),
                    'color'=>'#8A6A3E', 'p'=>['p'=>'chapter','book'=>$book_id,'id'=>(int)$row['cid']]];
    }
    return null;
}

/** Options for the "+ Add from Codex" picker: [{type,id,label,sub}] across all four kinds. */
function canvas_ref_options($book_id) {
    $opts = [];
    foreach (get_entries($book_id) as $row) {
        $e = entry_to_struct($row);
        $opts[] = ['type'=>'entry', 'id'=>$e['db'].'/'.$e['slug'], 'label'=>$e['name'], 'sub'=>(DBMETA[$e['db']]['singular'] ?? 'Entry').' · '.$e['status']];
    }
    foreach (get_threads($book_id, 'open') as $t)
        $opts[] = ['type'=>'thread', 'id'=>(string)$t['id'], 'label'=>mb_substr(trim(strip_tags((string)$t['text'])), 0, 90), 'sub'=>'Open thread · '.$t['entry_name']];
    foreach (get_progressions($book_id) as $p)
        $opts[] = ['type'=>'progression', 'id'=>(string)$p['id'], 'label'=>mb_substr(trim((string)$p['what']), 0, 90), 'sub'=>'Beat · '.$p['chapter']];
    foreach (all("SELECT s.id,s.title,s.ordinal,c.num FROM scenes s JOIN chapters c ON c.id=s.chapter_id WHERE s.book_id=? ORDER BY (c.num+0), c.num, s.ordinal", [$book_id]) as $s)
        $opts[] = ['type'=>'scene', 'id'=>(string)$s['id'], 'label'=>($s['title'] !== '' ? $s['title'] : 'Scene '.$s['ordinal']), 'sub'=>'Scene · Ch. '.$s['num']];
    return $opts;
}
function move_canvas_card($id,$x,$y) { ensure_spatial(); q("UPDATE canvas_cards SET x=?,y=? WHERE id=?", [(int)$x,(int)$y,(int)$id]); }
function text_canvas_card($id,$text) { ensure_spatial(); q("UPDATE canvas_cards SET text=? WHERE id=?", [$text,(int)$id]); }
function delete_canvas_card($id) { ensure_spatial(); q("DELETE FROM canvas_links WHERE from_id=? OR to_id=?", [(int)$id,(int)$id]); q("DELETE FROM canvas_cards WHERE id=?", [(int)$id]); }
function add_canvas_link($book_id,$from,$to) {
    ensure_spatial();
    if ((int)$from === (int)$to) return null;
    $e = val("SELECT id FROM canvas_links WHERE book_id=? AND ((from_id=? AND to_id=?) OR (from_id=? AND to_id=?))", [$book_id,$from,$to,$to,$from]);
    if ($e) return $e;
    q("INSERT INTO canvas_links (book_id,from_id,to_id) VALUES (?,?,?)", [$book_id,(int)$from,(int)$to]);
    return last_id();
}
function delete_canvas_link($id) { ensure_spatial(); q("DELETE FROM canvas_links WHERE id=?", [(int)$id]); }
function get_vision($book_id) { ensure_spatial(); return all("SELECT * FROM vision_items WHERE book_id=? ORDER BY sort_order, id", [$book_id]); }
function add_vision($book_id,$caption,$image_url) { ensure_spatial(); q("INSERT INTO vision_items (book_id,caption,image_url) VALUES (?,?,?)", [$book_id,$caption,$image_url]); return last_id(); }
function delete_vision($id) { ensure_spatial(); q("DELETE FROM vision_items WHERE id=?", [(int)$id]); }

/* --------------------------------------------------------------- tasks */
/** Phase 4: sub-steps table + priority/due columns. Lazy-create like the other
 *  ensure_* helpers so it works against an existing DB with no migration step.
 *  Existing rows backfill to due='someday' (kept out of Today); new tasks created
 *  via save_task default to due='today'. task_steps is app-only (not synced). */
function ensure_task_extras() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec("CREATE TABLE IF NOT EXISTS task_steps (
        id $pk, task_id INT NOT NULL, text VARCHAR(255) NOT NULL,
        done INT DEFAULT 0, sort_order INT DEFAULT 0 )"); } catch (Exception $e) {}
    foreach ([
        "ALTER TABLE tasks ADD COLUMN priority VARCHAR(10) DEFAULT 'med'",
        "ALTER TABLE tasks ADD COLUMN due VARCHAR(20) DEFAULT 'someday'",
    ] as $s) { try { db()->exec($s); } catch (Exception $e) {} }
}
function get_task_steps($task_id) { ensure_task_extras(); return all("SELECT * FROM task_steps WHERE task_id=? ORDER BY sort_order, id", [$task_id]); }
function add_task_step($task_id, $text) {
    ensure_task_extras();
    $n = (int) val("SELECT COALESCE(MAX(sort_order),-1)+1 FROM task_steps WHERE task_id=?", [$task_id]);
    q("INSERT INTO task_steps (task_id,text,done,sort_order) VALUES (?,?,0,?)", [$task_id, $text, $n]);
    return last_id();
}
function toggle_task_step($id) { ensure_task_extras(); q("UPDATE task_steps SET done=1-done WHERE id=?", [$id]); }
function delete_task_step($id) { ensure_task_extras(); q("DELETE FROM task_steps WHERE id=?", [$id]); }

function get_tasks($book_id = null, $filters = []) {
    ensure_task_extras();
    $w = []; $p = [];
    if ($book_id) { $w[] = 'book_id=?'; $p[] = $book_id; }
    if (isset($filters['for_claude'])) { $w[] = 'for_claude=?'; $p[] = $filters['for_claude'] ? 1 : 0; }
    if (isset($filters['status']))     { $w[] = 'status=?';     $p[] = $filters['status']; }
    $sql = "SELECT * FROM tasks" . ($w ? " WHERE " . implode(' AND ', $w) : "") . " ORDER BY status, updated_at DESC";
    return all($sql, $p);
}
function get_task($id) { ensure_task_extras(); return one("SELECT * FROM tasks WHERE id=?", [$id]); }
function save_task($d) {
    ensure_task_extras();
    $priority = $d['priority'] ?? 'med';
    $due      = $d['due'] ?? 'today';
    if (!empty($d['id'])) {
        q("UPDATE tasks SET title=?,body=?,status=?,for_claude=?,target_db=?,target_slug=?,result=?,priority=?,due=? WHERE id=?",
          [$d['title'],$d['body']??'',$d['status']??'todo',!empty($d['for_claude'])?1:0,
           $d['target_db']??'',$d['target_slug']??'',$d['result']??'',$priority,$due,$d['id']]);
        return $d['id'];
    }
    q("INSERT INTO tasks (book_id,title,body,status,for_claude,target_db,target_slug,result,priority,due) VALUES (?,?,?,?,?,?,?,?,?,?)",
      [$d['book_id'],$d['title'],$d['body']??'',$d['status']??'todo',!empty($d['for_claude'])?1:0,
       $d['target_db']??'',$d['target_slug']??'',$d['result']??'',$priority,$due]);
    return last_id();
}
function delete_task($id) { ensure_task_extras(); q("DELETE FROM task_steps WHERE task_id=?", [$id]); q("DELETE FROM tasks WHERE id=?", [$id]); }

/* ----------------------------------------------------------- writing log */
function get_writing_log($book_id = null) {
    if ($book_id) return all("SELECT * FROM writing_log WHERE book_id=? ORDER BY log_date DESC, id DESC", [$book_id]);
    return all("SELECT * FROM writing_log ORDER BY log_date DESC, id DESC");
}
function add_writing_log($d) {
    q("INSERT INTO writing_log (book_id,log_date,words_added,total_words,chapters,minutes,mood,note,source)
       VALUES (?,?,?,?,?,?,?,?,?)",
      [$d['book_id'],$d['log_date'],$d['words_added']??0,$d['total_words']??0,$d['chapters']??'',
       $d['minutes']??0,$d['mood']??'',$d['note']??'',$d['source']??'manual']);
    return last_id();
}
/** Apply a skill/automation result bundle (the 'apply' API action). Restored —
 *  api.php calls this for the tasks-bridge outbox and the MCP task/log tools.
 *  Shape: { task_results:[{id,status,result}], writing_log:[{...}], thread_status:[{id,status}] } */
function apply_results($payload) {
    $report = ['tasks'=>0,'writing_log'=>0,'threads'=>0];
    foreach (($payload['task_results'] ?? []) as $tr) {
        $t = get_task($tr['id']); if (!$t) continue;
        q("UPDATE tasks SET status=?, result=? WHERE id=?",
          [$tr['status'] ?? 'done', $tr['result'] ?? $t['result'], $tr['id']]);
        $report['tasks']++;
    }
    foreach (($payload['writing_log'] ?? []) as $wl) {
        $wl['source'] = $wl['source'] ?? 'claude';
        add_writing_log($wl);
        $report['writing_log']++;
    }
    foreach (($payload['thread_status'] ?? []) as $ts) {
        set_thread_status($ts['id'], $ts['status']); $report['threads']++;
    }
    return $report;
}
/** Most recent total_words baseline for a book (0 if none yet). */
function last_total_words($book_id) {
    return (int) val("SELECT total_words FROM writing_log WHERE book_id=? ORDER BY log_date DESC, id DESC LIMIT 1", [$book_id]);
}
/** Consecutive-day writing streak (days with words_added>0), counting back from
 *  today — or yesterday if nothing's logged today yet, so the streak stays "alive"
 *  until a day is actually missed. Pass a book_id to scope to one book. */
function writing_streak($book_id = null) {
    $rows = $book_id
        ? all("SELECT DISTINCT log_date FROM writing_log WHERE book_id=? AND words_added>0", [$book_id])
        : all("SELECT DISTINCT log_date FROM writing_log WHERE words_added>0");
    if (!$rows) return 0;
    $set = [];
    foreach ($rows as $r) $set[substr($r['log_date'],0,10)] = true;
    $cursor = new DateTime('today');
    if (!isset($set[$cursor->format('Y-m-d')])) {
        $cursor->modify('-1 day');
        if (!isset($set[$cursor->format('Y-m-d')])) return 0;
    }
    $streak = 0;
    while (isset($set[$cursor->format('Y-m-d')])) { $streak++; $cursor->modify('-1 day'); }
    return $streak;
}

/* ------------------------------------------------------- chapter notes */
/** Revision notes flagged against a chapter. Keyed by (book_id, chapter_file)
 *  so they survive chapter re-imports (which reassign chapter row ids). */
function ensure_chapter_notes() {
    static $done = false; if ($done) return; $done = true;
    $pk  = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec("CREATE TABLE IF NOT EXISTS chapter_notes (
        id $pk, book_id VARCHAR(40) NOT NULL, chapter_file VARCHAR(255) NOT NULL,
        quote TEXT, note TEXT, status VARCHAR(20) DEFAULT 'open', task_id INT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"); } catch (Exception $e) {}
}
function get_chapter_notes($book_id, $file = null, $status = null) {
    ensure_chapter_notes();
    $w = ['book_id=?']; $p = [$book_id];
    if ($file !== null)   { $w[] = 'chapter_file=?'; $p[] = $file; }
    if ($status !== null) { $w[] = 'status=?';       $p[] = $status; }
    return all("SELECT * FROM chapter_notes WHERE " . implode(' AND ', $w) . " ORDER BY status, id DESC", $p);
}
function get_chapter_note($id) { ensure_chapter_notes(); return one("SELECT * FROM chapter_notes WHERE id=?", [$id]); }
function count_chapter_notes($book_id, $file, $status = 'open') {
    ensure_chapter_notes();
    return (int) val("SELECT COUNT(*) FROM chapter_notes WHERE book_id=? AND chapter_file=? AND status=?", [$book_id, $file, $status]);
}
function add_chapter_note($book_id, $file, $quote, $note) {
    ensure_chapter_notes();
    q("INSERT INTO chapter_notes (book_id, chapter_file, quote, note, status) VALUES (?,?,?,?, 'open')",
      [$book_id, $file, $quote, $note]);
    return last_id();
}
function set_chapter_note_status($id, $status) {
    $ok = ['open','resolved'];
    if (in_array($status, $ok, true)) q("UPDATE chapter_notes SET status=? WHERE id=?", [$status, $id]);
}
function set_chapter_note_task($id, $task_id) { q("UPDATE chapter_notes SET task_id=? WHERE id=?", [$task_id, $id]); }
function delete_chapter_note($id) { q("DELETE FROM chapter_notes WHERE id=?", [$id]); }

/* ----------------------------------------------------------- sync_state */
function sync_get($book_id, $k) { return val("SELECT v FROM sync_state WHERE book_id=? AND k=?", [$book_id, $k]); }
function sync_set($book_id, $k, $v) {
    if (val("SELECT id FROM sync_state WHERE book_id=? AND k=?", [$book_id, $k]) !== null)
        q("UPDATE sync_state SET v=? WHERE book_id=? AND k=?", [$v, $book_id, $k]);
    else q("INSERT INTO sync_state (book_id,k,v) VALUES (?,?,?)", [$book_id, $k, $v]);
}

/* ===================================================== SYNC: import snap */
/** Load a canonical snapshot (from codex_sync_lib.snapshot) into the DB. */
function import_snapshot($snap) {
    migrate(); // ensure tables/columns exist (lets no-shell hosts seed via the website)
    foreach ($snap['books'] as $bk) {
        $b = $bk['book'];
        save_book([
            'id'=>$b['id'],'folder'=>$b['folder'],'title'=>$b['title'],'series'=>$b['series']??'',
            'num'=>$b['num']??'','status'=>$b['status']??'planning','logline'=>$b['logline']??'',
            'genre'=>$b['genre']??'','word_target'=>$b['wordTarget']??($b['word_target']??''),
            'dot'=>$b['dot']??'#4A4391','sort_order'=>$b['sort_order']??0,
        ]);
        $bid = $b['id'];
        foreach ($bk['entries'] as $e) {
            if (!empty($e['error'])) continue;
            save_entry($bid, $e['db'], $e);
        }
        // chapters — preserve any statuses already set in the app across a re-import
        $prevStatus = [];
        foreach (all("SELECT file,status FROM chapters WHERE book_id=?", [$bid]) as $r) $prevStatus[$r['file']] = $r['status'];
        q("DELETE FROM chapters WHERE book_id=?", [$bid]);
        $i = 0;
        foreach ($bk['chapters'] as $c) {
            $st = $prevStatus[$c['file'] ?? ''] ?? ($c['status'] ?? 'drafted');
            q("INSERT INTO chapters (book_id,num,title,pov,status,words,word_count,summary,body,file,sort_order)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)",
              [$bid,$c['num'],$c['title'],$c['pov']??'',$st,$c['words']??'',
               $c['wordCount']??0,$c['summary']??'',$c['body']??'',$c['file']??'',$i++]);
        }
        // progressions
        q("DELETE FROM progressions WHERE book_id=?", [$bid]);
        $i = 0;
        foreach ($bk['progressions'] as $pr)
            q("INSERT INTO progressions (book_id,chapter,type,what,related_csv,sort_order) VALUES (?,?,?,?,?,?)",
              [$bid,$pr['chapter'],$pr['type']??'turn',$pr['what'],implode(',', $pr['related']??[]),$i++]);
        // meta
        foreach ($bk['meta'] as $mp) save_meta_page($bid, $mp['slug'], $mp['title'], $mp['body']);
        // notes (Codex/Notes) — present only if the snapshot includes them
        foreach (($bk['notes'] ?? []) as $np) save_note_page($bid, $np['slug'], $np['title'] ?? ucfirst(str_replace('-',' ',$np['slug'])), $np['body'] ?? '');
        rebuild_threads($bid);
    }
}

/* ===================================================== SYNC: push files */
/** payload: { books: [ {folder, files:{relpath: content}} ] }  (folder -> web) */
function push_files($payload) {
    $report = ['entries'=>0,'chapters'=>0,'meta'=>0,'notes'=>0,'progressions'=>0,'archived'=>0,'skipped'=>0,'created'=>[],'books'=>[]];
    // Recognize every profile's Codex folders (Phase 11), not just fiction's, so a
    // non-fiction book's Codex/Concepts/*.md round-trips through the same parser.
    $db_by_folder = folder_db_map();
    $folder_re = '#^Codex/(' . implode('|', array_map(function($f){return preg_quote($f, '#');}, array_keys($db_by_folder))) . ')/(.+)\.md$#';

    migrate(); // ensure tables exist (lets a fresh DB accept the first push)

    foreach ($payload['books'] as $bk) {
        $bid = book_id_for_folder($bk['folder']);
        if (!$bid && !empty($bk['book']) && !empty($bk['book']['id'])) {
            // Auto-register a book the first time its folder is pushed. Create-only:
            // an existing book's metadata is never overwritten by the sync.
            $m = $bk['book'];
            $m['folder'] = $bk['folder'];          // trust the on-disk folder name
            save_book($m);
            $bid = $m['id'];
            $report['created'][] = $bid;
        }
        if (!$bid) { $report['skipped'] += count($bk['files'] ?? []); continue; }
        $touched = 0;
        $seenChapters = [];   // lower(basename) of every chapter file in this push
        q("DELETE FROM chapters WHERE book_id=? AND (LOWER(file)='readme.md' OR LOWER(file) LIKE '%/readme.md')", [$bid]);
        foreach (($bk['files'] ?? []) as $rel => $content) {
            $rel = str_replace('\\', '/', $rel);
            $base = strtolower(basename($rel));
            if (preg_match($folder_re, $rel, $m)) {
                $folder = $m[1]; $slug = $m[2];
                if ($base === 'index.md' || strpos($base,'template')!==false || $base[0]==='_') { continue; }
                $db = $db_by_folder[$folder];
                $e = md_parse_entry($content, $db, $slug);
                if (!$e['slug']) $e['slug'] = $slug;
                save_entry($bid, $db, $e);
                $report['entries']++; $touched++;
            } elseif (preg_match('#^Manuscript/(.+\.md)$#', $rel, $m)) {
                $mbase = strtolower(basename($m[1]));
                if ($mbase === 'readme.md' || $mbase[0] === '_') { /* not a chapter */ }
                else { upsert_chapter_from_md($bid, $m[1], $content); $report['chapters']++; $seenChapters[$mbase] = true; }
            } elseif (preg_match('#^Codex/Meta/progressions\.md$#', $rel)) {
                import_progressions_md($bid, $content);
                $report['progressions']++;
            } elseif (preg_match('#^Codex/Notes/(.+)\.md$#', $rel, $m)) {
                $slug = $m[1];
                $title = ucfirst(str_replace(array('-','_','/'), array(' ',' ',' / '), $slug));
                save_note_page($bid, $slug, $title, $content);
                $report['notes']++;
            } elseif (preg_match('#^Codex/Meta/(.+)\.md$#', $rel, $m)) {
                $slug = $m[1];
                $title = ucfirst(str_replace('-', ' ', $slug));
                save_meta_page($bid, $slug, $title, $content);
                $report['meta']++;
            }
        }
        // ---- reconcile: soft-archive chapters the folder no longer has ----
        // The sync sends the complete current Manuscript set every run, so any
        // chapter in the DB that isn't in this push has been moved/removed in the
        // folder (e.g. into Manuscript/_archive/). We mark it 'archived' rather than
        // delete it -- never hard-delete on sync. An explicit list in
        // 'manuscript_present' (lower basenames) wins when provided; otherwise we
        // infer from the chapter files seen in this push. Only reconcile when there
        // is a manuscript signal, so an entries-only push never archives anything.
        $present = null;
        if (array_key_exists('manuscript_present', $bk) && is_array($bk['manuscript_present'])) {
            $present = [];
            foreach ($bk['manuscript_present'] as $f) $present[strtolower(basename(str_replace('\\','/', (string)$f)))] = true;
        } elseif (array_key_exists('manuscript_count', $bk) && (int)$bk['manuscript_count'] === 0) {
            $present = [];   // folder has a Manuscript dir but zero live chapters -> archive all
        } elseif ($seenChapters) {
            $present = $seenChapters;
        }
        if ($present !== null) {
            foreach (all("SELECT id,file,status FROM chapters WHERE book_id=?", [$bid]) as $crow) {
                $bn = strtolower(basename($crow['file']));
                if ($bn === 'readme.md' || ($bn !== '' && $bn[0] === '_')) continue;
                if ($crow['status'] === 'archived') continue;
                if (!isset($present[$bn])) { q("UPDATE chapters SET status='archived' WHERE id=?", [$crow['id']]); $report['archived']++; }
            }
        }
        rebuild_threads($bid);
        $report['books'][$bk['folder']] = $touched;
    }
    return $report;
}
function upsert_chapter_from_md($book_id, $file, $content) {
    $content = str_replace("\x00", '', $content);
    $base = preg_replace('/\.md$/', '', $file);
    $num = '';
    if (preg_match('/(\d+)/', $base, $m)) $num = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $title = ucwords(str_replace('_', ' ', $base));
    if (preg_match('/^#{2,3}\s*Chapter[^\n\x{2014}-]*[\x{2014}-]\s*(.+?)\s*$/mu', $content, $m)) {
        $title = trim(preg_replace('/\*?\(.*?\)\*?/', '', $m[1]), " *");
    } elseif (stripos($base, 'prologue') === 0) { $title = 'Prologue'; }
    $wc = md_word_count($content);
    $words = number_format($wc);
    // Order by chapter number (prologue first, unnumbered last) -- NOT word count.
    if (preg_match('/(\d+)/', $base, $sm)) $sort = (int)$sm[1];
    elseif (stripos($base, 'prologue') === 0) $sort = 0;
    else $sort = 9999;
    $row = one("SELECT id FROM chapters WHERE book_id=? AND file=?", [$book_id, $file]);
    // Update content + counts (and re-derive sort order so existing rows self-heal); never touch the app-managed status.
    if ($row) q("UPDATE chapters SET num=?,title=?,words=?,word_count=?,body=?,sort_order=? WHERE id=?", [$num ?: $base, $title, $words, $wc, $content, $sort, $row['id']]);
    else q("INSERT INTO chapters (book_id,num,title,status,words,word_count,body,file,sort_order) VALUES (?,?,?,?,?,?,?,?,?)",
           [$book_id, $num ?: $base, $title, 'drafted', $words, $wc, $content, $file, $sort]);
    // P1: re-split scenes from the (re-)synced prose, preserving app-only fields.
    $cid = $row ? (int)$row['id'] : (int)last_id();
    reconcile_scenes($book_id, $cid, $content);
}
function import_progressions_md($book_id, $content) {
    // Mirrors codex_sync_lib.parse_progressions(). Supports two layouts (and a mix):
    //   * canonical: '### Chapter N - "Title"' headings with '- **...**' bullets beneath;
    //   * old flat:  '- **Ch. N - Title:** what happened' bullets carrying the label inline.
    // Any non-chapter heading closes the active chapter so stray bullets aren't logged.
    $content = str_replace("\x00", '', $content);
    ensure_progress_cols();
    // Snapshot app-only timeline overrides before we wipe+reparse, keyed by the
    // beat's identity (chapter + what), so set when_label/when_order survive sync.
    $pk = function ($chapter, $what) {
        return strtolower(preg_replace('/\s+/u', ' ', trim((string)$chapter))) . '||' .
               strtolower(preg_replace('/\s+/u', ' ', trim((string)$what)));
    };
    $preserve = [];
    foreach (all("SELECT chapter,what,when_label,when_order FROM progressions WHERE book_id=?", [$book_id]) as $old) {
        $hasWhen = $old['when_order'] !== null && $old['when_order'] !== '';
        if (trim((string)$old['when_label']) === '' && !$hasWhen) continue;   // nothing to keep
        $preserve[$pk($old['chapter'], $old['what'])] = ['when_label' => (string)$old['when_label'], 'when_order' => $hasWhen ? (int)$old['when_order'] : null];
    }
    q("DELETE FROM progressions WHERE book_id=?", [$book_id]);
    $i = 0;
    $current = null;

    $emit = function($chapter, $rawBody) use ($book_id, &$i) {
        // display text: [[slug|Display]] -> Display, [[slug]] -> slug; strip bold markers
        $what = preg_replace_callback('/\[\[([^\]]+)\]\]/u', function($mm){
            $parts = explode('|', $mm[1]); return trim(end($parts));
        }, $rawBody);
        $what = trim(str_replace('**', '', $what));
        // related slugs: the part before '|'
        preg_match_all('/\[\[([^\]]+)\]\]/u', $rawBody, $lm);
        $rel = array_map(function($x){ $p = explode('|', $x); return trim($p[0]); }, $lm[1]);
        $wl = strtolower($what); $type = 'turn';
        if (strpos($wl,'introduc')!==false || strpos($wl,'first on-page')!==false || strpos($wl,'reveals herself')!==false) $type='intro';
        elseif (strpos($wl,'death')!==false || strpos($wl,'dies')!==false || strpos($wl,'executed')!==false || strpos($wl,'destroyed')!==false) $type='death';
        elseif (strpos($wl,'open thread')!==false) $type='thread-opened';
        q("INSERT INTO progressions (book_id,chapter,type,what,related_csv,sort_order) VALUES (?,?,?,?,?,?)",
          [$book_id, $chapter, $type, $what, implode(',', $rel), $i++]);
    };

    foreach (explode("\n", $content) as $ln) {
        $s = trim($ln);
        // chapter section heading -> sets the active chapter
        if (preg_match('/^#{2,4}\s*Chapter\s+(.+?)\s*$/iu', $s, $hm)) {
            $c = preg_replace('/\s*\([^)]*\)\s*$/u', '', $hm[1]);
            $c = preg_replace('/["\x{201C}\x{201D}]/u', '', $c);
            $c = preg_replace('/^[\s\x{2013}\x{2014}-]+|[\s\x{2013}\x{2014}-]+$/u', '', $c);
            $current = preg_match('/^ch\.?\b/iu', $c) ? $c : 'Ch. ' . $c;
            continue;
        }
        // any other heading closes the chapter scope
        if (preg_match('/^#{1,6}\s+/u', $s)) { $current = null; continue; }
        // old flat 'Ch. N:' bullet carries its own chapter inline
        if (preg_match('/^- \*\*(Ch\.?\s*[\dA-Za-z\x{2013}\x{2014}-]+[^:*]*?)\:\*\*\s*(.+)$/u', $s, $m)) {
            $emit(trim($m[1]), $m[2]);
            continue;
        }
        // otherwise a bold-led bullet under an active chapter heading
        if ($current !== null && preg_match('/^[-*]\s+\*\*(.+)$/u', $s, $m)) {
            $emit($current, '**' . $m[1]);
        }
    }

    // Re-apply preserved app-only timeline overrides onto the freshly parsed beats.
    if ($preserve) {
        foreach (all("SELECT id,chapter,what FROM progressions WHERE book_id=?", [$book_id]) as $nw) {
            $k = $pk($nw['chapter'], $nw['what']);
            if (isset($preserve[$k])) {
                q("UPDATE progressions SET when_label=?, when_order=? WHERE id=?",
                  [$preserve[$k]['when_label'], $preserve[$k]['when_order'], (int)$nw['id']]);
            }
        }
    }
}

/* ===================================================== SYNC: pull files */
/** Return { books: [ {folder, files:{relpath: rendered_md}} ] }  (web -> folder).
 *  Entries only — manuscript prose and hand-authored notes stay folder-owned. */
function pull_files($book_id = null) {
    $books = $book_id ? [get_book($book_id)] : get_books();
    $out = [];
    foreach ($books as $b) {
        if (!$b) continue;
        $files = [];
        foreach (get_entries($b['id']) as $row) {
            $e = entry_to_struct($row);
            $folder = DBMETA[$e['db']]['folder'];
            $files["Codex/$folder/{$e['slug']}.md"] = md_render_entry($e);
        }
        $out[] = ['folder' => $b['folder'], 'files' => $files];
    }
    return ['books' => $out];
}

/* ============================================ mentions / aliases (Phase 5) */
function ensure_mentions() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec("CREATE TABLE IF NOT EXISTS mentions (
        id $pk, book_id VARCHAR(40) NOT NULL, entry_slug VARCHAR(160) NOT NULL,
        src_type VARCHAR(16) NOT NULL, src_ref VARCHAR(255) DEFAULT '',
        src_label VARCHAR(255) DEFAULT '', cnt INT DEFAULT 1, kind VARCHAR(8) DEFAULT 'auto' )"); } catch (Exception $e) {}
    try { db()->exec("CREATE INDEX k_mentions ON mentions (book_id, entry_slug)"); } catch (Exception $e) {}
}

/** name + Aliases -> slug for a book, longest phrase first. Skips short/opted-out. */
function build_mention_targets($book_id) {
    $targets = [];
    foreach (get_entries($book_id) as $row) {
        $e = entry_to_struct($row);
        $optout = false; $aliases = [];
        foreach (($e['fields'] ?? []) as $f) {
            $l = strtolower(trim($f['label']));
            if ($l === 'aliases') foreach (preg_split('/[,;]+/', $f['value']) as $a) { $a = trim($a); if ($a !== '') $aliases[] = $a; }
            if (($l === 'mention scan' || $l === 'index') && preg_match('/^(off|no|none)$/i', trim($f['value']))) $optout = true;
        }
        if ($optout) continue;
        foreach (array_merge([$e['name']], $aliases) as $p) {
            $p = trim($p);
            if (function_exists('mb_strlen') ? mb_strlen($p) < 3 : strlen($p) < 3) continue;  // skip noisy short phrases
            $targets[] = ['phrase' => $p, 'slug' => $e['slug']];
        }
    }
    usort($targets, function ($a, $b) { return strlen($b['phrase']) - strlen($a['phrase']); });
    return $targets;
}

/** Scan one text unit for auto-mentions: longest-match-first, word-boundary,
 *  with manual [[links]] removed first (they win, recorded separately). [slug=>count]. */
function scan_mentions($text, $targets) {
    $text = preg_replace('/\[\[[^\]]+\]\]/u', '   ', (string)$text);
    $len = strlen($text);
    if ($len === 0) return [];
    $used = str_repeat("\0", $len);   // byte map; "\1" marks a covered span
    $hits = [];
    foreach ($targets as $t) {
        if (!preg_match_all('/(?<!\w)'.preg_quote($t['phrase'], '/').'(?!\w)/iu', $text, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as $hit) {
            $s = $hit[1]; $w = strlen($hit[0]);
            if (strpos(substr($used, $s, $w), "\1") !== false) continue;   // overlaps a longer match
            $used = substr($used, 0, $s) . str_repeat("\1", $w) . substr($used, $s + $w);
            $hits[$t['slug']] = ($hits[$t['slug']] ?? 0) + 1;
        }
    }
    return $hits;
}

/** Explicit [[slug]] / [[slug|Display]] links in a text -> [slug=>count]. */
function scan_links($text) {
    $hits = [];
    if (preg_match_all('/\[\[([^\]|]+)(?:\|[^\]]*)?\]\]/u', (string)$text, $m))
        foreach ($m[1] as $s) { $s = trim($s); if ($s !== '') $hits[$s] = ($hits[$s] ?? 0) + 1; }
    return $hits;
}

function _record_mentions($book_id, $hits, $type, $ref, $label, $kind) {
    foreach ($hits as $slug => $cnt)
        q("INSERT INTO mentions (book_id,entry_slug,src_type,src_ref,src_label,cnt,kind) VALUES (?,?,?,?,?,?,?)",
          [$book_id, $slug, $type, $ref, $label, (int)$cnt, $kind]);
}

/** (Re)build the mentions index for a book: scene-granular over chapters, plus
 *  each entry's section prose. Records auto matches + explicit [[links]]. */
function index_mentions($book_id) {
    ensure_mentions();
    $targets = build_mention_targets($book_id);
    q("DELETE FROM mentions WHERE book_id=?", [$book_id]);
    foreach (all("SELECT num,title,file,body FROM chapters WHERE book_id=? AND status<>'archived' AND LOWER(file) NOT LIKE '%readme.md'", [$book_id]) as $ch) {
        foreach (md_split_scenes($ch['body']) as $sc) {
            $label = 'Ch. '.$ch['num'].' · '.($sc['title'] !== '' ? $sc['title'] : 'Scene '.$sc['ordinal']);
            $ref = $ch['file'].'#'.$sc['ordinal'];
            _record_mentions($book_id, scan_mentions($sc['body'], $targets), 'chapter', $ref, $label, 'auto');
            _record_mentions($book_id, scan_links($sc['body']),          'chapter', $ref, $label, 'link');
        }
    }
    foreach (get_entries($book_id) as $row) {
        $e = entry_to_struct($row);
        $body = '';
        foreach (($e['sections'] ?? []) as $s) $body .= "\n".$s['body'];
        $hits = scan_mentions($body, $targets); unset($hits[$e['slug']]);   // no self-mention
        _record_mentions($book_id, $hits, 'entry', $e['slug'], $e['name'], 'auto');
        $lk = scan_links($body); unset($lk[$e['slug']]);
        _record_mentions($book_id, $lk, 'entry', $e['slug'], $e['name'], 'link');
    }
    return (int) val("SELECT COUNT(*) FROM mentions WHERE book_id=?", [$book_id]);
}

function index_mentions_all() { ensure_mentions(); foreach (get_books() as $b) index_mentions($b['id']); }

/** Where an entry is mentioned, grouped by source. */
function get_appearances($book_id, $slug) {
    ensure_mentions();
    return all("SELECT src_type, src_ref, src_label, SUM(cnt) AS total, MAX(kind) AS kind
                FROM mentions WHERE book_id=? AND entry_slug=?
                GROUP BY src_type, src_ref, src_label
                ORDER BY src_type DESC, src_label", [$book_id, $slug]);
}

/* =================================== smart-editing diagnostics (Phase 7) ===
 * Three lexical analyzers over chapter prose, sharing one tokenizer. Findings are
 * descriptive, not provenance: "patterns to review" flags stylistic tells, NOT
 * "AI-written". Results cache in prose_analysis keyed by md5(body) so re-analysis
 * only happens when a chapter actually changes. Logic ported 1:1 from a Python
 * reference and unit-tested. */
function diag_stopwords() {
    static $s = null; if ($s !== null) return $s;
    $s = [];
    foreach (explode(' ', "the a an and or but if then else of to in on at by for with from into over under as is are was were be been being it its this that these those he she they them his her their you your i we our us not no so do does did have has had will would can could should may might must just only very really there here what which who whom whose when where why how all any both each few more most other some such than too s t re ve ll m d") as $w) $s[$w] = true;
    return $s;
}
function diag_lexicon() { return ['tapestry','testament','delve','realm','intricate','nuanced','multifaceted','underscore','underscores','boasts','boasting','beacon','myriad','plethora','interplay','symphony','kaleidoscope','ever-evolving','fast-paced','whimsical','meticulous','meticulously']; }
function diag_hedges()  { return ['perhaps','maybe','somewhat','rather','quite','fairly','arguably','seemingly','possibly','relatively','virtually','essentially','basically','generally','apparently']; }
function diag_bookisms(){ static $b=null; if($b!==null) return $b; $b=[]; foreach (['interjected','opined','exclaimed','retorted','hissed','chuckled','laughed','smiled','grimaced','grinned','sneered','spat','breathed','beamed','quipped','mused','pronounced','ejaculated','gushed','snorted'] as $v) $b[$v]=true; return $b; }

/** Strip the Codex markdown dialect down to readable prose for lexical analysis. */
function diag_plain_text($md) {
    $md = (string)$md;
    $md = preg_replace('/```.*?```/su', ' ', $md);          // fenced code
    $md = preg_replace('/`[^`]*`/u', ' ', $md);             // inline code
    $md = preg_replace('/<!--.*?-->/su', ' ', $md);         // HTML comments
    $md = preg_replace('/^\s*#{1,6}\s*/mu', '', $md);       // heading markers
    $md = preg_replace('/^\s*([-*_]\s*){3,}\s*$/mu', ' ', $md);  // scene breaks
    $md = preg_replace('/\[\[([^\]|]+)\|([^\]]+)\]\]/u', '$2', $md);  // [[slug|Disp]]
    $md = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $md);   // [[slug]]
    $md = preg_replace('/\[([^\]]+)\]\([^)]*\)/u', '$1', $md);   // [text](url)
    $md = preg_replace('/[*_]{1,3}/u', '', $md);            // emphasis markers
    $md = preg_replace('/^\s*>\s?/mu', '', $md);            // blockquote
    return $md;
}
function diag_words($text) { preg_match_all("/[a-z]+(?:'[a-z]+)?/u", strtolower((string)$text), $m); return $m[0]; }
function diag_sentences($text) {
    $parts = preg_split('/(?<=[.!?])["”\')\s]+/u', trim((string)$text));
    $out = []; foreach ($parts as $s) { $s = trim($s); if (mb_strlen($s) > 1) $out[] = $s; } return $out;
}

/** Analyzer 1 — usage frequency: overused content words + repeated phrase windows. */
function diag_usage_frequency($text) {
    $stop = diag_stopwords(); $ws = diag_words($text); $n = count($ws);
    $freq = [];
    foreach ($ws as $w) { if (isset($stop[$w]) || strlen($w) < 4) continue; $freq[$w] = ($freq[$w] ?? 0) + 1; }
    arsort($freq);
    $overused = [];
    foreach ($freq as $w => $c) { if ($c < 5) break; $overused[] = ['phrase'=>$w,'count'=>$c]; if (count($overused) >= 15) break; }
    $phrases = [];
    foreach ([2,3,4] as $size) {
        for ($i = 0; $i + $size <= $n; $i++) {
            $gram = array_slice($ws, $i, $size);
            $allstop = true; foreach ($gram as $g) { if (!isset($stop[$g])) { $allstop = false; break; } }
            if ($allstop) continue;
            if (isset($stop[$gram[0]]) && isset($stop[$gram[$size-1]])) continue;
            $key = implode(' ', $gram); $phrases[$key] = ($phrases[$key] ?? 0) + 1;
        }
    }
    $rep = [];
    foreach ($phrases as $p => $c) if ($c >= 3) $rep[] = ['phrase'=>$p,'count'=>$c];
    usort($rep, function ($a, $b) { return $a['count'] !== $b['count'] ? $b['count'] - $a['count'] : strlen($b['phrase']) - strlen($a['phrase']); });
    return ['words'=>$n, 'overused'=>$overused, 'repeated_phrases'=>array_slice($rep, 0, 15)];
}

/** Analyzer 2 — patterns to review (stylistic tells; never "AI-written"). */
/** Severity from how far a measure exceeds its threshold (ratio = value/threshold). */
function diag_sev($ratio) { return $ratio >= 2.0 ? 'HIGH' : ($ratio >= 1.3 ? 'MED' : 'LOW'); }

function diag_patterns($text, $ctx = []) {
    $findings = []; $ws = count(diag_words($text)); $low = strtolower($text);
    // em-dash density, compared to the book average when available (Phase 9d)
    $em = substr_count($text, '—') + substr_count($text, '--');
    if ($ws > 0 && $em > 0) {
        $per1k = $em / $ws * 1000;
        if ($per1k > 6) {
            $detail = "$em em-dashes in ".number_format($ws)." words (~".number_format($per1k,1)."/1k)";
            $base = $ctx['em_baseline'] ?? 0;
            if ($base > 0) { $detail .= " — roughly ".number_format($per1k/$base,1)."× your book average"; $sev = diag_sev($per1k/$base); }
            else { $sev = diag_sev($per1k/6); }
            $findings[] = ['kind'=>'em-dash density','sev'=>$sev,'detail'=>$detail];
        }
    }
    $nj = preg_match_all("/\bit(?:'s| is)\s+not\s+(?:just|only|merely)\b/iu", $text);
    if ($nj) $findings[] = ['kind'=>"\"it's not just X…\" construction",'sev'=>diag_sev($nj),'detail'=>"$nj occurrence(s)"];
    $lex = [];
    foreach (diag_lexicon() as $term) { $c = preg_match_all('/\b'.preg_quote($term,'/').'\b/u', $low); if ($c) $lex[$term] = $c; }
    if ($lex) { arsort($lex); $parts = []; $tot = 0; foreach ($lex as $t => $c) { $parts[] = "$t×$c"; $tot += $c; } $findings[] = ['kind'=>'flagged lexicon','sev'=>diag_sev($tot/2),'detail'=>implode(', ', $parts)]; }
    // sentence rhythm: longest run of sentences opening "subject + past-tense verb" (Phase 9d)
    $rhythm = diag_rhythm_run($text);
    if ($rhythm >= 4) $findings[] = ['kind'=>'sentence rhythm','sev'=>($rhythm>=6?'HIGH':($rhythm>=5?'MED':'LOW')),'detail'=>"$rhythm consecutive sentences open with subject + past-tense verb"];
    // uniform sentence length (kept from P7)
    $sl = []; foreach (diag_sentences($text) as $s) { $w = count(diag_words($s)); if ($w > 0) $sl[] = $w; }
    if (count($sl) >= 8) {
        $mean = array_sum($sl) / count($sl); $var = 0; foreach ($sl as $x) $var += ($x - $mean) ** 2; $sd = sqrt($var / count($sl));
        if ($mean > 0 && $sd / $mean < 0.35) $findings[] = ['kind'=>'uniform sentence length','sev'=>diag_sev(0.35/max($sd/$mean,0.01)),'detail'=>count($sl)." sentences, mean ".number_format($mean,1)." words, low variation (cv ".number_format($sd/$mean,2).")"];
    }
    // hedging clusters with paragraph spread (Phase 9d)
    $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/u', $text))); $hitParas = 0; $seen = []; $hc = 0;
    foreach ($paras as $p) {
        $pl = strtolower($p); $pc = 0;
        foreach (diag_hedges() as $h) { $n = preg_match_all('/\b'.preg_quote($h,'/').'\b/u', $pl); if ($n) { $seen[$h] = ($seen[$h] ?? 0) + $n; $pc += $n; } }
        if ($pc) $hitParas++; $hc += $pc;
    }
    if ($ws > 0 && $hc > 0 && $hc / $ws * 1000 > 8) {
        arsort($seen); $top = array_slice(array_keys($seen), 0, 3); $quoted = implode(', ', array_map(function($h){ return "\"$h\""; }, $top));
        $findings[] = ['kind'=>'hedging clusters','sev'=>diag_sev($hc/$ws*1000/8),'detail'=>"$quoted appear {$hc}× across {$hitParas} paragraph(s)"];
    }
    return $findings;
}

/** Longest run of consecutive sentences whose opener is "subject (proper noun/pronoun) + past-tense verb". */
function diag_rhythm_run($text) {
    static $pron = ['he'=>1,'she'=>1,'they'=>1,'it'=>1,'i'=>1,'we'=>1,'you'=>1];
    static $irr = ['was'=>1,'were'=>1,'had'=>1,'said'=>1,'went'=>1,'came'=>1,'saw'=>1,'knew'=>1,'took'=>1,'felt'=>1,'made'=>1,'found'=>1,'told'=>1,'left'=>1,'stood'=>1,'sat'=>1,'ran'=>1,'held'=>1,'looked'=>1,'turned'=>1,'watched'=>1,'waited'=>1,'stepped'=>1,'sighed'=>1];
    $best = 0; $run = 0;
    foreach (diag_sentences($text) as $s) {
        preg_match_all("/[A-Za-z']+/u", $s, $tm); $toks = $tm[0];
        $ok = false;
        if (count($toks) >= 2) {
            $subj = (ctype_upper(substr($toks[0],0,1)) || isset($pron[strtolower($toks[0])]));
            if ($subj) foreach (array_slice($toks, 1, 2) as $t) { $tl = strtolower($t); if (substr($tl,-2) === 'ed' || isset($irr[$tl])) { $ok = true; break; } }
        }
        $run = $ok ? $run + 1 : 0; if ($run > $best) $best = $run;
    }
    return $best;
}

/** Analyzer 3 — dialogue control: quote count, said-bookisms, per-speaker tags, adverb examples. */
function diag_dialogue($text, $ctx = []) {
    preg_match_all('/[“"]([^”"]{1,500})[”"]/u', $text, $qm, PREG_OFFSET_CAPTURE);
    $quotes = count($qm[0]); $book = diag_bookisms(); $bookHits = []; $advEx = []; $per = []; $tagged = 0;
    $speakers = $ctx['speakers'] ?? [];
    usort($speakers, function($a,$b){ return mb_strlen($b) - mb_strlen($a); });   // longest-first
    $nameAlt = $speakers ? '(?:'.implode('|', array_map(function($s){ return preg_quote($s,'/'); }, $speakers)).')' : '';
    $verb = '(?:said|asked|replied|added|swore|whispered|called|murmured|interjected|chuckled|laughed|hissed|retorted|mused)';
    foreach ($qm[0] as $i => $hit) {
        $span = $hit[0]; $start = $hit[1]; $end = $start + strlen($span);
        $lead = substr($text, max(0, $start - 60), min(60, $start)); $tail = substr($text, $end, 60); $lowtail = strtolower($tail);
        // said-bookism right after the quote
        preg_match_all("/\b([a-z]+)\b/u", $lowtail, $tw);
        foreach (array_slice($tw[1], 0, 4) as $w) { if (isset($book[$w])) { $bookHits[$w] = ($bookHits[$w] ?? 0) + 1; break; } }
        // adverb-laden tag with an example snippet
        if (preg_match('/\b(said|asked|replied|added|swore|whispered|called)\s+(?:\w+\s+)?(\w+ly)\b/u', $lowtail, $am)) $advEx['"'.$am[1].' '.$am[2].'"'] = true;
        // attribute to a speaker (Phase 9d): "Name said" / "said Name" in the tail, else "Name said," in the lead
        if ($nameAlt) {
            $head = substr($tail, 0, 45); $sp = null;
            if (preg_match('/^[\s,]*('.$nameAlt.')\s+'.$verb.'\b/u', $head, $m1)) $sp = $m1[1];
            elseif (preg_match('/^[\s,]*'.$verb.'\s+('.$nameAlt.')\b/u', $head, $m2)) $sp = $m2[1];
            elseif (preg_match('/('.$nameAlt.')\s+'.$verb.'[\s,:]*$/u', substr($lead, -45), $m3)) $sp = $m3[1];
            if ($sp !== null) { $per[$sp] = ($per[$sp] ?? 0) + 1; $tagged++; }
        }
    }
    arsort($bookHits); $bk = []; foreach ($bookHits as $w => $c) $bk[] = ['verb'=>$w,'count'=>$c];
    arsort($per); $tps = []; foreach ($per as $sp => $c) $tps[] = ['speaker'=>$sp,'count'=>$c];
    return ['quotes'=>$quotes, 'bookisms'=>$bk, 'tagged'=>$tagged, 'tags_per_speaker'=>$tps, 'adverb_examples'=>array_keys($advEx)];
}

function analyze_prose($md, $ctx = []) {
    $text = diag_plain_text($md);
    return ['words'=>count(diag_words($text)), 'usage'=>diag_usage_frequency($text), 'patterns'=>diag_patterns($text, $ctx), 'dialogue'=>diag_dialogue($text, $ctx)];
}

/** Book-wide em-dash baseline (per 1k words), a cheap scan cached per request. */
function book_em_baseline($book_id) {
    static $cache = [];
    if (isset($cache[$book_id])) return $cache[$book_id];
    $em = 0; $ws = 0;
    foreach (all("SELECT body FROM chapters WHERE book_id=? AND status<>'archived'", [$book_id]) as $ch) {
        $t = diag_plain_text((string)$ch['body']);
        $em += substr_count($t, '—') + substr_count($t, '--');
        $ws += count(diag_words($t));
    }
    return $cache[$book_id] = $ws > 0 ? $em / $ws * 1000 : 0.0;
}

/** Character names for this book, used for dialogue speaker attribution. */
function book_speakers($book_id) {
    static $cache = [];
    if (isset($cache[$book_id])) return $cache[$book_id];
    $names = [];
    foreach (all("SELECT name FROM entries WHERE book_id=? AND db_key='characters'", [$book_id]) as $r) {
        $n = trim((string)$r['name']); if ($n !== '' && (function_exists('mb_strlen') ? mb_strlen($n) : strlen($n)) >= 2) $names[] = $n;
    }
    return $cache[$book_id] = $names;
}

function ensure_prose_analysis() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec("CREATE TABLE IF NOT EXISTS prose_analysis (
        id $pk, book_id VARCHAR(40) NOT NULL, chapter_id INT NOT NULL,
        body_hash VARCHAR(40) DEFAULT '', data MEDIUMTEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP )"); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_prose_ch ON prose_analysis (chapter_id)"); } catch (Exception $e) {}
}

/** Diagnostics for one chapter, computed on demand and cached until its body changes. */
function get_chapter_diagnostics($book_id, $chapter_id) {
    ensure_prose_analysis();
    $c = one("SELECT id,book_id,num,title,file,body FROM chapters WHERE id=? AND book_id=?", [$chapter_id, $book_id]);
    if (!$c) return null;
    $hash = md5('v2|'.(string)$c['body']);   // bump tag when analyzer output shape changes (forces recompute)
    $row = one("SELECT body_hash,data FROM prose_analysis WHERE chapter_id=?", [$chapter_id]);
    if ($row && $row['body_hash'] === $hash) {
        $data = json_decode($row['data'], true);
        if (is_array($data)) return ['chapter'=>$c, 'data'=>$data, 'cached'=>true];
    }
    $ctx = ['em_baseline'=>book_em_baseline($book_id), 'speakers'=>book_speakers($book_id)];
    $data = analyze_prose($c['body'], $ctx);
    q("DELETE FROM prose_analysis WHERE chapter_id=?", [$chapter_id]);
    q("INSERT INTO prose_analysis (book_id,chapter_id,body_hash,data) VALUES (?,?,?,?)", [$book_id, $chapter_id, $hash, json_encode($data)]);
    return ['chapter'=>$c, 'data'=>$data, 'cached'=>false];
}

/** One row per chapter with finding counts, for the book-level Diagnostics summary. */
function get_book_diagnostics($book_id) {
    $out = [];
    foreach (get_chapters($book_id) as $ch) {
        if (strtolower((string)$ch['file']) === '' || preg_match('/readme\.md$/i', (string)$ch['file'])) continue;
        $d = get_chapter_diagnostics($book_id, $ch['id']); if (!$d) continue;
        $data = $d['data'];
        $pat = count($data['patterns'] ?? []);
        $rep = count($data['usage']['repeated_phrases'] ?? []);
        $bk  = count($data['dialogue']['bookisms'] ?? []);
        $adv = count($data['dialogue']['adverb_examples'] ?? $data['dialogue']['adverb_tags'] ?? []);
        $out[] = ['chapter'=>$ch, 'words'=>$data['words'] ?? 0,
                  'patterns'=>$pat, 'repeats'=>$rep, 'bookisms'=>$bk,
                  'flags'=>$pat + $rep + $bk + $adv];
    }
    return $out;
}

/** Warm/refresh the diagnostics cache for a book (used by the reindex CLI/timer). */
function reindex_prose($book_id) { ensure_prose_analysis(); $n = 0; foreach (get_chapters($book_id) as $ch) { if (preg_match('/readme\.md$/i', (string)$ch['file'])) continue; get_chapter_diagnostics($book_id, $ch['id']); $n++; } return $n; }
function reindex_prose_all() { foreach (get_books() as $b) reindex_prose($b['id']); }
