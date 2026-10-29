<?php
// Signed-in user's PDS list: GET lists them, POST renames, copies or deletes one.
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

$user = auth_user();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!$user) reply(200, ['user' => null, 'docs' => []]);
    $st = db()->prepare("SELECT id, name, form, updated_at AS updated,
        CONCAT_WS(' ', JSON_UNQUOTE(JSON_EXTRACT(data, '$.fields.first_name')), JSON_UNQUOTE(JSON_EXTRACT(data, '$.fields.surname'))) AS person
        FROM pds WHERE user_id = ? ORDER BY updated_at DESC");
    $st->execute([$user['id']]);
    reply(200, ['user' => ['email' => $user['email'], 'name' => $user['name']], 'docs' => $st->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405, ['error' => 'Method not allowed']);
require_same_origin();
if (!$user) reply(401, ['error' => 'Sign in first']);
$body = json_decode(file_get_contents('php://input', false, null, 0, 65536), true);
$rec = pds_read($body['id'] ?? '');
if (!$rec || (int)$rec['user_id'] !== $user['id']) reply(404, ['error' => 'Not found']);

switch ($body['op'] ?? '') {
    case 'rename':
        db()->prepare('UPDATE pds SET name = ? WHERE id = ?')->execute([pds_name($body['name'] ?? ''), $rec['id']]);
        reply(200, ['ok' => true]);
    case 'delete':
        db()->prepare('DELETE FROM pds WHERE id = ?')->execute([$rec['id']]);
        reply(200, ['ok' => true]);
    case 'copy':
        // Copies the chosen sections into a new PDS on the current form version.
        $sections = array_filter((array)($body['sections'] ?? []), 'is_string');
        $res = pds_create(pds_pick($rec['data'], $sections), (string)($body['name'] ?? ''), $user['id']);
        reply(200, ['id' => $res['id']]);
}
reply(400, ['error' => 'Unknown action']);
