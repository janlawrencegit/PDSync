<?php
// PDS storage in MySQL (see sql/schema.sql); anonymous records are reached by key, account records by owner.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/forms.php';

const ANON_DAYS = 180;
const MAX_BYTES = 3145728;
const MAX_IMAGE = 600000;
const IMAGE_FIELDS = ['signature', 'photo', 'thumbmark'];
const VERSIONS_KEPT = 15;
const VERSION_GAP_MINUTES = 10;

// Questions of the current form version (lib/schema.json for Revised 2026).
function pds_schema(): array
{
    return $GLOBALS['PDS_SCHEMA'] ??= json_decode(file_get_contents(form_current()['schema']), true);
}

// Id of the current form version, stored with each PDS.
function pds_form_id(): string
{
    return form_current()['id'];
}

function pds_valid_id($id): bool
{
    return is_string($id) && preg_match('/^[a-f0-9]{16}$/', $id) === 1;
}

function pds_read($id): ?array
{
    if (!pds_valid_id($id)) return null;
    $st = db()->prepare('SELECT *, UNIX_TIMESTAMP(updated_at) AS updated_ts FROM pds WHERE id = ?');
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
    // A typed value matching a suggestion ("s") apart from case and spacing takes the suggestion's exact spelling.
    $pick = function (array $def, string $v): string {
        foreach ($def['s'] ?? [] as $o) if (strcasecmp(preg_replace('/\s+/', ' ', $v), $o) === 0) return $o;
        return $v;
    };
    foreach (pds_schema() as $sec) {
        foreach ($sec['fields'] ?? [] as $f) {
            $v = $in['fields'][$f['k']] ?? '';
            $t = $f['t'] ?? '';
            $out['fields'][$f['k']] = $t === 'image' ? pds_image($v) : ($t === 'check' ? ($v === 'Yes' ? 'Yes' : '') : $pick($f, $str($v)));
        }
        foreach ($sec['tables'] ?? [] as $t) {
            $rows = is_array($in['tables'][$t['k']] ?? null) ? $in['tables'][$t['k']] : [];
            // Rows past the form's own rows go on the official continuation sheet ("more").
            $rows = array_slice(array_values($rows), 0, ($t['max'] ?? 50) + ($t['more'] ?? 0));
            $out['tables'][$t['k']] = array_map(function ($row) use ($t, $str, $pick) {
                $r = [];
                foreach ($t['c'] as $c) $r[$c['k']] = $pick($c, $str(is_array($row) ? ($row[$c['k']] ?? '') : ''));
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

// Share of items answered, as on the app's progress bar: every shown item outside checkboxes and images, plus one
// per table that has an entry.
function pds_progress(array $data): int
{
    $done = $total = 0;
    foreach (pds_schema() as $sec) {
        foreach ($sec['fields'] ?? [] as $f) {
            if (in_array($f['t'] ?? '', ['check', 'image'], true)) continue;
            if (isset($f['if']) && ($data['fields'][$f['if'][0]] ?? '') !== $f['if'][1]) continue;
            $total++;
            if (trim((string)($data['fields'][$f['k']] ?? '')) !== '') $done++;
        }
        foreach ($sec['tables'] ?? [] as $t) {
            $total++;
            foreach ($data['tables'][$t['k']] ?? [] as $row) {
                if (trim(implode('', array_map('strval', (array)$row))) !== '') { $done++; break; }
            }
        }
    }
    return $total ? (int)round(100 * $done / $total) : 0;
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
    $clean = pds_clean($data);
    db()->prepare('INSERT INTO pds (id, key_hash, user_id, form, name, data, progress, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())')
        ->execute([$id, hash('sha256', $key), $uid, pds_form_id(), pds_name($name), json_encode($clean), pds_progress($clean)]);
    return ['id' => $id, 'key' => $key];
}

// Saves a record and returns its new updated_at, which clients send back to detect edits made elsewhere.
function pds_update(string $id, array $data, string $name): string
{
    $clean = pds_clean($data);
    pds_snapshot($id);
    db()->prepare('UPDATE pds SET data = ?, name = ?, form = ?, progress = ?, updated_at = NOW() WHERE id = ?')
        ->execute([json_encode($clean), pds_name($name), pds_form_id(), pds_progress($clean), $id]);
    $st = db()->prepare('SELECT updated_at FROM pds WHERE id = ?');
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

// Keeps the stored copy as a version before it is overwritten, at most one per VERSION_GAP_MINUTES, the last
// VERSIONS_KEPT of them. Images are left out to keep versions small; a restore keeps the current images.
function pds_snapshot(string $id): void
{
    $st = db()->prepare('SELECT MAX(saved_at) > NOW() - INTERVAL ' . VERSION_GAP_MINUTES . ' MINUTE FROM pds_versions WHERE pds_id = ?');
    $st->execute([$id]);
    if ($st->fetchColumn()) return;
    $rec = pds_read($id);
    if (!$rec) return;
    foreach (IMAGE_FIELDS as $k) unset($rec['data']['fields'][$k]);
    db()->prepare('INSERT INTO pds_versions (pds_id, saved_at, data) SELECT id, updated_at, ? FROM pds WHERE id = ?')
        ->execute([json_encode($rec['data']), $id]);
    db()->prepare('DELETE FROM pds_versions WHERE pds_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM pds_versions WHERE pds_id = ? ORDER BY saved_at DESC, id DESC LIMIT ' . VERSIONS_KEPT . ') AS kept)')
        ->execute([$id, $id]);
}

function pds_versions(string $id): array
{
    $st = db()->prepare('SELECT id, UNIX_TIMESTAMP(saved_at) AS saved FROM pds_versions WHERE pds_id = ? ORDER BY saved_at DESC, id DESC');
    $st->execute([$id]);
    return $st->fetchAll();
}

// One version's data with the record's current images put back.
function pds_version(array $rec, $vid): ?array
{
    $st = db()->prepare('SELECT data FROM pds_versions WHERE id = ? AND pds_id = ?');
    $st->execute([(int)$vid, $rec['id']]);
    $data = json_decode((string)$st->fetchColumn(), true);
    if (!is_array($data)) return null;
    foreach (IMAGE_FIELDS as $k) $data['fields'][$k] = $rec['data']['fields'][$k] ?? '';
    return $data;
}

// Image fields sent as "=" are unchanged since the last save, so the stored image is kept (saves upload less).
function pds_keep_images(array $data, ?array $rec): array
{
    foreach (IMAGE_FIELDS as $k) {
        if (($data['fields'][$k] ?? null) === '=') $data['fields'][$k] = $rec['data']['fields'][$k] ?? '';
    }
    return $data;
}

function pds_delete(string $id): void
{
    db()->prepare('DELETE FROM pds WHERE id = ?')->execute([$id]);
}

// Fixed-window limit per visitor address for actions that create data; old windows are removed now and then.
function rate_ok(string $action, int $max, int $seconds): bool
{
    $window = intdiv(time(), $seconds);
    $k = hash('sha256', "$action|" . ($_SERVER['REMOTE_ADDR'] ?? '') . "|$window", true);
    if (random_int(1, 50) === 1) db()->exec('DELETE FROM rate_limit WHERE until < NOW()');
    db()->prepare('INSERT INTO rate_limit (k, n, until) VALUES (?, 1, NOW() + INTERVAL ? SECOND) ON DUPLICATE KEY UPDATE n = n + 1')
        ->execute([$k, $seconds]);
    $st = db()->prepare('SELECT n FROM rate_limit WHERE k = ?');
    $st->execute([$k]);
    return (int)$st->fetchColumn() <= $max;
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
