<?php
// Copies PDS records saved as data/<id>.json (before MySQL) into the pds table; the files are left in place.
require __DIR__ . '/../lib/store.php';

$st = db()->prepare('INSERT IGNORE INTO pds (id, key_hash, user_id, form, name, data, created_at, updated_at) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)');
foreach (glob(DATA_DIR . '/*.json') as $file) {
    $rec = json_decode(file_get_contents($file), true);
    $id = basename($file, '.json');
    if (!pds_valid_id($id) || !isset($rec['key_hash'], $rec['data'])) continue;
    $at = fn($k) => date('Y-m-d H:i:s', strtotime($rec[$k] ?? 'now'));
    $st->execute([$id, $rec['key_hash'], FORM_ID, '', json_encode(pds_clean($rec['data'])), $at('created'), $at('updated')]);
    echo "$id: " . ($st->rowCount() ? 'imported' : 'already there') . "\n";
}
