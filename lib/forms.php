<?php
// Form versions (CS Form 212 revisions). The built-in version is Revised 2026, defined by lib/pds-xlsx.php's map and
// lib/templates/. Later revisions are added from the admin page (public/forms.php) and live in data/forms/<id>/:
// form.json (name, status, cell map), schema.json (questions), layout.json (preview boxes), template.xlsx and pageN.png.
// The active version is what the editor, preview and exports use; data/forms/active.json names it.
require_once __DIR__ . '/config.php';

const FORMS_DIR = DATA_DIR . '/forms';
const FORM_BUILTIN = 'pds-2026';

function form_valid_id($id): bool
{
    return is_string($id) && preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $id) === 1;
}

// The built-in Revised 2026 version.
function form_builtin(): array
{
    return [
        'id' => FORM_BUILTIN,
        'title' => 'CS Form No. 212',
        'revision' => 'Revised 2026',
        'status' => 'active',
        'builtin' => true,
        'dir' => __DIR__ . '/templates',
        'template' => __DIR__ . '/templates/pds-2026.xlsx',
        'layout' => __DIR__ . '/templates/pds-2026-layout.json',
        'schema' => __DIR__ . '/schema.json',
        'pages' => 4,
        'map' => null, // lib/pds-xlsx.php fills it from its constants
    ];
}

// A version from data/forms, or the built-in one; null when unknown.
function form_get(?string $id): ?array
{
    if ($id === null || $id === FORM_BUILTIN) return form_builtin();
    if (!form_valid_id($id)) return null;
    $dir = FORMS_DIR . "/$id";
    $f = is_file("$dir/form.json") ? json_decode(file_get_contents("$dir/form.json"), true) : null;
    if (!$f) return null;
    return $f + [
        'id' => $id, 'builtin' => false, 'dir' => $dir,
        'template' => "$dir/template.xlsx", 'layout' => "$dir/layout.json", 'schema' => "$dir/schema.json",
    ];
}

function form_save(array $f): void
{
    if (!empty($f['builtin'])) throw new RuntimeException('The built-in version cannot be changed');
    if (!is_dir($f['dir'])) mkdir($f['dir'], 0700, true);
    $keep = array_diff_key($f, array_flip(['builtin', 'dir', 'template', 'layout', 'schema']));
    file_put_contents("$f[dir]/form.json", json_encode($keep, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

// All versions, newest first, the built-in one last.
function forms_list(): array
{
    $out = [];
    foreach (glob(FORMS_DIR . '/*/form.json') ?: [] as $file) {
        $f = form_get(basename(dirname($file)));
        if ($f) $out[] = $f;
    }
    usort($out, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
    $out[] = form_builtin();
    $active = form_active_id();
    foreach ($out as &$f) $f['status'] = $f['id'] === $active ? 'active' : (($f['status'] ?? '') === 'active' ? 'retired' : ($f['status'] ?? 'draft'));
    return $out;
}

function form_active_id(): string
{
    static $id = null;
    if ($id !== null) return $id;
    $a = is_file(FORMS_DIR . '/active.json') ? json_decode(file_get_contents(FORMS_DIR . '/active.json'), true) : null;
    $id = isset($a['id']) && form_get($a['id']) ? $a['id'] : FORM_BUILTIN;
    return $id;
}

function form_set_active(string $id): void
{
    if (!form_get($id)) throw new RuntimeException('Unknown form version');
    if (!is_dir(FORMS_DIR)) mkdir(FORMS_DIR, 0700, true);
    file_put_contents(FORMS_DIR . '/active.json', json_encode(['id' => $id, 'since' => date('c')]), LOCK_EX);
}

// The version the app is using now: the active one, or a draft an admin is testing (set with form_use()).
function form_current(): array
{
    return $GLOBALS['FORM_CURRENT'] ??= form_get(form_active_id()) ?? form_builtin();
}
function form_use(array $f): void
{
    $GLOBALS['FORM_CURRENT'] = $f;
    unset($GLOBALS['PDS_SCHEMA'], $GLOBALS['PDS_MAP'], $GLOBALS['PDS_LAYOUT_DATA']);
}

// Display name, e.g. "CS Form No. 212 (Revised 2026)".
function form_name(?array $f = null): string
{
    $f ??= form_current();
    return trim(($f['title'] ?? 'CS Form No. 212') . ' (' . ($f['revision'] ?? $f['id']) . ')');
}

// URL of a page image of the current version, with a version stamp for caching.
function form_page_url(int $n, ?array $f = null): string
{
    $f ??= form_current();
    if (!empty($f['builtin'])) {
        $file = __DIR__ . "/../public/pds/page$n.png";
        return "pds/page$n.png?v=" . (is_file($file) ? filemtime($file) : 0);
    }
    $file = "$f[dir]/page$n.png";
    return 'form-page.php?id=' . rawurlencode($f['id']) . "&n=$n&v=" . (is_file($file) ? filemtime($file) : 0);
}

// Preview layout (boxes per page) of the current version.
function form_layout(): array
{
    $f = form_current();
    return $GLOBALS['PDS_LAYOUT_DATA'] ??= (is_file($f['layout']) ? json_decode(file_get_contents($f['layout']), true) : ['aspect' => 1.4142, 'pt' => [], 'items' => []]);
}
