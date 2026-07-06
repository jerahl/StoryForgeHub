<?php
/**
 * oauth_test.php — fixture checks for the OAuth 2.1 authorization server
 * (standalone plan, Track B3): dynamic client registration, the PKCE code
 * flow, refresh rotation with replay detection, and access-token resolution
 * (the api.php auth path).
 *
 *   php tests/php/oauth_test.php
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_oauth_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';
require_once __DIR__ . '/../../src/oauth.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function throws($label, $fn, $needle = '') {
    global $pass, $fail;
    try { $fn(); $fail++; echo "FAIL  $label (no exception)\n"; }
    catch (Exception $e) {
        if ($needle === '' || strpos($e->getMessage(), $needle) !== false) { $pass++; echo "  ok  $label\n"; }
        else { $fail++; echo "FAIL  $label (got: {$e->getMessage()})\n"; }
    }
}
function pkce_pair() {
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    return [$verifier, $challenge];
}

migrate();
$alice = create_user('alice@x.com', 'Alice', 'password1', 0);

/* ---- registration ---- */
$client = oauth_register_client('Claude', ['https://claude.ai/api/mcp/auth_callback']);
check('client gets a cc_ id', strpos($client['client_id'], 'cc_') === 0);
check('redirect uris survive round-trip', $client['redirect_uris'] === ['https://claude.ai/api/mcp/auth_callback']);
throws('http redirect on a public host is refused',
       function () { oauth_register_client('x', ['http://evil.com/cb']); }, 'invalid redirect_uri');
throws('fragment in redirect is refused',
       function () { oauth_register_client('x', ['https://a.com/cb#frag']); }, 'invalid redirect_uri');
throws('empty redirect list is refused',
       function () { oauth_register_client('x', []); });
$loopback = oauth_register_client('dev', ['http://127.0.0.1:6274/callback']);
check('http loopback redirect is allowed for local dev', $loopback['redirect_uris'][0] === 'http://127.0.0.1:6274/callback');
check('exact redirect match required',
      oauth_redirect_allowed($client, 'https://claude.ai/api/mcp/auth_callback')
      && !oauth_redirect_allowed($client, 'https://claude.ai/api/mcp/auth_callback2')
      && !oauth_redirect_allowed($client, 'https://claude.ai/api/mcp/'));

/* ---- code flow (happy path) ---- */
$cb = 'https://claude.ai/api/mcp/auth_callback';
[$verifier, $challenge] = pkce_pair();
$code = oauth_create_code($client['client_id'], $alice, $cb, $challenge, 'codex');
check('raw code has the cxc_ prefix', strpos($code, 'cxc_') === 0);
check('only the code hash is stored', val("SELECT COUNT(*) FROM oauth_codes WHERE code_hash=?", [hash_api_token($code)]) == 1);
$t = oauth_exchange_code($code, $client['client_id'], $cb, $verifier);
check('exchange yields prefixed tokens',
      strpos($t['access'], OAUTH_ACCESS_PREFIX) === 0 && strpos($t['refresh'], OAUTH_REFRESH_PREFIX) === 0);
check('access token resolves to alice', ($u = user_for_oauth_token($t['access'])) && (int)$u['id'] === $alice);
check('a personal-token lookup ignores oauth tokens', user_for_api_token($t['access']) === null);
check('an oauth lookup ignores other strings', user_for_oauth_token('codex_notoauth') === null);

/* ---- code flow (attacks) ---- */
throws('code is single-use',
       function () use ($code, $client, $cb, $verifier) { oauth_exchange_code($code, $client['client_id'], $cb, $verifier); },
       'invalid_grant');
[$v2, $c2] = pkce_pair();
$code2 = oauth_create_code($client['client_id'], $alice, $cb, $c2);
throws('wrong verifier is refused',
       function () use ($code2, $client, $cb) { oauth_exchange_code($code2, $client['client_id'], $cb, 'A' . str_repeat('a', 42)); },
       'PKCE');
