<?php
// Admin dashboard. Admins sign in with a Google account listed in ADMIN_EMAILS, or with a password account from
// data/admins.json (tools/admin-user.php).
require __DIR__ . '/../lib/admin.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/admin-nav.php';

security_headers();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
$googleAdmin = admin_google();
$googleUser = auth_user();
admin_start();

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_same_origin();
    if (!admin_csrf_ok($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif (isset($_POST['logout'])) {
        admin_logout();
        header('Location: admin.php');
        exit;
    } else {
        $error = admin_login(trim((string)($_POST['user'] ?? '')), (string)($_POST['pass'] ?? ''));
        if (!$error) {
            header('Location: admin.php');
            exit;
        }
    }
}

$admin = admin_user() ?? $googleAdmin;
$viaGoogle = !admin_user() && $googleAdmin;
$stats = null;
if ($admin) {
    try {
        $count = fn($sql) => (int)db()->query($sql)->fetchColumn();
        $stats = [
            'Accounts' => $count('SELECT COUNT(*) FROM users'),
            'PDS in accounts' => $count('SELECT COUNT(*) FROM pds WHERE user_id IS NOT NULL'),
            'Anonymous PDS' => $count('SELECT COUNT(*) FROM pds WHERE user_id IS NULL'),
            'Updated in last 7 days' => $count('SELECT COUNT(*) FROM pds WHERE updated_at > NOW() - INTERVAL 7 DAY'),
        ];
        $forms = db()->query('SELECT form, COUNT(*) AS n FROM pds GROUP BY form ORDER BY form')->fetchAll();
        stats_forget();
        $usage = stats_report();
    } catch (Throwable $e) {
        error_log((string)$e);
    }
}

// Usage counts as CSV (one row per day, then one per month), for reports and proof of use.
if ($admin && isset($_GET['csv']) && isset($usage)) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pdsync-usage-' . stats_periods()[0] . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, array_merge(['period', 'type'], array_values(STATS_METRICS)));
    foreach (['day' => $usage['days'], 'month' => $usage['months']] as $type => $rows) {
        foreach ($rows as $period => $r) {
            fputcsv($out, array_merge([$period, $type], array_map(fn($k) => $r[$k] ?? 0, array_keys(STATS_METRICS))));
        }
    }
    exit;
}

$n = fn($v) => number_format((int)$v);
?>
<?php
$icon = fn($d, $cls = 'size-4') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
$I = [
    'pen' => '<path d="M17 3a2.8 2.8 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5z"/>',
    'library' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
    'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
    'external' => '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
    'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M12 18v-6M9 15h6"/>',
    'export' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
    'up' => '<path d="m18 15-6-6-6 6"/>',
    'down' => '<path d="m6 9 6 6 6-6"/>',
    'flat' => '<path d="M5 12h14"/>',
];
$google = '<svg class="size-4" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>';

// Change against the previous period, as text with an arrow (never color alone).
$delta = function (int $now, int $before, string $what) use ($icon, $I): string {
    if (!$now && !$before) return '<span class="kpi-delta">' . $icon($I['flat'], 'size-3.5') . "No change $what</span>";
    if (!$before) return '<span class="kpi-delta up">' . $icon($I['up'], 'size-3.5') . '+' . number_format($now) . " $what</span>";
    $p = (int)round(100 * ($now - $before) / $before);
    $dir = $p > 0 ? 'up' : ($p < 0 ? 'down' : 'flat');
    return '<span class="kpi-delta ' . $dir . '">' . $icon($I[$dir], 'size-3.5') . ($p > 0 ? '+' : '') . "$p% $what</span>";
};

