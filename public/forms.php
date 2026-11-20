<?php
// Admin: form versions. Lists the versions and adds revised forms (upload template -> page images -> line up the
// boxes -> check the questions -> test -> make active). The page is drawn by forms.js from ?api=... JSON calls.
require __DIR__ . '/../lib/form-admin.php';
require __DIR__ . '/../lib/form-paint.php';
require_once __DIR__ . '/../lib/admin.php';
require_once __DIR__ . '/../lib/admin-nav.php';

security_headers();
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$allowed = admin_allowed();
// Who the header's account menu shows: the Google admin, or the password admin of the admin session.
$navGoogle = admin_google() !== null;
$navWho = admin_google() ?? ($_SESSION['admin'] ?? null);
$api = $_GET['api'] ?? null;

if ($api !== null) {
    header('Content-Type: application/json');
    $reply = function (int $code, array $body) {
        http_response_code($code);
        echo json_encode($body);
        exit;
    };
    if (!$allowed) $reply(403, ['error' => 'Admins only']);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') require_same_origin();
    $f = isset($_GET['id']) ? form_get((string)$_GET['id']) : null;
    if (isset($_GET['id']) && !$f) $reply(404, ['error' => 'Unknown form version']);
    $body = $_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'json')
        ? (json_decode(file_get_contents('php://input', false, null, 0, 2000000), true) ?: []) : [];
    $editable = fn() => $f && empty($f['builtin']) ?: $reply(400, ['error' => 'The built-in version cannot be changed.']);
    // Changes to form versions are recorded in the admin activity log.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($api, ['calib', 'paint'], true)) {
        admin_log('Form versions: ' . preg_replace('/[^a-z-]/', '', (string)$api), (string)($_GET['id'] ?? ($_POST['name'] ?? '')));
    }
    try {
        switch ($api) {
            case 'list':
                $reply(200, ['active' => form_active_id(), 'forms' => array_map(fn($x) => [
                    'id' => $x['id'], 'name' => form_name($x), 'revision' => $x['revision'] ?? '', 'status' => $x['status'],
                    'builtin' => !empty($x['builtin']), 'created' => $x['created'] ?? null, 'images' => $x['images'] ?? ($x['builtin'] ? 4 : 0),
                    'pds' => fv_pds_count($x['id']), 'based_on' => $x['based_on'] ?? null,
                ], forms_list())]);
            case 'create':
                if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) $reply(400, ['error' => 'Choose the revised form\'s Excel file.']);
                if (!preg_match('/\.xlsx$/i', $_FILES['file']['name'])) $reply(400, ['error' => 'The template must be an .xlsx file.']);
                $id = fv_create(file_get_contents($_FILES['file']['tmp_name']), (string)($_POST['revision'] ?? ''), $_FILES['file']['name']);
                $reply(200, ['id' => $id]);
            case 'data':
                $schema = json_decode(file_get_contents($f['schema']), true);
                $items = array_map(fn($it, $i) => $it + ['index' => $i, 'label' => fv_item_label($it['id'], $schema)], $f['report']['items'] ?? [], array_keys($f['report']['items'] ?? []));
                $layout = form_get($f['id']) === null ? [] : (function () use ($f) { form_use($f); return form_layout(); })();
                $reply(200, [
                    'id' => $f['id'], 'name' => form_name($f), 'revision' => $f['revision'] ?? '', 'status' => $f['id'] === form_active_id() ? 'active' : ($f['status'] ?? 'draft'),
                    'builtin' => !empty($f['builtin']), 'source' => $f['source'] ?? null, 'images' => $f['images'] ?? 0, 'images_from' => $f['images_from'] ?? null,
                    'pages' => array_map(fn($n) => form_page_url($n, $f), range(1, max(1, $f['images'] ?? 0))),
                    'items' => $items, 'added' => $f['report']['added'] ?? [], 'gone' => $f['report']['gone'] ?? [],
                    'calib' => $f['calib'] ?? [], 'boxes' => $f['boxes'] ?? [], 'geometry' => empty($f['builtin']) ? fv_geometry($f) : [],
                    'layout' => $layout, 'sections' => array_map(fn($s) => ['id' => $s['id'], 'title' => $s['title']], $schema),
                    'pds' => fv_pds_count($f['id']),
                ]);
            case 'pages':
                $editable();
                $images = [];
                for ($i = 1; isset($_FILES["page$i"]); $i++) {
                    if ($_FILES["page$i"]['error'] !== UPLOAD_ERR_OK) $reply(400, ['error' => "Page $i did not upload."]);
                    $images[] = file_get_contents($_FILES["page$i"]['tmp_name']);
                }
                if (!$images) $reply(400, ['error' => 'No page images were sent.']);
                fv_save_pages($f, $images, ($_POST['source'] ?? '') === 'excel' ? 'excel' : 'pdf');
                $reply(200, ['ok' => true]);
            case 'paint':
                $editable();
                // A4, like the official page images, so the drawn pages and the boxes share one calibration.
                $reply(200, fp_paint(file_get_contents($f['template']), $f['map'], 1.41421));
            case 'calib':
                $editable();
                $calib = [];
                foreach ((array)($body['calib'] ?? []) as $n => $c) $calib[(int)$n] = array_map('floatval', array_intersect_key((array)$c, array_flip(['sx', 'ox', 'sy', 'oy'])));
                $boxes = [];
                foreach ((array)($body['boxes'] ?? []) as $k => $bx) $boxes[(string)$k] = array_map('floatval', array_intersect_key((array)$bx, array_flip(['x', 'y', 'w', 'h'])));
                $f['calib'] = $calib;
                $f['boxes'] = $boxes;
                $f['lined_up'] = date('c');
                form_save($f);
                fv_rebuild($f);
                $reply(200, ['ok' => true]);
            case 'ref':
                $editable();
                fv_set_ref($f, (int)($body['index'] ?? -1), isset($body['ref']) && $body['ref'] !== '' ? (string)$body['ref'] : null);
                $reply(200, ['ok' => true]);
            case 'add':
                $editable();
                fv_add_field($f, (string)($body['section'] ?? ''), (string)($body['label'] ?? ''), (string)($body['type'] ?? 'text'), (string)($body['ref'] ?? ''));
                $reply(200, ['ok' => true]);
            case 'drop':
                $editable();
                fv_drop_field($f, (string)($body['key'] ?? ''));
                $reply(200, ['ok' => true]);
            case 'rename':
                $editable();
                $rev = trim((string)($body['revision'] ?? ''));
                if ($rev === '') $reply(400, ['error' => 'Give the revision a name.']);
                $f['revision'] = substr($rev, 0, 60);
                form_save($f);
                $reply(200, ['ok' => true]);
            case 'activate':
                if (empty($f['builtin']) && !($f['images'] ?? 0)) $reply(400, ['error' => 'Add the page images first.']);
                form_set_active($f['id']);
                if (empty($f['builtin'])) {
                    $f['status'] = 'active';
                    form_save($f);
                }
                $reply(200, ['ok' => true]);
            case 'delete':
                fv_delete($f);
                $reply(200, ['ok' => true]);
            case 'sample':
                form_use($f);
                $reply(200, ['data' => fv_sample(pds_schema())]);
        }
        $reply(400, ['error' => 'Unknown action']);
    } catch (Throwable $e) {
        error_log((string)$e);
        $reply(400, ['error' => $e instanceof RuntimeException ? $e->getMessage() : 'Something went wrong. Please try again.']);
    }
}

