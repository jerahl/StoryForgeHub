<?php
/**
 * auth.php — Phase 17 (Track E): accounts, sessions, and invites.
 *
 * Replaces the single shared APP_PASSWORD gate with real per-user accounts:
 *   - `users`           real accounts (email + password_hash + admin flag + status)
 *   - `invites`         invite-only onboarding (no public signup)
 *   - `password_resets` admin-issued, link-based password reset (no email transport yet)
 *
 * All tables are additive; there is no contract change (auth stays app-layer).
 * The MCP / REST token in api.php is untouched here — per-user MCP auth is P20.
 */
require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------ schema */

/** Lazy-migration for the identity tables. Idempotent; cheap to call often. */
function ensure_users() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    foreach ([
        "CREATE TABLE IF NOT EXISTS users (
            id $pk,
            email VARCHAR(190) NOT NULL,
            display_name VARCHAR(160) DEFAULT '',
            password_hash VARCHAR(255) DEFAULT '',
            status VARCHAR(20) DEFAULT 'active',
            is_admin INT DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME DEFAULT NULL )",
        "CREATE TABLE IF NOT EXISTS invites (
            id $pk,
            email VARCHAR(190) DEFAULT '',
            token VARCHAR(64) NOT NULL,
            role VARCHAR(20) DEFAULT 'editor',
            book_id VARCHAR(40) DEFAULT '',
            is_admin INT DEFAULT 0,
            invited_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            accepted_at DATETIME DEFAULT NULL,
            accepted_user_id INT DEFAULT NULL )",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id $pk,
            user_id INT NOT NULL,
            token VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME DEFAULT NULL,
            used_at DATETIME DEFAULT NULL )",
    ] as $s) { try { db()->exec($s); } catch (Exception $e) {} }
    try { db()->exec("CREATE UNIQUE INDEX uniq_user_email ON users (email)"); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_invite_token ON invites (token)"); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_reset_token ON password_resets (token)"); } catch (Exception $e) {}
    // Additive upgrade: per-book invites (Phase 19) — an invite can carry the book
    // it grants access to, so accepting it also creates the membership.
    try { db()->exec("ALTER TABLE invites ADD COLUMN book_id VARCHAR(40) DEFAULT ''"); } catch (Exception $e) {}
}

/* ------------------------------------------------------------ small helpers */

function normalize_email($e) { return strtolower(trim((string)$e)); }
function gen_token($bytes = 24) { return bin2hex(random_bytes($bytes)); }
function now_stamp() { return date('Y-m-d H:i:s'); }

/** Returns a human error string if the password is too weak, else null. */
function password_problem($pw) {
    $pw = (string)$pw;
    if (strlen($pw) < 8) return 'Password must be at least 8 characters.';
    return null;
}

/* -------------------------------------------------------------------- users */

function count_users()         { ensure_users(); return (int) val("SELECT COUNT(*) FROM users"); }
function count_active_admins() { ensure_users(); return (int) val("SELECT COUNT(*) FROM users WHERE is_admin=1 AND status='active'"); }
function get_user($id)         { ensure_users(); return one("SELECT * FROM users WHERE id=?", [(int)$id]); }
function find_user_by_email($email) { ensure_users(); return one("SELECT * FROM users WHERE email=?", [normalize_email($email)]); }
function list_users() {
    ensure_users();
    return all("SELECT * FROM users ORDER BY is_admin DESC, LOWER(display_name), LOWER(email)");
}

/**
 * Create a real account. Throws on invalid/duplicate email. Returns the new id.
 * $is_admin/$status are trusted callers' concern (bootstrap / invite / admin UI).
 */
function create_user($email, $display_name, $password, $is_admin = 0, $status = 'active') {
    ensure_users();
    $email = normalize_email($email);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new InvalidArgumentException('A valid email address is required.');
    if (find_user_by_email($email))
        throw new RuntimeException('An account with that email already exists.');
    $name = trim((string)$display_name);
    if ($name === '') $name = explode('@', $email)[0];
    $status = in_array($status, ['active','disabled'], true) ? $status : 'active';
    $hash = password_hash((string)$password, PASSWORD_DEFAULT);
    q("INSERT INTO users (email, display_name, password_hash, status, is_admin) VALUES (?,?,?,?,?)",
      [$email, $name, $hash, $status, $is_admin ? 1 : 0]);
    return (int) last_id();
}

