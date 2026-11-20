<?php
// Settings from config.local.php (never committed) with environment variable fallbacks.

const DATA_DIR = __DIR__ . '/../data';

// An environment variable, when set, wins over config.local.php, so a container or host can supply settings (for
// example the database address in compose.yaml) without editing the file.
function cfg(string $k): string
{
    static $c = null;
    $file = __DIR__ . '/../config.local.php';
    $c ??= is_file($file) ? require $file : [];
    $env = getenv($k);
    return (string)($env !== false && $env !== '' ? $env : ($c[$k] ?? ''));
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
    if ($pdo) return $pdo;
    $pdo = new PDO(cfg('DB_DSN'), cfg('DB_USER'), cfg('DB_PASS'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    db_upgrade($pdo);
    return $pdo;
}

// Database layout version; raise it when sql/schema.sql or the column list below changes.
const SCHEMA_VERSION = 3;

// Version of the privacy notice people agree to; raise it when the notice changes so everyone is asked again.
const CONSENT_VERSION = '2026-11-19';

// Brings an existing database up to date once, so updating the files is enough: creates missing tables from
// sql/schema.sql and adds columns introduced after the first release. data/schema-version remembers it is done.
function db_upgrade(PDO $pdo): void
{
    $flag = DATA_DIR . '/schema-version';
    if ((int)@file_get_contents($flag) >= SCHEMA_VERSION) return;
    $sql = preg_replace('/^\s*--.*$/m', '', file_get_contents(__DIR__ . '/../sql/schema.sql'));
    foreach (preg_split('/;\s*(\n|$)/', $sql) as $stmt) {
        if (trim($stmt) !== '') $pdo->exec($stmt);
    }
    $cols = $pdo->query('SHOW COLUMNS FROM pds')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('progress', $cols, true)) $pdo->exec('ALTER TABLE pds ADD COLUMN progress TINYINT UNSIGNED NULL');
    if (!in_array('deleted_at', $cols, true)) $pdo->exec('ALTER TABLE pds ADD COLUMN deleted_at DATETIME NULL, ADD KEY deleted (deleted_at)');
    // Version 3: answers are encrypted at rest; records saved before that are sealed once here.
    require_once __DIR__ . '/crypto.php';
    foreach (['pds', 'pds_versions'] as $table) {
        $rows = $pdo->query("SELECT id, data FROM $table WHERE data NOT LIKE 'enc1:%'")->fetchAll();
        $up = $pdo->prepare("UPDATE $table SET data = ? WHERE id = ?");
        foreach ($rows as $r) $up->execute([seal($r['data']), $r['id']]);
    }
    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0700, true);
    file_put_contents($flag, (string)SCHEMA_VERSION, LOCK_EX);
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

// Rejects state-changing requests sent from another site (CSRF). Browsers send Origin on such requests; when it is
// missing, the Fetch Metadata header Sec-Fetch-Site and then Referer decide. A request with none of them does not come
// from a browser page, so it carries no visitor's cookies to abuse.
function require_same_origin(): void
{
    if (!same_origin_ok($_SERVER)) {
        http_response_code(403);
        exit('Cross-site request blocked.');
    }
}

// The decision behind require_same_origin(), from the request's server variables.
function same_origin_ok(array $server): bool
{
    $host = $server['HTTP_HOST'] ?? '';
    $hostOf = fn(string $url) => parse_url($url, PHP_URL_HOST) . (($p = parse_url($url, PHP_URL_PORT)) ? ":$p" : '');
    $origin = $server['HTTP_ORIGIN'] ?? '';
    $site = $server['HTTP_SEC_FETCH_SITE'] ?? '';
    $referer = $server['HTTP_REFERER'] ?? '';
    if ($origin !== '' && $origin !== 'null') return $hostOf($origin) === $host;
    if ($site !== '') return in_array($site, ['same-origin', 'none'], true);
    return $referer === '' || $hostOf($referer) === $host;
}

// Random value for this response that inline scripts must carry to run (Content-Security-Policy nonce).
function csp_nonce(): string
{
    static $n = null;
    return $n ??= base64_encode(random_bytes(16));
}

// Content-Security-Policy: scripts only from this site or with this response's nonce, no plugins, no framing by
// other sites, forms and connections to this site only (plus the optional analytics service).
function csp_header(): string
{
    $script = ["'self'", "'nonce-" . csp_nonce() . "'"];
    $connect = ["'self'"];
    if (cfg('UMAMI_SCRIPT') !== '' && ($h = parse_url(cfg('UMAMI_SCRIPT'), PHP_URL_HOST))) {
        $script[] = "https://$h";
        $connect[] = "https://$h";
    }
    if (cfg('GOATCOUNTER_URL') !== '' && ($h = parse_url(cfg('GOATCOUNTER_URL'), PHP_URL_HOST))) {
        $script[] = 'https://gc.zgo.at';
        $connect[] = "https://$h";
    }
    return implode('; ', [
        "default-src 'self'",
        'script-src ' . implode(' ', $script),
        "style-src 'self' 'unsafe-inline'",
        "img-src 'self' data: blob:",
        "font-src 'self'",
        'connect-src ' . implode(' ', $connect),
        "worker-src 'self' blob:",
        "frame-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ]) . (https_on() ? '; upgrade-insecure-requests' : '');
}

function security_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: same-origin');
    header('Content-Security-Policy: ' . csp_header());
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
    if (https_on()) header('Strict-Transport-Security: max-age=31536000');
}

// Google accounts that may open the admin page (ADMIN_EMAILS, comma-separated), lowercased.
function admin_emails(): array
{
    return array_values(array_filter(array_map(fn($e) => strtolower(trim($e)), explode(',', cfg('ADMIN_EMAILS')))));
}

// True for a signed-in admin: a password admin session, or a Google account in ADMIN_EMAILS. Admins are left out of
// usage counts so their own testing does not inflate them.
function is_admin_viewer(): bool
{
    if (isset($_COOKIE['pds_admin'])) return true;
    if (!admin_emails() || !isset($_COOKIE['pds_auth']) || !function_exists('auth_user')) return false;
    return in_array(strtolower(auth_user()['email'] ?? ''), admin_emails(), true);
}

// Optional standard analytics next to the built-in counts: Umami (UMAMI_SCRIPT + UMAMI_WEBSITE_ID) and/or
// GoatCounter (GOATCOUNTER_URL). Returns the script tags for the page head, or '' when none is set up.
// Signed-in admins are left out, as in the built-in counts.
function analytics_tags(): string
{
    if (is_admin_viewer()) return '';
    $e = fn($v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
    $out = '';
    if (cfg('UMAMI_SCRIPT') !== '' && cfg('UMAMI_WEBSITE_ID') !== '') {
        $out .= '<script defer src="' . $e(cfg('UMAMI_SCRIPT')) . '" data-website-id="' . $e(cfg('UMAMI_WEBSITE_ID')) . '"></script>';
    }
    if (cfg('GOATCOUNTER_URL') !== '') {
        $out .= '<script async data-goatcounter="' . $e(cfg('GOATCOUNTER_URL')) . '" src="https://gc.zgo.at/count.js"></script>';
    }
    return $out;
}
