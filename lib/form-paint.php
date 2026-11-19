<?php
// Describes the printed pages of a template so the admin page can draw them (public/forms.js) when no PDF is at
// hand: every bordered, shaded or written cell of the print area, the checkboxes and dropdowns (from the VML
// drawing, which holds their real positions and labels) and the text boxes. Positions are points from the print
// area's top-left corner, the same units the preview boxes use, so a drawn page lines up with them exactly.
require_once __DIR__ . '/form-layout.php';

// Excel's built-in palette for indexed colors 0-63.
const FP_INDEXED = ['000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF', '000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
    '800000', '008000', '000080', '808000', '800080', '008080', 'C0C0C0', '808080', '9999FF', '993366', 'FFFFCC', 'CCFFFF', '660066', 'FF8080', '0066CC', 'CCCCFF',
    '000080', 'FF00FF', 'FFFF00', '00FFFF', '800080', '800000', '008080', '0000FF', '00CCFF', 'CCFFFF', 'CCFFCC', 'FFFF99', '99CCFF', 'FF99CC', 'CC99FF', 'FFCC99',
    '3366FF', '33CCCC', '99CC00', 'FFCC00', 'FF9900', 'FF6600', '666699', '969696', '003366', '339966', '003300', '333300', '993300', '993366', '333399', '333333'];

// Theme colors in Excel's index order (light 1, dark 1, light 2, dark 2, accents 1-6, hyperlinks).
function fp_theme(array &$b): array
{
    $xml = '';
    foreach ($b['z'] as $path => $data) if (str_starts_with($path, 'xl/theme/theme')) { $xml = $data; break; }
    $get = function ($name) use ($xml) {
        if (!preg_match("#<a:$name>(.*?)</a:$name>#s", $xml, $m)) return '000000';
        if (preg_match('#srgbClr val="([0-9A-Fa-f]{6})"#', $m[1], $c)) return strtoupper($c[1]);
        if (preg_match('#lastClr="([0-9A-Fa-f]{6})"#', $m[1], $c)) return strtoupper($c[1]);
        return '000000';
    };
    return array_map($get, ['lt1', 'dk1', 'lt2', 'dk2', 'accent1', 'accent2', 'accent3', 'accent4', 'accent5', 'accent6', 'hlink', 'folHlink']);
}

// A color element's value as #rrggbb, or null.
function fp_color(?string $el, array $theme): ?string
{
    if (!$el) return null;
    if (preg_match('#rgb="(?:[0-9A-Fa-f]{2})?([0-9A-Fa-f]{6})"#', $el, $m)) $hex = $m[1];
    elseif (preg_match('#indexed="(\d+)"#', $el, $m)) $hex = (int)$m[1] === 64 ? '000000' : (FP_INDEXED[(int)$m[1]] ?? null);
    elseif (preg_match('#theme="(\d+)"#', $el, $m)) $hex = $theme[(int)$m[1]] ?? null;
    else return null;
    if (!$hex) return null;
    // Tint lightens (positive) or darkens (negative) the color.
    if (preg_match('#tint="(-?[\d.]+)"#', $el, $t)) {
        $tint = (float)$t[1];
        $rgb = array_map('hexdec', str_split($hex, 2));
        $rgb = array_map(fn($v) => (int)round($tint < 0 ? $v * (1 + $tint) : $v + (255 - $v) * $tint), $rgb);
        $hex = vsprintf('%02X%02X%02X', $rgb);
    }
    return '#' . strtolower($hex);
}

