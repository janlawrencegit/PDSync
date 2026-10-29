<?php
// Optional Google sign-in (OAuth 2.0 code flow with PKCE); the session holds only the user id.
require_once __DIR__ . '/store.php';

const AUTH_COOKIE = 'pds_auth';
const PDS_COOKIE = 'pds_session';
const AUTH_DAYS = 30;

function auth_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)(AUTH_DAYS * 86400));
    ini_set('session.use_strict_mode', '1');
    session_name(AUTH_COOKIE);
    session_set_cookie_params(['lifetime' => AUTH_DAYS * 86400, 'path' => '/', 'secure' => https_on(), 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

// Signed-in user as [id, email, name], or null; no session is started for anonymous visitors.
function auth_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    $user = null;
    if (!isset($_COOKIE[AUTH_COOKIE])) return null;
    auth_start();
    $uid = $_SESSION['uid'] ?? null;
    session_write_close();
    if (!$uid) return null;
    $st = db()->prepare('SELECT id, email, name FROM users WHERE id = ?');
    $st->execute([$uid]);
    $row = $st->fetch();
    if ($row) $user = ['id' => (int)$row['id'], 'email' => $row['email'], 'name' => $row['name']];
    return $user;
}

function auth_uid(): ?int
{
    return auth_user()['id'] ?? null;
}

// This browser's anonymous PDS cookie as [id, key].
function pds_cookie(): array
{
    $parts = explode(':', (string)($_COOKIE[PDS_COOKIE] ?? ''), 2);
    return count($parts) === 2 && pds_valid_id($parts[0]) ? $parts : [null, null];
}

function pds_cookie_set(?string $id, ?string $key): void
{
    setcookie(PDS_COOKIE, $id ? "$id:$key" : '', [
        'expires' => $id ? time() + 10 * 365 * 86400 : 1,
        'path' => '/',
        'secure' => https_on(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

function google_redirect_uri(): string
{
    return app_url() . '/auth.php';
}

function google_login_url(): string
{
    auth_start();
    $_SESSION['oauth'] = ['state' => bin2hex(random_bytes(16)), 'verifier' => bin2hex(random_bytes(32))];
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $_SESSION['oauth']['verifier'], true)), '+/', '-_'), '=');
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => cfg('GOOGLE_CLIENT_ID'),
        'redirect_uri' => google_redirect_uri(),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $_SESSION['oauth']['state'],
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ]);
}

// Exchanges the callback code for Google's ID token claims; null when anything does not check out.
function google_claims(string $code, string $state): ?array
{
    auth_start();
    $o = $_SESSION['oauth'] ?? null;
    unset($_SESSION['oauth']);
    if (!$o || !hash_equals($o['state'], $state)) return null;
    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_POSTFIELDS => http_build_query([
            'code' => $code,
            'client_id' => cfg('GOOGLE_CLIENT_ID'),
            'client_secret' => cfg('GOOGLE_CLIENT_SECRET'),
            'redirect_uri' => google_redirect_uri(),
            'grant_type' => 'authorization_code',
            'code_verifier' => $o['verifier'],
        ]),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    // The token came straight from Google over TLS, so its claims are checked without verifying the signature (OIDC 3.1.3.7).
    $p = explode('.', (string)($res['id_token'] ?? ''))[1] ?? '';
    $c = json_decode((string)base64_decode(strtr($p, '-_', '+/')), true);
    $ok = is_array($c)
        && in_array($c['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true)
        && ($c['aud'] ?? '') === cfg('GOOGLE_CLIENT_ID')
        && ($c['exp'] ?? 0) > time()
        && ($c['email_verified'] ?? false) === true
        && !empty($c['sub']);
    return $ok ? $c : null;
}

// Creates or updates the user, starts a fresh session, and moves this browser's anonymous PDS into the account; returns its id.
function auth_login(array $c): ?string
{
    db()->prepare('INSERT INTO users (google_sub, email, name, created_at, last_login) VALUES (?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE email = VALUES(email), name = VALUES(name), last_login = NOW()')
        ->execute([$c['sub'], $c['email'], substr((string)($c['name'] ?? ''), 0, 255)]);
    $st = db()->prepare('SELECT id FROM users WHERE google_sub = ?');
    $st->execute([$c['sub']]);
    $uid = (int)$st->fetchColumn();
    auth_start();
    session_regenerate_id(true);
    $_SESSION['uid'] = $uid;
    [$id, $key] = pds_cookie();
    $rec = pds_read($id);
    $claim = $rec && !$rec['user_id'] && pds_can($rec, $key, null);
    if ($claim) pds_claim($id, $uid);
    pds_cookie_set(null, null);
    return $claim ? $id : null;
}

function auth_logout(): void
{
    auth_start();
    $_SESSION = [];
    session_destroy();
    setcookie(AUTH_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => https_on(), 'httponly' => true, 'samesite' => 'Lax']);
    pds_cookie_set(null, null);
}
