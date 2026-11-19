<?php
// Maps a revised CS Form 212 template onto the current form version. Every cell the current map uses is found again
// in the new workbook by the printed labels around it (the label to its left, or the column header above it, telling
// repeated labels apart by their order on the sheet). Tables, continuation sheets, checkboxes, signature boxes and the
// country list are located the same way. The result is a draft map plus a report of what matched, what needs a
// person, and which labels are new or gone; nothing is guessed silently.
require_once __DIR__ . '/pds-xlsx.php';
require_once __DIR__ . '/xlsx-geometry.php';

// Label text normalized for comparison: no item numbers ("12."), no punctuation, single spaces, upper case.
function fa_norm(string $t): string
{
    $t = strtoupper(preg_replace('/\s+/u', ' ', $t));
    $t = preg_replace('/^\s*\d+\s*[.)]\s*/', '', $t);
    $t = preg_replace('/[^A-Z0-9&\/ ]+/', '', $t);
    return trim(preg_replace('/\s+/', ' ', $t));
}

// Text cells of a sheet: ref => [row, col, raw text, normalized], in reading order; merged areas count once.
function fa_texts(array &$b, string $sheet): array
{
    $xml = $b['z'][$b['sheets'][$sheet]];
    preg_match_all('#<c r="([A-Z]+)(\d+)"[^>]*?(?:/>|>(.*?)</c>)#s', $xml, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $c) {
        if (!isset($c[3]) || !str_contains($c[3], '<v') && !str_contains($c[3], '<is')) continue;
        $raw = trim(xlsx_get($b, $sheet, $c[1] . $c[2]));
        if ($raw === '' || is_numeric($raw)) continue;
        $n = fa_norm($raw);
        if ($n === '') continue;
        $out[$c[1] . $c[2]] = [(int)$c[2], xg_col($c[1]), $raw, $n];
    }
    uasort($out, fn($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
    return $out;
}

// Merge area [c0, r0, c1, r1] containing a cell, or the cell itself.
function fa_area(array $g, int $c, int $r): array
{
    foreach ($g['merges'] as [$c0, $r0, $c1, $r1]) if ($c >= $c0 && $c <= $c1 && $r >= $r0 && $r <= $r1) return [$c0, $r0, $c1, $r1];
    return [$c, $r, $c, $r];
}

// Context of one sheet for matching: text cells, an index of label text to their refs in order, geometry.
function fa_sheet(array &$b, string $sheet): array
{
    $t = fa_texts($b, $sheet);
    $by = [];
    foreach ($t as $ref => [, , , $n]) $by[$n][] = $ref;
    return ['texts' => $t, 'by' => $by, 'g' => xg_sheet($b, $sheet)];
}

// Signatures of a target cell in the old sheet: the nearest label to its left and the nearest header above it.
function fa_signatures(array $ctx, string $ref): array
{
    [$c, $r] = xg_ref($ref);
    [$c0, $r0, $c1, $r1] = fa_area($ctx['g'], $c, $r);
    $sig = [];
    // Left: the first text cell in this row (or any row of this merged cell) left of it.
    for ($cc = $c0 - 1; $cc >= 1 && !isset($sig['left']); $cc--) {
        for ($rr = $r0; $rr <= $r1; $rr++) {
            [$lc0, $lr0, $lc1] = fa_area($ctx['g'], $cc, $rr);
            $lref = xg_letters($lc0) . $lr0;
            if (isset($ctx['texts'][$lref])) {
                $n = $ctx['texts'][$lref][3];
                $sig['left'] = ['text' => $n, 'occ' => array_search($lref, $ctx['by'][$n], true), 'dc' => $c0 - $lc1, 'dr' => $r0 - $lr0];
                break;
            }
            $cc = min($cc, $lc0);
        }
    }
    // Above: the first text cell over this column.
    for ($rr = $r0 - 1; $rr >= 1; $rr--) {
        [$ac0, $ar0, , $ar1] = fa_area($ctx['g'], $c0, $rr);
        $aref = xg_letters($ac0) . $ar0;
        if (isset($ctx['texts'][$aref])) {
            $n = $ctx['texts'][$aref][3];
            $sig['above'] = ['text' => $n, 'occ' => array_search($aref, $ctx['by'][$n], true), 'dr' => $r0 - $ar1, 'dc' => $c0 - $ac0];
            break;
        }
        $rr = min($rr, $ar0);
    }
    return $sig;
}

// Finds the cell a signature points to in the new sheet; null when its label is not there.
function fa_follow(array $ctx, array $s, string $kind): ?string
{
    $refs = $ctx['by'][$s['text']] ?? [];
    if (!$refs) return null;
    $lref = $refs[min($s['occ'], count($refs) - 1)];
    [$lc, $lr] = xg_ref($lref);
    [$lc0, $lr0, $lc1, $lr1] = fa_area($ctx['g'], $lc, $lr);
    if ($kind === 'left') return xg_letters($lc1 + $s['dc']) . ($lr0 + $s['dr']);
    return xg_letters($lc0 + $s['dc']) . ($lr1 + $s['dr']);
}

// Locates one old cell in the new sheet. Both signatures agreeing is "matched"; one of them is "check".
function fa_locate(array $old, array $new, string $ref): array
{
    $sig = fa_signatures($old, $ref);
    $hits = [];
    foreach (['left', 'above'] as $k) if (isset($sig[$k])) $hits[$k] = fa_follow($new, $sig[$k], $k);
    $hits = array_filter($hits);
    if (!$hits) return ['ref' => null, 'status' => 'missing', 'how' => 'Its labels are not on the new form'];
    $vals = array_values(array_unique($hits));
    if (count($vals) === 1) {
        $same = count($hits) === 2 || count($sig) === 1;
        // A target that holds a label in the new form is suspicious; it is kept but flagged.
        $isLabel = isset($new['texts'][$vals[0]]);
        return ['ref' => $vals[0], 'status' => $same && !$isLabel ? 'matched' : 'check',
            'how' => $isLabel ? 'Found, but that cell holds text in the new form' : ($same ? 'Same labels around it' : 'Found by one label only')];
    }
    // Disagreement: prefer the left label for single cells.
    return ['ref' => $hits['left'] ?? $vals[0], 'status' => 'check', 'how' => 'The label on the left and the header above point to different cells'];
}

// Matches sheets by name, then by position.
function fa_sheet_map(array $oldB, array $newB): array
{
    $old = array_keys($oldB['sheets']);
    $new = array_keys($newB['sheets']);
    $out = [];
    foreach ($old as $i => $name) {
        $out[$name] = in_array($name, $new, true) ? $name : (current(array_filter($new, fn($n) => strcasecmp(trim($n), trim($name)) === 0)) ?: ($new[$i] ?? null));
    }
    return $out;
}

// Linked cells of the checkboxes on a sheet, with each control's anchor (for its preview box).
function fa_controls(array &$b, string $sheet): array
{
    $path = $b['sheets'][$sheet];
    $xml = $b['z'][$path];
    $rels = xlsx_rels($b['z'], $path);
    $out = [];
    preg_match_all('#<control shapeId="(\d+)" r:id="(rId\d+)"[^>]*?(?:name="([^"]*)")?[^>]*>(.*?)</control>#s', $xml, $m, PREG_SET_ORDER);
    foreach ($m as [$all, $shape, $rid, $name, $inner]) {
        $prop = $b['z'][$rels[$rid][1] ?? ''] ?? '';
        if (!preg_match('#fmlaLink="\$?([A-Z]+)\$?(\d+)"#', $prop, $lk)) continue;
        $type = preg_match('#objectType="(\w+)"#', $prop, $ot) ? $ot[1] : '';
        $pos = [];
        foreach (['from', 'to'] as $k) {
            if (preg_match("#<xdr:$k><xdr:col>(\d+)</xdr:col><xdr:colOff>(-?\d+)</xdr:colOff><xdr:row>(\d+)</xdr:row><xdr:rowOff>(-?\d+)</xdr:rowOff></xdr:$k>#", $inner, $p)) $pos[$k] = array_map('intval', array_slice($p, 1));
        }
        $out[$lk[1] . $lk[2]] = ['type' => $type, 'name' => $name, 'anchor' => $pos, 'list' => preg_match('#fmlaRange="([^"]+)"#', $prop, $fr) ? $fr[1] : null];
    }
    // The VML drawing holds where Excel actually draws each control; its anchors win over the sheet's own copy.
    foreach (fa_vml_controls($b, $sheet) as $v) {
        if (!$v['link']) continue;
        $out[$v['link']] = ['type' => $out[$v['link']]['type'] ?? $v['type'], 'name' => $out[$v['link']]['name'] ?? '', 'list' => $out[$v['link']]['list'] ?? null,
            'anchor' => ['from' => $v['from'], 'to' => $v['to']], 'label' => $v['label']];
    }
    return $out;
}

// Checkboxes and dropdowns from the sheet's VML drawing: linked cell, label, and cell-relative anchor.
function fa_vml_controls(array &$b, string $sheet): array
{
    $out = [];
    foreach (xlsx_rels($b['z'], $b['sheets'][$sheet]) as [$type, $path]) {
        if ($type !== 'vmlDrawing' || !isset($b['z'][$path])) continue;
        preg_match_all('#<v:shape .*?</v:shape>#s', $b['z'][$path], $m);
        foreach ($m[0] as $sh) {
            if (!preg_match('#ObjectType="(\w+)"#', $sh, $ot) || !in_array($ot[1], ['Checkbox', 'Drop', 'Radio'], true)) continue;
            if (!preg_match('#<x:Anchor>\s*([^<]+)</x:Anchor>#', $sh, $an)) continue;
            $a = array_map('intval', explode(',', $an[1]));
            if (count($a) < 8) continue;
            $label = preg_match('#<v:textbox[^>]*>(.*?)</v:textbox>#s', $sh, $tb) ? trim(html_entity_decode(strip_tags($tb[1]), ENT_QUOTES | ENT_HTML5)) : '';
            $out[] = [
                'type' => $ot[1],
                'link' => preg_match('#<x:FmlaLink>\$?([A-Z]+)\$?(\d+)</x:FmlaLink>#', $sh, $lk) ? $lk[1] . $lk[2] : null,
                'label' => preg_replace('/\s+/', ' ', $label),
                'size' => preg_match('#<font [^>]*size="(\d+)"#', $sh, $fs) ? (int)$fs[1] / 20 : 8,
                // Same shape as an xlsx drawing anchor: [col, EMU offset, row, EMU offset]; VML offsets are pixels.
                'from' => [$a[0], $a[1] * 9525, $a[2], $a[3] * 9525],
                'to' => [$a[4], $a[5] * 9525, $a[6], $a[7] * 9525],
            ];
        }
    }
    return $out;
}

// The country list's first row from the dropdown's data validation or list control, for PDS_COUNTRY's offset.
function fa_list_offset(array &$b, string $sheet, string $cell, array $controls): ?int
{
    $range = $controls[$cell]['list'] ?? null;
    if (!$range) {
        $xml = $b['z'][$b['sheets'][$sheet]];
        if (preg_match('#<dataValidation[^>]*sqref="[^"]*\b' . $cell . '\b[^"]*"[^>]*>\s*<formula1>([^<]+)</formula1>#', $xml, $dv)) $range = $dv[1];
    }
    if (!$range || !preg_match('#\$?Q\$?(\d+)#', $range, $r)) return null;
    // A list control's value N selects the Nth row of its range, which pds_export reads as Q(offset + N).
    return (int)$r[1] - 1;
}

// Rows of a table: from its first row down until a row holds text in a table column, has a cell merged into the next
// table column (a footer such as "Continue on a separate sheet"), or is a thin spacer. Templates do not merge their
// rows consistently, so the analysis compares this count between the old and new template rather than trusting it.
function fa_table_rows(array $ctx, int $start, array $cols): int
{
    $cs = array_map('xg_col', $cols);
    sort($cs);
    $n = 0;
    for ($r = $start; $r < $start + 200 && $r <= $ctx['g']['area'][3]; $r++) {
        foreach ($cs as $i => $c) {
            if (isset($ctx['texts'][xg_letters($c) . $r])) return $n;
            $a = fa_area($ctx['g'], $c, $r);
            if ($a[1] < $r && $a[3] >= $r && $r !== $start && $a[3] > $a[1]) return $n;
            if (isset($cs[$i + 1]) && $a[2] >= $cs[$i + 1]) return $n;
        }
        if (($ctx['g']['rows'][$r] ?? $ctx['g']['defH']) < 4) return $n;
        $n++;
    }
    return $n;
}

// Builds the draft map for a new template. Returns ['map' => ..., 'report' => ..., 'max' => table row counts].
function fa_analyze(string $newBin): array
{
    $cur = pds_map();
    $oldB = xlsx_open(file_get_contents(form_current()['template']));
    $newB = xlsx_open($newBin);
    $sheets = fa_sheet_map($oldB, $newB);
    $ctx = ['old' => [], 'new' => []];
    $get = function (string $side, string $sheet) use (&$ctx, &$oldB, &$newB) {
        if (!isset($ctx[$side][$sheet])) {
            if ($side === 'old') $ctx[$side][$sheet] = fa_sheet($oldB, $sheet);
            else $ctx[$side][$sheet] = fa_sheet($newB, $sheet);
        }
        return $ctx[$side][$sheet];
    };
    $items = [];
    $locate = function (string $id, string $addr, array $path) use (&$items, $sheets, $get) {
        [$s, $ref] = pds_ref($addr);
        $ns = $sheets[$s] ?? null;
        if (!$ns) {
            $items[] = ['id' => $id, 'path' => $path, 'old' => $addr, 'new' => null, 'status' => 'missing', 'how' => "Sheet $s is not in the new file"];
            return null;
        }
        $r = fa_locate($get('old', $s), $get('new', $ns), $ref);
        $items[] = ['id' => $id, 'path' => $path, 'old' => $addr, 'new' => $r['ref'] ? "$ns!$r[ref]" : null, 'status' => $r['status'], 'how' => $r['how']];
        return $r['ref'] ? "$ns!$r[ref]" : null;
    };

    $map = ['fields' => [], 'checks' => [], 'tables' => [], 'cont' => [], 'edu_sheet' => $sheets[$cur['edu_sheet']] ?? 'C1', 'edu_rows' => [], 'edu_cols' => [],
        'country' => null, 'clear' => [], 'images' => []];

    foreach ($cur['fields'] as $k => $addrs) {
        $found = [];
        foreach (explode(' ', $addrs) as $i => $a) $found[] = $locate("f:$k", $a, ['fields', $k, $i]);
        $found = array_filter($found);
        if ($found) $map['fields'][$k] = implode(' ', $found);
    }

    // Checkboxes: located like cells, then snapped to the nearest linked cell of a checkbox on that row.
    $linked = [];
    foreach ($cur['checks'] as $k => $opts) {
        foreach ($opts as $opt => $addr) {
            $hit = $locate("c:$k:$opt", $addr, ['checks', $k, $opt]);
            if (!$hit) continue;
            [$ns, $ref] = pds_ref($hit);
            $linked[$ns] ??= fa_controls($newB, $ns);
            if (!isset($linked[$ns][$ref])) {
                [$c, $r] = xg_ref($ref);
                $near = array_filter(array_keys($linked[$ns]), fn($x) => xg_ref($x)[1] === $r && abs(xg_ref($x)[0] - $c) <= 3);
                usort($near, fn($a, $b2) => abs(xg_ref($a)[0] - $c) <=> abs(xg_ref($b2)[0] - $c));
                $last = array_key_last($items);
                if ($near) {
                    $ref = $near[0];
                    $items[$last]['new'] = "$ns!$ref";
                } else {
                    $items[$last]['status'] = 'check';
                    $items[$last]['how'] = 'No checkbox is linked to this cell in the new form';
                }
            }
            $map['checks'][$k][$opt] = "$ns!$ref";
        }
    }

    // Country dropdown.
    if ($cur['country']) {
        [$cs, $cc] = $cur['country'];
        $hit = $locate('f:dual_country', "$cs!$cc", ['country']);
        if ($hit) {
            [$ns, $ref] = pds_ref($hit);
            $linked[$ns] ??= fa_controls($newB, $ns);
            $off = fa_list_offset($newB, $ns, $ref, $linked[$ns]);
            $map['country'] = [$ns, $ref, $off ?? $cur['country'][2]];
        }
    }

    // Tables: the first row's cells, then the row count of the new form.
    $max = [];
    foreach ($cur['tables'] as $t => [$s, $start, $cols]) {
        $newCols = [];
        $row = null;
        foreach ($cols as $c => $col) {
            $hit = $locate("t:$t:0:$c", "$s!$col$start", ['tables', $t, $c]);
            if (!$hit) continue;
            [$ns, $ref] = pds_ref($hit);
            [$nc, $nr] = xg_ref($ref);
            $newCols[$c] = xg_letters($nc);
            $row ??= $nr;
        }
        if (count($newCols) === count($cols)) {
            $map['tables'][$t] = [$ns, $row, $newCols];
            $was = fa_table_rows($get('old', $s), $start, array_values($cols));
            $now = fa_table_rows($get('new', $ns), $row, array_values($newCols));
            $max[$t] = max(1, pds_max_rows($t) + $now - $was);
        }
    }

    // Continuation sheets: the sheet whose title matches, with the same header method.
    foreach ($cur['cont'] as $t => [$s, $start, $cols]) {
        $ns = $sheets[$s] ?? null;
        if (!$ns) continue;
        $newCols = [];
        $row = null;
        foreach ($cols as $c => $col) {
            $hit = $locate("cont:$t:$c", "$s!$col$start", ['cont', $t, $c]);
            if (!$hit) continue;
            [, $ref] = pds_ref($hit);
            [$nc, $nr] = xg_ref($ref);
            $newCols[$c] = xg_letters($nc);
            $row ??= $nr;
        }
        if (count($newCols) === count($cols)) {
            $map['cont'][$t] = [$ns, $row, $newCols];
            $more = 0;
            foreach (pds_schema() as $sec) foreach ($sec['tables'] ?? [] as $tt) if ($tt['k'] === $t) $more = $tt['more'] ?? 0;
            $was = fa_table_rows($get('old', $s), $start, array_values($cols));
            $now = fa_table_rows($get('new', $ns), $row, array_values($newCols));
            $max["$t.more"] = max(0, $more + $now - $was);
        }
    }

    // Education: one row per level, found by the level's label.
    foreach ($cur['edu_rows'] as $level => $r) {
        $hit = $locate("e:$level:row", "$cur[edu_sheet]!" . current($cur['edu_cols']) . $r, ['edu_rows', $level]);
        if ($hit) $map['edu_rows'][$level] = xg_ref(pds_ref($hit)[1])[1];
    }
    $firstLevel = array_key_first($cur['edu_rows']);
    foreach ($cur['edu_cols'] as $c => $col) {
        $hit = $locate("e:$firstLevel:$c", "$cur[edu_sheet]!$col" . $cur['edu_rows'][$firstLevel], ['edu_cols', $c]);
        if ($hit) $map['edu_cols'][$c] = preg_replace('/\d+/', '', pds_ref($hit)[1]);
    }

    // Placeholder labels to clear and signature boxes: the same text in the new form.
    $placeholder = [];
    foreach ($cur['clear'] as $addr) {
        [$s, $ref] = pds_ref($addr);
        $text = $get('old', $s)['texts'][$ref][3] ?? null;
        $placeholder[$addr] = $text;
    }
    foreach ($newB['sheets'] as $ns => $_) {
        foreach ($get('new', $ns)['texts'] as $ref => [, , , $n]) {
            if (in_array($n, $placeholder, true)) $map['clear'][] = "$ns!$ref";
        }
    }
    foreach ($cur['images'] as $k => $addrs) {
        foreach ($addrs as $addr) {
            [$s, $ref] = pds_ref($addr);
            if (str_starts_with($ref, 'shape:')) {
                $ns = $sheets[$s] ?? null;
                $name = substr($ref, 6);
                $has = $ns && str_contains(implode('', array_map(fn($p) => $newB['z'][$p] ?? '', array_filter(array_keys($newB['z']), fn($p) => str_starts_with($p, 'xl/drawings/drawing')))), 'name="' . $name . '"');
                $items[] = ['id' => "s:$k", 'path' => ['images', $k, count($map['images'][$k] ?? [])], 'old' => $addr, 'new' => $has ? "$ns!$ref" : null, 'status' => $has ? 'matched' : 'missing', 'how' => $has ? 'Same frame name' : 'The frame is not in the new form'];
                if ($has) $map['images'][$k][] = "$ns!$ref";
                continue;
            }
            // Signature boxes are the placeholder cells; others are located like any cell.
            $hit = isset($placeholder[$addr]) ? null : $locate("s:$k", $addr, ['images', $k, count($map['images'][$k] ?? [])]);
            if (isset($placeholder[$addr])) {
                $ns = $sheets[$s] ?? null;
                $cand = $ns ? array_values(array_filter($map['clear'], fn($a) => pds_ref($a)[0] === $ns)) : [];
                $old = array_values(array_filter($cur['clear'], fn($a) => pds_ref($a)[0] === $s));
                $hit = $cand[array_search($addr, $old, true)] ?? null;
                $items[] = ['id' => "s:$k", 'path' => ['images', $k, count($map['images'][$k] ?? [])], 'old' => $addr, 'new' => $hit, 'status' => $hit ? 'matched' : 'missing', 'how' => $hit ? 'Same signature box text' : 'The signature box is not in the new form'];
            }
            if ($hit) $map['images'][$k][] = $hit;
        }
    }

    // Labels that are new in this form, or gone from it.
    $oldLabels = $newLabels = [];
    foreach ($sheets as $s => $ns) {
        foreach ($get('old', $s)['texts'] as [, , $raw, $n]) $oldLabels[$n] = $raw;
        if ($ns) foreach ($get('new', $ns)['texts'] as $ref => [, , $raw, $n]) $newLabels[$n] ??= ['text' => $raw, 'where' => "$ns!$ref"];
    }
    $added = array_values(array_filter($newLabels, fn($v, $n) => !isset($oldLabels[$n]) && strlen($n) > 2, ARRAY_FILTER_USE_BOTH));
    $gone = array_values(array_filter($oldLabels, fn($raw, $n) => !isset($newLabels[$n]) && strlen($n) > 2, ARRAY_FILTER_USE_BOTH));
    $countries = 0;
    if ($map['country']) foreach ($get('new', $map['country'][0])['texts'] as $ref => $_) if (preg_match('/^Q\d+$/', $ref)) $countries++;
    $added = array_values(array_filter($added, fn($l) => !preg_match('/^[A-Z]+!Q\d+$/', $l['where'])));

    return ['map' => $map, 'max' => $max, 'sheets' => $sheets, 'report' => ['items' => $items, 'added' => $added, 'gone' => $gone]];
}