// Fonts, fills, borders and cell formats from styles.xml.
function fp_styles(array &$b): array
{
    $s = $b['z']['xl/styles.xml'];
    $theme = fp_theme($b);
    $section = fn($tag) => preg_match("#<$tag\b[^>]*>(.*?)</$tag>#s", $s, $m) ? $m[1] : '';
    preg_match_all('#<font>(.*?)</font>|<font/>#s', $section('fonts'), $fm);
    $fonts = array_map(fn($x) => [
        'size' => preg_match('#<sz val="([\d.]+)"#', $x, $v) ? (float)$v[1] : 11,
        'bold' => (bool)preg_match('#<b(?:\s+val="(?:1|true)")?\s*/>#', $x),
        'italic' => (bool)preg_match('#<i(?:\s+val="(?:1|true)")?\s*/>#', $x),
        'face' => preg_match('#<name val="([^"]+)"#', $x, $v) ? $v[1] : 'Arial',
        'color' => preg_match('#<color [^>]*/>#', $x, $v) ? fp_color($v[0], $theme) : null,
    ], $fm[1]);
    preg_match_all('#<fill>(.*?)</fill>#s', $section('fills'), $fl);
    $fills = array_map(function ($x) use ($theme) {
        if (!preg_match('#patternType="(\w+)"#', $x, $p) || $p[1] === 'none') return null;
        preg_match('#<fgColor [^>]*/>#', $x, $c);
        return fp_color($c[0] ?? null, $theme) ?? ($p[1] === 'solid' ? '#c0c0c0' : null);
    }, $fl[1]);
    $weight = ['hair' => 0.5, 'thin' => 1, 'dotted' => 1, 'dashed' => 1, 'dashDot' => 1, 'dashDotDot' => 1, 'mediumDashed' => 2, 'mediumDashDot' => 2, 'mediumDashDotDot' => 2, 'slantDashDot' => 2, 'medium' => 2, 'double' => 2.5, 'thick' => 3];
    preg_match_all('#<border\b[^>]*>(.*?)</border>|<border\b[^>]*/>#s', $section('borders'), $bm);
    $borders = array_map(function ($x) use ($weight) {
        $side = fn($n) => preg_match("#<$n style=\"(\w+)\"#", $x, $v) ? ($weight[$v[1]] ?? 1) : 0;
        return [$side('top'), $side('right'), $side('bottom'), $side('left')];
    }, $bm[1]);
    preg_match_all('#<xf ([^>]*?)(?:/>|>(.*?)</xf>)#s', $section('cellXfs'), $xm, PREG_SET_ORDER);
    $xfs = array_map(fn($x) => [
        'font' => preg_match('#fontId="(\d+)"#', $x[1], $v) ? (int)$v[1] : 0,
        'fill' => preg_match('#fillId="(\d+)"#', $x[1], $v) ? (int)$v[1] : 0,
        'border' => preg_match('#borderId="(\d+)"#', $x[1], $v) ? (int)$v[1] : 0,
        'h' => preg_match('#horizontal="(\w+)"#', $x[2] ?? '', $v) ? $v[1] : 'general',
        'v' => preg_match('#vertical="(\w+)"#', $x[2] ?? '', $v) ? $v[1] : 'bottom',
        'wrap' => str_contains($x[2] ?? '', 'wrapText="1"'),
        'rotate' => preg_match('#textRotation="(\d+)"#', $x[2] ?? '', $v) ? (int)$v[1] : 0,
    ], $xm);
    return compact('fonts', 'fills', 'borders', 'xfs', 'theme');
}


