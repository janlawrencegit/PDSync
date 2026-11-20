<?php
// Admin operations on form versions (public/forms.php): create a draft from an uploaded template, add its page images,
// line its boxes up, correct or add questions, test and activate it. Drafts are never used by people filling out
// a PDS until they are activated.
require_once __DIR__ . '/form-layout.php';

const FV_MAX_TEMPLATE = 10000000;
const FV_MAX_PAGE = 8000000;

// Readable name of a report item, e.g. "Surname" or "Work Experience, Position Title".
function fv_item_label(string $id, array $schema): string
{
    $fields = $tables = [];
    foreach ($schema as $sec) {
        foreach ($sec['fields'] ?? [] as $f) $fields[$f['k']] = $f['l'];
        foreach ($sec['tables'] ?? [] as $t) $tables[$t['k']] = $t;
    }
    $p = explode(':', $id);
    $col = fn($t, $c) => current(array_filter($tables[$t]['c'] ?? [], fn($x) => $x['k'] === $c))['l'] ?? $c;
    return match ($p[0]) {
        'f' => $fields[$p[1]] ?? $p[1],
        'c' => ($fields[$p[1]] ?? $p[1]) . ": $p[2]",
        't' => ($tables[$p[1]]['l'] ?? $p[1]) . ', ' . $col($p[1], $p[3]) . ' (first row)',
        'cont' => ($tables[$p[1]]['l'] ?? $p[1]) . ', ' . $col($p[1], $p[2]) . ' (continuation sheet)',
        'e' => $p[2] === 'row' ? "Education: $p[1] row" : 'Education, ' . $col('education', $p[2]),
        's' => ['signature' => 'E-signature box', 'photo' => 'Photo frame', 'thumbmark' => 'Thumbmark box'][$p[1]] ?? $p[1],
        default => $id,
    };
}

function fv_slug(string $revision): string
{
    $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($revision)), '-') ?: 'revision';
    $id = 'pds-' . substr($s, 0, 30);
    for ($i = 2, $base = $id; form_get($id); $i++) $id = "$base-$i";
    return $id;
}

