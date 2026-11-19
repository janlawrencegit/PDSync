<?php
// Page geometry of an xlsx sheet without Excel: where each cell lands on the printed page, from column widths, row
// heights, merged cells, the print area and the page setup (paper, margins, fit to page, centering). Used to place
// the preview boxes of a new form revision. Positions are percentages of the page, like the layout files.
require_once __DIR__ . '/xlsx.php';

// Paper sizes in points (width, height), portrait, by Excel's paperSize code.
const XLSX_PAPER = [1 => [612, 792], 5 => [612, 1008], 9 => [595.28, 841.89], 14 => [612, 936]];

// Column letters to number (A = 1) and back.
function xg_col(string $letters): int
{
    $n = 0;
    foreach (str_split(strtoupper($letters)) as $c) $n = $n * 26 + ord($c) - 64;
    return $n;
}
function xg_letters(int $n): string
{
    $s = '';
    for (; $n > 0; $n = intdiv($n - 1, 26)) $s = chr(65 + ($n - 1) % 26) . $s;
    return $s;
}
function xg_ref(string $ref): array
{
    preg_match('/^\$?([A-Z]+)\$?(\d+)$/i', $ref, $m);
    return [xg_col($m[1]), (int)$m[2]];
}

// Maximum digit width in pixels of the workbook's Normal font, which Excel uses to turn column widths into pixels.
function xg_digit_width(array &$b): float
{
    $styles = $b['z']['xl/styles.xml'] ?? '';
    preg_match('#<fonts[^>]*>\s*<font>(.*?)</font>#s', $styles, $f);
    preg_match('#<sz val="([\d.]+)"#', $f[1] ?? '', $sz);
    preg_match('#<name val="([^"]+)"#', $f[1] ?? '', $nm);
    $size = (float)($sz[1] ?? 11);
    $name = strtolower($nm[1] ?? 'calibri');
    // Measured digit widths at 96 dpi for common Normal fonts; others scale from Calibri.
    $base = ['calibri' => 7 / 11, 'arial' => 7 / 10, 'arial narrow' => 6 / 10, 'times new roman' => 7 / 11, 'cambria' => 7 / 11, 'tahoma' => 7 / 10, 'verdana' => 8 / 10];
    return round(($base[$name] ?? 7 / 11) * $size);
}

// Loads one sheet's geometry: column edges and row edges in points over the print area, merges and page setup.
function xg_sheet(array &$b, string $sheet): array
{
    $path = $b['sheets'][$sheet] ?? throw new RuntimeException("Missing sheet $sheet");
    $xml = $b['z'][$path];
    $mdw = xg_digit_width($b);
    $px2pt = 0.75;
    $colPt = function (float $w) use ($mdw, $px2pt) {
        $px = floor((256 * $w + floor(128 / $mdw)) / 256 * $mdw);
        return $px * $px2pt;
    };

    preg_match('#<sheetFormatPr([^>]*)>#', $xml, $fmt);
    $defW = preg_match('#defaultColWidth="([\d.]+)"#', $fmt[1] ?? '', $m) ? (float)$m[1]
        : (preg_match('#baseColWidth="([\d.]+)"#', $fmt[1] ?? '', $m) ? (float)$m[1] + 0.7109375 : 8.7109375);
    $defH = preg_match('#defaultRowHeight="([\d.]+)"#', $fmt[1] ?? '', $m) ? (float)$m[1] : 15;

    $cols = [];
    preg_match_all('#<col ([^>]*)/>#', $xml, $cm);
    foreach ($cm[1] as $a) {
        preg_match('#min="(\d+)"#', $a, $mi);
        preg_match('#max="(\d+)"#', $a, $ma);
        $hidden = str_contains($a, 'hidden="1"');
        $w = preg_match('#width="([\d.]+)"#', $a, $wm) ? (float)$wm[1] : $defW;
        for ($c = (int)$mi[1]; $c <= min((int)$ma[1], 200); $c++) $cols[$c] = $hidden ? 0 : $colPt($w);
    }
    $rows = [];
    preg_match_all('#<row ([^>]*)>#', $xml, $rm);
    foreach ($rm[1] as $a) {
        preg_match('#r="(\d+)"#', $a, $r);
        $hidden = str_contains($a, 'hidden="1"');
        $h = preg_match('#ht="([\d.]+)"#', $a, $hm) ? (float)$hm[1] : $defH;
        $rows[(int)$r[1]] = $hidden ? 0 : $h;
    }

    // Print area from the workbook's defined names, else the used range.
    $idx = array_search($sheet, array_keys($b['sheets']), true);
    $area = null;
    if (preg_match_all('#<definedName name="_xlnm.Print_Area" localSheetId="(\d+)"[^>]*>([^<]*)</definedName>#', $b['z']['xl/workbook.xml'], $dn, PREG_SET_ORDER)) {
        foreach ($dn as $d) if ((int)$d[1] === $idx && preg_match('#!\$?([A-Z]+)\$?(\d+):\$?([A-Z]+)\$?(\d+)#', html_entity_decode($d[2]), $p)) $area = [xg_col($p[1]), (int)$p[2], xg_col($p[3]), (int)$p[4]];
    }
    if (!$area) {
        preg_match('#<dimension ref="([A-Z]+)(\d+):([A-Z]+)(\d+)"#', $xml, $p);
        $area = [xg_col($p[1] ?? 'A'), (int)($p[2] ?? 1), xg_col($p[3] ?? 'A'), (int)($p[4] ?? 1)];
    }

    $merges = [];
    preg_match_all('#<mergeCell ref="([A-Z]+\d+):([A-Z]+\d+)"#', $xml, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) $merges[] = [...xg_ref($m[1]), ...xg_ref($m[2])];

    preg_match('#<pageMargins([^>]*)/>#', $xml, $pm);
    $margin = fn($k, $d) => preg_match("#$k=\"([\d.]+)\"#", $pm[1] ?? '', $v) ? (float)$v[1] * 72 : $d * 72;
    preg_match('#<pageSetup([^>]*)/>#', $xml, $ps);
    $attr = fn($k, $d) => preg_match("#\b$k=\"([^\"]+)\"#", $ps[1] ?? '', $v) ? $v[1] : $d;
    $paper = XLSX_PAPER[(int)$attr('paperSize', 9)] ?? XLSX_PAPER[9];
    if ($attr('orientation', 'portrait') === 'landscape') $paper = [$paper[1], $paper[0]];
    preg_match('#<printOptions([^>]*)/>#', $xml, $po);

    return [
        'cols' => $cols, 'rows' => $rows, 'defW' => $colPt($defW), 'defH' => $defH, 'area' => $area, 'merges' => $merges,
        'paper' => $paper,
        'margins' => [$margin('left', 0.7), $margin('top', 0.75), $margin('right', 0.7), $margin('bottom', 0.75)],
        'fit' => str_contains($xml, 'fitToPage="1"'),
        'fitW' => (int)$attr('fitToWidth', 1), 'fitH' => (int)$attr('fitToHeight', 1),
        'scale' => (int)$attr('scale', 100) / 100,
        'hcenter' => str_contains($po[1] ?? '', 'horizontalCentered="1"'),
        'vcenter' => str_contains($po[1] ?? '', 'verticalCentered="1"'),
    ];
}

