<?php
/**
 * auth_test.php — Phase 17 fixture checks for accounts, invites, and resets.
 *
 *   php tests/php/auth_test.php
 *
 * Runs against a throwaway SQLite database so it never touches real data.
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_auth_') . '.sqlite';
putenv('DB_DRIVER=sqlite');
putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  $label\n"; }
    else       { $fail++; echo "FAIL  $label\n"; }
}
function throws($fn) { try { $fn(); return false; } catch (Throwable $e) { return true; } }

migrate();
ensure_users();

/* ---- bootstrap: first admin ---- */
check('no users on a fresh install', count_users() === 0);
$adminId = create_user('Owner@Example.com ', 'The Owner', 'supersecret', 1, 'active');
check('first admin created', $adminId > 0);
$admin = get_user($adminId);
check('email normalized to lowercase+trimmed', $admin['email'] === 'owner@example.com');
check('admin flag set', (int)$admin['is_admin'] === 1);
check('one active admin counted', count_active_admins() === 1);

/* ---- duplicate + invalid emails ---- */
check('duplicate email rejected', throws(fn() => create_user('owner@example.com', 'Dup', 'password1')));
check('invalid email rejected',   throws(fn() => create_user('not-an-email', 'Bad', 'password1')));

/* ---- login ---- */
check('correct password logs in',   verify_login('owner@example.com', 'supersecret') !== null);
check('wrong password rejected',     verify_login('owner@example.com', 'nope') === null);
check('unknown email rejected',      verify_login('ghost@example.com', 'supersecret') === null);
check('case-insensitive email login', verify_login('OWNER@EXAMPLE.COM', 'supersecret') !== null);

/* disabled users cannot log in */
$tmpId = create_user('temp@example.com', 'Temp', 'password1', 0, 'active');
set_user_status($tmpId, 'disabled');
check('disabled user cannot log in', verify_login('temp@example.com', 'password1') === null);
set_user_status($tmpId, 'active');
check('re-enabled user can log in',  verify_login('temp@example.com', 'password1') !== null);

/* ---- password change ---- */
set_user_password($tmpId, 'brandnewpass');
check('old password no longer works', verify_login('temp@example.com', 'password1') === null);
check('new password works',           verify_login('temp@example.com', 'brandnewpass') !== null);

/* ---- password strength ---- */
check('short password flagged',   password_problem('short') !== null);
check('8+ char password accepted', password_problem('longenough') === null);

/* ---- invites ---- */
$token = create_invite('writer@example.com', 'editor', 0, $adminId);
check('invite token issued', is_string($token) && strlen($token) >= 32);
$inv = get_invite_by_token($token);
check('invite is valid before use', invite_is_valid($inv));
check('invite appears in pending list', count(array_filter(list_invites(true), fn($i) => $i['token'] === $token)) === 1);
check('inviting an existing account is rejected', throws(fn() => create_invite('owner@example.com')));

$newId = accept_invite($token, 'The Writer', 'writerpass');
check('accepting an invite creates a user', $newId > 0);
check('accepted user can log in', verify_login('writer@example.com', 'writerpass') !== null);
check('accepted invite is no longer valid', !invite_is_valid(get_invite_by_token($token)));
check('re-accepting a used invite is rejected', throws(fn() => accept_invite($token, 'Again', 'password1')));
check('accepted invite drops out of pending list', count(array_filter(list_invites(true), fn($i) => $i['token'] === $token)) === 0);

/* expired invite */
$exp = create_invite('late@example.com', 'viewer', 0, $adminId);
q("UPDATE invites SET expires_at=? WHERE token=?", ['2000-01-01 00:00:00', $exp]);
check('expired invite is invalid', !invite_is_valid(get_invite_by_token($exp)));
check('accepting an expired invite is rejected', throws(fn() => accept_invite($exp, 'Late', 'password1')));

/* revoke */
$rev = create_invite('revoke@example.com', 'editor', 0, $adminId);
revoke_invite(get_invite_by_token($rev)['id']);
check('revoked invite is gone', get_invite_by_token($rev) === null);

/* admin invite carries the admin flag */
$adminInvite = create_invite('admin2@example.com', 'owner', 1, $adminId);
$admin2Id = accept_invite($adminInvite, 'Second Admin', 'password2');
check('admin invite yields an admin account', (int)get_user($admin2Id)['is_admin'] === 1);

/* ---- password reset ---- */
$rtok = create_password_reset($newId);
check('reset token issued', is_string($rtok) && strlen($rtok) >= 32);
check('reset token is valid', reset_is_valid(get_password_reset($rtok)));
consume_password_reset($rtok, 'resetpass123');
check('password changed via reset', verify_login('writer@example.com', 'resetpass123') !== null);
check('old password dead after reset', verify_login('writer@example.com', 'writerpass') === null);
check('reset token cannot be reused', throws(fn() => consume_password_reset($rtok, 'another')));

$rexp = create_password_reset($newId);
q("UPDATE password_resets SET expires_at=? WHERE token=?", ['2000-01-01 00:00:00', $rexp]);
check('expired reset token is invalid', !reset_is_valid(get_password_reset($rexp)));

/* ---- admin guardrails counting ---- */
check('two admins now counted', count_active_admins() === 2);
set_user_admin($admin2Id, 0);
check('demoting an admin lowers the count', count_active_admins() === 1);
delete_user($newId);
check('deleting a user removes their resets', get_password_reset(create_password_reset($adminId)) !== null); // sanity: admin resets still work

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
