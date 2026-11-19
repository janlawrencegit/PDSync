<?php
// Usage counts for the admin page, kept as proof of use. Nothing personal is stored: only counters per day, and for
// unique counts (visitors, PDS worked on) a keyed hash whose random salt is deleted once its day or month is over,
// so old hashes can no longer be matched to anyone. No cookies are set.
require_once __DIR__ . '/config.php';

// Metric => admin label. Unique metrics count each visitor or PDS once per day and once per month.
const STATS_METRICS = [
    'visitors' => 'Visitors',
    'visits' => 'App opens',
    'pds_created' => 'New PDS',
    'active_pds' => 'PDS worked on',
    'export_pdf' => 'Print / PDF',
    'export_xlsx' => 'Excel exports',
    'import_xlsx' => 'Excel imports',
    'signup' => 'New accounts',
    'signin' => 'Sign-ins',
];
const STATS_UNIQUE = ['visitors', 'active_pds'];

// Today and this month in the app's time zone (TIMEZONE in config, Philippine time by default).
function stats_periods(): array
{
    $now = new DateTimeImmutable('now', new DateTimeZone(cfg('TIMEZONE') ?: 'Asia/Manila'));
    return [$now->format('Y-m-d'), $now->format('Y-m')];
}

// Crawlers, link previews, scripts and signed-in admins are not counted.
function stats_skip(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    return $ua === '' || is_admin_viewer()
        || preg_match('#bot|crawl|spider|slurp|preview|headless|lighthouse|curl|wget|python|java/|go-http|httpclient|okhttp|facebookexternalhit#i', $ua) === 1;
}

// Statistics never break the app: errors are logged and ignored.
function stats_try(callable $fn): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        error_log((string)$e);
    }
}

function stats_bump(string $period, string $metric): void
{
    db()->prepare('INSERT INTO stats (period, metric, n) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE n = n + 1')->execute([$period, $metric]);
}

// Adds one to today's count of an event.
function stats_add(string $metric): void
{
    if (stats_skip()) return;
    stats_try(fn() => stats_bump(stats_periods()[0], $metric));
}

// Counts $subject at most once today and once this month under $metric.
function stats_unique(string $metric, string $subject): void
{
    if (stats_skip()) return;
    stats_try(function () use ($metric, $subject) {
        if (random_int(1, 50) === 1) stats_forget();
        foreach (stats_periods() as $p) {
            db()->prepare('INSERT IGNORE INTO stats_salt (period, salt) VALUES (?, ?)')->execute([$p, bin2hex(random_bytes(32))]);
            $st = db()->prepare('SELECT salt FROM stats_salt WHERE period = ?');
            $st->execute([$p]);
            $seen = db()->prepare('INSERT IGNORE INTO stats_seen (period, metric, h) VALUES (?, ?, ?)');
            $seen->execute([$p, $metric, hash_hmac('sha256', $subject, (string)$st->fetchColumn(), true)]);
            if ($seen->rowCount() === 1) stats_bump($p, $metric);
        }
    });
}

// Deletes the salts and hashes of past days and months; their counts stay.
function stats_forget(): void
{
    [$d, $m] = stats_periods();
    db()->prepare('DELETE FROM stats_salt WHERE period NOT IN (?, ?)')->execute([$d, $m]);
    db()->prepare('DELETE FROM stats_seen WHERE period NOT IN (?, ?)')->execute([$d, $m]);
}

// All counts grouped for the admin page: per day, per month (event counts summed from days), all-time event totals,
// and the first day with data.
function stats_report(): array
{
    $days = $months = $total = [];
    foreach (db()->query('SELECT period, metric, n FROM stats ORDER BY period DESC') as $r) {
        $n = (int)$r['n'];
        if (strlen($r['period']) === 7) {
            if (in_array($r['metric'], STATS_UNIQUE, true)) $months[$r['period']][$r['metric']] = $n;
            continue;
        }
        $days[$r['period']][$r['metric']] = $n;
        if (in_array($r['metric'], STATS_UNIQUE, true)) continue;
        $m = substr($r['period'], 0, 7);
        $months[$m][$r['metric']] = ($months[$m][$r['metric']] ?? 0) + $n;
        $total[$r['metric']] = ($total[$r['metric']] ?? 0) + $n;
    }
    krsort($months);
    return ['days' => $days, 'months' => $months, 'total' => $total, 'since' => $days ? array_key_last($days) : null];
}