function set_user_password($uid, $password) {
    ensure_users();
    q("UPDATE users SET password_hash=? WHERE id=?",
      [password_hash((string)$password, PASSWORD_DEFAULT), (int)$uid]);
}
function set_user_display_name($uid, $name) {
    ensure_users();
    q("UPDATE users SET display_name=? WHERE id=?", [trim((string)$name), (int)$uid]);
}
function set_user_status($uid, $status) {
    ensure_users();
    $status = in_array($status, ['active','disabled'], true) ? $status : 'active';
    q("UPDATE users SET status=? WHERE id=?", [$status, (int)$uid]);
}
function set_user_admin($uid, $is_admin) {
    ensure_users();
    q("UPDATE users SET is_admin=? WHERE id=?", [$is_admin ? 1 : 0, (int)$uid]);
}
function delete_user($uid) {
    ensure_users();
    q("DELETE FROM password_resets WHERE user_id=?", [(int)$uid]);
    q("DELETE FROM users WHERE id=?", [(int)$uid]);
}
function touch_last_seen($uid) {
    ensure_users();
    try { q("UPDATE users SET last_seen_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$uid]); } catch (Exception $e) {}
}

/** Verify credentials. Returns the active user row on success, else null. */
function verify_login($email, $password) {
    ensure_users();
    $u = find_user_by_email($email);
    if (!$u || $u['status'] !== 'active' || empty($u['password_hash'])) return null;
    if (!password_verify((string)$password, $u['password_hash'])) return null;
    return $u;
}

/* ----------------------------------------------------------------- session */

/** Bind the session to a user, regenerating the id to defeat fixation. */
function login_user($u) {
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    $_SESSION['uid'] = (int)$u['id'];
    unset($_SESSION['auth']);            // retire the legacy shared-password flag
    $GLOBALS['__current_user'] = null;   // bust the request cache
    touch_last_seen((int)$u['id']);
}
function logout_user() {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) session_regenerate_id(true);
    unset($_SESSION['uid']);
    $GLOBALS['__current_user'] = null;
}
/** The logged-in, still-active user row, or null. Cached per request. */
function current_user() {
    if (array_key_exists('__current_user', $GLOBALS) && $GLOBALS['__current_user'] !== null)
        return $GLOBALS['__current_user'];
    if (empty($_SESSION['uid'])) return $GLOBALS['__current_user'] = null;
    $u = get_user((int)$_SESSION['uid']);
    if (!$u || $u['status'] !== 'active') { unset($_SESSION['uid']); return $GLOBALS['__current_user'] = null; }
    return $GLOBALS['__current_user'] = $u;
}
function current_user_id() { $u = current_user(); return $u ? (int)$u['id'] : null; }
function user_is_admin()   { $u = current_user(); return $u && (int)$u['is_admin'] === 1; }

/* ----------------------------------------------------------------- invites */

/**
 * Create an invite for $email at an intended book $role (used by P18/P19).
 * Returns the raw token. No public signup exists — only admins call this.
 */
function create_invite($email, $role = 'editor', $is_admin = 0, $invited_by = null, $ttl_days = 14, $book_id = '') {
    ensure_users();
    $email = normalize_email($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new InvalidArgumentException('A valid email address is required.');
    if ($email !== '' && find_user_by_email($email))
        throw new RuntimeException('That email already has an account.');
    $role = in_array($role, ['owner','editor','viewer'], true) ? $role : 'editor';
    $token = gen_token(24);
    $expires = date('Y-m-d H:i:s', time() + $ttl_days * 86400);
    q("INSERT INTO invites (email, token, role, book_id, is_admin, invited_by, expires_at) VALUES (?,?,?,?,?,?,?)",
      [$email, $token, $role, (string)$book_id, $is_admin ? 1 : 0, $invited_by !== null ? (int)$invited_by : null, $expires]);
    return $token;
}
function get_invite_by_token($token) { ensure_users(); return one("SELECT * FROM invites WHERE token=?", [(string)$token]); }
function invite_is_valid($inv) {
    if (!$inv) return false;
    if (!empty($inv['accepted_at'])) return false;
    if (!empty($inv['expires_at']) && $inv['expires_at'] < now_stamp()) return false;
    return true;
}
function list_invites($pending_only = true) {
    ensure_users();
    $sql = "SELECT * FROM invites" . ($pending_only ? " WHERE accepted_at IS NULL" : "") . " ORDER BY created_at DESC, id DESC";
    return all($sql);
}
function revoke_invite($id) { ensure_users(); q("DELETE FROM invites WHERE id=? AND accepted_at IS NULL", [(int)$id]); }
/** Pending invites scoped to one book (Phase 19 per-book member management). */
function list_book_invites($book_id) {
    ensure_users();
    return all("SELECT * FROM invites WHERE accepted_at IS NULL AND book_id=? ORDER BY created_at DESC, id DESC", [(string)$book_id]);
}

/**
 * Accept an invite: create the real user, mark the invite consumed.
 * Returns the new user id. Throws if the invite is invalid/expired/used.
 */
function accept_invite($token, $display_name, $password) {
    ensure_users();
    $inv = get_invite_by_token($token);
    if (!invite_is_valid($inv)) throw new RuntimeException('This invite is invalid, expired, or already used.');
    $email = normalize_email($inv['email']);
    if ($email === '') throw new RuntimeException('This invite has no email on file; ask your admin to reissue it.');
    $uid = create_user($email, $display_name, $password, (int)$inv['is_admin'], 'active');
    // Phase 19: a per-book invite also grants membership on acceptance.
    if (!empty($inv['book_id'])) add_book_member($inv['book_id'], $uid, $inv['role'] ?: 'editor', (int)($inv['invited_by'] ?? 0) ?: null);
    q("UPDATE invites SET accepted_at=CURRENT_TIMESTAMP, accepted_user_id=? WHERE id=?", [$uid, (int)$inv['id']]);
    return $uid;
}

/* --------------------------------------------------------- password resets */

/** Issue an admin-generated reset link for an existing user. Returns the token. */
function create_password_reset($uid, $ttl_hours = 48) {
    ensure_users();
    $token = gen_token(24);
    $expires = date('Y-m-d H:i:s', time() + $ttl_hours * 3600);
    q("INSERT INTO password_resets (user_id, token, expires_at) VALUES (?,?,?)", [(int)$uid, $token, $expires]);
    return $token;
}
function get_password_reset($token) { ensure_users(); return one("SELECT * FROM password_resets WHERE token=?", [(string)$token]); }
function reset_is_valid($r) {
    if (!$r) return false;
    if (!empty($r['used_at'])) return false;
    if (!empty($r['expires_at']) && $r['expires_at'] < now_stamp()) return false;
    if (!get_user((int)$r['user_id'])) return false;
    return true;
}
/** Consume a reset token and set the new password. Returns the user id. */
function consume_password_reset($token, $password) {
    ensure_users();
    $r = get_password_reset($token);
    if (!reset_is_valid($r)) throw new RuntimeException('This reset link is invalid, expired, or already used.');
    set_user_password((int)$r['user_id'], $password);
    q("UPDATE password_resets SET used_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$r['id']]);
    return (int)$r['user_id'];
}

/* =====================================================================
   Book membership & library scoping (Phase 18, Track E)

   The unit of ownership is the *book*, not the user: a shared book has an
   owner plus any editors/viewers, expressed as rows in `book_members`. The
   web library is scoped to the caller's memberships; the token REST API and
   CLI (no session user) stay unscoped — per-user MCP auth is P20.
   ===================================================================== */

/** Lazy-migration for the membership join. Table + indexes only — the backfill is
 *  deliberately kept OUT of here so it never runs on the create path (which would
 *  grab a book between its row insert and its owner row). */
function ensure_book_members() {
    static $done = false; if ($done) return; $done = true;
    ensure_users();
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec(
        "CREATE TABLE IF NOT EXISTS book_members (
            id $pk,
            book_id VARCHAR(40) NOT NULL,
            user_id INT NOT NULL,
            role VARCHAR(20) DEFAULT 'editor',
            added_by INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"
    ); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_book_member ON book_members (book_id, user_id)"); } catch (Exception $e) {}
    try { db()->exec("CREATE INDEX k_member_user ON book_members (user_id)"); } catch (Exception $e) {}
}

/**
 * Assign every member-less book to the earliest active admin as owner. This is the
 * upgrade path that makes the existing installation own its pre-P18 books, plus a
 * safety net for books created by the token API / CLI (which have no session user).
 *
 * Called once per request from the auth gate — BEFORE any create/import in the same
 * request — so a book being created now (its owner row written later this request)
 * is never mistaken for an orphan. Idempotent: only touches books with zero members.
 */
function backfill_book_members() {
    static $done = false; if ($done) return;
    ensure_book_members();
    $adminId = val("SELECT id FROM users WHERE is_admin=1 AND status='active' ORDER BY id LIMIT 1");
    if (!$adminId) return;   // no admin yet — retry on a later request
    $done = true;
    $orphans = [];
    try { $orphans = all("SELECT b.id FROM books b LEFT JOIN book_members m ON m.book_id=b.id WHERE m.id IS NULL"); }
    catch (Exception $e) { $done = false; return; }   // books table may not exist yet on a truly empty box
    foreach ($orphans as $o) add_book_member($o['id'], (int)$adminId, 'owner', (int)$adminId);
}

/** Add or update a membership (unique per book+user). Returns nothing. */
function add_book_member($book_id, $user_id, $role = 'editor', $added_by = null) {
    ensure_book_members();
    $role = in_array($role, ['owner','editor','viewer'], true) ? $role : 'editor';
    $ex = one("SELECT id FROM book_members WHERE book_id=? AND user_id=?", [$book_id, (int)$user_id]);
    if ($ex) q("UPDATE book_members SET role=? WHERE id=?", [$role, (int)$ex['id']]);
    else     q("INSERT INTO book_members (book_id, user_id, role, added_by) VALUES (?,?,?,?)",
               [$book_id, (int)$user_id, $role, $added_by !== null ? (int)$added_by : null]);
}
function remove_book_member($book_id, $user_id) {
    ensure_book_members();
    q("DELETE FROM book_members WHERE book_id=? AND user_id=?", [$book_id, (int)$user_id]);
}
function get_book_members($book_id) {
    ensure_book_members();
    return all("SELECT m.*, u.email, u.display_name, u.status AS user_status
                FROM book_members m JOIN users u ON u.id=m.user_id
                WHERE m.book_id=?
                ORDER BY CASE m.role WHEN 'owner' THEN 0 WHEN 'editor' THEN 1 ELSE 2 END, LOWER(u.display_name)",
               [$book_id]);
}
function count_book_owners($book_id) {
    ensure_book_members();
    return (int) val("SELECT COUNT(*) FROM book_members WHERE book_id=? AND role='owner'", [$book_id]);
}
/** The caller's role on a book ('owner'|'editor'|'viewer'), or null if not a member. */
function user_book_role($user_id, $book_id) {
    ensure_book_members();
    return val("SELECT role FROM book_members WHERE book_id=? AND user_id=?", [$book_id, (int)$user_id]);
}
function user_can_view_book($user_id, $book_id) {
    if ($user_id === null) return true;   // unscoped context (token API / CLI)
    return user_book_role($user_id, $book_id) !== null;
}

/**
 * The user id the library should be scoped to, or null for an unscoped view.
 * Null when: no session user (token API / CLI), or the caller is an admin —
 * admins operate the instance and see every book.
 */
function book_scope_uid() {
    $uid = current_user_id();
    if ($uid === null) return null;        // token API / CLI → full access
    if (user_is_admin()) return null;      // admins see everything
    return $uid;
}

/* --------------------------------------------- roles & permissions (Phase 19)

   The role matrix, enforced server-side on every book-scoped mutation:
     owner   — full read/write + manage members/invites + delete the book
     editor  — read/write prose, entries, sources, tasks, plot board
     viewer  — read everything; may add comments / chapter_notes; no prose edits
   Admins and the unscoped token/CLI path bypass the matrix.
   ------------------------------------------------------------------------- */

/** Does a role grant a capability? Capabilities: view | comment | edit | manage. */
function role_allows($role, $cap) {
    switch ($cap) {
        case 'view':
        case 'comment': return in_array($role, ['owner','editor','viewer'], true);
        case 'edit':    return in_array($role, ['owner','editor'], true);
        case 'manage':  return $role === 'owner';
    }
    return false;
}

/** Can the caller perform $cap on $book_id? Admin & unscoped (token/CLI) always may. */
function user_can($book_id, $cap) {
    $uid = current_user_id();
    if ($uid === null) return true;      // token API / CLI — unscoped (P20 governs MCP)
    if (user_is_admin()) return true;    // instance admin — superuser
    if ($book_id === null || $book_id === '') return false;
    return role_allows(user_book_role($uid, $book_id), $cap);
}

/* ----------------------------------------------- book activity log (Phase 19)
   A lightweight who-did-what-when trail, valuable the moment a book is shared. */

function ensure_book_activity() {
    static $done = false; if ($done) return; $done = true;
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec(
        "CREATE TABLE IF NOT EXISTS book_activity (
            id $pk,
            book_id VARCHAR(40) NOT NULL,
            user_id INT DEFAULT NULL,
            action VARCHAR(40) NOT NULL,
            detail VARCHAR(255) DEFAULT '',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"
    ); } catch (Exception $e) {}
    try { db()->exec("CREATE INDEX k_activity_book ON book_activity (book_id)"); } catch (Exception $e) {}
}
/** Record an action against a book. Best-effort; never throws into the caller. */
function log_activity($book_id, $action, $detail = '') {
    if ($book_id === null || $book_id === '') return;
    ensure_book_activity();
    try { q("INSERT INTO book_activity (book_id, user_id, action, detail) VALUES (?,?,?,?)",
             [$book_id, current_user_id(), (string)$action, mb_substr((string)$detail, 0, 255)]); }
    catch (Exception $e) {}
}
function recent_activity($book_id, $limit = 20) {
    ensure_book_activity();
    $limit = max(1, min(200, (int)$limit));
    return all("SELECT a.*, u.display_name, u.email FROM book_activity a
                LEFT JOIN users u ON u.id=a.user_id
                WHERE a.book_id=? ORDER BY a.id DESC LIMIT $limit", [$book_id]);
}
