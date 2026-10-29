<?php
// PDS storage in MySQL (see sql/schema.sql); anonymous records are reached by key, account records by owner.
require_once __DIR__ . '/config.php';

const FORM_ID = 'pds-2026';
const ANON_DAYS = 180;
const MAX_BYTES = 3145728;
const MAX_IMAGE = 600000;

function pds_schema(): array
{
    static $schema = null;
    return $schema ??= json_decode(file_get_contents(__DIR__ . '/schema.json'), true);
}

function pds_valid_id($id): bool
{
    return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id) === 1;
}

function pds_read($id): ?array
{
    if (!pds_valid_id($id)) return null;
    $st = db()->prepare('SELECT * FROM pds WHERE id = ?');
    $st->execute([$id]);
    $rec = $st->fetch();
    if (!$rec) return null;
    $rec['data'] = json_decode($rec['data'], true);
    return $rec;
}

// Access is granted by the edit key, or by ownership for a signed-in user.
function pds_can(?array $rec, $key, ?int $uid): bool
{
    if (!$rec) return false;
    if ($uid && (int)$rec['user_id'] === $uid) return true;
    return is_string($key) && hash_equals($rec['key_hash'], hash('sha256', $key));
}

// Accepts only a PNG or JPEG data URL of bounded size for image fields (signature, photo, thumbmark).
function pds_image($v): string
{
    return is_string($v) && strlen($v) <= MAX_IMAGE && preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/]+=*$#', $v) ? $v : '';
}

// Keeps only schema-defined keys as trimmed strings.
function pds_clean(array $in): array
{
    $out = ['fields' => [], 'tables' => []];
    $str = fn($v) => is_scalar($v) && preg_match('/^.{0,500}/su', trim((string)$v), $m) ? $m[0] : '';
    foreach (pds_schema() as $sec) {
        foreach ($sec['fields'] ?? [] as $f) {
            $v = $in['fields'][$f['k']] ?? '';
            $t = $f['t'] ?? '';
            $out['fields'][$f['k']] = $t === 'image' ? pds_image($v) : ($t === 'check' ? ($v === 'Yes' ? 'Yes' : '') : $str($v));
        }
        foreach ($sec['tables'] ?? [] as $t) {
            $rows = is_array($in['tables'][$t['k']] ?? null) ? $in['tables'][$t['k']] : [];
            $rows = array_slice(array_values($rows), 0, $t['max'] ?? 50);
            $out['tables'][$t['k']] = array_map(function ($row) use ($t, $str) {
                $r = [];
                foreach ($t['c'] as $c) $r[$c['k']] = $str(is_array($row) ? ($row[$c['k']] ?? '') : '');
                return $r;
            }, $rows);
        }
    }
    // A conditional field (schema "if": [key, value]) is blanked unless its condition holds, e.g. dual country only for dual citizens.
    foreach (pds_schema() as $sec) {
        foreach ($sec['fields'] ?? [] as $f) {
            if (isset($f['if']) && ($out['fields'][$f['if'][0]] ?? '') !== $f['if'][1]) $out['fields'][$f['k']] = '';
        }
    }
    // Enforced here so a client cannot save a different permanent address while "same as residential" is ticked.
    if (($out['fields']['perm_same'] ?? '') === 'Yes') {
        foreach (['house', 'street', 'subd', 'brgy', 'city', 'prov', 'zip'] as $s) $out['fields']["perm_$s"] = $out['fields']["res_$s"] ?? '';
    }
    return $out;
}

function pds_name($v): string
{
    return is_string($v) && preg_match('/^.{0,120}/su', trim(preg_replace('/[\x00-\x1f]/', '', $v)), $m) ? $m[0] : '';
}

// Creates a record and returns its id and edit key; anonymous records older than ANON_DAYS are pruned now and then.
function pds_create(array $data, string $name, ?int $uid): array
{
    if (random_int(1, 100) === 1) db()->exec('DELETE FROM pds WHERE user_id IS NULL AND updated_at < NOW() - INTERVAL ' . ANON_DAYS . ' DAY');
    $id = bin2hex(random_bytes(8));
    $key = bin2hex(random_bytes(16));
    db()->prepare('INSERT INTO pds (id, key_hash, user_id, form, name, data, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$id, hash('sha256', $key), $uid, FORM_ID, pds_name($name), json_encode(pds_clean($data))]);
    return ['id' => $id, 'key' => $key];
}

function pds_update(string $id, array $data, string $name): void
{
    db()->prepare('UPDATE pds SET data = ?, name = ?, form = ?, updated_at = NOW() WHERE id = ?')
        ->execute([json_encode(pds_clean($data)), pds_name($name), FORM_ID, $id]);
}

// Moves an anonymous record into a user's account.
function pds_claim(string $id, int $uid): void
{
    db()->prepare('UPDATE pds SET user_id = ? WHERE id = ? AND user_id IS NULL')->execute([$uid, $id]);
}

// Keeps only the given schema sections, used when copying details into a new PDS.
function pds_pick(array $data, array $sections): array
{
    $out = ['fields' => [], 'tables' => []];
    foreach (pds_schema() as $sec) {
        if (!in_array($sec['id'], $sections, true)) continue;
        foreach ($sec['fields'] ?? [] as $f) $out['fields'][$f['k']] = $data['fields'][$f['k']] ?? '';
        foreach ($sec['tables'] ?? [] as $t) $out['tables'][$t['k']] = $data['tables'][$t['k']] ?? [];
    }
    return $out;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
