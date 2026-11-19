<?php
// Preview layout of a form version computed from its template, for versions added from the admin page (the built-in
// Revised 2026 layout was measured with Excel by tools/build-layout.ps1). Boxes come from cell geometry
// (lib/xlsx-geometry.php); each page then gets a calibration that maps template points to the page image:
// x% = sx * points + ox, y% = sy * points + oy, fitted to the image by the admin page (public/forms.js).
require_once __DIR__ . '/pds-xlsx.php';
require_once __DIR__ . '/xlsx-geometry.php';
require_once __DIR__ . '/form-analyze.php';

// Main sheets of a map in workbook order: the sheets that hold the form's own boxes (not continuation sheets).
function fl_pages(array &$b, array $map): array
{
    $used = [];
    $add = function ($addr) use (&$used) { $used[pds_ref($addr)[0]] = true; };
    foreach ($map['fields'] as $a) foreach (explode(' ', $a) as $x) $add($x);
    foreach ($map['checks'] as $opts) foreach ($opts as $x) $add($x);
    foreach ($map['tables'] as [$s]) $used[$s] = true;
    if ($map['edu_rows']) $used[$map['edu_sheet']] = true;
    foreach ($map['images'] as $list) foreach ($list as $x) $add($x);
    return array_values(array_filter(array_keys($b['sheets']), fn($s) => isset($used[$s])));
}

// Style of a cell: font size and name, alignment and wrap, from styles.xml.
function fl_style(array &$b, string $sheet, string $ref): array
{
    static $cache = [];
    $styles = $b['z']['xl/styles.xml'];
    $key = crc32($styles) . ':' . strlen($styles);
    $cache['fonts'][$key] ??= (function () use ($styles) {
        preg_match('#<fonts[^>]*>(.*?)</fonts>#s', $styles, $f);
        preg_match_all('#<font>(.*?)</font>|<font/>#s', $f[1] ?? '', $m);
        return array_map(fn($x) => [preg_match('#<sz val="([\d.]+)"#', $x, $s) ? (float)$s[1] : 11, preg_match('#<name val="([^"]+)"#', $x, $n) ? $n[1] : 'Arial'], $m[1]);
    })();
    $cache['xfs'][$key] ??= (function () use ($styles) {
        preg_match('#<cellXfs[^>]*>(.*?)</cellXfs>#s', $styles, $x);
        preg_match_all('#<xf ([^>]*?)(?:/>|>(.*?)</xf>)#s', $x[1] ?? '', $m, PREG_SET_ORDER);
        return array_map(function ($xf) {
            $a = $xf[2] ?? '';
            return [
                'font' => preg_match('#fontId="(\d+)"#', $xf[1], $f) ? (int)$f[1] : 0,
                'h' => preg_match('#horizontal="(\w+)"#', $a, $h) ? $h[1] : 'general',
                'v' => preg_match('#vertical="(\w+)"#', $a, $v) ? $v[1] : 'bottom',
                'wrap' => str_contains($a, 'wrapText="1"'),
            ];
        }, $m);
    })();
    $xml = $b['z'][$b['sheets'][$sheet]];
    $s = preg_match('#<c r="' . $ref . '"[^>]*\bs="(\d+)"#', $xml, $m) ? (int)$m[1] : 0;
    $xf = $cache['xfs'][$key][$s] ?? ['font' => 0, 'h' => 'general', 'v' => 'bottom', 'wrap' => false];
    [$size, $face] = $cache['fonts'][$key][$xf['font']] ?? [11, 'Arial'];
    return [
        'font' => $size, 'face' => $face,
        'align' => in_array($xf['h'], ['center', 'centerContinuous'], true) ? 'center' : ($xf['h'] === 'right' ? 'right' : 'left'),
        'valign' => $xf['v'] === 'top' ? 'top' : ($xf['v'] === 'center' ? 'center' : 'bottom'),
        'wrap' => $xf['wrap'],
    ];
}

// Point position of a drawing anchor {col, colOff, row, rowOff} (zero-based, EMU offsets), relative to the print area.
function fl_anchor_pt(array $g, array $a): array
{
    [$c0, $r0] = $g['area'];
    $x = 0;
    for ($c = $c0; $c <= $a[0]; $c++) $x += $g['cols'][$c] ?? $g['defW'];
    $y = 0;
    for ($r = $r0; $r <= $a[2]; $r++) $y += $g['rows'][$r] ?? $g['defH'];
    return [$x + $a[1] / 12700, $y + $a[3] / 12700];
}

