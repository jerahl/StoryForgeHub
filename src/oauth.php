<?php
/**
 * oauth.php — OAuth 2.1 authorization server for Claude connectors
 * (standalone plan, Track B3).
 *
 * Lets a user connect Claude to the MCP by SIGNING IN instead of pasting a
 * personal token: Claude discovers this server via RFC 9728/8414 metadata,
 * registers itself (RFC 7591 dynamic client registration, public client +
 * PKCE S256), sends the user to /oauth.php (authorize) for consent, and
 * exchanges the code at the token endpoint. The issued access token is a
 * bearer the whole existing stack already understands how to scope: api.php
 * resolves it with user_for_oauth_token() and acts as that user, exactly like
 * a Phase 20 personal token — so the MCP pass-through needs no changes.
 *
 * Storage follows the api_tokens pattern: only sha256 hashes of codes and
 * tokens are persisted; raw values are shown/issued once. Codes are
 * single-use and short-lived; refresh tokens rotate on every use and a
 * replayed (already-rotated) refresh token revokes the whole grant.
 */
require_once __DIR__ . '/auth.php';

const OAUTH_CODE_TTL     = 300;                 // authorization codes: 5 minutes
const OAUTH_ACCESS_TTL   = 3600;                // access tokens: 1 hour
const OAUTH_REFRESH_TTL  = 30 * 86400;          // refresh tokens: 30 days
const OAUTH_ACCESS_PREFIX  = 'codexoa_';        // distinguishable from codex_ personal tokens
const OAUTH_REFRESH_PREFIX = 'codexor_';