// Page placement: the scale and the top-left corner of the print area on the page, in points.
function xg_page(array $g): array
{
    [$c0, $r0, $c1, $r1] = $g['area'];
    $w = 0;
    for ($c = $c0; $c <= $c1; $c++) $w += $g['cols'][$c] ?? $g['defW'];
    $h = 0;
    for ($r = $r0; $r <= $r1; $r++) $h += $g['rows'][$r] ?? $g['defH'];
    [$ml, $mt, $mr, $mb] = $g['margins'];
    $pw = $g['paper'][0] - $ml - $mr;
    $ph = $g['paper'][1] - $mt - $mb;
    $s = $g['fit'] ? min($g['fitW'] ? $pw * $g['fitW'] / $w : INF, $g['fitH'] ? $ph * $g['fitH'] / $h : INF, 1) : $g['scale'];
    $x = $ml + ($g['hcenter'] ? ($pw - $w * $s) / 2 : 0);
    $y = $mt + ($g['vcenter'] ? ($ph - $h * $s) / 2 : 0);
    return ['s' => $s, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h];
}

// Box of a cell (or the merged range it starts) as page percentages {x, y, w, h}; null when outside the print area.
function xg_box(array $g, array $page, string $ref): ?array
{
    [$c, $r] = xg_ref($ref);
    [$c0, $r0, $c1, $r1] = $g['area'];
    if ($c < $c0 || $c > $c1 || $r < $r0 || $r > $r1) return null;
    $ce = $c;
    $re = $r;
    foreach ($g['merges'] as [$mc0, $mr0, $mc1, $mr1]) {
        if ($c >= $mc0 && $c <= $mc1 && $r >= $mr0 && $r <= $mr1) {
            [$c, $r, $ce, $re] = [$mc0, $mr0, min($mc1, $c1), min($mr1, $r1)];
            break;
        }
    }
    $x = 0;
    for ($i = $c0; $i < $c; $i++) $x += $g['cols'][$i] ?? $g['defW'];
    $w = 0;
    for ($i = $c; $i <= $ce; $i++) $w += $g['cols'][$i] ?? $g['defW'];
    $y = 0;
    for ($i = $r0; $i < $r; $i++) $y += $g['rows'][$i] ?? $g['defH'];
    $h = 0;
    for ($i = $r; $i <= $re; $i++) $h += $g['rows'][$i] ?? $g['defH'];
    [$PW, $PH] = $g['paper'];
    return [
        'x' => round(100 * ($page['x'] + $x * $page['s']) / $PW, 3),
        'y' => round(100 * ($page['y'] + $y * $page['s']) / $PH, 3),
        'w' => round(100 * $w * $page['s'] / $PW, 3),
        'h' => round(100 * $h * $page['s'] / $PH, 3),
    ];
}
