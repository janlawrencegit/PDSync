<?php
// Maps PDS data to the official CS Form 212 (Revised 2026) sheets C1-C4.
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/xlsx.php';

const PDS_TEMPLATE = __DIR__ . '/templates/pds-2026.xlsx';

// Text fields as "Sheet!Cell", space separated when written to several cells.
const PDS_FIELDS = [
    'surname' => 'C1!D10', 'first_name' => 'C1!D11', 'name_ext' => 'C1!N11', 'middle_name' => 'C1!D12',
    'dob' => 'C1!D13', 'pob' => 'C1!D15', 'civil_status_other' => 'C1!E20',
    'height' => 'C1!D22', 'weight' => 'C1!D24', 'blood_type' => 'C1!D25', 'umid' => 'C1!D27',
    'pagibig' => 'C1!D29', 'philhealth' => 'C1!D31', 'psn' => 'C1!D32', 'tin' => 'C1!D33', 'agency_no' => 'C1!D34',
    'res_house' => 'C1!I17', 'res_street' => 'C1!L17', 'res_subd' => 'C1!I19', 'res_brgy' => 'C1!L19',
    'res_city' => 'C1!I22', 'res_prov' => 'C1!L22', 'res_zip' => 'C1!I24',
    'perm_house' => 'C1!I25', 'perm_street' => 'C1!L25', 'perm_subd' => 'C1!I27', 'perm_brgy' => 'C1!L27',
    'perm_city' => 'C1!I29', 'perm_prov' => 'C1!L29', 'perm_zip' => 'C1!I31',
    'telephone' => 'C1!I32', 'mobile' => 'C1!I33', 'email' => 'C1!I34',
    'sp_surname' => 'C1!D36', 'sp_first' => 'C1!D37', 'sp_ext' => 'C1!H37', 'sp_middle' => 'C1!D38',
    'sp_occupation' => 'C1!D39', 'sp_employer' => 'C1!D40', 'sp_biz_address' => 'C1!D41', 'sp_tel' => 'C1!D42',
    'fa_surname' => 'C1!D44', 'fa_first' => 'C1!D45', 'fa_ext' => 'C1!H45', 'fa_middle' => 'C1!D46',
    'mo_surname' => 'C1!D48', 'mo_first' => 'C1!D49', 'mo_middle' => 'C1!D50',
    'q34a_details' => 'C4!I6', 'q34b_details' => 'C4!I11', 'q35a_details' => 'C4!I15',
    'q35b_details' => 'C4!L19', 'q35b_date' => 'C4!L20', 'q35b_status' => 'C4!L21',
    'q36_details' => 'C4!I25', 'q37_details' => 'C4!I29', 'q38a_details' => 'C4!L32', 'q38b_details' => 'C4!L35',
    'q39_details' => 'C4!I39', 'q40a_details' => 'C4!M44', 'q40b_details' => 'C4!M46', 'q40c_details' => 'C4!M48',
    'gov_id' => 'C4!D61', 'gov_id_no' => 'C4!D62', 'gov_id_issued' => 'C4!D64',
    'date_accomplished' => 'C4!G64 C1!L61 C2!J43 C3!I47',
];

