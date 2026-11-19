<?php
// Page images of form versions added from the admin page (data/forms/<id>/pageN.png). The active version's pages are
// public, like public/pds/ for the built-in one; drafts and retired versions are shown to admins only.
require __DIR__ . '/../lib/admin.php';

$f = form_get((string)($_GET['id'] ?? ''));
$n = (int)($_GET['n'] ?? 0);
$file = $f && empty($f['builtin']) && $n > 0 ? "$f[dir]/page$n.png" : '';
if (!$file || !is_file($file) || ($f['id'] !== form_active_id() && !admin_allowed())) {
    http_response_code(404);
    exit;
}
// Let go of the session at once so the other page images of the same screen load alongside this one.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$info = getimagesize($file);
header('Content-Type: ' . ($info['mime'] ?? 'image/png'));
header('X-Content-Type-Options: nosniff');
// Each upload changes the file time, which is in the URL, so a long cache is safe for the active version.
header($f['id'] === form_active_id() && isset($_GET['v']) ? 'Cache-Control: public, max-age=31536000, immutable' : 'Cache-Control: private, no-store');
readfile($file);