// Creates a draft from an uploaded template; returns its id.
function fv_create(string $bin, string $revision, string $fileName): string
{
    if (strlen($bin) > FV_MAX_TEMPLATE) throw new RuntimeException('The file is too large.');
    $revision = trim(preg_replace('/\s+/', ' ', $revision)) ?: 'New revision';
    $r = fa_analyze($bin);
    $id = fv_slug($revision);
    $dir = FORMS_DIR . "/$id";
    mkdir($dir, 0700, true);
    file_put_contents("$dir/template.xlsx", $bin);
    // Questions start as a copy of the current version's, with table sizes from the new form.
    $schema = pds_schema();
    foreach ($schema as &$sec) {
        foreach ($sec['tables'] ?? [] as $i => $t) {
            if (isset($r['max'][$t['k']])) $sec['tables'][$i]['max'] = $r['max'][$t['k']];
            if (isset($r['max']["$t[k].more"])) $sec['tables'][$i]['more'] = $r['max']["$t[k].more"];
        }
    }
    unset($sec);
    file_put_contents("$dir/schema.json", json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $base = form_current();
    $f = [
        'id' => $id, 'dir' => $dir, 'title' => $base['title'] ?? 'CS Form No. 212', 'revision' => $revision, 'status' => 'draft',
        'created' => date('c'), 'source' => basename($fileName), 'based_on' => $base['id'],
        'map' => $r['map'], 'report' => $r['report'], 'calib' => [], 'boxes' => [], 'aspect' => null, 'images' => 0,
    ];
    form_save($f);
    fv_rebuild(form_get($id));
    return $id;
}

// Writes layout.json from the map, calibration and box corrections.
function fv_rebuild(array $f): array
{
    $schema = json_decode(file_get_contents($f['schema']), true);
    $layout = fl_build(file_get_contents($f['template']), $f['map'], $schema, $f['calib'] ?? [], $f['aspect'] ?? null);
    $boxes = $f['boxes'] ?? [];
    foreach ($layout['items'] as &$it) {
        $o = $boxes["$it[id]|$it[page]"] ?? null;
        if ($o) foreach (['x', 'y', 'w', 'h'] as $k) $it[$k] = round((float)$o[$k], 3);
    }
    unset($it);
    file_put_contents($f['layout'], json_encode($layout));
    return $layout;
}

// Geometry for lining up each page (column and row edges in points, default calibration).
function fv_geometry(array $f): array
{
    return fl_geometry(file_get_contents($f['template']), $f['map'], $f['aspect'] ?? null);
}

// Saves page images (PNG or JPEG data from the admin page), numbered from 1.
function fv_save_pages(array $f, array $images, string $source = 'pdf'): array
{
    $n = 0;
    $aspect = null;
    foreach ($images as $i => $bin) {
        if (strlen($bin) > FV_MAX_PAGE) throw new RuntimeException('A page image is too large.');
        $size = @getimagesizefromstring($bin);
        if (!$size || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) throw new RuntimeException('Page images must be PNG or JPEG.');
        $aspect ??= $size[1] / $size[0];
        file_put_contents("$f[dir]/page" . ($i + 1) . '.png', $bin);
        $n++;
    }
    foreach (glob("$f[dir]/page*.png") as $old) {
        if ((int)preg_replace('/\D/', '', basename($old)) > $n) unlink($old);
    }
    $f['images'] = $n;
    // Pages drawn from the Excel file use the template's own geometry, so the default calibration is already right.
    $f['images_from'] = $source;
    $f['aspect'] = $source === 'excel' ? 1.41421 : $aspect;
    $f['calib'] = [];
    $f['boxes'] = [];
    form_save($f);
    fv_rebuild($f);
    return $f;
}

// Points one report item at another cell (or clears it) and updates the map.
function fv_set_ref(array $f, int $index, ?string $addr): array
{
    $item = $f['report']['items'][$index] ?? throw new RuntimeException('Unknown item');
    $path = $item['path'] ?? throw new RuntimeException('This item cannot be changed here');
    if ($addr !== null) {
        $addr = trim($addr);
        if (!preg_match('/^(.+)!(?:([A-Za-z]{1,3}\d{1,5})|shape:(.+))$/i', $addr, $m)) throw new RuntimeException('Write the cell as Sheet!Cell, for example C1!D12.');
        $b = xlsx_open(file_get_contents($f['template']));
        $sheet = current(array_filter(array_keys($b['sheets']), fn($s) => strcasecmp(trim($s), trim($m[1])) === 0));
        if ($sheet === false) throw new RuntimeException("There is no sheet called $m[1] in this form.");
        $addr = $sheet . '!' . (($m[3] ?? '') !== '' ? 'shape:' . $m[3] : strtoupper($m[2]));
    }
    $map = &$f['map'];
    switch ($path[0]) {
        case 'fields':
            $list = isset($map['fields'][$path[1]]) ? explode(' ', $map['fields'][$path[1]]) : [];
            if ($addr) $list[$path[2]] = $addr;
            else unset($list[$path[2]]);
            ksort($list);
            if ($list) $map['fields'][$path[1]] = implode(' ', $list);
            else unset($map['fields'][$path[1]]);
            break;
        case 'checks':
            if ($addr) $map['checks'][$path[1]][$path[2]] = $addr;
            else unset($map['checks'][$path[1]][$path[2]]);
            break;
        case 'country':
            $map['country'] = $addr ? [pds_ref($addr)[0], pds_ref($addr)[1], $map['country'][2] ?? 10] : null;
            break;
        case 'tables':
        case 'cont':
            if (!$addr) throw new RuntimeException('A table column needs a cell.');
            [$s, $ref] = pds_ref($addr);
            [$c, $r] = xg_ref($ref);
            $t = $map[$path[0]][$path[1]] ?? [$s, $r, []];
            $t[2][$path[2]] = xg_letters($c);
            if ($path[2] === array_key_first($t[2]) || !isset($map[$path[0]][$path[1]])) [$t[0], $t[1]] = [$s, $r];
            $map[$path[0]][$path[1]] = $t;
            break;
        case 'edu_rows':
            if ($addr) $map['edu_rows'][$path[1]] = xg_ref(pds_ref($addr)[1])[1];
            else unset($map['edu_rows'][$path[1]]);
            break;
        case 'edu_cols':
            if ($addr) $map['edu_cols'][$path[1]] = preg_replace('/\d+/', '', pds_ref($addr)[1]);
            break;
        case 'images':
            if ($addr) $map['images'][$path[1]][$path[2]] = $addr;
            else unset($map['images'][$path[1]][$path[2]]);
            $map['images'][$path[1]] = array_values($map['images'][$path[1]] ?? []);
            break;
    }
    unset($map);
    $f['report']['items'][$index]['new'] = $addr;
    $f['report']['items'][$index]['status'] = $addr ? 'set' : 'removed';
    $f['report']['items'][$index]['how'] = $addr ? 'Set by an admin' : 'Left out of this version';
    form_save($f);
    fv_rebuild($f);
    return $f;
}

// Adds a question the new form has: a field in a section, written to one cell.
function fv_add_field(array $f, string $section, string $label, string $type, string $addr): array
{
    $schema = json_decode(file_get_contents($f['schema']), true);
    $label = trim($label);
    if ($label === '') throw new RuntimeException('Give the question a label.');
    $keys = [];
    foreach ($schema as $sec) foreach ($sec['fields'] ?? [] as $x) $keys[$x['k']] = true;
    $key = substr(trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($label)), '_'), 0, 30) ?: 'item';
    for ($i = 2, $base = $key; isset($keys[$key]); $i++) $key = "{$base}_$i";
    $def = ['k' => $key, 'l' => $label];
    if ($type === 'yn' || $type === 'date') $def['t'] = $type;
    if ($type === 'yn') $def['w'] = 4;
    $found = false;
    foreach ($schema as &$sec) {
        if ($sec['id'] !== $section) continue;
        $sec['fields'][] = $def;
        $found = true;
    }
    unset($sec);
    if (!$found) throw new RuntimeException('Unknown section.');
    file_put_contents($f['schema'], json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    // A yes/no question has no single cell in the template; it is written as text "Yes"/"No" to the cell given.
    $f['report']['items'][] = ['id' => "f:$key", 'path' => ['fields', $key, 0], 'old' => null, 'new' => null, 'status' => 'set', 'how' => 'Added by an admin'];
    form_save($f);
    return fv_set_ref(form_get($f['id']), count($f['report']['items']) - 1, $addr);
}