// Checkbox groups as field => [value => "Sheet!LinkedCell"].
const PDS_CHECKS = [
    'sex' => ['Male' => 'C1!D16', 'Female' => 'C1!E16'],
    'civil_status' => ['Single' => 'C1!D17', 'Married' => 'C1!E17', 'Widowed' => 'C1!D18', 'Separated' => 'C1!E19', 'Other/s' => 'C1!D20'],
    'citizenship' => ['Filipino' => 'C1!J13', 'Dual Citizenship' => 'C1!K13'],
    'dual_type' => ['By Birth' => 'C1!L14', 'By Naturalization' => 'C1!M14'],
    'q34a' => ['Yes' => 'C4!H3', 'No' => 'C4!J3'], 'q34b' => ['Yes' => 'C4!H8', 'No' => 'C4!J8'],
    'q35a' => ['Yes' => 'C4!H13', 'No' => 'C4!J13'], 'q35b' => ['Yes' => 'C4!H18', 'No' => 'C4!J18'],
    'q36' => ['Yes' => 'C4!H23', 'No' => 'C4!J23'], 'q37' => ['Yes' => 'C4!H27', 'No' => 'C4!J27'],
    'q38a' => ['Yes' => 'C4!H31', 'No' => 'C4!J31'], 'q38b' => ['Yes' => 'C4!H34', 'No' => 'C4!J34'],
    'q39' => ['Yes' => 'C4!H37', 'No' => 'C4!J37'], 'q40a' => ['Yes' => 'C4!H43', 'No' => 'C4!J43'],
    'q40b' => ['Yes' => 'C4!H45', 'No' => 'C4!J45'], 'q40c' => ['Yes' => 'C4!H47', 'No' => 'C4!J47'],
];

// Repeating rows as table => [sheet, first row, column letters].
const PDS_TABLES = [
    'children' => ['C1', 37, ['name' => 'I', 'dob' => 'M']],
    'eligibility' => ['C2', 5, ['name' => 'A', 'rating' => 'F', 'date' => 'G', 'place' => 'I', 'license_no' => 'L', 'valid_until' => 'M']],
    'work' => ['C2', 18, ['from' => 'A', 'to' => 'C', 'position' => 'D', 'office' => 'G', 'salary' => 'J', 'sg' => 'K', 'status' => 'L', 'govt' => 'M']],
    'learning' => ['C3', 5, ['title' => 'A', 'from' => 'E', 'to' => 'F', 'hours' => 'G', 'type' => 'H', 'sponsor' => 'I']],
    'voluntary' => ['C3', 27, ['org' => 'A', 'from' => 'E', 'to' => 'F', 'hours' => 'G', 'position' => 'H']],
    'skills' => ['C3', 39, ['v' => 'A']],
    'recognitions' => ['C3', 39, ['v' => 'C']],
    'memberships' => ['C3', 39, ['v' => 'I']],
    'references' => ['C4', 52, ['name' => 'A', 'address' => 'G', 'tel' => 'H']],
];

const PDS_EDU_ROWS = ['Elementary' => 55, 'Secondary' => 56, 'Vocational/Trade Course' => 57, 'College' => 58, 'Graduate Studies' => 59];
const PDS_EDU_COLS = ['school' => 'D', 'course' => 'G', 'from' => 'J', 'to' => 'K', 'units' => 'L', 'year_grad' => 'M', 'honors' => 'N'];
const PDS_COUNTRY = ['C1', 'J16', 10]; // Dropdown cell and row offset of its list in column Q.

// "(e-signature/digital certificate)" placeholder labels removed from the output (user request).
const PDS_CLEAR = ['C1!D61', 'C2!D43', 'C3!C47', 'C4!G60', 'C4!F68'];

// Boxes that receive each uploaded image field; "shape:" targets a drawn frame instead of a cell.
const PDS_IMAGES = [
    'signature' => ['C1!D61', 'C2!D43', 'C3!C47', 'C4!G60'],
    'photo' => ['C4!shape:Text Box 100'],
    'thumbmark' => ['C4!L58'],
];
const PDS_LAYOUT = __DIR__ . '/templates/pds-2026-layout.json';

// Returns the date or money type of fields and table columns ("table.col") from the schema.
function pds_key_types(): array
{
    $keys = [];
    $typed = fn($x) => in_array($x['t'] ?? '', ['date', 'money'], true);
    foreach (pds_schema() as $sec) {
        foreach ($sec['fields'] ?? [] as $f) if ($typed($f)) $keys[$f['k']] = $f['t'];
        foreach ($sec['tables'] ?? [] as $t) foreach ($t['c'] as $c) if ($typed($c)) $keys["$t[k].$c[k]"] = $c['t'];
    }
    return $keys;
}