// Text boxes, rectangles and lines from the sheet's drawing (form controls are left to the VML above).
function fp_shapes(array &$b, string $sheet, array $g): array
{
    $out = [];
    foreach (xlsx_rels($b['z'], $b['sheets'][$sheet]) as [$type, $path]) {
        if ($type !== 'drawing' || !isset($b['z'][$path])) continue;
        $xml = $b['z'][$path];
        preg_match_all('#<xdr:(twoCellAnchor|oneCellAnchor)\b.*?</xdr:\1>#s', $xml, $m, PREG_SET_ORDER);
        foreach ($m as [$chunk, $kind]) {
            if (preg_match('#name="(?:Check Box|Drop Down|Option Button|List Box)\b#', $chunk)) continue;
            if (!preg_match('#<xdr:from><xdr:col>(\d+)</xdr:col><xdr:colOff>(-?\d+)</xdr:colOff><xdr:row>(\d+)</xdr:row><xdr:rowOff>(-?\d+)</xdr:rowOff></xdr:from>#', $chunk, $f)) continue;
            [$x0, $y0] = fl_anchor_pt($g, array_map('intval', array_slice($f, 1)));
            if ($kind === 'twoCellAnchor' && preg_match('#<xdr:to><xdr:col>(\d+)</xdr:col><xdr:colOff>(-?\d+)</xdr:colOff><xdr:row>(\d+)</xdr:row><xdr:rowOff>(-?\d+)</xdr:rowOff></xdr:to>#', $chunk, $t)) {
                [$x1, $y1] = fl_anchor_pt($g, array_map('intval', array_slice($t, 1)));
            } elseif (preg_match('#<xdr:ext cx="(\d+)" cy="(\d+)"#', $chunk, $e)) {
                [$x1, $y1] = [$x0 + $e[1] / 12700, $y0 + $e[2] / 12700];
            } else {
                continue;
            }
            $sp = preg_match('#<xdr:spPr\b.*?</xdr:spPr>#s', $chunk, $p) ? $p[0] : '';
            $line = preg_match('#<a:ln\b[^>]*?(?:w="(\d+)")?[^>]*>(.*?)</a:ln>#s', $sp, $ln) ? $ln : null;
            $stroke = $line && !str_contains($line[2], '<a:noFill/>') ? (preg_match('#<a:srgbClr val="([0-9A-Fa-f]{6})"#', $line[2], $c) ? '#' . strtolower($c[1]) : '#000000') : null;
            $fillXml = preg_replace('#<a:ln\b.*?</a:ln>#s', '', $sp);
            $fill = preg_match('#<a:solidFill>\s*<a:srgbClr val="([0-9A-Fa-f]{6})"#', $fillXml, $c) ? '#' . strtolower($c[1]) : null;
            $paras = [];
            preg_match_all('#<a:p>(.*?)</a:p>#s', $chunk, $pp);
            foreach ($pp[1] as $para) {
                preg_match_all('#<a:t>([^<]*)</a:t>#', $para, $tt);
                $paras[] = [
                    'text' => html_entity_decode(implode('', $tt[1]), ENT_QUOTES | ENT_XML1),
                    'size' => preg_match('#\bsz="(\d+)"#', $para, $sz) ? (int)$sz[1] / 100 : 10,
                    'bold' => (bool)preg_match('#<a:rPr[^>]*\bb="1"#', $para),
                    'align' => preg_match('#algn="(\w+)"#', $para, $al) ? ['ctr' => 'center', 'r' => 'right'][$al[1]] ?? 'left' : 'left',
                ];
            }
            $isLine = str_contains($chunk, '<xdr:cxnSp') || preg_match('#prst="line"#', $sp);
            $out[] = ['x' => $x0, 'y' => $y0, 'w' => $x1 - $x0, 'h' => $y1 - $y0, 'stroke' => $stroke ?? ($isLine ? '#000000' : null),
                'width' => isset($line[1]) && $line[1] !== '' ? (int)$line[1] / 12700 : 0.75, 'fill' => $fill, 'line' => (bool)$isLine,
                'paras' => array_values(array_filter($paras, fn($q) => trim($q['text']) !== '')),
                'valign' => preg_match('#anchor="(\w+)"#', $chunk, $va) ? (['ctr' => 'center', 'b' => 'bottom'][$va[1]] ?? 'top') : 'top'];
        }
    }
    return $out;
}