// Leaves a question out of this version (when the new form no longer asks it).
function fv_drop_field(array $f, string $key): array
{
    $schema = json_decode(file_get_contents($f['schema']), true);
    foreach ($schema as &$sec) $sec['fields'] = array_values(array_filter($sec['fields'] ?? [], fn($x) => $x['k'] !== $key));
    unset($sec);
    file_put_contents($f['schema'], json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    unset($f['map']['fields'][$key], $f['map']['checks'][$key]);
    foreach ($f['report']['items'] as &$it) {
        if (in_array($it['id'], ["f:$key"], true) || str_starts_with($it['id'], "c:$key:")) {
            $it['status'] = 'removed';
            $it['how'] = 'Left out of this version';
        }
    }
    unset($it);
    form_save($f);
    fv_rebuild($f);
    return $f;
}

// Sample answers for testing a version: every question filled, tables with two rows.
function fv_sample(array $schema): array
{
    $d = ['fields' => [], 'tables' => []];
    foreach ($schema as $sec) {
        foreach ($sec['fields'] ?? [] as $x) {
            $t = $x['t'] ?? '';
            if ($t === 'image') continue;
            $d['fields'][$x['k']] = match ($t) {
                'date' => '2000-01-31', 'check' => '', 'yn' => 'No', 'select' => $x['o'][0] ?? '',
                default => isset($x['s'][0]) ? $x['s'][0] : 'Sample ' . substr($x['l'], 0, 18),
            };
        }
        foreach ($sec['tables'] ?? [] as $tb) {
            for ($i = 0; $i < 2; $i++) {
                $row = [];
                foreach ($tb['c'] as $c) $row[$c['k']] = ($c['t'] ?? '') === 'date' ? '2015-06-0' . ($i + 1) : (($c['t'] ?? '') === 'select' ? ($c['o'][$i] ?? $c['o'][0]) : 'Row ' . ($i + 1) . ' ' . substr($c['l'], 0, 12));
                $d['tables'][$tb['k']][] = $row;
            }
        }
    }
    $d['fields']['citizenship'] = 'Dual Citizenship';
    $d['fields']['dual_country'] = 'Japan';
    return $d;
}

function fv_pds_count(string $id): int
{
    try {
        $st = db()->prepare('SELECT COUNT(*) FROM pds WHERE form = ?');
        $st->execute([$id]);
        return (int)$st->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

function fv_delete(array $f): void
{
    if (!empty($f['builtin'])) throw new RuntimeException('The built-in version cannot be deleted.');
    if ($f['id'] === form_active_id()) throw new RuntimeException('Make another version active first.');
    if (fv_pds_count($f['id'])) throw new RuntimeException('Some PDS use this version, so it is kept.');
    foreach (glob("$f[dir]/*") as $file) unlink($file);
    rmdir($f['dir']);
}
