<?php
/**
 * auth_pages.php — Phase 17 front gate + the no-chrome auth screens.
 *
 * run_auth_gate() is called once, early in index.php. It processes the auth
 * POSTs (login / logout / first-run setup / accept-invite / reset), renders the
 * matching full-page screen when the request is not yet authenticated, and
 * `exit`s. When the caller is a logged-in active user it simply returns that
 * user row and the normal app renders.
 */
require_once __DIR__ . '/layout.php';   // e(), url(), layout_head/foot

/** Current theme for the pre-login screens (cookie override, cfg fallback). */
function auth_theme() {
    $CFG = cfg();
    return [
        $_COOKIE['accent']   ?? $CFG['accent'],
        $_COOKIE['bodyType'] ?? $CFG['bodyType'],
        $_COOKIE['density']  ?? $CFG['density'],
        $_COOKIE['mode']     ?? ($CFG['mode'] ?? 'Beacon'),
    ];
}

/** Render a centered auth card and exit. $inner is pre-built HTML. */
function auth_screen($title, $inner) {
    [$accent, $bodyType, $density, $mode] = auth_theme();
    layout_head($title, $accent, $bodyType, $density, $mode);
    echo '<div class="content" style="max-width:380px;margin:8vh auto">';
    echo '<h1 style="margin-bottom:4px">Stephen\'s Codex</h1>';
    echo $inner;
    echo '</div>';
    layout_foot();
    exit;
}

function auth_flash_err($msg) { return $msg ? '<div class="flash err">' . e($msg) . '</div>' : ''; }

/**
 * The gate. Returns the authenticated user row, or renders a screen and exits.
 */
