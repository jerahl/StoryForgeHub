<?php
/**
 * tokens_test.php — Phase 20 fixture checks for per-user API tokens, the
 * "act as user" context, and per-user state (dictionary scoping, note author).
 *
 *   php tests/php/tokens_test.php
 */
$tmp = tempnam(sys_get_temp_dir(), 'codex_tok_') . '.sqlite';
putenv('DB_DRIVER=sqlite'); putenv('DB_PATH=' . $tmp);
$_ENV['DB_DRIVER'] = 'sqlite'; $_ENV['DB_PATH'] = $tmp;
register_shutdown_function(function () use ($tmp) { @unlink($tmp); });

require_once __DIR__ . '/../../src/repo.php';

$pass = 0; $fail = 0;
function check($label, $cond) { global $pass, $fail; if ($cond) { $pass++; echo "  ok  $label\n"; } else { $fail++; echo "FAIL  $label\n"; } }
function as_user($uid) { $_SESSION['uid'] = $uid === null ? null : (int)$uid; $GLOBALS['__current_user'] = null; $GLOBALS['__acting_user'] = null; if ($uid === null) unset($_SESSION['uid']); }

migrate();
$alice = create_user('alice@x.com', 'Alice', 'password1', 0);
$bob   = create_user('bob@x.com',   'Bob',   'password1', 0);

/* ---- token lifecycle ---- */
$raw = create_api_token($alice, 'Claude desktop');
check('minted token has the codex_ prefix', strpos($raw, 'codex_') === 0 && strlen($raw) > 40);
$u = user_for_api_token($raw);
check('token resolves to its user', $u && (int)$u['id'] === $alice);
check('garbage token resolves to nobody', user_for_api_token('codex_deadbeef') === null);
check('empty token resolves to nobody', user_for_api_token('') === null);
$toks = list_api_tokens($alice);
check('token is listed for its owner', count($toks) === 1 && $toks[0]['label'] === 'Claude desktop');
check('last_used is stamped after a resolve', !empty(get_user_token_lastused($toks[0]['id'])));

/* revoke */
revoke_api_token($toks[0]['id'], $alice);
check('revoked token no longer resolves', user_for_api_token($raw) === null);
check('revoked token drops off the active list', count(list_api_tokens($alice)) === 0);

/* revoke is owner-scoped */
$raw2 = create_api_token($alice, 't2');
$id2 = list_api_tokens($alice)[0]['id'];
revoke_api_token($id2, $bob);   // Bob may not revoke Alice's token
check('a non-owner cannot revoke your token', user_for_api_token($raw2) !== null);
revoke_api_token($id2, $alice);
check('the owner can revoke it', user_for_api_token($raw2) === null);

/* a disabled user's token stops working */
$raw3 = create_api_token($bob, 't3');
set_user_status($bob, 'disabled');
check('a disabled user\'s token is rejected', user_for_api_token($raw3) === null);
set_user_status($bob, 'active');
check('re-enabling restores the token', user_for_api_token($raw3) !== null);

/* ---- act_as_user drives current_user without a session ---- */
as_user(null);
act_as_user(get_user($alice));
check('act_as_user sets the current user', current_user_id() === $alice);
check('acting user beats an empty session', current_user()['email'] === 'alice@x.com');
act_as_user(null);
check('clearing the acting user falls back to none', current_user_id() === null);

/* ---- per-user dictionary scoping ---- */
save_book(['id'=>'A','folder'=>'a','title'=>'Book A','sort_order'=>1]);
as_user($alice); add_dictionary_term('A', 'Zorblax');               // Alice's personal word
add_dictionary_term('A', 'Myceliad', 'codex');                      // shared proper noun (codex import)
$aliceWords = get_dictionary_words('A');
check('Alice sees her own word', in_array('Zorblax', $aliceWords, true));
check('Alice sees the shared codex word', in_array('Myceliad', $aliceWords, true));
as_user($bob);
$bobWords = get_dictionary_words('A');
check('Bob does NOT see Alice\'s personal word', !in_array('Zorblax', $bobWords, true));
check('Bob DOES see the shared codex word', in_array('Myceliad', $bobWords, true));
as_user(null);
check('unscoped (service/CLI) sees every term', in_array('Zorblax', get_dictionary_words('A'), true));

/* ---- chapter notes carry their author ---- */
as_user($alice);
$nid = add_chapter_note('A', 'Manuscript/ch1.md', 'a line', 'fix this');
$n = get_chapter_note($nid);
check('a chapter note records its author', (int)$n['user_id'] === $alice);

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);

/** tiny helper: last_used_at for a token id (tokens have no public getter). */
function get_user_token_lastused($id) { return val("SELECT last_used_at FROM api_tokens WHERE id=?", [(int)$id]); }
