<?php
// JSON endpoint for one PDS: by id for its signed-in owner, else this browser's anonymous PDS (key only in an HttpOnly cookie).
//   GET                      the PDS (with "updated", sent back on save to detect edits made in another tab or device)
//   GET ?versions            its earlier versions; GET ?version=N one of them
//   POST {data, name, base}  saves; answers 409 when "base" is older than the stored copy, unless "force" is set
//   DELETE                   deletes this browser's anonymous PDS and forgets it
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
    if (!pds_can($rec, $key, null) || $rec['deleted_at']) return null;
    if ($rec['user_id'] && (int)$rec['user_id'] !== $uid) {
        pds_cookie_set(null, null);
        return null;
    }
    return $rec + ['key' => $key];
}

// The PDS a request is about: by id for its owner, otherwise this browser's own.
function target_rec(?string $id, ?int $uid): ?array
{
    if ($id === null) return cookie_rec($uid);
    $rec = pds_read($id);
    return pds_can($rec, null, $uid) && !$rec['deleted_at'] ? $rec : null;
}

$method = $_SERVER['REQUEST_METHOD'];
$uid = auth_uid();
if ($method !== 'GET') require_same_origin();

if ($method === 'DELETE') {
    // An anonymous PDS has no other way back in once forgotten, so it is deleted rather than left behind.
    $rec = cookie_rec($uid);
    if ($rec && !$rec['user_id']) pds_delete($rec['id']);
    pds_cookie_set(null, null);
    reply(200, ['ok' => true]);
}

if ($method === 'GET') {
    $rec = target_rec(isset($_GET['id']) ? (string)$_GET['id'] : null, $uid);
    // A browser with no PDS yet is normal, not an error.
    if (!$rec && !isset($_GET['id']) && !isset($_GET['versions']) && !isset($_GET['version'])) reply(200, ['id' => null]);
    if (!$rec) reply(404, ['error' => 'Not found']);
    if (isset($_GET['versions'])) reply(200, ['versions' => pds_versions($rec['id'])]);
    if (isset($_GET['version'])) {
        $data = pds_version($rec, $_GET['version']);
        if (!$data) reply(404, ['error' => 'Version not found']);
        reply(200, ['data' => $data]);
    }
    reply(200, ['id' => $rec['id'], 'name' => $rec['name'], 'data' => $rec['data'], 'updated' => $rec['updated_at'], 'updated_ts' => (int)$rec['updated_ts'], 'progress' => pds_progress($rec['data'])]);
}

if ($method !== 'POST') reply(405, ['error' => 'Method not allowed']);

$raw = file_get_contents('php://input', false, null, 0, MAX_BYTES + 1);
if (strlen($raw) > MAX_BYTES) reply(413, ['error' => 'Too large']);
$body = json_decode($raw, true);
if (!is_array($body) || !is_array($body['data'] ?? null)) reply(400, ['error' => 'Invalid payload']);
$name = (string)($body['name'] ?? '');

$rec = isset($body['id']) ? target_rec((string)$body['id'], $uid) : cookie_rec($uid);
if (isset($body['id']) && !$rec) reply(403, ['error' => 'No access to this PDS']);

if ($rec) {
    $base = $body['base'] ?? null;
    if (is_string($base) && $base !== $rec['updated_at'] && empty($body['force'])) {
        reply(409, ['error' => 'This PDS was changed in another tab or device.', 'updated' => $rec['updated_at']]);
    }
    $updated = pds_update($rec['id'], pds_keep_images($body['data'], $rec), $name);
    if (!$uid) pds_cookie_set($rec['id'], $rec['key']);
    stats_unique('active_pds', $rec['id']);
    reply(200, ['id' => $rec['id'], 'updated' => $updated]);
}

if (!rate_ok('create', 30, 3600)) reply(429, ['error' => 'Too many new PDS from this network. Please try again in an hour.']);
$res = pds_create(pds_keep_images($body['data'], null), $name, $uid);
stats_add('pds_created');
stats_unique('active_pds', $res['id']);
if (!$uid) pds_cookie_set($res['id'], $res['key']);
$st = db()->prepare('SELECT updated_at FROM pds WHERE id = ?');
$st->execute([$res['id']]);
reply(200, ['id' => $res['id'], 'updated' => (string)$st->fetchColumn()]);
