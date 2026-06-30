<?php
/** db.php — PDO connection + tiny query helpers. MySQL (prod) or SQLite (dev/test). */

function cfg() {
    static $c = null;
    if ($c === null) {
        $f = dirname(__DIR__) . '/config.php';
        $c = is_file($f) ? require $f : require dirname(__DIR__) . '/config.sample.php';
    }
    return $c;
}

function db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $d = cfg()['db'];
    $driver = $d['driver'] ?? 'mysql';
    if ($driver === 'sqlite') {
        $pdo = new PDO('sqlite:' . $d['path']);
        $pdo->exec('PRAGMA foreign_keys = ON');
    } else {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $d['host'], $d['port'] ?? 3306, $d['name'], $d['charset'] ?? 'utf8mb4');
        $pdo = new PDO($dsn, $d['user'], $d['pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function is_sqlite() { return (cfg()['db']['driver'] ?? 'mysql') === 'sqlite'; }

function q($sql, $params = []) {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function all($sql, $params = []) { return q($sql, $params)->fetchAll(); }
function one($sql, $params = []) { $r = q($sql, $params)->fetch(); return $r === false ? null : $r; }
function val($sql, $params = []) { $r = q($sql, $params)->fetch(PDO::FETCH_NUM); return $r ? $r[0] : null; }
function last_id() { return db()->lastInsertId(); }

/**
 * Create all tables. Driver-aware so the same call works for MySQL or SQLite.
 * Production users can also just run schema.sql; this is the one-command path.
 */
function migrate() {
    $sqlite = is_sqlite();
    $pk = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $now = $sqlite ? "DATETIME DEFAULT CURRENT_TIMESTAMP" : "DATETIME DEFAULT CURRENT_TIMESTAMP";
    $tables = [
"books" => "(
  id VARCHAR(40) NOT NULL PRIMARY KEY, folder VARCHAR(120) NOT NULL, title VARCHAR(255) NOT NULL,
  series VARCHAR(160) DEFAULT '', num VARCHAR(10) DEFAULT '', status VARCHAR(20) DEFAULT 'planning',
  logline TEXT, genre VARCHAR(255) DEFAULT '', word_target VARCHAR(20) DEFAULT '',
  dot VARCHAR(10) DEFAULT '#4A4391', sort_order INT DEFAULT 0, updated_at $now )",
"entries" => "(
  id $pk, book_id VARCHAR(40) NOT NULL, db_key VARCHAR(20) NOT NULL, slug VARCHAR(160) NOT NULL,
  name VARCHAR(255) NOT NULL, status VARCHAR(20) DEFAULT 'seed', type VARCHAR(60) DEFAULT '',
  detail VARCHAR(255) DEFAULT '', detail_label VARCHAR(60) DEFAULT '', first_app VARCHAR(160) DEFAULT '',
  related_raw TEXT, sort_order INT DEFAULT 0, updated_at $now )",
"entry_fields"    => "( id $pk, entry_id INT NOT NULL, label VARCHAR(120) NOT NULL, value TEXT, sort_order INT DEFAULT 0 )",
"entry_sections"  => "( id $pk, entry_id INT NOT NULL, heading VARCHAR(255) NOT NULL, body TEXT, sort_order INT DEFAULT 0 )",
"entry_relations" => "( id $pk, entry_id INT NOT NULL, target_slug VARCHAR(160) NOT NULL, sort_order INT DEFAULT 0 )",
"chapters" => "(
  id $pk, book_id VARCHAR(40) NOT NULL, num VARCHAR(20) DEFAULT '', title VARCHAR(255) DEFAULT '',
  pov VARCHAR(160) DEFAULT '', status VARCHAR(20) DEFAULT 'drafted', words VARCHAR(20) DEFAULT '',
  word_count INT DEFAULT 0, summary TEXT, body MEDIUMTEXT, file VARCHAR(255) DEFAULT '', sort_order INT DEFAULT 0, updated_at $now )",
"progressions" => "( id $pk, book_id VARCHAR(40) NOT NULL, chapter VARCHAR(60) DEFAULT '', type VARCHAR(40) DEFAULT 'turn', what TEXT, related_csv TEXT, sort_order INT DEFAULT 0 )",
"threads" => "( id $pk, book_id VARCHAR(40) NOT NULL, entry_slug VARCHAR(160) DEFAULT '', entry_name VARCHAR(255) DEFAULT '', db_key VARCHAR(20) DEFAULT '', status VARCHAR(20) DEFAULT 'open', text TEXT, sort_order INT DEFAULT 0 )",
"meta_pages" => "( id $pk, book_id VARCHAR(40) NOT NULL, slug VARCHAR(120) NOT NULL, title VARCHAR(255) DEFAULT '', body TEXT )",
"tasks" => "(
  id $pk, book_id VARCHAR(40) NOT NULL, title VARCHAR(255) NOT NULL, body TEXT, status VARCHAR(20) DEFAULT 'todo',
  for_claude INT DEFAULT 0, target_db VARCHAR(20) DEFAULT '', target_slug VARCHAR(160) DEFAULT '',
  result TEXT, created_at $now, updated_at $now )",
"writing_log" => "(
  id $pk, book_id VARCHAR(40) NOT NULL, log_date DATE NOT NULL, words_added INT DEFAULT 0, total_words INT DEFAULT 0,
  chapters VARCHAR(60) DEFAULT '', minutes INT DEFAULT 0, mood VARCHAR(60) DEFAULT '', note TEXT,
  source VARCHAR(20) DEFAULT 'manual', created_at $now )",