// Formats a numeric amount as pesos (e.g. 38413 -> ₱38,413.00); other text is kept.
function pds_money(string $v): string
{
    $n = preg_replace('/[₱,\s]|PHP/iu', '', $v);
    return is_numeric($n) ? '₱' . number_format((float)$n, 2) : trim($v);
}

// Converts a stored value to how the form displays it.
function pds_display(string $v, ?string $type): string
{
    return $type === 'date' ? pds_to_form_date($v) : ($type === 'money' ? pds_money($v) : $v);
}

function pds_max_rows(string $table): int
{
    foreach (pds_schema() as $sec) foreach ($sec['tables'] ?? [] as $t) if ($t['k'] === $table) return $t['max'];
    return 0;
}

// Converts yyyy-mm-dd to the form's dd/mm/yyyy.
function pds_to_form_date(string $v): string
{
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) ? "$m[3]/$m[2]/$m[1]" : $v;
}

// Converts an Excel serial or dd/mm/yyyy text to yyyy-mm-dd when possible.
function pds_from_form_date(string $v): string
{
    if (is_numeric($v) && $v > 0 && $v < 100000) return gmdate('Y-m-d', (int)round(($v - 25569) * 86400));
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return $v;
}

// Trims ordinary and non-breaking spaces.
function pds_trim(string $v): string
{
    return preg_replace('/^[\s\x{a0}]+|[\s\x{a0}]+$/u', '', $v);
}

function pds_ref(string $addr): array
{
    return explode('!', $addr, 2);
}

function pds_export(array $d): string
{
    $b = xlsx_open(file_get_contents(PDS_TEMPLATE));
    $types = pds_key_types();
    $val = fn($v, $key) => pds_display($v, $types[$key] ?? null);

    foreach (PDS_CLEAR as $a) xlsx_set($b, ...[...pds_ref($a), '']);
    foreach (PDS_FIELDS as $k => $addrs) {
        foreach (explode(' ', $addrs) as $a) xlsx_set($b, ...[...pds_ref($a), $val($d['fields'][$k] ?? '', $k)]);
    }
    foreach (PDS_CHECKS as $k => $opts) {
        foreach ($opts as $opt => $a) xlsx_check($b, ...[...pds_ref($a), ($d['fields'][$k] ?? '') === $opt]);
    }
    [$sheet, $cell, $offset] = PDS_COUNTRY;
    $country = strtolower(pds_trim($d['fields']['dual_country'] ?? ''));
    for ($i = 2; $country !== '' && $i < 300; $i++) {
        // Some template entries end with a non-breaking space (e.g. Spain, Thailand), so compare trimmed.
        if (strtolower(pds_trim(xlsx_get($b, $sheet, 'Q' . ($offset + $i)))) === $country) {
            xlsx_select($b, $sheet, $cell, $i);
            break;
        }
    }
    foreach (PDS_TABLES as $t => [$sheet, $start, $cols]) {
        foreach (array_slice($d['tables'][$t] ?? [], 0, pds_max_rows($t)) as $i => $row) {
            xlsx_merge_row($b, $sheet, $start, $start + $i);
            foreach ($cols as $c => $col) {
                xlsx_align_like($b, $sheet, $col . $start, $col . ($start + $i));
                xlsx_set($b, $sheet, $col . ($start + $i), $val($row[$c] ?? '', "$t.$c"));
            }
        }
    }
    $seen = [];
    foreach ($d['tables']['education'] ?? [] as $row) {
        $r = PDS_EDU_ROWS[$row['level'] ?? ''] ?? null;
        if (!$r || isset($seen[$r])) continue;
        $seen[$r] = true;
        foreach (PDS_EDU_COLS as $c => $col) xlsx_set($b, 'C1', $col . $r, $row[$c] ?? '');
    }
    pds_export_images($b, $d['fields']);
    return xlsx_save($b);
}

