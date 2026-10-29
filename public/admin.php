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
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>PDSync Admin</title>
<link rel="stylesheet" href="style.css?v=<?= filemtime(__DIR__ . '/style.css') ?>">
</head>
<body>
<header class="bar">
  <strong>PDSync Admin</strong>
  <?php if ($admin): ?>
  <form method="post" class="acct">
    <span class="muted"><?= h($admin) ?></span>
    <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
    <button name="logout" value="1">Sign out</button>
  </form>
  <?php endif; ?>
</header>
<?php if (!$admin): ?>
<form method="post" class="admin-login">
  <h2>Admin sign-in</h2>
  <?php if ($error): ?><p class="err"><?= h($error) ?></p><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
  <label class="f">Username<input name="user" autocomplete="username" required autofocus></label>
  <label class="f">Password<input name="pass" type="password" autocomplete="current-password" required></label>
  <button class="primary">Sign in</button>
</form>
<?php else: ?>
<main class="admin">
  <h2>Overview</h2>
  <?php if ($stats === null): ?>
  <p class="err">The database is not reachable. Check config.local.php.</p>
  <?php else: ?>
  <div class="stats">
    <?php foreach ($stats as $label => $n): ?><div><b><?= $n ?></b><span><?= h($label) ?></span></div><?php endforeach; ?>
  </div>
  <h3>PDS per form version</h3>
  <table class="admin-table">
    <tr><th>Form</th><th>PDS</th></tr>
    <?php foreach ($forms as $f): ?><tr><td><?= h($f['form']) ?></td><td><?= (int)$f['n'] ?></td></tr><?php endforeach; ?>
  </table>
  <?php endif; ?>
</main>
<?php endif; ?>
</body>
</html>
