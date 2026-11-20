<?php
// Work Experience Sheet in the official CSC template (lib/wes-docx.php), as PDF or Word.
//   GET  ?formats                                         ->  {"pdf": true|false} (PDF needs LibreOffice)
//   POST {entries: [...], name, date, file, format}       ->  .pdf (format "pdf") or .docx download
// Nothing is stored: the entries come from the editor and go straight into the file.
require __DIR__ . '/../lib/config.php';
require __DIR__ . '/../lib/wes-docx.php';
require_once __DIR__ . '/../lib/store.php';

security_headers();
json_errors();
$fail = function (int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: application/json');
    exit(json_encode(['error' => $msg]));
};

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['formats'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    exit(json_encode(['pdf' => wes_soffice() !== null]));
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') $fail(405, 'Method not allowed');
require_same_origin();
if (!rate_ok('wes', 120, 3600)) $fail(429, 'Too many downloads from this network. Please try again later.');

$body = json_decode(file_get_contents('php://input', false, null, 0, 400000), true);
$entries = wes_clean($body['entries'] ?? []);
if (!$entries) $fail(400, 'Add at least one entry first.');
$line = fn($v) => is_scalar($v) && preg_match('/^.{0,120}/su', trim(preg_replace('/[\x00-\x1f]/', '', (string)$v)), $m) ? $m[0] : '';
$file = preg_replace('/[\\\\\/:*?"<>|]+/', '', $line($body['file'] ?? '')) ?: 'Work Experience Sheet';
$docx = wes_docx($entries, mb_strtoupper($line($body['name'] ?? '')), $line($body['date'] ?? ''));

if (($body['format'] ?? '') === 'pdf') {
    try {
        $pdf = wes_pdf($docx);
    } catch (RuntimeException $e) {
        $fail(503, $e->getMessage());
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $file . ' - Work Experience Sheet.pdf"');
    header('Cache-Control: no-store');
    exit($pdf);
}
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $file . ' - Work Experience Sheet.docx"');
header('Cache-Control: no-store');
echo $docx;
