<?php
// Admin accounts in data/admins.json (bcrypt hashes, created with tools/admin-user.php) and a separate admin session.
require_once __DIR__ . '/store.php';

const ADMIN_FILE = DATA_DIR . '/admins.json';
const ADMIN_ATTEMPTS = DATA_DIR . '/admin-attempts.json';
const ADMIN_MAX_FAILS = 5;
const ADMIN_LOCK_SECS = 900;
const ADMIN_IDLE_SECS = 1800;
const ADMIN_MAX_SECS = 28800;

function admin_accounts(): array
{
    return is_file(ADMIN_FILE) ? (json_decode(file_get_contents(ADMIN_FILE), true) ?: []) : [];
}

function admin_save_accounts(array $a): void
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
    file_put_contents(ADMIN_FILE, json_encode($a, JSON_PRETTY_PRINT), LOCK_EX);
    chmod(ADMIN_FILE, 0600);
}

// Own cookie and session folder, so the short admin lifetime never expires users' sign-in sessions.
function admin_start(): void
{
    $dir = DATA_DIR . '/admin-sessions';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string)ADMIN_IDLE_SECS);
    ini_set('session.use_strict_mode', '1');
    session_name('pds_admin');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => https_on(), 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
    $s = &$_SESSION;
    $expired = isset($s['admin']) && (time() - $s['seen'] > ADMIN_IDLE_SECS || time() - $s['since'] > ADMIN_MAX_SECS
        || !hash_equals($s['agent'], hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')));
    if ($expired) $s = [];
    if (isset($s['admin'])) $s['seen'] = time();
    $s['csrf'] ??= bin2hex(random_bytes(32));
}

function admin_user(): ?string
{
    return $_SESSION['admin'] ?? null;
}

function admin_csrf_ok($token): bool
{
    return is_string($token) && hash_equals($_SESSION['csrf'] ?? '', $token);
}

// Counts recent failed logins for this IP; $failed true records one, false clears them after a success.
function admin_attempts(?bool $failed = null): int
{
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? '');
    $fp = fopen(ADMIN_ATTEMPTS, 'c+');
    flock($fp, LOCK_EX);
    $all = json_decode(stream_get_contents($fp), true) ?: [];
    foreach ($all as $k => $times) {
        $all[$k] = array_values(array_filter($times, fn($t) => $t > time() - ADMIN_LOCK_SECS));
        if (!$all[$k]) unset($all[$k]);
    }
    if ($failed === true) $all[$ip][] = time();
    if ($failed === false) unset($all[$ip]);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($all));
    flock($fp, LOCK_UN);
    fclose($fp);
    return count($all[$ip] ?? []);
}

// Checks credentials with lockout; returns an error message, or null after a successful sign-in.
function admin_login(string $user, string $pass): ?string
{
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
    if (admin_attempts() >= ADMIN_MAX_FAILS) return 'Too many failed attempts. Try again in 15 minutes.';
    $all = admin_accounts();
    $acc = $all[$user] ?? null;
    // Unknown users are checked against a dummy hash of the same cost so response time does not reveal valid usernames.
    $dummy = substr(current($all)['hash'] ?? '$2y$10$', 0, 7) . str_repeat('x', 53);
    $ok = password_verify($pass, $acc['hash'] ?? $dummy);
    if (!$acc || !$ok) {
        admin_attempts(true);
        return 'Wrong username or password.';
    }
    admin_attempts(false);
    if (password_needs_rehash($acc['hash'], PASSWORD_DEFAULT)) {
        $all = admin_accounts();
        $all[$user]['hash'] = password_hash($pass, PASSWORD_DEFAULT);
        admin_save_accounts($all);
    }
    session_regenerate_id(true);
    $_SESSION = ['admin' => $user, 'since' => time(), 'seen' => time(), 'csrf' => bin2hex(random_bytes(32)),
        'agent' => hash('sha256', $_SERVER['HTTP_USER_AGENT'] ?? '')];
    return null;
}

function admin_logout(): void
{
    $_SESSION = [];
    session_destroy();
    setcookie('pds_admin', '', ['expires' => 1, 'path' => '/', 'secure' => https_on(), 'httponly' => true, 'samesite' => 'Strict']);
}
