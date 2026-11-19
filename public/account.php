<?php
// Signed-in user's PDS library (library.php).
//   GET              PDS in the account; GET ?trash those in the Trash (kept 30 days)
//   POST {op, id}    rename, copy (all or chosen sections), trash, restore, purge (delete forever), empty-trash
require __DIR__ . '/../lib/auth.php';

security_headers();
json_errors();
header('Content-Type: application/json');
header('Cache-Control: no-store');

const TRASH_DAYS = 30;

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$user) reply(200, ['user' => null, 'docs' => []]);
    $uid = $user['id'];
    if (random_int(1, 20) === 1) db()->exec('DELETE FROM pds WHERE deleted_at < NOW() - INTERVAL ' . TRASH_DAYS . ' DAY');
    // Records saved before progress was stored get it once here.
    $st = db()->prepare('SELECT id, data FROM pds WHERE user_id = ? AND progress IS NULL');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) {
        db()->prepare('UPDATE pds SET progress = ? WHERE id = ?')->execute([pds_progress(json_decode($r['data'], true) ?: []), $r['id']]);
    }
    $field = fn($k) => "JSON_UNQUOTE(JSON_EXTRACT(data, '$.fields.$k'))";
    // Times are sent as Unix seconds so the browser shows them right whatever time zone the database uses.
    $st = db()->prepare("SELECT id, name, form, progress, UNIX_TIMESTAMP(created_at) AS created, UNIX_TIMESTAMP(updated_at) AS updated,
        UNIX_TIMESTAMP(deleted_at) AS deleted,
        {$field('surname')} AS surname, {$field('first_name')} AS first_name, {$field('middle_name')} AS middle_name
        FROM pds WHERE user_id = ? AND deleted_at IS " . (isset($_GET['trash']) ? 'NOT NULL ORDER BY deleted_at DESC' : 'NULL ORDER BY updated_at DESC'));
    $st->execute([$uid]);
    $docs = array_map(function ($d) {
        $d['person'] = trim(implode(' ', array_filter([$d['first_name'], $d['surname']], fn($v) => $v && $v !== 'null')));
        $d['progress'] = (int)$d['progress'];
        return $d;
    }, $st->fetchAll());
    reply(200, ['user' => ['email' => $user['email'], 'name' => $user['name']], 'docs' => $docs, 'trash_days' => TRASH_DAYS]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'Method not allowed']);
require_same_origin();
if (!$user) reply(401, ['error' => 'Sign in first']);
$body = json_decode(file_get_contents('php://input', false, null, 0, 65536), true);
$op = $body['op'] ?? '';

if ($op === 'empty-trash') {
    db()->prepare('DELETE FROM pds WHERE user_id = ? AND deleted_at IS NOT NULL')->execute([$user['id']]);
    reply(200, ['ok' => true]);
}

$rec = pds_read($body['id'] ?? '');
if (!$rec || (int)$rec['user_id'] !== $user['id']) reply(404, ['error' => 'Not found']);

switch ($op) {
    case 'rename':
        db()->prepare('UPDATE pds SET name = ? WHERE id = ?')->execute([pds_name($body['name'] ?? ''), $rec['id']]);
        reply(200, ['ok' => true]);
    case 'trash':
    case 'delete':
        db()->prepare('UPDATE pds SET deleted_at = NOW() WHERE id = ?')->execute([$rec['id']]);
        reply(200, ['ok' => true]);
    case 'restore':
        db()->prepare('UPDATE pds SET deleted_at = NULL WHERE id = ?')->execute([$rec['id']]);
        reply(200, ['ok' => true]);
    case 'purge':
        if (!$rec['deleted_at']) reply(400, ['error' => 'Move it to the Trash first']);
        pds_delete($rec['id']);
        reply(200, ['ok' => true]);
    case 'copy':
        // Copies all sections, or the chosen ones, into a new PDS on the current form version.
        if (!rate_ok('create', 30, 3600)) reply(429, ['error' => 'Too many new PDS from this network. Please try again in an hour.']);
        $sections = isset($body['sections']) ? array_filter((array)$body['sections'], 'is_string') : array_column(pds_schema(), 'id');
        $res = pds_create(pds_pick($rec['data'], $sections), (string)($body['name'] ?? ''), $user['id']);
        stats_add('pds_created');
        reply(200, ['id' => $res['id']]);
}
reply(400, ['error' => 'Unknown action']);
