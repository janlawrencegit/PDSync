<?php
// Settings from config.local.php (never committed) with environment variable fallbacks.

const DATA_DIR = __DIR__ . '/../data';

function cfg(string $k): string
{
    static $c = null;
    $file = __DIR__ . '/../config.local.php';
    $c ??= is_file($file) ? require $file : [];
    return (string)($c[$k] ?? getenv($k) ?: '');
}

// Errors are logged, never shown, so paths and queries do not leak to visitors.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// For JSON endpoints: answers an uncaught error with a short JSON message and logs the details.
function json_errors(): void
{
    set_exception_handler(function (Throwable $e) {
        error_log((string)$e);
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['error' => cfg('DB_DSN') === '' ? 'Database is not set up yet (see README: config.local.php)' : 'Server error, please try again']);
    });
}

function db(): PDO
{
    static $pdo = null;
    return $pdo ??= new PDO(cfg('DB_DSN'), cfg('DB_USER'), cfg('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function https_on(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with(cfg('APP_URL'), 'https:');
}

// Public base URL without a trailing slash, from APP_URL or the current request.
function app_url(): string
{
    if (cfg('APP_URL') !== '') return rtrim(cfg('APP_URL'), '/');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (https_on() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

// Rejects state-changing requests sent from another site.
function require_same_origin(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) . (($p = parse_url($origin, PHP_URL_PORT)) ? ":$p" : '') !== ($_SERVER['HTTP_HOST'] ?? '')) {
        http_response_code(403);
        exit('Cross-site request blocked.');
    }
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    if (https_on()) header('Strict-Transport-Security: max-age=31536000');
}