function run_auth_gate() {
    ensure_users();
    $CFG    = cfg();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $method === 'POST' ? ($_POST['action'] ?? '') : '';
    $p      = $_GET['p'] ?? '';

    /* logout is always available */
    if ($action === 'auth_logout') { logout_user(); header('Location: ?'); exit; }

    /* ---- first run: no accounts yet → bootstrap the first admin ---- */
    if (count_users() === 0) {
        $err = null;
        if ($action === 'auth_setup') {
            try {
                // If a shared password is still configured, the incumbent owner must
                // prove they know it before claiming admin #1 — protects live installs.
                if (!empty($CFG['app_password']) && !hash_equals($CFG['app_password'], (string)($_POST['app_password'] ?? '')))
                    throw new RuntimeException('The current app password is incorrect.');
                if ($e = password_problem($_POST['password'] ?? '')) throw new RuntimeException($e);
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) throw new RuntimeException('The two passwords do not match.');
                migrate();   // a brand-new box may have no schema yet; the first admin also lays the base tables
                $uid = create_user($_POST['email'] ?? '', $_POST['display_name'] ?? '', $_POST['password'] ?? '', 1, 'active');
                login_user(get_user($uid));
                header('Location: ?'); exit;
            } catch (Exception $ex) { $err = $ex->getMessage(); }
        }
        $needPw = !empty($CFG['app_password']);
        $inner  = '<p class="desc">Create the first administrator account. This one-time setup replaces the shared password with real, invite-only accounts.</p>'
            . auth_flash_err($err)
            . '<form method="post">'
            . '<input type="hidden" name="action" value="auth_setup">'
            . '<label class="f">Your name</label><input type="text" name="display_name" value="' . e($_POST['display_name'] ?? '') . '" autofocus>'
            . '<label class="f">Email</label><input type="email" name="email" value="' . e($_POST['email'] ?? '') . '" required>'
            . '<label class="f">Password</label><input type="password" name="password" required>'
            . '<label class="f">Confirm password</label><input type="password" name="password2" required>'
            . ($needPw ? '<label class="f">Current app password</label><input type="password" name="app_password" required>' : '')
            . '<div class="toolbar"><button class="btn primary">Create admin account</button></div>'
            . '</form>';
        auth_screen('Set up', $inner);
    }

    /* ---- public: accept an invite (sets password, becomes a real user) ---- */
    if ($p === 'accept_invite') {
        $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
        $inv   = get_invite_by_token($token);
        $err = null;
        if ($action === 'auth_accept') {
            try {
                if ($e = password_problem($_POST['password'] ?? '')) throw new RuntimeException($e);
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) throw new RuntimeException('The two passwords do not match.');
                $uid = accept_invite($token, $_POST['display_name'] ?? '', $_POST['password'] ?? '');
                login_user(get_user($uid));
                header('Location: ?'); exit;
            } catch (Exception $ex) { $err = $ex->getMessage(); }
        }
        if (!invite_is_valid($inv)) {
            auth_screen('Invite', '<p class="desc">This invite link is invalid, expired, or already used. Ask your administrator for a fresh one.</p><div class="toolbar"><a class="btn" href="?">Back to sign in</a></div>');
        }
        $inner = '<p class="desc">You\'ve been invited to join as <strong>' . e($inv['email']) . '</strong>. Set your name and a password to finish.</p>'
            . auth_flash_err($err)
            . '<form method="post">'
            . '<input type="hidden" name="action" value="auth_accept">'
            . '<input type="hidden" name="token" value="' . e($token) . '">'
            . '<label class="f">Your name</label><input type="text" name="display_name" value="' . e($_POST['display_name'] ?? '') . '" autofocus>'
            . '<label class="f">Password</label><input type="password" name="password" required>'
            . '<label class="f">Confirm password</label><input type="password" name="password2" required>'
            . '<div class="toolbar"><button class="btn primary">Create my account</button></div>'
            . '</form>';
        auth_screen('Accept invite', $inner);
    }

    /* ---- public: complete an admin-issued password reset ---- */
    if ($p === 'reset_password') {
        $token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));
        $r     = get_password_reset($token);
        $err = null;
        if ($action === 'auth_reset') {
            try {
                if ($e = password_problem($_POST['password'] ?? '')) throw new RuntimeException($e);
                if (($_POST['password'] ?? '') !== ($_POST['password2'] ?? '')) throw new RuntimeException('The two passwords do not match.');
                consume_password_reset($token, $_POST['password'] ?? '');
                auth_screen('Password reset', '<p class="desc">Your password has been updated. You can sign in now.</p><div class="toolbar"><a class="btn primary" href="?">Sign in</a></div>');
            } catch (Exception $ex) { $err = $ex->getMessage(); }
        }
        if (!reset_is_valid($r)) {
            auth_screen('Password reset', '<p class="desc">This reset link is invalid, expired, or already used. Ask your administrator for a fresh one.</p><div class="toolbar"><a class="btn" href="?">Back to sign in</a></div>');
        }
        $u = get_user((int)$r['user_id']);
        $inner = '<p class="desc">Set a new password for <strong>' . e($u['email']) . '</strong>.</p>'
            . auth_flash_err($err)
            . '<form method="post">'
            . '<input type="hidden" name="action" value="auth_reset">'
            . '<input type="hidden" name="token" value="' . e($token) . '">'
            . '<label class="f">New password</label><input type="password" name="password" required autofocus>'
            . '<label class="f">Confirm password</label><input type="password" name="password2" required>'
            . '<div class="toolbar"><button class="btn primary">Set new password</button></div>'
            . '</form>';
        auth_screen('Password reset', $inner);
    }

    /* ---- login ---- */
    $err = null;
    if ($action === 'auth_login') {
        $u = verify_login($_POST['email'] ?? '', $_POST['password'] ?? '');
        if ($u) { login_user($u); header('Location: ' . (($_POST['next'] ?? '') ?: '?')); exit; }
        $err = 'Wrong email or password.';
    }

    $u = current_user();
    if ($u) {
        backfill_book_members();   // Phase 18: hand any member-less book to admin #1 (runs before this request's creates)
        return $u;                 // authenticated — hand control back to the app
    }

    $inner = auth_flash_err($err)
        . '<form method="post">'
        . '<input type="hidden" name="action" value="auth_login">'
        . '<label class="f">Email</label><input type="email" name="email" value="' . e($_POST['email'] ?? '') . '" autofocus>'
        . '<label class="f">Password</label><input type="password" name="password">'
        . '<div class="toolbar"><button class="btn primary">Sign in</button></div>'
        . '</form>';
    auth_screen('Sign in', $inner);
}
