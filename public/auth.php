<?php
// Google sign-in: ?login starts it, Google redirects back here with a code, POST ?logout signs out.
require __DIR__ . '/../lib/auth.php';

security_headers();
header('Cache-Control: no-store');

function fail_page(string $msg): void
{
    http_response_code(400);
    exit('<!doctype html><meta charset="utf-8"><title>Sign-in</title><p>' . h($msg) . '</p><p><a href="./">Back to PDSync</a></p>');
}

if (isset($_GET['logout'])) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail_page('Use the Sign out button.');
    require_same_origin();
    auth_logout();
    header('Location: ./');
    exit;
}

if (cfg('GOOGLE_CLIENT_ID') === '') fail_page('Google sign-in is not configured on this server.');

if (isset($_GET['login'])) {
    $url = google_login_url();
    if (($_GET['next'] ?? '') === 'admin') $_SESSION['oauth']['next'] = 'admin.php';
    header('Location: ' . $url);
    exit;
}

if (isset($_GET['error'])) fail_page('Sign-in was cancelled.');
auth_start();
$next = $_SESSION['oauth']['next'] ?? '';
$c = isset($_GET['code'], $_GET['state']) ? google_claims((string)$_GET['code'], (string)$_GET['state']) : null;
if (!$c) fail_page('Sign-in failed or expired. Please try again.');
$id = auth_login($c);
header('Location: ' . ($next === 'admin.php' ? 'admin.php' : './' . ($id ? "#open=$id" : '')));