// Fits each uploaded image inside its boxes (geometry from the layout file), keeping its aspect ratio.
function pds_export_images(array &$b, array $fields): void
{
    $emu = 12700;
    $layout = json_decode(file_get_contents(PDS_LAYOUT), true);
    foreach ($layout['items'] as $i) {
        if (!str_starts_with($i['id'], 's:') || empty($i['anchor'])) continue;
        $url = $fields[substr($i['id'], 2)] ?? '';
        if (!preg_match('#^data:image/(png|jpeg);base64,#', $url, $m)) continue;
        $bytes = base64_decode(substr($url, strlen($m[0])));
        $size = getimagesizefromstring($bytes);
        if (!$size || !$size[0] || !$size[1]) continue;
        [, , $w, $h] = $i['pt'];
        [$ref, $ox, $oy] = $i['anchor'];
        $sheet = 'C' . $i['page'];
        // Drawn frames (the photo) take the frame's own anchor so the picture lines up when printed, inset on
        // the long side so the image keeps its shape (the frame's printed aspect comes from the layout box).
        $addr = current(array_filter(PDS_IMAGES[substr($i['id'], 2)], fn($a) => pds_ref($a)[0] === $sheet));
        $frame = null;
        if (str_contains($addr, '!shape:')) {
            $box = $i['w'] / ($i['h'] * $layout['aspect']);
            $img = $size[0] / $size[1];
            $ix = $img < $box ? (1 - $img / $box) / 2 * $w * $emu : 0;
            $iy = $img > $box ? (1 - $box / $img) / 2 * $h * $emu : 0;
            $shape = substr($addr, strpos($addr, 'shape:') + 6);
            $frame = xlsx_shape_anchor($b, $sheet, $shape, (int)$ix + $emu, (int)$iy + $emu);
            xlsx_clear_shape_text($b, $sheet, $shape);
        }
        $s = min(($w - 2) / $size[0], ($h - 2) / $size[1]);
        [$cw, $ch] = [$size[0] * $s, $size[1] * $s];
        xlsx_add_image($b, $sheet, $bytes, $m[1], $ref, (int)(($ox + ($w - $cw) / 2) * $emu), (int)(($oy + ($h - $ch) / 2) * $emu), (int)($cw * $emu), (int)($ch * $emu), $frame);
    }
}

function pds_import(string $bin): array
{
    $b = xlsx_open($bin);
    $types = pds_key_types();
    $get = function ($a, $key) use (&$b, $types) {
        $v = xlsx_get($b, ...pds_ref($a));
        $type = $types[$key] ?? null;
        return $type === 'date' ? pds_from_form_date($v) : ($type === 'money' ? pds_money($v) : $v);
    };
    $d = ['fields' => [], 'tables' => []];

    foreach (PDS_FIELDS as $k => $addrs) $d['fields'][$k] = $get(explode(' ', $addrs)[0], $k);
    foreach (PDS_CHECKS as $k => $opts) {
        $d['fields'][$k] = '';
        foreach ($opts as $opt => $a) if (xlsx_checked($b, ...pds_ref($a))) $d['fields'][$k] = $opt;
    }
    [$sheet, $cell, $offset] = PDS_COUNTRY;
    $i = (int)xlsx_get($b, $sheet, $cell);
    $d['fields']['dual_country'] = $i > 1 && $d['fields']['citizenship'] === 'Dual Citizenship' ? pds_trim(xlsx_get($b, $sheet, 'Q' . ($offset + $i))) : '';

    foreach (PDS_TABLES as $t => [$sheet, $start, $cols]) {
        $d['tables'][$t] = [];
        for ($i = 0; $i < pds_max_rows($t); $i++) {
            $row = [];
            foreach ($cols as $c => $col) $row[$c] = $get("$sheet!$col" . ($start + $i), "$t.$c");
            if (implode('', $row) !== '') $d['tables'][$t][] = $row;
        }
    }
    $d['tables']['education'] = [];
    foreach (PDS_EDU_ROWS as $level => $r) {
        $row = ['level' => $level];
        foreach (PDS_EDU_COLS as $c => $col) $row[$c] = xlsx_get($b, 'C1', $col . $r);
        if (implode('', array_slice($row, 1)) !== '') $d['tables']['education'][] = $row;
    }
    return pds_clean($d);
}