// A drawn shape by name (the photo frame): its box in points and the cell its top-left corner sits in.
function fl_shape(array &$b, string $sheet, string $name, array $g): ?array
{
    $rels = xlsx_rels($b['z'], $b['sheets'][$sheet]);
    foreach ($rels as [$type, $path]) {
        if ($type !== 'drawing' || !isset($b['z'][$path])) continue;
        $xml = $b['z'][$path];
        $at = strpos($xml, 'name="' . $name . '"');
        if ($at === false) continue;
        // The anchor that holds this shape is the last one that opens before its name.
        $start = strrpos(substr($xml, 0, $at), '<xdr:twoCellAnchor');
        if ($start === false) continue;
        foreach ([substr($xml, $start, $at - $start)] as $chunk) {
            $p = [];
            foreach (['from', 'to'] as $k) {
                if (!preg_match("#<xdr:$k><xdr:col>(\d+)</xdr:col><xdr:colOff>(-?\d+)</xdr:colOff><xdr:row>(\d+)</xdr:row><xdr:rowOff>(-?\d+)</xdr:rowOff></xdr:$k>#", $chunk, $m)) continue 2;
                $p[$k] = array_map('intval', array_slice($m, 1));
            }
            [$x0, $y0] = fl_anchor_pt($g, $p['from']);
            [$x1, $y1] = fl_anchor_pt($g, $p['to']);
            return ['pt' => [$x0, $y0, $x1 - $x0, $y1 - $y0], 'anchor' => [xg_letters($p['from'][0] + 1) . ($p['from'][2] + 1), $p['from'][1] / 12700, $p['from'][3] / 12700]];
        }
    }
    return null;
}

// Raw page geometry for calibration: column and row edges in points over the print area, and the default calibration
// from the sheet's own page setup.
function fl_geometry(string $templateBin, array $map, ?float $aspect = null): array
{
    $b = xlsx_open($templateBin);
    $out = [];
    foreach (fl_pages($b, $map) as $i => $sheet) {
        $g = xg_sheet($b, $sheet);
        // The page images may be on other paper than the sheet asks for (Excel prints on the printer's paper); use
        // the standard paper whose shape matches the images.
        if ($aspect) {
            $best = null;
            foreach (XLSX_PAPER as $paper) if (!$best || abs($paper[1] / $paper[0] - $aspect) < abs($best[1] / $best[0] - $aspect)) $best = $paper;
            $g['paper'] = $best;
        }
        $p = xg_page($g);
        [$c0, $r0, $c1, $r1] = $g['area'];
        $xs = [0];
        for ($c = $c0; $c <= $c1; $c++) $xs[] = end($xs) + ($g['cols'][$c] ?? $g['defW']);
        $ys = [0];
        for ($r = $r0; $r <= $r1; $r++) $ys[] = end($ys) + ($g['rows'][$r] ?? $g['defH']);
        [$PW, $PH] = $g['paper'];
        $out[$i + 1] = [
            'sheet' => $sheet, 'paper' => $g['paper'], 'cols' => $xs, 'rows' => $ys,
            'calib' => ['sx' => 100 * $p['s'] / $PW, 'ox' => 100 * $p['x'] / $PW, 'sy' => 100 * $p['s'] / $PH, 'oy' => 100 * $p['y'] / $PH],
        ];
    }
    return $out;
}

