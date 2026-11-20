<?php
// Signed-in user's PDS library (library.php).
//   GET              PDS in the account; GET ?trash those in the Trash (kept 30 days)
//   POST {op, id}    rename, copy (all or chosen sections), trash, restore, purge (delete forever), empty-trash
//   GET ?export      everything kept about the account, as a JSON download (Data Privacy Act: right to access)
//   POST {op: delete-account, confirm: DELETE}   erases the account and all its PDS (right to erasure)
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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['export'])) {
    if (!$user) reply(401, ['error' => 'Sign in first']);
    $st = db()->prepare('SELECT email, name, created_at, last_login FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $out = ['exported_at' => date('c'), 'account' => $st->fetch(), 'pds' => [], 'consents' => []];
    $st = db()->prepare('SELECT id, name, form, created_at, updated_at, deleted_at FROM pds WHERE user_id = ? ORDER BY created_at');
    $st->execute([$user['id']]);
    foreach ($st->fetchAll() as $row) {
        $rec = pds_read($row['id']);
        $vs = db()->prepare('SELECT saved_at, data FROM pds_versions WHERE pds_id = ? ORDER BY saved_at');
        $vs->execute([$row['id']]);
        $row['answers'] = $rec['data'] ?? null;
        $row['earlier_versions'] = array_map(fn($v) => ['saved_at' => $v['saved_at'], 'answers' => json_decode(unseal($v['data']), true)], $vs->fetchAll());
        $out['pds'][] = $row;
    }
    $st = db()->prepare('SELECT c.version, c.agreed_at, c.pds_id FROM consents c LEFT JOIN pds p ON p.id = c.pds_id WHERE c.user_id = ? OR p.user_id = ? ORDER BY c.agreed_at');
    $st->execute([$user['id'], $user['id']]);
    $out['consents'] = $st->fetchAll();
    header('Content-Disposition: attachment; filename="pdsync-my-data-' . date('Y-m-d') . '.json"');
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$user) reply(200, ['user' => null, 'docs' => []]);
    $uid = $user['id'];
    if (random_int(1, 20) === 1) db()->exec('DELETE FROM pds WHERE deleted_at < NOW() - INTERVAL ' . TRASH_DAYS . ' DAY');
    // Records saved before progress was stored get it once here.
    $st = db()->prepare('SELECT id, data FROM pds WHERE user_id = ? AND progress IS NULL');
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) {
        db()->prepare('UPDATE pds SET progress = ? WHERE id = ?')->execute([pds_progress(json_decode(unseal($r['data']), true) ?: []), $r['id']]);
    }
    // Times are sent as Unix seconds so the browser shows them right whatever time zone the database uses. Names
    // for the cards are read from the (encrypted) answers here, not in SQL.
    $st = db()->prepare("SELECT id, name, form, progress, data, UNIX_TIMESTAMP(created_at) AS created, UNIX_TIMESTAMP(updated_at) AS updated,
        UNIX_TIMESTAMP(deleted_at) AS deleted
        FROM pds WHERE user_id = ? AND deleted_at IS " . (isset($_GET['trash']) ? 'NOT NULL ORDER BY deleted_at DESC' : 'NULL ORDER BY updated_at DESC'));
    $st->execute([$uid]);
    $docs = array_map(function ($d) {
        $f = (json_decode(unseal($d['data']), true) ?: [])['fields'] ?? [];
        unset($d['data']);
        foreach (['surname', 'first_name', 'middle_name'] as $k) $d[$k] = (string)($f[$k] ?? '');
        $d['person'] = trim(implode(' ', array_filter([$d['first_name'], $d['surname']])));
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

if ($op === 'delete-account') {
    if (($body['confirm'] ?? '') !== 'DELETE') reply(400, ['error' => 'Type DELETE to confirm']);
    // PDS, their versions and consents go with the account (foreign keys cascade).
    db()->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);
    auth_logout();
    reply(200, ['ok' => true]);
}

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
