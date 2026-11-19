<?php
// POST JSON exports a PDS to the official xlsx; POST with an uploaded file imports one.
require __DIR__ . '/../lib/pds-xlsx.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/admin.php';
security_headers();

function fail(string $msg): void
{
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only');
// Admins test a draft form version with ?form=<id> (public/forms.php).
if (isset($_GET['form']) && admin_allowed() && ($draft = form_get((string)$_GET['form']))) form_use($draft);
if (!rate_ok('xlsx', 120, 3600)) fail('Too many Excel requests from this network. Please try again later.');

try {
    if (isset($_FILES['file'])) {
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5000000) fail('Upload failed or file too large');
        header('Content-Type: application/json');
        $data = pds_import(file_get_contents($f['tmp_name']));
        stats_add('import_xlsx');
        echo json_encode(['data' => $data]);
    } else {
        $body = json_decode(file_get_contents('php://input', false, null, 0, MAX_BYTES), true);
        $bin = pds_export(pds_clean(is_array($body) ? $body : []));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="PDS.xlsx"');
        stats_add('export_xlsx');
        echo $bin;
    }
} catch (Throwable $e) {
    // Details go to the log only; they can contain server paths.
    error_log((string)$e);
    fail(isset($_FILES['file']) ? 'This file could not be read. Use a filled-out ' . form_name() . ' .xlsx file.' : 'The Excel file could not be created. Please try again.');
}
