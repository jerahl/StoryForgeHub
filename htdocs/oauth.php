<?php
/**
 * oauth.php — OAuth 2.1 endpoints for Claude connectors (Track B3).
 *
 *   GET  oauth.php?client_id=...&redirect_uri=...&response_type=code&
 *        code_challenge=...&code_challenge_method=S256[&state=][&scope=]
 *                                  → sign-in (if needed) + consent screen
 *   POST oauth.php (action=oauth_decision)     → mint code, redirect back
 *   POST oauth.php?action=token                → code/refresh → tokens (JSON)
 *   POST oauth.php?action=register             → RFC 7591 dynamic registration
 *   GET  oauth.php?action=as_metadata          → RFC 8414 (also served at
 *        /.well-known/oauth-authorization-server via a Caddy rewrite)
 *   GET  oauth.php?action=resource_metadata    → RFC 9728 (…/oauth-protected-resource)
 *
 * The interactive pages ride the normal app session (same cookie hardening
 * as index.php); the machine endpoints are stateless JSON.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    $__secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','httponly'=>true,'secure'=>$__secure,'samesite'=>'Lax']);
    session_start();
}
require_once dirname(__DIR__) . '/src/repo.php';
require_once dirname(__DIR__) . '/src/oauth.php';
require_once dirname(__DIR__) . '/src/auth_pages.php';   // auth_screen() — themed card + exit

function oauth_issuer() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
function oauth_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Access-Control-Allow-Origin: *');   // public, cookie-less machine endpoints
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}
function oauth_json_error($error, $desc, $code = 400) {
    oauth_json(['error' => $error, 'error_description' => $desc], $code);
}
/** Redirect back to the client with query params appended (code or error). */
function oauth_redirect($uri, array $params) {
    $sep = strpos($uri, '?') === false ? '?' : '&';
    header('Location: ' . $uri . $sep . http_build_query($params));
    exit;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') {   // CORS preflight for the machine endpoints
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, mcp-protocol-version');
    http_response_code(204); exit;
}

/* ---------------------------------------------------------- metadata */
if ($action === 'as_metadata')       oauth_json(oauth_as_metadata(oauth_issuer()));
if ($action === 'resource_metadata') oauth_json(oauth_resource_metadata(oauth_issuer()));

/* ---------------------------------------------------------- registration */
if ($action === 'register') {
    if ($method !== 'POST') oauth_json_error('invalid_request', 'POST a client metadata document', 405);
    $body = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($body)) oauth_json_error('invalid_client_metadata', 'expected a JSON object');
    try {
        $client = oauth_register_client($body['client_name'] ?? '', (array)($body['redirect_uris'] ?? []));
    } catch (Exception $e) {
        oauth_json_error('invalid_redirect_uri', $e->getMessage());
    }
    oauth_json([
        'client_id' => $client['client_id'],
        'client_name' => $client['name'],
        'redirect_uris' => $client['redirect_uris'],
        'token_endpoint_auth_method' => 'none',       // public client; PKCE is enforced
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
    ], 201);
}

/* ---------------------------------------------------------- token */
if ($action === 'token') {
    if ($method !== 'POST') oauth_json_error('invalid_request', 'POST form-encoded grant parameters', 405);
    $grant = $_POST['grant_type'] ?? '';
    try {
        if ($grant === 'authorization_code') {
            foreach (['code', 'client_id', 'redirect_uri', 'code_verifier'] as $k)
                if (empty($_POST[$k])) throw new RuntimeException("invalid_request: $k is required");
            $t = oauth_exchange_code($_POST['code'], $_POST['client_id'], $_POST['redirect_uri'], $_POST['code_verifier']);
        } elseif ($grant === 'refresh_token') {
            foreach (['refresh_token', 'client_id'] as $k)
                if (empty($_POST[$k])) throw new RuntimeException("invalid_request: $k is required");
            $t = oauth_refresh_grant($_POST['refresh_token'], $_POST['client_id']);
        } else {
            throw new RuntimeException('unsupported_grant_type: use authorization_code or refresh_token');
        }
    } catch (Exception $e) {
        [$err, $desc] = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
        oauth_json_error($err ?: 'invalid_grant', $desc);
    }
    oauth_json([
        'access_token' => $t['access'],
        'token_type' => 'Bearer',
        'expires_in' => $t['expires_in'],
        'refresh_token' => $t['refresh'],
        'scope' => $t['scope'],
    ]);
}

/* ---------------------------------------------------------- authorize */
/* Everything below is the interactive flow: validate the client, make sure a
 * user is signed in, ask for consent, mint the code. */

