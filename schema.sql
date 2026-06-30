-- Stephen's Codex — web app schema (MySQL 5.7+/8, MariaDB 10+)
-- Run once:  mysql -u USER -p DBNAME < schema.sql
SET NAMES utf8mb4;
SET foreign_key_checks = 0;

CREATE TABLE IF NOT EXISTS books (
  id           VARCHAR(40)  NOT NULL PRIMARY KEY,
  folder       VARCHAR(120) NOT NULL,
  title        VARCHAR(255) NOT NULL,
  series       VARCHAR(160) DEFAULT '',
  num          VARCHAR(10)  DEFAULT '',
  status       VARCHAR(20)  DEFAULT 'planning',   -- planning|drafting|revising|published
  profile      VARCHAR(20)  DEFAULT 'fiction',    -- fiction|nonfiction|selfhelp|memoir (Phase 10)
  logline      TEXT,
  genre        VARCHAR(255) DEFAULT '',
  word_target  VARCHAR(20)  DEFAULT '',
  dot          VARCHAR(10)  DEFAULT '#4A4391',
  sort_order   INT          DEFAULT 0,
  updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entries (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  book_id      VARCHAR(40)  NOT NULL,
  db_key       VARCHAR(20)  NOT NULL,             -- characters|locations|factions|objects|lore
  slug         VARCHAR(160) NOT NULL,
  name         VARCHAR(255) NOT NULL,
  status       VARCHAR(20)  DEFAULT 'seed',       -- seed|sketch|canon
  type         VARCHAR(60)  DEFAULT '',
  detail       VARCHAR(255) DEFAULT '',
  detail_label VARCHAR(60)  DEFAULT '',
  first_app    VARCHAR(160) DEFAULT '',
  related_raw  TEXT,
  sort_order   INT          DEFAULT 0,
  updated_at   DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_entry (book_id, db_key, slug),
  KEY k_book_db (book_id, db_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_fields (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  entry_id  INT NOT NULL,
  label     VARCHAR(120) NOT NULL,
  value     TEXT,
  sort_order INT DEFAULT 0,
  KEY k_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_sections (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  entry_id  INT NOT NULL,
  heading   VARCHAR(255) NOT NULL,
  body      MEDIUMTEXT,
  sort_order INT DEFAULT 0,
  KEY k_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS entry_relations (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  entry_id    INT NOT NULL,
  target_slug VARCHAR(160) NOT NULL,
  sort_order  INT DEFAULT 0,
  KEY k_entry (entry_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chapters (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  book_id    VARCHAR(40) NOT NULL,
  num        VARCHAR(20) DEFAULT '',
  title      VARCHAR(255) DEFAULT '',
  pov        VARCHAR(160) DEFAULT '',
  status     VARCHAR(20) DEFAULT 'drafted',       -- outline|drafted|revised
  words      VARCHAR(20) DEFAULT '',
  word_count INT DEFAULT 0,
  summary    TEXT,
  body       MEDIUMTEXT,
  file       VARCHAR(255) DEFAULT '',
  sort_order INT DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_ch (book_id, file),
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS progressions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  book_id     VARCHAR(40) NOT NULL,
  chapter     VARCHAR(60) DEFAULT '',
  type        VARCHAR(40) DEFAULT 'turn',
  what        TEXT,
  related_csv TEXT,
  sort_order  INT DEFAULT 0,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS threads (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  book_id     VARCHAR(40) NOT NULL,
  entry_slug  VARCHAR(160) DEFAULT '',
  entry_name  VARCHAR(255) DEFAULT '',
  db_key      VARCHAR(20) DEFAULT '',
  status      VARCHAR(20) DEFAULT 'open',         -- open|resolved
  text        TEXT,
  sort_order  INT DEFAULT 0,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS meta_pages (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  book_id  VARCHAR(40) NOT NULL,
  slug     VARCHAR(120) NOT NULL,
  title    VARCHAR(255) DEFAULT '',
  body     MEDIUMTEXT,
  UNIQUE KEY uniq_meta (book_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tasks (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  book_id     VARCHAR(40) NOT NULL,
  title       VARCHAR(255) NOT NULL,
  body        TEXT,
  status      VARCHAR(20) DEFAULT 'todo',         -- todo|doing|done
  for_claude  TINYINT(1) DEFAULT 0,
  target_db   VARCHAR(20) DEFAULT '',
  target_slug VARCHAR(160) DEFAULT '',
  result      TEXT,
  priority    VARCHAR(10) DEFAULT 'med',          -- low|med|high
  due         VARCHAR(20) DEFAULT 'someday',      -- today|tomorrow|week|someday
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- task sub-steps (Beacon "break it into tiny pieces") — app-only, NOT synced
CREATE TABLE IF NOT EXISTS task_steps (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  task_id    INT NOT NULL,
  text       VARCHAR(255) NOT NULL,
  done       TINYINT(1) DEFAULT 0,
  sort_order INT DEFAULT 0,
  KEY k_task (task_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS writing_log (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  book_id     VARCHAR(40) NOT NULL,
  log_date    DATE NOT NULL,
  words_added INT DEFAULT 0,
  total_words INT DEFAULT 0,
  chapters    VARCHAR(60) DEFAULT '',
  minutes     INT DEFAULT 0,
  mood        VARCHAR(60) DEFAULT '',
  note        TEXT,
  source      VARCHAR(20) DEFAULT 'manual',       -- manual|claude
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- chapter revision notes — flag a passage and attach a change note
CREATE TABLE IF NOT EXISTS chapter_notes (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  book_id      VARCHAR(40) NOT NULL,
  chapter_file VARCHAR(255) NOT NULL,              -- stable key (survives re-imports); links to chapters.file
  quote        TEXT,                               -- the flagged passage (may be '')
  note         TEXT,                               -- the change to make
  status       VARCHAR(20) DEFAULT 'open',         -- open|resolved
  task_id      INT DEFAULT NULL,                   -- set when promoted to a Task
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY k_book_file (book_id, chapter_file)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- planning notes (Codex/Notes/*.md) — folder-authored, synced into the app for reading
CREATE TABLE IF NOT EXISTS note_pages (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  book_id  VARCHAR(40) NOT NULL,
  slug     VARCHAR(160) NOT NULL,
  title    VARCHAR(255) DEFAULT '',
  body     MEDIUMTEXT,
  UNIQUE KEY uniq_note (book_id, slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- brain-dump inbox (Beacon capture bar) — app-only, NOT synced to folders
CREATE TABLE IF NOT EXISTS captures (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  book_id    VARCHAR(40) DEFAULT '',                 -- '' = global / untriaged
  text       TEXT,
  status     VARCHAR(20) DEFAULT 'inbox',            -- inbox|triaged|dismissed
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY k_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- plot board (Beacon canvas) — app-only, NOT synced
CREATE TABLE IF NOT EXISTS canvas_cards (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  book_id    VARCHAR(40) NOT NULL,
  x          INT DEFAULT 40,
  y          INT DEFAULT 40,
  text       TEXT,
  color      VARCHAR(10) DEFAULT '#7c8cff',
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS canvas_links (
  id      INT AUTO_INCREMENT PRIMARY KEY,
  book_id VARCHAR(40) NOT NULL,
  from_id INT NOT NULL,
  to_id   INT NOT NULL,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- mood board (Beacon vision board) — app-only, NOT synced
CREATE TABLE IF NOT EXISTS vision_items (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  book_id    VARCHAR(40) NOT NULL,
  caption    VARCHAR(255) DEFAULT '',
  image_url  TEXT,
  sort_order INT DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY k_book (book_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- key/value sync bookkeeping (e.g., per-chapter word baselines)
CREATE TABLE IF NOT EXISTS sync_state (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  book_id  VARCHAR(40) NOT NULL,
  k        VARCHAR(160) NOT NULL,
  v        TEXT,
  UNIQUE KEY uniq_kv (book_id, k)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;
