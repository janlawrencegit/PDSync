<?php
// Prints every template cell and form control the preview overlays, as JSON for build-layout.ps1.
require __DIR__ . '/../lib/pds-xlsx.php';

$out = [];
$add = function ($id, $addr, $kind = 'cell') use (&$out) {
    $out[] = ['id' => $id, 'sheet' => pds_ref($addr)[0], 'ref' => pds_ref($addr)[1], 'kind' => $kind];
};

foreach (PDS_FIELDS as $k => $addrs) foreach (explode(' ', $addrs) as $a) $add("f:$k", $a);
foreach (PDS_CHECKS as $k => $opts) foreach ($opts as $opt => $a) $add("c:$k:$opt", $a, 'control');
$add('f:dual_country', PDS_COUNTRY[0] . '!' . PDS_COUNTRY[1], 'control');
foreach (PDS_TABLES as $t => [$sheet, $start, $cols]) {
    for ($i = 0; $i < pds_max_rows($t); $i++) foreach ($cols as $c => $col) $add("t:$t:$i:$c", "$sheet!$col" . ($start + $i));
}
foreach (PDS_IMAGES as $k => $addrs) foreach ($addrs as $a) {
    [$sheet, $ref] = pds_ref($a);
    $out[] = str_starts_with($ref, 'shape:')
        ? ['id' => "s:$k", 'sheet' => $sheet, 'ref' => substr($ref, 6), 'kind' => 'shape']
        : ['id' => "s:$k", 'sheet' => $sheet, 'ref' => $ref, 'kind' => 'cell'];
}
foreach (PDS_EDU_ROWS as $level => $r) foreach (PDS_EDU_COLS as $c => $col) $add("e:$level:$c", "C1!$col$r");

echo json_encode($out, JSON_PRETTY_PRINT);