$client_id    = (string)($_POST['client_id'] ?? $_GET['client_id'] ?? '');
$redirect_uri = (string)($_POST['redirect_uri'] ?? $_GET['redirect_uri'] ?? '');
$state        = (string)($_POST['state'] ?? $_GET['state'] ?? '');
$challenge    = (string)($_POST['code_challenge'] ?? $_GET['code_challenge'] ?? '');
$challenge_m  = (string)($_POST['code_challenge_method'] ?? $_GET['code_challenge_method'] ?? '');
$scope        = (string)($_POST['scope'] ?? $_GET['scope'] ?? 'codex');
$response_ty  = (string)($_POST['response_type'] ?? $_GET['response_type'] ?? '');

$client = $client_id !== '' ? oauth_get_client($client_id) : null;

/* Unknown client or unregistered redirect_uri: render, NEVER redirect (the
 * redirect target can't be trusted). */
if (!$client || !oauth_redirect_allowed($client, $redirect_uri)) {
    http_response_code(400);
    auth_screen('Connection failed', '<p class="desc">This connection request is invalid — the app is not '
        . 'registered here, or its callback address does not match its registration. Go back to the app '
        . 'and try connecting again.</p>');
}
/* From here on, errors go back to the client per RFC 6749. */
$fail = null;
if ($response_ty !== 'code' && $method === 'GET') $fail = ['error' => 'unsupported_response_type'];
elseif ($challenge === '' || ($challenge_m !== '' && $challenge_m !== 'S256') || ($method === 'GET' && $challenge_m === ''))
    $fail = ['error' => 'invalid_request', 'error_description' => 'PKCE with code_challenge_method=S256 is required'];
if ($fail) {
    if ($state !== '') $fail['state'] = $state;
    oauth_redirect($redirect_uri, $fail);
}

/* Sign in first (the normal app login, which returns here via ?next=). */
$me = current_user();
if (!$me) {
    $here = oauth_issuer() . ($_SERVER['REQUEST_URI'] ?? '/oauth.php');
    $next = parse_url($here, PHP_URL_PATH) . '?' . (parse_url($here, PHP_URL_QUERY) ?? '');
    auth_screen('Sign in to connect', '<p class="desc">Sign in to connect <strong>'
        . e($client['name'] ?: 'this app') . '</strong> to your Codex.</p>'
        . '<form method="post" action="index.php">'
        . '<input type="hidden" name="action" value="auth_login">'
        . '<input type="hidden" name="next" value="' . e($next) . '">'
        . '<label class="f">Email</label><input type="email" name="email" autofocus>'
        . '<label class="f">Password</label><input type="password" name="password">'
        . '<div class="toolbar"><button class="btn primary">Sign in</button></div>'
        . '</form>');
}

/* Consent decision (POST from the form below). */
if ($method === 'POST' && ($_POST['action'] ?? '') === 'oauth_decision') {
    $nonce = $_SESSION['oauth_nonce'] ?? '';
    if ($nonce === '' || !hash_equals($nonce, (string)($_POST['nonce'] ?? ''))) {
        oauth_redirect($redirect_uri, array_filter(['error' => 'access_denied',
            'error_description' => 'stale consent form', 'state' => $state]));
    }
    unset($_SESSION['oauth_nonce']);
    if (($_POST['decision'] ?? '') !== 'approve') {
        oauth_redirect($redirect_uri, array_filter(['error' => 'access_denied', 'state' => $state]));
    }
    $code = oauth_create_code($client_id, (int)$me['id'], $redirect_uri, $challenge, $scope);
    oauth_redirect($redirect_uri, array_filter(['code' => $code, 'state' => $state]));
}

/* Consent screen. */
$_SESSION['oauth_nonce'] = gen_token(16);
$fields = '';
foreach (['client_id' => $client_id, 'redirect_uri' => $redirect_uri, 'state' => $state,
          'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'scope' => $scope,
          'nonce' => $_SESSION['oauth_nonce']] as $k => $v) {
    $fields .= '<input type="hidden" name="' . e($k) . '" value="' . e($v) . '">';
}
auth_screen('Connect ' . ($client['name'] ?: 'app'),
      '<p class="desc"><strong>' . e($client['name'] ?: 'An app') . '</strong> wants to connect to your '
    . 'Codex as <strong>' . e($me['display_name'] ?: $me['email']) . '</strong>. It will be able to read '
    . 'and edit exactly the books you can, at your role, and its changes are logged under your name. '
    . 'You can disconnect it any time from Account → Connected apps.</p>'
    . '<form method="post"><input type="hidden" name="action" value="oauth_decision">' . $fields
    . '<div class="toolbar">'
    . '<button class="btn primary" name="decision" value="approve">Connect</button>'
    . '<button class="btn" name="decision" value="deny">Cancel</button>'
    . '</div></form>');
