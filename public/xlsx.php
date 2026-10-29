<?php
// POST JSON exports a PDS to the official xlsx; POST with an uploaded file imports one.
require __DIR__ . '/../lib/pds-xlsx.php';
security_headers();

function fail(string $msg): void
{
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $msg]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('POST only');

try {
    if (isset($_FILES['file'])) {
        $f = $_FILES['file'];
        if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 5000000) fail('Upload failed or file too large');
        header('Content-Type: application/json');
        echo json_encode(['data' => pds_import(file_get_contents($f['tmp_name']))]);
    } else {
        $body = json_decode(file_get_contents('php://input', false, null, 0, MAX_BYTES), true);
        $bin = pds_export(pds_clean(is_array($body) ? $body : []));
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="PDS.xlsx"');
        echo $bin;
    }
} catch (Throwable $e) {
    fail('Could not process Excel file: ' . $e->getMessage());
}