// Builds layout.json data for a version: every box of the map, placed with each page's calibration.
function fl_build(string $templateBin, array $map, array $schema, array $calib, ?float $aspect = null): array
{
    $b = xlsx_open($templateBin);
    $pages = fl_pages($b, $map);
    $pageOf = array_flip($pages);
    $geo = $raw = [];
    foreach ($pages as $s) {
        $geo[$s] = xg_sheet($b, $s);
        $raw[$s] = ['s' => 1, 'x' => 0, 'y' => 0];
    }
    $defaults = fl_geometry($templateBin, $map, $aspect);
    $items = [];
    $box = function (string $s, string $ref, ?string $widthFrom = null) use (&$geo, &$raw) {
        $g = $geo[$s];
        $g['paper'] = [100, 100];
        $bx = xg_box($g, $raw[$s], $ref);
        if ($bx && $widthFrom) {
            $w = xg_box($g, $raw[$s], $widthFrom);
            if ($w) $bx['w'] = $w['w'];
        }
        return $bx;
    };
    $place = function (string $id, string $s, array $bx, array $extra = []) use (&$items, $pageOf, $calib, $defaults) {
        $n = $pageOf[$s] + 1;
        $c = $calib[$n] ?? $defaults[$n]['calib'];
        $items[] = ['id' => $id, 'page' => $n,
            'x' => round($c['sx'] * $bx['x'] + $c['ox'], 3), 'y' => round($c['sy'] * $bx['y'] + $c['oy'], 3),
            'w' => round($c['sx'] * $bx['w'], 3), 'h' => round($c['sy'] * $bx['h'], 3)] + $extra;
    };
    $text = function (string $id, string $s, string $ref, ?string $widthFrom = null) use (&$b, $box, $place, $calib, $defaults, $pageOf) {
        $bx = $box($s, $ref, $widthFrom);
        if (!$bx) return;
        $st = fl_style($b, $s, $ref);
        $sx = ($calib[$pageOf[$s] + 1] ?? $defaults[$pageOf[$s] + 1]['calib'])['sx'];
        $place($id, $s, $bx, ['font' => round($st['font'] * $sx, 3), 'face' => $st['face'], 'align' => $st['align'], 'valign' => $st['valign'], 'wrap' => $st['wrap']]);
    };

    foreach ($map['fields'] as $k => $addrs) foreach (explode(' ', $addrs) as $a) {
        [$s, $ref] = pds_ref($a);
        if (isset($pageOf[$s])) $text("f:$k", $s, $ref);
    }
    if ($map['country']) {
        [$s, $ref] = $map['country'];
        if (isset($pageOf[$s]) && ($bx = $box($s, $ref))) $place('f:dual_country', $s, $bx, ['control' => true]);
    }
    // Checkboxes: a square at the left of the control, the size of the box drawn by Excel.
    $controls = [];
    foreach ($map['checks'] as $k => $opts) foreach ($opts as $opt => $a) {
        [$s, $ref] = pds_ref($a);
        if (!isset($pageOf[$s])) continue;
        $controls[$s] ??= fa_controls($b, $s);
        $ctl = $controls[$s][$ref] ?? null;
        if ($ctl && isset($ctl['anchor']['from'], $ctl['anchor']['to'])) {
            [$x0, $y0] = fl_anchor_pt($geo[$s], $ctl['anchor']['from']);
            [, $y1] = fl_anchor_pt($geo[$s], $ctl['anchor']['to']);
            $size = min(9, max(5, $y1 - $y0 - 2));
            $bx = ['x' => $x0 + 2.5, 'y' => ($y0 + $y1) / 2 - $size / 2, 'w' => $size, 'h' => $size];
        } else {
            $cell = $box($s, $ref);
            if (!$cell) continue;
            $bx = ['x' => $cell['x'] + 2, 'y' => $cell['y'] + $cell['h'] / 2 - 4, 'w' => 8, 'h' => 8];
        }
        $place("c:$k:$opt", $s, $bx, ['control' => true]);
    }
    $maxOf = [];
    foreach ($schema as $sec) foreach ($sec['tables'] ?? [] as $t) $maxOf[$t['k']] = $t['max'];
    foreach ($map['tables'] as $t => [$s, $start, $cols]) {
        if (!isset($pageOf[$s])) continue;
        for ($i = 0; $i < ($maxOf[$t] ?? 0); $i++) foreach ($cols as $c => $col) $text("t:$t:$i:$c", $s, $col . ($start + $i), $col . $start);
    }
    foreach ($map['edu_rows'] as $level => $r) foreach ($map['edu_cols'] as $c => $col) {
        if (isset($pageOf[$map['edu_sheet']])) $text("e:$level:$c", $map['edu_sheet'], $col . $r);
    }
    foreach ($map['images'] as $k => $list) foreach ($list as $a) {
        [$s, $ref] = pds_ref($a);
        if (!isset($pageOf[$s])) continue;
        if (str_starts_with($ref, 'shape:')) {
            $sh = fl_shape($b, $s, substr($ref, 6), $geo[$s]);
            if (!$sh) continue;
            [$x, $y, $w, $h] = $sh['pt'];
            $place("s:$k", $s, ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h], ['pt' => $sh['pt'], 'anchor' => $sh['anchor']]);
            continue;
        }
        $bx = $box($s, $ref);
        if (!$bx) continue;
        [$cc, $rr] = xg_ref($ref);
        [$ac0, $ar0] = fa_area($geo[$s], $cc, $rr);
        $place("s:$k", $s, $bx, ['pt' => [$bx['x'], $bx['y'], $bx['w'], $bx['h']], 'anchor' => [xg_letters($ac0) . $ar0, 0, 0]]);
    }

    $pt = [];
    foreach ($pages as $i => $s) $pt[$i + 1] = round(($calib[$i + 1] ?? $defaults[$i + 1]['calib'])['sx'], 4);
    $first = $defaults[1]['paper'] ?? [595.28, 841.89];
    return ['aspect' => $aspect ?? $first[1] / $first[0], 'pt' => $pt, 'pages' => count($pages), 'items' => $items];
}