// Bar chart of one series (visitors per day), drawn as SVG. Bars start at the baseline with 4px rounded tops; each
// day has a full-height hover target with its numbers for the tooltip and for screen readers.
function visitors_chart(array $days, array $usage): string
{
    $vals = array_map(fn($d) => (int)($usage['days'][$d]['visitors'] ?? 0), $days);
    $max = max(1, ...$vals);
    $mag = 10 ** floor(log10($max));
    foreach ([1, 2, 5, 10] as $m) if ($m * $mag >= $max) { $top = $m * $mag; break; }
    $top = max($top, 4);
    [$W, $H, $L, $B, $T] = [1040, 250, 34, 26, 12];
    $pw = $W - $L - 4;
    $ph = $H - $B - $T;
    $slot = $pw / count($days);
    $bw = max(2, $slot - 4);
    $svg = "<svg viewBox=\"0 0 $W $H\" class=\"chart-svg\" role=\"img\" aria-label=\"Visitors per day for the last 30 days\">";
    foreach ([0, 0.5, 1] as $f) {
        $y = round($T + $ph * (1 - $f), 1);
        $svg .= "<line x1=\"$L\" x2=\"" . ($W - 4) . "\" y1=\"$y\" y2=\"$y\" class=\"chart-grid\"/>";
        $svg .= '<text x="' . ($L - 8) . "\" y=\"" . ($y + 4) . '" class="chart-axis" text-anchor="end">' . number_format($top * $f) . '</text>';
    }
    foreach ($days as $i => $d) {
        $v = $vals[$i];
        $x = round($L + $i * $slot + ($slot - $bw) / 2, 1);
        $h = $v ? max(3, $ph * $v / $top) : 0;
        $y = $T + $ph - $h;
        if ($h) {
            $r = min(4, $h, $bw / 2);
            $svg .= sprintf('<path class="chart-bar" d="M%.1f %.1fv%.1fq0 %.1f %.1f %.1fh%.1fq%.1f 0 %.1f %.1fv%.1fz"/>',
                $x, $T + $ph, -($h - $r), -$r, $r, -$r, $bw - 2 * $r, $r, $r, $r, $h - $r);
        }
        $r = $usage['days'][$d] ?? [];
        $label = date('D, M j', strtotime($d)) . ': ' . number_format($v) . ' visitor' . ($v === 1 ? '' : 's') . ', ' . number_format($r['visits'] ?? 0) . ' app opens, ' . number_format(($r['export_pdf'] ?? 0) + ($r['export_xlsx'] ?? 0)) . ' exports';
        $svg .= sprintf('<rect class="chart-hit" x="%.1f" y="%d" width="%.1f" height="%d" tabindex="0" data-tip="%s" aria-label="%s"/>',
            $L + $i * $slot, $T, $slot, $ph, h($label), h($label));
        if ($i % 5 === 0 || $i === count($days) - 1) {
            $svg .= sprintf('<text x="%.1f" y="%d" class="chart-axis" text-anchor="middle">%s</text>', $x + $bw / 2, $H - 6, date('M j', strtotime($d)));
        }
    }
    return $svg . '</svg>';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Admin - PDSync</title>
<script>try { const t = localStorage.getItem('pds-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}</script>
<link rel="preload" href="fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/instrument-serif-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
<link rel="icon" href="icon.svg" type="image/svg+xml">
</head>
<body class="flex min-h-screen flex-col bg-base-200 text-base-content antialiased">
<?php admin_nav('dashboard', $admin, (bool)$viaGoogle); ?>

<?php if (!$admin): ?>
<main class="auth-stage grid flex-1 place-items-center p-4">
  <form method="post" class="grid w-[min(400px,100%)] gap-4 rounded-box border border-base-300 bg-base-100 p-7 shadow-sm">
    <div class="text-center">
      <span class="mx-auto grid size-12 place-items-center rounded-xl bg-primary/10 text-primary"><?= $icon('<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>', 'size-6') ?></span>
      <h1 class="mt-3 text-xl font-semibold">Admin sign-in</h1>
      <p class="text-sm text-base-content/60">Usage numbers and records for PDSync administrators.</p>
    </div>
    <?php if ($error): ?><div role="alert" class="alert alert-error alert-soft text-sm"><?= h($error) ?></div><?php endif; ?>
    <?php if ($googleUser): ?>
    <div role="alert" class="alert alert-warning alert-soft text-sm">You are signed in as <?= h($googleUser['email']) ?>, which is not an admin account.</div>
    <?php elseif (cfg('GOOGLE_CLIENT_ID') !== '' && admin_emails()): ?>
    <a href="auth.php?login&amp;next=admin" class="btn btn-outline gap-2"><?= $google ?>Sign in with Google</a>
    <div class="divider my-0 text-xs text-base-content/50">or use an admin password</div>
    <?php endif; ?>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/70">Username</span><input name="user" class="input w-full" autocomplete="username" required></label>
    <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/70">Password</span><input name="pass" type="password" class="input w-full" autocomplete="current-password" required></label>
    <button class="btn btn-primary">Sign in</button>
    <a href="./" class="text-center text-xs text-base-content/60 hover:underline">&larr; Back to PDSync</a>
  </form>
</main>
<?php site_footer(); ?>
<?php else: ?>
<main class="mx-auto grid w-full max-w-6xl flex-1 content-start gap-5 p-4 sm:p-6">
  <?php if ($stats === null || !isset($usage)): ?>
  <div role="alert" class="alert alert-error alert-soft">The database is not reachable. Check the database settings in config.local.php.</div>
  <?php else: ?>
  <?php
    [$today, $month] = stats_periods();
    $t = $usage['total'];
    $dayN = fn($day, $k) => (int)($usage['days'][$day][$k] ?? 0);
    $monN = fn($m, $k) => (int)($usage['months'][$m][$k] ?? 0);
    $yesterday = (new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $lastMonth = (new DateTimeImmutable("$month-01"))->modify('-1 month')->format('Y-m');
    $exportsOf = fn($m) => $monN($m, 'export_pdf') + $monN($m, 'export_xlsx');
    $kpis = [
        ['Visitors today', $dayN($today, 'visitors'), $delta($dayN($today, 'visitors'), $dayN($yesterday, 'visitors'), 'vs yesterday'), $I['users']],
        ['Visitors this month', $monN($month, 'visitors'), $delta($monN($month, 'visitors'), $monN($lastMonth, 'visitors'), 'vs last month'), $I['calendar']],
        ['PDS created', $t['pds_created'] ?? 0, '<span class="kpi-delta">' . number_format($monN($month, 'pds_created')) . ' this month</span>', $I['file']],
        ['Exports', ($t['export_pdf'] ?? 0) + ($t['export_xlsx'] ?? 0), '<span class="kpi-delta">' . number_format($exportsOf($month)) . ' this month &middot; ' . number_format($t['export_pdf'] ?? 0) . ' PDF, ' . number_format($t['export_xlsx'] ?? 0) . ' Excel</span>', $I['export']],
    ];
    $cols = ['visitors', 'visits', 'pds_created', 'active_pds', 'export_pdf', 'export_xlsx', 'import_xlsx', 'signup'];
    $last30 = [];
    $d0 = new DateTimeImmutable($today);
    for ($i = 29; $i >= 0; $i--) $last30[] = $d0->modify("-$i day")->format('Y-m-d');
  ?>
  <div class="flex flex-wrap items-end justify-between gap-3">
    <div>
      <h1 class="text-2xl font-semibold">Dashboard</h1>
      <p class="mt-0.5 max-w-2xl text-sm text-base-content/60">
        <?= $usage['since'] ? 'Counting since ' . h(date('F j, Y', strtotime($usage['since']))) . '.' : 'No usage counted yet.' ?>
        Visitors are people who opened the app, counted once a day and once a month without cookies. Bots and admins are left out.
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if (cfg('ANALYTICS_DASHBOARD_URL') !== ''): ?><a href="<?= h(cfg('ANALYTICS_DASHBOARD_URL')) ?>" class="btn btn-sm btn-ghost" target="_blank" rel="noopener"><?= $icon($I['external']) ?>Analytics dashboard</a><?php endif; ?>
      <a href="admin.php?csv=1" class="btn btn-sm btn-outline"><?= $icon($I['download']) ?>Download CSV</a>
    </div>
  </div>

  <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Key numbers">
    <?php foreach ($kpis as [$label, $value, $sub, $ic]): ?>
    <div class="kpi">
      <div class="flex items-center justify-between gap-2">
        <span class="text-sm font-medium text-base-content/70"><?= h($label) ?></span>
        <span class="kpi-icon"><?= $icon($ic) ?></span>
      </div>
      <div class="mt-2 text-3xl font-semibold tabular-nums tracking-tight"><?= $n($value) ?></div>
      <div class="mt-1"><?= $sub ?></div>
    </div>
    <?php endforeach; ?>
  </section>

  <section class="card-box">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="font-semibold">Visitors per day</h2>
      <span class="text-xs text-base-content/60">Last 30 days &middot; hover a bar for the day's numbers</span>
    </div>
    <div class="chart mt-3" id="chart">
      <?= visitors_chart($last30, $usage) ?>
      <div class="chart-tip" id="chart-tip" role="status" hidden></div>
    </div>
    <details class="mt-3">
      <summary class="cursor-pointer text-sm text-base-content/70 hover:text-base-content">Show the numbers as a table</summary>
      <div class="mt-3 overflow-x-auto">
        <table class="table table-sm">
          <thead><tr><th>Day</th><?php foreach ($cols as $k): ?><th class="text-right"><?= h(STATS_METRICS[$k]) ?></th><?php endforeach; ?></tr></thead>
          <tbody>
          <?php foreach (array_reverse($last30) as $day): ?>
            <tr><td class="whitespace-nowrap"><?= h(date('D, M j', strtotime($day))) ?></td><?php foreach ($cols as $k): ?><td class="text-right tabular-nums"><?= $n($dayN($day, $k)) ?></td><?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </details>
  </section>

  <div class="grid gap-5 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
    <section class="card-box overflow-x-auto">
      <h2 class="font-semibold">By month</h2>
      <p class="mb-3 text-xs text-base-content/60">Visitors and "PDS worked on" are unique within the month; the other columns are totals.</p>
      <table class="table table-sm">
        <thead><tr><th>Month</th><?php foreach ($cols as $k): ?><th class="text-right"><?= h(STATS_METRICS[$k]) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach (array_slice($usage['months'], 0, 12, true) as $m => $r): ?>
          <tr><td class="whitespace-nowrap"><?= h(date('M Y', strtotime("$m-01"))) ?></td><?php foreach ($cols as $k): ?><td class="text-right tabular-nums"><?= $n($r[$k] ?? 0) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$usage['months']): ?><tr><td colspan="<?= count($cols) + 1 ?>" class="text-base-content/60">No data yet.</td></tr><?php endif; ?>
        </tbody>
        <tfoot><tr><th>All time</th><?php foreach ($cols as $k): ?><th class="text-right tabular-nums"><?= in_array($k, STATS_UNIQUE, true) ? '&ndash;' : $n($t[$k] ?? 0) ?></th><?php endforeach; ?></tr></tfoot>
      </table>
    </section>

    <section class="card-box">
      <h2 class="font-semibold">Records now</h2>
      <p class="mb-3 text-xs text-base-content/60">What is stored at this moment.</p>
      <dl class="grid gap-2">
        <?php foreach ($stats as $label => $v): ?>
        <div class="flex items-center justify-between gap-3 rounded-field bg-base-200/60 px-3 py-2">
          <dt class="text-sm text-base-content/70"><?= h($label) ?></dt><dd class="font-semibold tabular-nums"><?= $n($v) ?></dd>
        </div>
        <?php endforeach; ?>
      </dl>
      <h3 class="mb-2 mt-5 text-sm font-semibold">PDS per form version</h3>
      <dl class="grid gap-2">
        <?php foreach ($forms as $f): ?>
        <div class="flex items-center justify-between gap-3 rounded-field bg-base-200/60 px-3 py-2">
          <dt class="text-sm text-base-content/70"><?= h($f['form']) ?></dt><dd class="font-semibold tabular-nums"><?= $n($f['n']) ?></dd>
        </div>
        <?php endforeach; ?>
        <?php if (!$forms): ?><p class="text-sm text-base-content/60">No PDS yet.</p><?php endif; ?>
      </dl>
    </section>
  </div>
  <?php endif; ?>
</main>
<?php site_footer(); ?>
<script>
  // Chart tooltip: follows the hovered or focused day.
  (() => {
    const chart = document.getElementById('chart'), tip = document.getElementById('chart-tip');
    if (!chart) return;
    const show = (rect) => {
      const c = chart.getBoundingClientRect(), r = rect.getBoundingClientRect();
      tip.textContent = rect.dataset.tip;
      tip.hidden = false;
      const x = Math.min(Math.max(r.left - c.left + r.width / 2 - tip.offsetWidth / 2, 0), c.width - tip.offsetWidth);
      tip.style.left = `${x}px`;
      chart.querySelectorAll('.chart-hit.on').forEach((n) => n.classList.remove('on'));
      rect.classList.add('on');
    };
    const hide = () => {
      tip.hidden = true;
      chart.querySelectorAll('.chart-hit.on').forEach((n) => n.classList.remove('on'));
    };
    chart.querySelectorAll('.chart-hit').forEach((r) => {
      r.addEventListener('mouseenter', () => show(r));
      r.addEventListener('focus', () => show(r));
      r.addEventListener('blur', hide);
    });
    chart.addEventListener('mouseleave', hide);
  })();
</script>
<?php endif; ?>
</body>
</html>
