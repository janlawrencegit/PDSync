<?php
// JSON endpoint for one PDS: by id with its edit key or owner sign-in, else this browser's anonymous session cookie.
require __DIR__ . '/../lib/auth.php';

security_headers();
json_errors();
header('Content-Type: application/json');
header('Cache-Control: no-store');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

// This browser's anonymous PDS, unless it now belongs to an account other than the signed-in one.
function cookie_rec(?int $uid): ?array
{
    [$id, $key] = pds_cookie();
    $rec = pds_read($id);
    if (!pds_can($rec, $key, null)) return null;
    if ($rec['user_id'] && (int)$rec['user_id'] !== $uid) {
        pds_cookie_set(null, null);
        return null;
    }
    return $rec + ['key' => $key];
}

$method = $_SERVER['REQUEST_METHOD'];
$uid = auth_uid();
if ($method !== 'GET') require_same_origin();

// DELETE forgets this browser's anonymous PDS so "New" starts fresh; the record itself is kept.
if ($method === 'DELETE') {
    pds_cookie_set(null, null);
    reply(200, ['ok' => true]);
}

if ($method === 'GET') {
    if (isset($_GET['id'])) {
        $rec = pds_read($_GET['id']);
        $key = $_GET['key'] ?? null;
        if (!pds_can($rec, $key, $uid)) reply(404, ['error' => 'Not found']);
        // A link opened on a PC without its own anonymous PDS becomes that PC's PDS.
        $own = $uid ? (int)$rec['user_id'] === $uid : !pds_cookie()[0];
        if ($own && !$uid && $key) pds_cookie_set($rec['id'], $key);
    } else {
        $rec = cookie_rec($uid);
        if (!$rec) reply(404, ['error' => 'Not found']);
        $key = $rec['key'];
        $own = true;
    }
    reply(200, ['id' => $rec['id'], 'key' => $key, 'own' => $own, 'name' => $rec['name'], 'data' => $rec['data']]);
}

if ($method !== 'POST') reply(405, ['error' => 'Method not allowed']);

$raw = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
if (strlen($raw) > MAX_BYTES) reply(413, ['error' => 'Too large']);
$body = json_decode($raw, true);
if (!is_array($body) || !is_array($body['data'] ?? null)) reply(400, ['error' => 'Invalid payload']);
$name = (string)($body['name'] ?? '');

if (isset($body['id'])) {
    $rec = pds_read($body['id']);
    if (!pds_can($rec, $body['key'] ?? null, $uid)) reply(403, ['error' => 'No access to this PDS']);
    pds_update($rec['id'], $body['data'], $name);
    if ($uid && !$rec['user_id']) pds_claim($rec['id'], $uid);
    reply(200, ['id' => $rec['id']]);
}

$rec = cookie_rec($uid);
if ($rec) {
    pds_update($rec['id'], $body['data'], $name);
    reply(200, ['id' => $rec['id'], 'key' => $rec['key']]);
}
$res = pds_create($body['data'], $name, $uid);
if (!$uid) pds_cookie_set($res['id'], $res['key']);
reply(200, $res);
