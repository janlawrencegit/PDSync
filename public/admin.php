<?php
// Admin sign-in (accounts from data/admins.json) and dashboard.
require __DIR__ . '/../lib/admin.php';

security_headers();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');
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

$admin = admin_user();
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
    } catch (Throwable $e) {
        error_log((string)$e);
    }
}
?>
<!doctype html>
<html lang="en" data-theme="pdsync">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>PDSync Admin</title>
<link rel="stylesheet" href="app.css?v=<?= filemtime(__DIR__ . '/app.css') ?>">
</head>
<body class="min-h-screen bg-base-200 text-base-content antialiased">
<header class="flex h-16 items-center gap-3 border-b border-base-300 bg-base-100 px-4">
  <span class="grid size-9 place-items-center rounded-lg bg-primary text-sm font-bold text-primary-content">PDS</span>
  <span class="font-semibold">PDSync Admin</span>
  <?php if ($admin): ?>
  <form method="post" class="ml-auto flex items-center gap-3">
    <span class="text-sm text-base-content/60"><?= h($admin) ?></span>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <button name="logout" value="1" class="btn btn-ghost btn-sm">Sign out</button>
  </form>
  <?php endif; ?>
</header>
<?php if (!$admin): ?>
<form method="post" class="mx-auto mt-[12vh] grid w-[min(380px,92vw)] gap-4 rounded-box border border-base-300 bg-base-100 p-7">
  <div>
    <h1 class="text-xl font-semibold">Admin sign-in</h1>
    <p class="text-sm text-base-content/60">For PDSync administrators only.</p>
  </div>
  <?php if ($error): ?><div role="alert" class="alert alert-error alert-soft text-sm"><?= h($error) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
  <label class="f"><span>Username</span><input name="user" class="input w-full" autocomplete="username" required autofocus></label>
  <label class="f"><span>Password</span><input name="pass" type="password" class="input w-full" autocomplete="current-password" required></label>
  <button class="btn btn-primary">Sign in</button>
</form>
<?php else: ?>
<main class="mx-auto grid max-w-6xl gap-6 p-6">
  <h1 class="text-2xl font-semibold">Overview</h1>
  <?php if ($stats === null): ?>
  <div role="alert" class="alert alert-error alert-soft">The database is not reachable. Check config.local.php.</div>
  <?php else: ?>
  <div class="stats stats-vertical w-full border border-base-300 bg-base-100 md:stats-horizontal">
    <?php foreach ($stats as $label => $n): ?>
    <div class="stat"><div class="stat-title"><?= h($label) ?></div><div class="stat-value text-primary"><?= $n ?></div></div>
    <?php endforeach; ?>
  </div>
  <section class="rounded-box border border-base-300 bg-base-100 p-5">
    <h2 class="mb-3 font-semibold">PDS per form version</h2>
    <table class="table">
      <thead><tr><th>Form</th><th class="text-right">PDS</th></tr></thead>
      <tbody>
      <?php foreach ($forms as $f): ?><tr><td><?= h($f['form']) ?></td><td class="text-right"><?= (int)$f['n'] ?></td></tr><?php endforeach; ?>
      </tbody>
    </table>
  </section>
  <?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
