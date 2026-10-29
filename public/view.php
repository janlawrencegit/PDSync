<?php
require __DIR__ . '/../lib/pds-xlsx.php';
security_headers();

// POST renders an unsaved draft for the live preview, GET renders a saved PDS.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input', false, null, 0, MAX_BYTES), true);
    $d = pds_clean(is_array($body) ? $body : []);
} else {
    $rec = pds_read($_GET['id'] ?? '');
    if (!$rec) {
        http_response_code(404);
        exit('PDS not found.');
    }
    $d = $rec['data'];
}
$name = trim(($d['fields']['first_name'] ?? '') . ' ' . ($d['fields']['surname'] ?? ''));
$layout = json_decode(file_get_contents(__DIR__ . '/../lib/templates/pds-2026-layout.json'), true);
$types = pds_key_types();

// Resolves a layout item id (see tools/layout-targets.php) to the text shown in its box.
function item_value(array $d, array $types, string $id): string
{
    $p = explode(':', $id);
    $f = $d['fields'];
    switch ($p[0]) {
        case 'f':
            if ($p[1] === 'dual_country' && ($f['citizenship'] ?? '') !== 'Dual Citizenship') return '';
            $v = $f[$p[1]] ?? '';
            return pds_display($v, $types[$p[1]] ?? null);
        case 'c':
            return ($f[$p[1]] ?? '') === $p[2] ? '✓' : '';
        case 't':
            $v = $d['tables'][$p[1]][(int)$p[2]][$p[3]] ?? '';
            return pds_display($v, $types["$p[1].$p[3]"] ?? null);
        case 'e':
            foreach ($d['tables']['education'] ?? [] as $row) if (($row['level'] ?? '') === $p[1]) return $row[$p[2]] ?? '';
    }
    return '';
}

// Every table row follows its first row's alignment, since the template's rows are inconsistent.
$first = [];
foreach ($layout['items'] as $i) if (preg_match('/^t:(\w+):0:(\w+)$/', $i['id'], $m)) $first["$m[1]:$m[2]"] = $i;
$pages = [];
foreach ($layout['items'] as $i) {
    if (preg_match('/^t:(\w+):\d+:(\w+)$/', $i['id'], $m) && isset($first["$m[1]:$m[2]"])) {
        ['align' => $i['align'], 'valign' => $i['valign']] = $first["$m[1]:$m[2]"];
    }
    $pages[$i['page']][] = $i;
}
ksort($pages);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>PDS - <?= h($name ?: 'Untitled') ?></title>
<link rel="stylesheet" href="preview.css?v=<?= filemtime(__DIR__ . '/preview.css') ?>">
</head>
<body class="official<?= isset($_GET['embed']) ? ' embed' : '' ?>" style="--ar:<?= round($layout['aspect'], 5) ?>">
<div class="noprint bar"><button onclick="print()" class="primary">Print / Save as PDF</button></div>
<?php foreach ($pages as $n => $items): ?>
  <div class="pg" style="aspect-ratio:1/<?= round($layout['aspect'], 5) ?>">
    <img src="pds/page<?= $n ?>.png?v=<?= filemtime(__DIR__ . "/pds/page$n.png") ?>" alt="PDS page <?= $n ?>">
    <?php foreach ($items as $i):
        $v = item_value($d, $types, $i['id']);
        $box = "left:$i[x]%;top:$i[y]%;width:$i[w]%;height:$i[h]%";
        if (str_starts_with($i['id'], 's:')): if (($img = $d['fields'][substr($i['id'], 2)] ?? '') === '') continue; ?>
      <img class="ov pic pic-<?= substr($i['id'], 2) ?>" style="<?= $box ?>" src="<?= h($img) ?>" alt="<?= substr($i['id'], 2) ?>">
        <?php elseif ($i['id'] === 'f:dual_country'): ?>
      <div class="ov sel" style="<?= $box ?>;font-size:<?= round(10 * $layout['pt'][$n], 3) ?>cqw"><span><?= h($v) ?></span></div>
        <?php elseif (!empty($i['control'])): if ($v === '') continue; ?>
      <svg class="ov chk" style="<?= $box ?>" viewBox="0 0 10 10" aria-label="checked"><path d="M2 5.2 4.2 7.6 8.2 2.4"/></svg>
        <?php elseif ($v !== ''): ?>
      <div class="ov <?= "$i[align] v-$i[valign]" ?>" style="<?= $box ?>;font-size:<?= round(10 * $layout['pt'][$n], 3) ?>cqw"><span><?= h($v) ?></span></div>
        <?php endif; endforeach; ?>
  </div>
<?php endforeach; ?>
<script>
// Text wraps at 10pt and shrinks only when the wrapped lines still overflow the box.
// Sizes stay in cqw (relative to page width) so the fit holds at any zoom and in print.
document.fonts.ready.then(() => document.querySelectorAll('.ov > span').forEach((s) => {
  const box = s.parentElement;
  const base = parseFloat(box.style.fontSize);
  const over = () => s.offsetWidth > box.clientWidth + 1 || s.offsetHeight > box.clientHeight + 1;
  const fit = () => { for (let f = 0.97; over() && f >= 0.35; f -= 0.03) box.style.fontSize = `${(base * f).toFixed(4)}cqw`; };
  fit();
  // Last resort for text too long even when small: allow breaks inside words and fit again.
  if (over()) {
    box.classList.add('breakall');
    box.style.fontSize = `${base}cqw`;
    fit();
  }
}));
</script>
</body>
</html>