function ensure_oauth() {
    static $done = false; if ($done) return; $done = true;
    ensure_users();
    $pk = is_sqlite() ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    try { db()->exec(
        "CREATE TABLE IF NOT EXISTS oauth_clients (
            id $pk,
            client_id VARCHAR(64) NOT NULL,
            name VARCHAR(255) DEFAULT '',
            redirect_uris TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"
    ); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_oauth_client ON oauth_clients (client_id)"); } catch (Exception $e) {}
    try { db()->exec(
        "CREATE TABLE IF NOT EXISTS oauth_codes (
            id $pk,
            code_hash VARCHAR(64) NOT NULL,
            client_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            redirect_uri TEXT,
            code_challenge VARCHAR(128) NOT NULL,
            scope VARCHAR(255) DEFAULT '',
            expires_at DATETIME NOT NULL,
            used_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"
    ); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_oauth_code ON oauth_codes (code_hash)"); } catch (Exception $e) {}
    try { db()->exec(
        "CREATE TABLE IF NOT EXISTS oauth_grants (
            id $pk,
            client_id VARCHAR(64) NOT NULL,
            user_id INT NOT NULL,
            scope VARCHAR(255) DEFAULT '',
            access_hash VARCHAR(64) NOT NULL,
            refresh_hash VARCHAR(64) NOT NULL,
            prev_refresh_hash VARCHAR(64) DEFAULT '',
            access_expires_at DATETIME NOT NULL,
            refresh_expires_at DATETIME NOT NULL,
            revoked_at DATETIME DEFAULT NULL,
            last_used_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP )"
    ); } catch (Exception $e) {}
    try { db()->exec("CREATE UNIQUE INDEX uniq_oauth_access ON oauth_grants (access_hash)"); } catch (Exception $e) {}
    try { db()->exec("CREATE INDEX k_oauth_user ON oauth_grants (user_id)"); } catch (Exception $e) {}
}

/* ------------------------------------------------ clients (RFC 7591 DCR) */

/** Register a public client (token_endpoint_auth_method "none", PKCE
 *  enforced at authorize/token time). Returns the stored client row. */
function oauth_register_client($name, array $redirect_uris) {
    ensure_oauth();
    $uris = [];
    foreach ($redirect_uris as $u) {
        $u = trim((string)$u);
        $parts = parse_url($u);
        // https only (loopback http allowed for local dev), no fragments — OAuth 2.1
        $ok = $parts && empty($parts['fragment']) && !empty($parts['scheme']) && !empty($parts['host'])
            && ($parts['scheme'] === 'https'
                || ($parts['scheme'] === 'http' && in_array($parts['host'], ['127.0.0.1', 'localhost'], true)));
        if (!$ok) throw new RuntimeException("invalid redirect_uri: $u");
        $uris[] = $u;
    }
    if (!$uris) throw new RuntimeException('at least one redirect_uri is required');
    $client_id = 'cc_' . gen_token(16);
    q("INSERT INTO oauth_clients (client_id, name, redirect_uris) VALUES (?,?,?)",
      [$client_id, mb_substr(trim((string)$name), 0, 255), json_encode($uris, JSON_UNESCAPED_SLASHES)]);
    return oauth_get_client($client_id);
}

function oauth_get_client($client_id) {
    ensure_oauth();
    $c = one("SELECT * FROM oauth_clients WHERE client_id=?", [(string)$client_id]);
    if ($c) $c['redirect_uris'] = json_decode($c['redirect_uris'] ?: '[]', true) ?: [];
    return $c;
}

/** Exact-match redirect_uri check (OAuth 2.1 — no prefix or wildcard match). */
function oauth_redirect_allowed($client, $uri) {
    return $client && in_array((string)$uri, $client['redirect_uris'], true);
}

/* ------------------------------------------------ authorization codes */

/** Mint a single-use code bound to (client, user, redirect_uri, PKCE challenge).
 *  Returns the RAW code for the redirect; only its hash is stored. */
function oauth_create_code($client_id, $user_id, $redirect_uri, $code_challenge, $scope = '') {
    ensure_oauth();
    if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', (string)$code_challenge))
        throw new RuntimeException('code_challenge (S256) is required');
    $raw = 'cxc_' . gen_token(24);
    q("INSERT INTO oauth_codes (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, expires_at)
       VALUES (?,?,?,?,?,?,?)",
      [hash_api_token($raw), (string)$client_id, (int)$user_id, (string)$redirect_uri,
       (string)$code_challenge, mb_substr((string)$scope, 0, 255),
       date('Y-m-d H:i:s', time() + OAUTH_CODE_TTL)]);
    return $raw;
}

/** RFC 7636: BASE64URL(SHA256(verifier)) must equal the stored challenge. */
function oauth_pkce_ok($verifier, $challenge) {
    if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', (string)$verifier)) return false;
    $computed = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return hash_equals((string)$challenge, $computed);
}

/**
 * Exchange a code (+ verifier) for a fresh grant. Enforces: known code, not
 * used, not expired, same client, same redirect_uri, PKCE match. Marks the
 * code used and returns ['access'=>raw,'refresh'=>raw,'expires_in'=>...,'scope'=>...].
 * Throws RuntimeException with an RFC 6749 error code as the message prefix.
 */
function oauth_exchange_code($raw_code, $client_id, $redirect_uri, $code_verifier) {
    ensure_oauth();
    $c = one("SELECT * FROM oauth_codes WHERE code_hash=?", [hash_api_token((string)$raw_code)]);
    if (!$c || $c['used_at'] !== null || $c['expires_at'] < date('Y-m-d H:i:s'))
        throw new RuntimeException('invalid_grant: unknown, used, or expired code');
    if (!hash_equals($c['client_id'], (string)$client_id))
        throw new RuntimeException('invalid_grant: code was issued to another client');
    if ((string)$c['redirect_uri'] !== (string)$redirect_uri)
        throw new RuntimeException('invalid_grant: redirect_uri mismatch');
    if (!oauth_pkce_ok($code_verifier, $c['code_challenge']))
        throw new RuntimeException('invalid_grant: PKCE verification failed');
    $u = get_user((int)$c['user_id']);
    if (!$u || $u['status'] !== 'active')
        throw new RuntimeException('invalid_grant: user is not active');
    q("UPDATE oauth_codes SET used_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$c['id']]);
    return oauth_issue_grant($c['client_id'], (int)$c['user_id'], (string)$c['scope']);
}

/* ------------------------------------------------ grants (access + refresh) */

function oauth_issue_grant($client_id, $user_id, $scope = '') {
    ensure_oauth();
    $access  = OAUTH_ACCESS_PREFIX . gen_token(24);
    $refresh = OAUTH_REFRESH_PREFIX . gen_token(24);
    q("INSERT INTO oauth_grants (client_id, user_id, scope, access_hash, refresh_hash,
                                 access_expires_at, refresh_expires_at)
       VALUES (?,?,?,?,?,?,?)",
      [(string)$client_id, (int)$user_id, (string)$scope,
       hash_api_token($access), hash_api_token($refresh),
       date('Y-m-d H:i:s', time() + OAUTH_ACCESS_TTL),
       date('Y-m-d H:i:s', time() + OAUTH_REFRESH_TTL)]);
    return ['access' => $access, 'refresh' => $refresh,
            'expires_in' => OAUTH_ACCESS_TTL, 'scope' => (string)$scope];
}

/**
 * Rotate a grant from its refresh token: new access + refresh, same row.
 * A REPLAYED refresh token (matches prev_refresh_hash) means the token was
 * stolen and already rotated — revoke the whole grant (OAuth 2.1 §4.3.1).
 */
function oauth_refresh_grant($raw_refresh, $client_id) {
    ensure_oauth();
    $h = hash_api_token((string)$raw_refresh);
    $g = one("SELECT * FROM oauth_grants WHERE refresh_hash=?", [$h]);
    if (!$g) {
        $stolen = one("SELECT * FROM oauth_grants WHERE prev_refresh_hash=? AND revoked_at IS NULL", [$h]);
        if ($stolen) q("UPDATE oauth_grants SET revoked_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$stolen['id']]);
        throw new RuntimeException('invalid_grant: unknown refresh token');
    }
    if ($g['revoked_at'] !== null || $g['refresh_expires_at'] < date('Y-m-d H:i:s'))
        throw new RuntimeException('invalid_grant: refresh token revoked or expired');
    if (!hash_equals($g['client_id'], (string)$client_id))
        throw new RuntimeException('invalid_grant: refresh token was issued to another client');
    $u = get_user((int)$g['user_id']);
    if (!$u || $u['status'] !== 'active')
        throw new RuntimeException('invalid_grant: user is not active');
    $access  = OAUTH_ACCESS_PREFIX . gen_token(24);
    $refresh = OAUTH_REFRESH_PREFIX . gen_token(24);
    q("UPDATE oauth_grants SET access_hash=?, refresh_hash=?, prev_refresh_hash=?,
              access_expires_at=?, refresh_expires_at=?, last_used_at=CURRENT_TIMESTAMP WHERE id=?",
      [hash_api_token($access), hash_api_token($refresh), $h,
       date('Y-m-d H:i:s', time() + OAUTH_ACCESS_TTL),
       date('Y-m-d H:i:s', time() + OAUTH_REFRESH_TTL), (int)$g['id']]);
    return ['access' => $access, 'refresh' => $refresh,
            'expires_in' => OAUTH_ACCESS_TTL, 'scope' => (string)$g['scope']];
}

/** Resolve a raw OAuth access token to its active user, or null — the OAuth
 *  twin of user_for_api_token(); api.php tries both. */
function user_for_oauth_token($raw) {
    if (!is_string($raw) || strpos($raw, OAUTH_ACCESS_PREFIX) !== 0) return null;
    ensure_oauth();
    $g = one("SELECT * FROM oauth_grants WHERE access_hash=?", [hash_api_token($raw)]);
    if (!$g || $g['revoked_at'] !== null || $g['access_expires_at'] < date('Y-m-d H:i:s')) return null;
    $u = get_user((int)$g['user_id']);
    if (!$u || $u['status'] !== 'active') return null;
    try { q("UPDATE oauth_grants SET last_used_at=CURRENT_TIMESTAMP WHERE id=?", [(int)$g['id']]); } catch (Exception $e) {}
    return $u;
}

/** Active grants for the Account page ("Connected apps"), newest first. */
function list_oauth_grants($uid) {
    ensure_oauth();
    return all("SELECT g.id, g.client_id, c.name AS client_name, g.created_at, g.last_used_at, g.refresh_expires_at
                  FROM oauth_grants g LEFT JOIN oauth_clients c ON c.client_id = g.client_id
                 WHERE g.user_id=? AND g.revoked_at IS NULL AND g.refresh_expires_at >= ?
                 ORDER BY g.created_at DESC", [(int)$uid, date('Y-m-d H:i:s')]);
}

function revoke_oauth_grant($id, $owner_uid = null) {
    ensure_oauth();
    if ($owner_uid === null) q("UPDATE oauth_grants SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND revoked_at IS NULL", [(int)$id]);
    else q("UPDATE oauth_grants SET revoked_at=CURRENT_TIMESTAMP WHERE id=? AND user_id=? AND revoked_at IS NULL", [(int)$id, (int)$owner_uid]);
}

/* ------------------------------------------------ server metadata */

/** RFC 8414 authorization-server metadata. $issuer = scheme://host (no path). */
function oauth_as_metadata($issuer) {
    return [
        'issuer' => $issuer,
        'authorization_endpoint' => $issuer . '/oauth.php',
        'token_endpoint' => $issuer . '/oauth.php?action=token',
        'registration_endpoint' => $issuer . '/oauth.php?action=register',
        'response_types_supported' => ['code'],
        'grant_types_supported' => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported' => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none'],
        'scopes_supported' => ['codex'],
    ];
}

/** RFC 9728 protected-resource metadata for the MCP endpoint. */
function oauth_resource_metadata($issuer) {
    return [
        'resource' => $issuer . '/mcp',
        'authorization_servers' => [$issuer],
        'bearer_methods_supported' => ['header'],
        'resource_name' => "Stephen's Codex MCP",
    ];
}