"sync_state" => "( id $pk, book_id VARCHAR(40) NOT NULL, k VARCHAR(160) NOT NULL, v TEXT )",
"chapter_notes" => "(
  id $pk, book_id VARCHAR(40) NOT NULL, chapter_file VARCHAR(255) NOT NULL,
  quote TEXT, note TEXT, status VARCHAR(20) DEFAULT 'open', task_id INT DEFAULT NULL, created_at $now )",
"note_pages" => "( id $pk, book_id VARCHAR(40) NOT NULL, slug VARCHAR(160) NOT NULL, title VARCHAR(255) DEFAULT '', body MEDIUMTEXT )",
"captures" => "( id $pk, book_id VARCHAR(40) DEFAULT '', text TEXT, status VARCHAR(20) DEFAULT 'inbox', created_at $now )",
"task_steps" => "( id $pk, task_id INT NOT NULL, text VARCHAR(255) NOT NULL, done INT DEFAULT 0, sort_order INT DEFAULT 0 )",
"canvas_cards" => "( id $pk, book_id VARCHAR(40) NOT NULL, x INT DEFAULT 40, y INT DEFAULT 40, text TEXT, color VARCHAR(10) DEFAULT '#7c8cff', updated_at $now )",
"canvas_links" => "( id $pk, book_id VARCHAR(40) NOT NULL, from_id INT NOT NULL, to_id INT NOT NULL )",
"vision_items" => "( id $pk, book_id VARCHAR(40) NOT NULL, caption VARCHAR(255) DEFAULT '', image_url TEXT, sort_order INT DEFAULT 0, created_at $now )",
    ];
    foreach ($tables as $name => $cols) {
        db()->exec("CREATE TABLE IF NOT EXISTS $name $cols");
    }
    // Additive column upgrades for installs created before a column existed
    // (CREATE TABLE IF NOT EXISTS won't add columns to an existing table).
    foreach ([
        "ALTER TABLE chapters ADD COLUMN body MEDIUMTEXT",
        "ALTER TABLE tasks ADD COLUMN priority VARCHAR(10) DEFAULT 'med'",
        "ALTER TABLE tasks ADD COLUMN due VARCHAR(20) DEFAULT 'someday'",
    ] as $s) {
        try { db()->exec($s); } catch (Exception $e) {}
    }
    // Indexes / unique constraints (best-effort; ignore if they already exist).
    $idx = [
        "CREATE UNIQUE INDEX uniq_entry ON entries (book_id, db_key, slug)",
        "CREATE INDEX k_entry_fields ON entry_fields (entry_id)",
        "CREATE INDEX k_entry_sections ON entry_sections (entry_id)",
        "CREATE INDEX k_entry_relations ON entry_relations (entry_id)",
        "CREATE UNIQUE INDEX uniq_ch ON chapters (book_id, file)",
        "CREATE UNIQUE INDEX uniq_meta ON meta_pages (book_id, slug)",
        "CREATE UNIQUE INDEX uniq_kv ON sync_state (book_id, k)",
        "CREATE INDEX k_chnote ON chapter_notes (book_id, chapter_file)",
        "CREATE UNIQUE INDEX uniq_note ON note_pages (book_id, slug)",
    ];
    foreach ($idx as $s) { try { db()->exec($s); } catch (Exception $e) {} }
}
