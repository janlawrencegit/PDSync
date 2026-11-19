<?php
// Usage beacon from app.js (see lib/stats.php): counts an app open or a print/PDF export. Always answers 204.
require __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/stats.php';

security_headers();
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
require_same_origin();
$body = json_decode(file_get_contents('php://input', false, null, 0, 200), true);
$event = is_array($body) ? ($body['e'] ?? '') : '';

stats_try(function () use ($event) {
    if ($event === 'visit') {
        stats_add('visits');
        // A signed-in user counts once on any device; anyone else by network address and browser.
        $uid = auth_uid();
        stats_unique('visitors', $uid ? "u:$uid" : ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    } elseif ($event === 'export_pdf') {
        stats_add('export_pdf');
    }
});