// Everything to draw on each main page, plus the calibration the drawing uses (A4, the sheet's own page setup).
function fp_paint(string $bin, array $map, float $aspect): array
{
    $b = xlsx_open($bin);
    $st = fp_styles($b);
    $geo = fl_geometry($bin, $map, $aspect);
    $pages = [];
    foreach (fl_pages($b, $map) as $i => $sheet) {
        $g = xg_sheet($b, $sheet);
        [$c0, $r0, $c1, $r1] = $g['area'];
        $raw = ['s' => 1, 'x' => 0, 'y' => 0];
        $g100 = $g;
        $g100['paper'] = [100, 100];
        // Cell formats and values by cell.
        $xml = $b['z'][$b['sheets'][$sheet]];
        preg_match_all('#<c r="([A-Z]+)(\d+)"([^>]*?)(?:/>|>(.*?)</c>)#s', $xml, $cm, PREG_SET_ORDER);
        $style = $has = [];
        // Cells behind checkboxes and dropdowns hold their hidden values (TRUE/FALSE, list positions); Excel never
        // prints them, so neither does the drawing.
        $linked = array_flip(array_filter(array_column(fa_vml_controls($b, $sheet), 'link')));
        foreach ($cm as $c) {
            $col = xg_col($c[1]);
            $row = (int)$c[2];
            if ($col < $c0 || $col > $c1 || $row < $r0 || $row > $r1) continue;
            $style["$col,$row"] = preg_match('#\bs="(\d+)"#', $c[3], $sv) ? (int)$sv[1] : 0;
            $hidden = isset($linked[$c[1] . $c[2]]) || str_contains($c[3], 't="b"');
            if (!$hidden && isset($c[4]) && (str_contains($c[4], '<v') || str_contains($c[4], '<is'))) $has["$col,$row"] = $c[1] . $c[2];
        }
        $xfOf = fn($col, $row) => $st['xfs'][$style["$col,$row"] ?? 0] ?? $st['xfs'][0];
        $covered = [];
        $areas = [];
        foreach ($g['merges'] as [$mc0, $mr0, $mc1, $mr1]) {
            if ($mc0 > $c1 || $mr0 > $r1 || $mc1 < $c0 || $mr1 < $r0) continue;
            $areas[] = [$mc0, $mr0, min($mc1, $c1), min($mr1, $r1)];
            for ($cc = $mc0; $cc <= $mc1; $cc++) for ($rr = $mr0; $rr <= $mr1; $rr++) $covered["$cc,$rr"] = true;
        }
        foreach ($style as $key => $_) if (!isset($covered[$key])) {
            [$cc, $rr] = array_map('intval', explode(',', $key));
            $areas[] = [$cc, $rr, $cc, $rr];
        }
        $cells = [];
        foreach ($areas as [$ac0, $ar0, $ac1, $ar1]) {
            $box = xg_box($g100, $raw, xg_letters($ac0) . $ar0);
            if (!$box || $box['w'] <= 0 || $box['h'] <= 0) continue;
            // Borders of a merged area come from the cells along each of its edges.
            $bd = [0, 0, 0, 0];
            for ($cc = $ac0; $cc <= $ac1; $cc++) {
                $bd[0] = max($bd[0], $st['borders'][$xfOf($cc, $ar0)['border']][0] ?? 0);
                $bd[2] = max($bd[2], $st['borders'][$xfOf($cc, $ar1)['border']][2] ?? 0);
            }
            for ($rr = $ar0; $rr <= $ar1; $rr++) {
                $bd[3] = max($bd[3], $st['borders'][$xfOf($ac0, $rr)['border']][3] ?? 0);
                $bd[1] = max($bd[1], $st['borders'][$xfOf($ac1, $rr)['border']][1] ?? 0);
            }
            $xf = $xfOf($ac0, $ar0);
            $fill = $st['fills'][$xf['fill']] ?? null;
            $text = isset($has["$ac0,$ar0"]) ? trim(xlsx_get($b, $sheet, $has["$ac0,$ar0"])) : '';
            if (!$text && !$fill && !array_sum($bd)) continue;
            // Cells listed in PDS_CLEAR (placeholders such as "(e-signature/digital certificate)") are drawn empty.
            if ($text && in_array("$sheet!" . xg_letters($ac0) . $ar0, $map['clear'] ?? [], true)) $text = '';
            $font = $st['fonts'][$xf['font']] ?? $st['fonts'][0];
            $cells[] = ['x' => round($box['x'], 2), 'y' => round($box['y'], 2), 'w' => round($box['w'], 2), 'h' => round($box['h'], 2),
                'b' => $bd, 'fill' => $fill, 'text' => $text, 'font' => $font,
                'align' => in_array($xf['h'], ['center', 'centerContinuous'], true) ? 'center' : ($xf['h'] === 'right' ? 'right' : 'left'),
                'valign' => $xf['v'] === 'top' ? 'top' : ($xf['v'] === 'center' ? 'center' : 'bottom'), 'wrap' => $xf['wrap'], 'rotate' => $xf['rotate']];
        }
        $controls = [];
        foreach (fa_vml_controls($b, $sheet) as $ctl) {
            [$x0, $y0] = fl_anchor_pt($g, $ctl['from']);
            [$x1, $y1] = fl_anchor_pt($g, $ctl['to']);
            $controls[] = ['type' => $ctl['type'], 'label' => $ctl['label'], 'size' => $ctl['size'], 'x' => $x0, 'y' => $y0, 'w' => $x1 - $x0, 'h' => $y1 - $y0];
        }
        $pages[$i + 1] = ['sheet' => $sheet, 'calib' => $geo[$i + 1]['calib'], 'cells' => $cells, 'controls' => $controls, 'shapes' => fp_shapes($b, $sheet, $g)];
    }
    return ['aspect' => $aspect, 'pages' => $pages];
}
