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
function create_invite($email, $role = 'editor', $is_admin = 0, $invited_by = null, $ttl_days = 14) {
    ensure_users();
    $email = normalize_email($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))
        throw new InvalidArgumentException('A valid email address is required.');
    if ($email !== '' && find_user_by_email($email))
        throw new RuntimeException('That email already has an account.');
    $role = in_array($role, ['owner','editor','viewer'], true) ? $role : 'editor';
    $token = gen_token(24);
    $expires = date('Y-m-d H:i:s', time() + $ttl_days * 86400);
    q("INSERT INTO invites (email, token, role, is_admin, invited_by, expires_at) VALUES (?,?,?,?,?,?)",
      [$email, $token, $role, $is_admin ? 1 : 0, $invited_by !== null ? (int)$invited_by : null, $expires]);
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