// the failed PKCE attempt must not have consumed the code — the legitimate
// holder can still redeem it with the right verifier.
$t2b = oauth_exchange_code($code2, $client['client_id'], $cb, $v2);
check('a failed PKCE attempt does not burn the code', strpos($t2b['access'], OAUTH_ACCESS_PREFIX) === 0);
revoke_oauth_grant(val("SELECT id FROM oauth_grants WHERE access_hash=?", [hash_api_token($t2b['access'])]));
[$v3, $c3] = pkce_pair();
$code3 = oauth_create_code($client['client_id'], $alice, $cb, $c3);
throws('redirect_uri mismatch is refused',
       function () use ($code3, $client, $v3) { oauth_exchange_code($code3, $client['client_id'], 'https://claude.ai/other', $v3); },
       'redirect_uri');
throws('another client cannot redeem the code',
       function () use ($code3, $loopback, $cb, $v3) { oauth_exchange_code($code3, $loopback['client_id'], $cb, $v3); },
       'another client');
q("UPDATE oauth_codes SET expires_at=? WHERE code_hash=?", ['2000-01-01 00:00:00', hash_api_token($code3)]);
throws('expired code is refused',
       function () use ($code3, $client, $cb, $v3) { oauth_exchange_code($code3, $client['client_id'], $cb, $v3); },
       'invalid_grant');

/* ---- refresh rotation + replay detection ---- */
$t2 = oauth_refresh_grant($t['refresh'], $client['client_id']);
check('refresh rotates both tokens', $t2['access'] !== $t['access'] && $t2['refresh'] !== $t['refresh']);
check('new access resolves', ($u = user_for_oauth_token($t2['access'])) && (int)$u['id'] === $alice);
check('old access dies on rotation', user_for_oauth_token($t['access']) === null);
throws('replaying the ROTATED-OUT refresh token revokes the grant',
       function () use ($t, $client) { oauth_refresh_grant($t['refresh'], $client['client_id']); },
       'unknown refresh');
check('the replay nuked the whole grant', user_for_oauth_token($t2['access']) === null);
throws('the current refresh is dead too after the replay',
       function () use ($t2, $client) { oauth_refresh_grant($t2['refresh'], $client['client_id']); },
       'revoked');

/* ---- expiry, revocation, account state ---- */
$t3 = oauth_issue_grant($client['client_id'], $alice, 'codex');
q("UPDATE oauth_grants SET access_expires_at=? WHERE access_hash=?", ['2000-01-01 00:00:00', hash_api_token($t3['access'])]);
check('expired access token no longer resolves', user_for_oauth_token($t3['access']) === null);
$t3b = oauth_refresh_grant($t3['refresh'], $client['client_id']);
check('…but its refresh still rotates a new one', user_for_oauth_token($t3b['access']) !== null);

$grants = list_oauth_grants($alice);
check('active grant is listed with the client name', count($grants) === 1 && $grants[0]['client_name'] === 'Claude');
revoke_oauth_grant($grants[0]['id'], $alice);
check('revoked grant drops off the list', list_oauth_grants($alice) === []);
check('revoked grant\'s access is dead', user_for_oauth_token($t3b['access']) === null);
revoke_oauth_grant(999999, $alice);   // someone else's / missing id: silently no-op

$t4 = oauth_issue_grant($client['client_id'], $alice, 'codex');
set_user_status($alice, 'disabled');
check('disabled user\'s access token stops resolving', user_for_oauth_token($t4['access']) === null);
throws('disabled user cannot refresh',
       function () use ($t4, $client) { oauth_refresh_grant($t4['refresh'], $client['client_id']); },
       'not active');
set_user_status($alice, 'active');

/* ---- metadata shapes ---- */
$as = oauth_as_metadata('https://hub.example');
check('AS metadata pins S256 + public clients',
      $as['code_challenge_methods_supported'] === ['S256'] && $as['token_endpoint_auth_methods_supported'] === ['none']);
$rs = oauth_resource_metadata('https://hub.example');
check('resource metadata points at /mcp and the issuer',
      $rs['resource'] === 'https://hub.example/mcp' && $rs['authorization_servers'] === ['https://hub.example']);

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