if (!$allowed) {
    header('Location: admin.php');
    exit;
}
$v = fn($f) => filemtime(__DIR__ . "/$f");
$icon = fn($d, $cls = 'size-4') => '<svg class="' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Form versions - PDSync Admin</title>
<script nonce="<?= csp_nonce() ?>">try { const t = localStorage.getItem('pds-theme'); if (t) document.documentElement.dataset.theme = t; } catch {}</script>
<link rel="preload" href="fonts/geist-latin-wght-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="fonts/instrument-serif-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="app.css?v=<?= $v('app.css') ?>">
<link rel="icon" href="icon.svg" type="image/svg+xml">
</head>
<body class="flex min-h-screen flex-col bg-base-200 text-base-content antialiased">
<?php admin_nav('forms', $navWho, $navGoogle); ?>
<main id="fv" class="mx-auto grid w-full max-w-6xl flex-1 content-start gap-5 p-4 sm:p-6" data-id="<?= h((string)($_GET['id'] ?? '')) ?>"></main>
<?php site_footer(); ?>
<div id="toasts" class="toast toast-end toast-bottom z-50" aria-live="polite"></div>
<dialog id="dlg-ask" class="modal">
  <form method="dialog" class="modal-box max-w-md">
    <h3 id="ask-title" class="text-lg font-semibold"></h3>
    <div id="ask-body" class="mt-2 text-sm text-base-content/75"></div>
    <input id="ask-input" class="input mt-4 w-full" hidden>
    <div id="ask-actions" class="modal-action"></div>
  </form>
</dialog>
<dialog id="dlg-add" class="modal">
  <form method="dialog" class="modal-box max-w-md" id="add-form">
    <h3 class="text-lg font-semibold">Add a question</h3>
    <p class="mt-1 text-sm text-base-content/70">For a question the new form asks that the current one does not.</p>
    <div class="mt-4 grid gap-3">
      <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/70">Question as people will see it</span><input name="label" class="input w-full" required></label>
      <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/70">Section</span><select name="section" class="select w-full"></select></label>
      <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/70">Answer type</span>
        <select name="type" class="select w-full"><option value="text">Text</option><option value="date">Date</option><option value="yn">Yes or No</option></select></label>
      <label class="grid gap-1.5"><span class="text-xs font-medium text-base-content/70">Cell for the answer in the template (Sheet!Cell)</span><input name="ref" class="input w-full" placeholder="C4!H52" required></label>
    </div>
    <div class="modal-action">
      <button class="btn btn-ghost" value="">Cancel</button>
      <button class="btn btn-primary" value="add">Add question</button>
    </div>
  </form>
</dialog>
<dialog id="dlg-try" class="modal">
  <div class="modal-box flex h-[90vh] w-[min(1000px,96vw)] max-w-none flex-col">
    <h3 class="text-lg font-semibold">Preview with sample answers</h3>
    <p class="text-sm text-base-content/60">Every question is filled with sample text, so you can see where each answer lands.</p>
    <iframe id="try-frame" title="Sample preview" class="mt-3 w-full flex-1 rounded-box border border-base-300 bg-[#e9ebee]"></iframe>
    <form method="dialog" class="modal-action"><button class="btn">Close</button></form>
  </div>
</dialog>
<script src="ui.js?v=<?= $v('ui.js') ?>"></script>
<script src="form-calib.js?v=<?= $v('form-calib.js') ?>"></script>
<script src="forms.js?v=<?= $v('forms.js') ?>"></script>
</body>
</html>
