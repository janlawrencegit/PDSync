<?php
require __DIR__ . '/../lib/pds-xlsx.php';
require_once __DIR__ . '/../lib/admin.php';
security_headers();
// Admins test a draft form version with ?form=<id> (public/forms.php).
if (isset($_GET['form']) && admin_allowed() && ($draft = form_get((string)$_GET['form']))) form_use($draft);

// Renders the posted draft for the live preview only; saved PDS are never served by link.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Not available.');
}
require_same_origin();
$body = json_decode(file_get_contents('php://input', false, null, 0, MAX_BYTES), true);
$d = pds_clean(is_array($body) ? $body : []);
$name = trim(($d['fields']['first_name'] ?? '') . ' ' . ($d['fields']['surname'] ?? ''));
$layout = form_layout();
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

// Continuation pages (official sheets C5-C11) for rows that do not fit on pages 1-4.
const CONT_TITLES = [
    'children' => ['FAMILY BACKGROUND (Continuation)', 'NAME of CHILDREN (Write full name and list all)'],
    'education' => ['EDUCATIONAL BACKGROUND (Continuation)', ''],
    'eligibility' => ['CIVIL SERVICE ELIGIBILITY (Continuation)', ''],
    'work' => ['WORK EXPERIENCE (Continuation)', '(Include private employment. Start from your recent work)'],
    'learning' => ['LEARNING AND DEVELOPMENT (L&D) INTERVENTIONS/TRAINING PROGRAMS ATTENDED (Continuation)', ''],
    'voluntary' => ['VOLUNTARY WORK OR INVOLVEMENT IN CIVIC / NON-GOVERNMENT / PEOPLE / VOLUNTARY ORGANIZATION/S (Continuation)', ''],
    'skills' => ['OTHER INFORMATION (Continuation)', 'SPECIAL SKILLS and HOBBIES'],
    'recognitions' => ['OTHER INFORMATION (Continuation)', 'NON-ACADEMIC DISTINCTIONS / RECOGNITION'],
    'memberships' => ['OTHER INFORMATION (Continuation)', 'MEMBERSHIP IN ASSOCIATION/ORGANIZATION'],
];
$columns = [];
foreach (pds_schema() as $sec) foreach ($sec['tables'] ?? [] as $t) $columns[$t['k']] = $t['c'];
$overflow = pds_overflow($d);
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
<body class="official embed" style="--ar:<?= round($layout['aspect'], 5) ?>">
<?php foreach ($pages as $n => $items): ?>
  <div class="pg" style="aspect-ratio:1/<?= round($layout['aspect'], 5) ?>">
    <img src="<?= h(form_page_url($n)) ?>" alt="PDS page <?= $n ?>">
    <?php foreach ($items as $i):
        $v = item_value($d, $types, $i['id']);
        $box = "left:$i[x]%;top:$i[y]%;width:$i[w]%;height:$i[h]%";
        $id = h($i['id']);
        $img = str_starts_with($i['id'], 's:') ? ($d['fields'][substr($i['id'], 2)] ?? '') : '';
        if ($img !== ''): ?>
      <img class="ov pic pic-<?= substr($i['id'], 2) ?>" data-id="<?= $id ?>" style="<?= $box ?>" src="<?= h($img) ?>" alt="<?= substr($i['id'], 2) ?>">
        <?php elseif ($i['id'] === 'f:dual_country' && $v !== ''): ?>
      <div class="ov sel" data-id="<?= $id ?>" style="<?= $box ?>;font-size:<?= round(10 * $layout['pt'][$n], 3) ?>cqw"><span><?= h($v) ?></span></div>
        <?php elseif (!empty($i['control']) && $v !== ''): ?>
      <svg class="ov chk" data-id="<?= $id ?>" style="<?= $box ?>" viewBox="0 0 10 10" aria-label="checked"><path d="M2 5.2 4.2 7.6 8.2 2.4"/></svg>
        <?php elseif ($v !== '' && empty($i['control']) && !str_starts_with($i['id'], 's:')): ?>
      <div class="ov <?= "$i[align] v-$i[valign]" ?>" data-id="<?= $id ?>" style="<?= $box ?>;font-size:<?= round(10 * $layout['pt'][$n], 3) ?>cqw"><span><?= h($v) ?></span></div>
        <?php else: ?>
      <div class="hit" data-id="<?= $id ?>" style="<?= $box ?>"></div>
        <?php endif; endforeach; ?>
  </div>
<?php endforeach; ?>
<?php foreach ($overflow as $t => $rows): [$title, $sub] = CONT_TITLES[$t]; ?>
  <div class="pg cont" style="aspect-ratio:1/<?= round($layout['aspect'], 5) ?>">
    <div class="cont-in">
      <p class="cont-form"><?= h(strtoupper(form_current()['title']) . ' (' . form_current()['revision'] . ')') ?></p>
      <h2><?= h($title) ?></h2>
      <?php if ($sub): ?><p class="cont-sub"><?= h($sub) ?></p><?php endif; ?>
      <table>
        <thead><tr><?php foreach ($columns[$t] as $c): ?><th><?= h(strtoupper($c['l'])) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r => $row): ?>
          <tr><?php foreach ($columns[$t] as $c): ?><td data-id="<?= h("t:$t:$r:$c[k]") ?>"><?= h(pds_display($row[$c['k']] ?? '', $types["$t.$c[k]"] ?? null)) ?></td><?php endforeach; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="cont-sign">
        <div><span>SIGNATURE</span><b><?php if ($d['fields']['signature'] ?? ''): ?><img src="<?= h($d['fields']['signature']) ?>" alt="signature"><?php endif; ?></b></div>
        <div><span>DATE</span><b><?= h(pds_display($d['fields']['date_accomplished'] ?? '', 'date')) ?></b></div>
      </div>
    </div>
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
