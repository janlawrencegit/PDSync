<?php
// Minimal xlsx reader and writer (zip, cells, form controls) built on zlib and DOM only.

const XLSX_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
const XLSX_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
const XLSX_MAX_PART = 20000000;

function xlsx_unzip(string $bin): array
{
    $eocd = strrpos($bin, "PK\x05\x06");
    if ($eocd === false) throw new RuntimeException('Not a valid .xlsx file');
    ['n' => $n, 'off' => $p] = unpack('vn/x4/Voff', substr($bin, $eocd + 10, 10));
    $files = [];
    for ($i = 0; $i < $n; $i++) {
        $h = unpack('vmethod/x8/Vcsize/Vusize/vnlen/velen/vclen/x8/Vlho', substr($bin, $p + 10, 36));
        $name = substr($bin, $p + 46, $h['nlen']);
        $l = unpack('vnlen/velen', substr($bin, $h['lho'] + 26, 4));
        $data = substr($bin, $h['lho'] + 30 + $l['nlen'] + $l['elen'], $h['csize']);
        if ($h['usize'] > XLSX_MAX_PART) throw new RuntimeException('File part too large');
        $files[$name] = $h['method'] === 8 ? gzinflate($data, max(1, $h['usize'])) : $data;
        $p += 46 + $h['nlen'] + $h['elen'] + $h['clen'];
    }
    return $files;
}

function xlsx_zip(array $files): string
{
    $out = $cd = '';
    foreach ($files as $name => $data) {
        $z = gzdeflate($data);
        $meta = [crc32($data), strlen($z), strlen($data), strlen($name)];
        $cd .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0, 8, 0, 0x21, ...[...$meta, 0, 0, 0, 0, 0, strlen($out)]) . $name;
        $out .= "PK\x03\x04" . pack('vvvvvVVVvv', 20, 0, 8, 0, 0x21, ...[...$meta, 0]) . $name . $z;
    }
    return $out . $cd . "PK\x05\x06" . pack('vvvvVVv', 0, 0, count($files), count($files), strlen($cd), strlen($out), 0);
}

function xlsx_path(string $dir, string $target): string
{
    $out = [];
    foreach (explode('/', $target[0] === '/' ? $target : "$dir/$target") as $seg) {
        if ($seg === '..') array_pop($out);
        elseif ($seg !== '.' && $seg !== '') $out[] = $seg;
    }
    return implode('/', $out);
}

// Returns relationships of a part as id => [type, target path].
function xlsx_rels(array $z, string $part): array
{
    $file = dirname($part) . '/_rels/' . basename($part) . '.rels';
    $out = [];
    foreach (isset($z[$file]) ? simplexml_load_string($z[$file])->Relationship : [] as $r) {
        $out[(string)$r['Id']] = [basename((string)$r['Type']), xlsx_path(dirname($part), (string)$r['Target'])];
    }
    return $out;
}

function xlsx_open(string $bin): array
{
    $b = ['z' => xlsx_unzip($bin), 'sheets' => [], 'dom' => [], 'ctl' => [], 'ss' => null];
    $rels = xlsx_rels($b['z'], 'xl/workbook.xml');
    $wb = simplexml_load_string($b['z']['xl/workbook.xml']);
    foreach ($wb->sheets->sheet as $s) {
        $rid = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        $b['sheets'][(string)$s['name']] = $rels[$rid][1];
    }
    return $b;
}

function xlsx_save(array $b): string
{
    foreach ($b['dom'] as $path => $doc) $b['z'][$path] = $doc->saveXML();
    if (isset($b['styles'])) $b['z']['xl/styles.xml'] = $b['styles']->saveXML();
    return xlsx_zip($b['z']);
}

function xlsx_xpath(array &$b, string $sheet): DOMXPath
{
    $path = $b['sheets'][$sheet] ?? throw new RuntimeException("Missing sheet $sheet");
    if (!isset($b['dom'][$path])) {
        $b['dom'][$path] = new DOMDocument();
        $b['dom'][$path]->loadXML($b['z'][$path], LIBXML_NONET);
    }
    $xp = new DOMXPath($b['dom'][$path]);
    $xp->registerNamespace('x', XLSX_NS);
    return $xp;
}

function xlsx_col(string $ref): int
{
    $n = 0;
    foreach (str_split(preg_replace('/\d/', '', $ref)) as $ch) $n = $n * 26 + ord($ch) - 64;
    return $n;
}

// Finds a cell element, creating its row and cell in sorted order when $create is set.
function xlsx_cell(array &$b, string $sheet, string $ref, bool $create): ?DOMElement
{
    $xp = xlsx_xpath($b, $sheet);
    $c = $xp->query("//x:sheetData/x:row/x:c[@r='$ref']")->item(0);
    if ($c || !$create) return $c;
    $doc = $xp->document;
    $r = (int)preg_replace('/\D/', '', $ref);
    $row = $xp->query("//x:sheetData/x:row[@r='$r']")->item(0);
    if (!$row) {
        $row = $doc->createElementNS(XLSX_NS, 'row');
        $row->setAttribute('r', (string)$r);
        $next = null;
        foreach ($xp->query('//x:sheetData/x:row') as $e) if ((int)$e->getAttribute('r') > $r && !$next) $next = $e;
        $xp->query('//x:sheetData')->item(0)->insertBefore($row, $next);
    }
    $c = $doc->createElementNS(XLSX_NS, 'c');
    $c->setAttribute('r', $ref);
    $next = null;
    foreach ($xp->query('x:c', $row) as $e) if (xlsx_col($e->getAttribute('r')) > xlsx_col($ref) && !$next) $next = $e;
    $row->insertBefore($c, $next);
    return $c;
}

// Copies the merged ranges of row $src onto row $dst when $dst has no merges.
function xlsx_merge_row(array &$b, string $sheet, int $src, int $dst): void
{
    $xp = xlsx_xpath($b, $sheet);
    $mc = $xp->query('//x:mergeCells')->item(0);
    if (!$mc || $src === $dst) return;
    $refs = array_map(fn($m) => $m->getAttribute('ref'), iterator_to_array($xp->query('x:mergeCell', $mc)));
    if (preg_grep("/^[A-Z]+$dst:/", $refs)) return;
    foreach (preg_grep("/^[A-Z]+$src:[A-Z]+$src$/", $refs) as $ref) {
        $mc->appendChild($xp->document->createElementNS(XLSX_NS, 'mergeCell'))->setAttribute('ref', str_replace($src, $dst, $ref));
    }
    $mc->setAttribute('count', (string)$xp->query('x:mergeCell', $mc)->length);
}

// Gives cell $dst the alignment of cell $src while keeping its own borders and font, adding a cell style when needed.
function xlsx_align_like(array &$b, string $sheet, string $src, string $dst): void
{
    $from = (int)xlsx_cell($b, $sheet, $src, true)->getAttribute('s');
    $cell = xlsx_cell($b, $sheet, $dst, true);
    $to = (int)$cell->getAttribute('s');
    if ($from === $to) return;
    if (!isset($b['xf']["$from:$to"])) {
        if (!isset($b['styles'])) {
            $b['styles'] = new DOMDocument();
            $b['styles']->loadXML($b['z']['xl/styles.xml'], LIBXML_NONET);
        }
        $xp = new DOMXPath($b['styles']);
        $xp->registerNamespace('x', XLSX_NS);
        $xfs = $xp->query('//x:cellXfs')->item(0);
        $list = $xp->query('x:xf', $xfs);
        $copy = $list->item($to)->cloneNode(true);
        foreach (iterator_to_array($xp->query('x:alignment', $copy)) as $a) $copy->removeChild($a);
        if ($align = $xp->query('x:alignment', $list->item($from))->item(0)) {
            $copy->insertBefore($align->cloneNode(true), $copy->firstChild);
        }
        $copy->setAttribute('applyAlignment', '1');
        $xfs->appendChild($copy);
        $xfs->setAttribute('count', (string)($list->length + 1));
        $b['xf']["$from:$to"] = $list->length;
    }
    $cell->setAttribute('s', (string)$b['xf']["$from:$to"]);
}

function xlsx_shared(array &$b): array
{
    if ($b['ss'] === null) {
        $b['ss'] = [];
        if (isset($b['z']['xl/sharedStrings.xml'])) {
            $doc = new DOMDocument();
            $doc->loadXML($b['z']['xl/sharedStrings.xml'], LIBXML_NONET);
            $xp = new DOMXPath($doc);
            $xp->registerNamespace('x', XLSX_NS);
            foreach ($xp->query('//x:si') as $si) {
                $b['ss'][] = implode('', array_map(fn($t) => $t->textContent, iterator_to_array($xp->query('x:t | x:r/x:t', $si))));
            }
        }
    }
    return $b['ss'];
}

function xlsx_get(array &$b, string $sheet, string $ref): string
{
    $c = xlsx_cell($b, $sheet, $ref, false);
    if (!$c) return '';
    $t = $c->getAttribute('t');
    if ($t === 'inlineStr') return trim($c->textContent);
    $v = $c->getElementsByTagName('v')->item(0)?->textContent ?? '';
    return trim($t === 's' ? (xlsx_shared($b)[(int)$v] ?? '') : $v);
}

// Writes a string, int, or bool into a cell while keeping its style.
function xlsx_set(array &$b, string $sheet, string $ref, $val): void
{
    $c = xlsx_cell($b, $sheet, $ref, true);
    while ($c->firstChild) $c->removeChild($c->firstChild);
    $c->removeAttribute('t');
    $doc = $c->ownerDocument;
    if (is_bool($val) || is_int($val)) {
        if (is_bool($val)) $c->setAttribute('t', 'b');
        $c->appendChild($doc->createElementNS(XLSX_NS, 'v'))->appendChild($doc->createTextNode((string)(int)$val));
    } elseif ($val !== '') {
        $c->setAttribute('t', 'inlineStr');
        $t = $c->appendChild($doc->createElementNS(XLSX_NS, 'is'))->appendChild($doc->createElementNS(XLSX_NS, 't'));
        $t->setAttribute('xml:space', 'preserve');
        $t->appendChild($doc->createTextNode($val));
    }
}

// Maps each form control's linked cell to its ctrlProp and VML parts for a sheet.
function xlsx_controls(array &$b, string $sheet): array
{
    if (!isset($b['ctl'][$sheet])) {
        $rels = xlsx_rels($b['z'], $b['sheets'][$sheet]);
        $vml = current(array_filter($rels, fn($r) => $r[0] === 'vmlDrawing'))[1] ?? null;
        $b['ctl'][$sheet] = [];
        foreach ($rels as [$type, $path]) {
            if ($type === 'ctrlProp' && preg_match('/fmlaLink="\$?([A-Z]+)\$?(\d+)"/', $b['z'][$path], $m)) {
                $b['ctl'][$sheet][$m[1] . $m[2]] = ['prop' => $path, 'vml' => $vml];
            }
        }
    }
    return $b['ctl'][$sheet];
}

// Ticks or clears a checkbox via its linked cell, control properties, and VML shape.
function xlsx_check(array &$b, string $sheet, string $ref, bool $on): void
{
    xlsx_set($b, $sheet, $ref, $on);
    $ctl = xlsx_controls($b, $sheet)[$ref] ?? null;
    if (!$ctl) return;
    $prop = preg_replace('/ checked="[^"]*"/', '', $b['z'][$ctl['prop']]);
    $b['z'][$ctl['prop']] = $on ? str_replace('<formControlPr ', '<formControlPr checked="Checked" ', $prop) : $prop;
    if (!$ctl['vml']) return;
    $parts = explode('</x:ClientData>', $b['z'][$ctl['vml']]);
    foreach ($parts as &$part) {
        if (preg_match('/<x:FmlaLink>([^<]*)<\/x:FmlaLink>/', $part, $m) && str_replace('$', '', $m[1]) === $ref) {
            $part = preg_replace('/\s*<x:Checked>\d*<\/x:Checked>/', '', $part) . ($on ? '<x:Checked>1</x:Checked>' : '');
        }
    }
    $b['z'][$ctl['vml']] = implode('</x:ClientData>', $parts);
}

function xlsx_checked(array &$b, string $sheet, string $ref): bool
{
    $v = strtoupper(xlsx_get($b, $sheet, $ref));
    if ($v === '1' || $v === 'TRUE') return true;
    $ctl = xlsx_controls($b, $sheet)[$ref] ?? null;
    return $ctl && str_contains($b['z'][$ctl['prop']], 'checked="Checked"');
}

// Adds a relationship to a part's .rels file and returns its new id.
function xlsx_add_rel(array &$b, string $part, string $type, string $target): string
{
    $file = dirname($part) . '/_rels/' . basename($part) . '.rels';
    $xml = $b['z'][$file] ?? '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"></Relationships>';
    preg_match_all('/Id="rId(\d+)"/', $xml, $m);
    $id = 'rId' . (max([0, ...array_map('intval', $m[1])]) + 1);
    $b['z'][$file] = str_replace('</Relationships>', "<Relationship Id=\"$id\" Type=\"" . XLSX_REL . "/$type\" Target=\"$target\"/></Relationships>", $xml);
    return $id;
}

function xlsx_content_type(array &$b, string $match, string $element): void
{
    $ct = &$b['z']['[Content_Types].xml'];
    if (!str_contains($ct, $match)) $ct = str_replace('</Types>', "$element</Types>", $ct);
}

// Returns a drawn shape's from/to anchor XML, inset by $ix (left/right) and $iy (top/bottom) EMUs, or null if not found.
function xlsx_shape_anchor(array &$b, string $sheet, string $name, int $ix = 0, int $iy = 0): ?string
{
    $drawing = current(array_filter(xlsx_rels($b['z'], $b['sheets'][$sheet]), fn($r) => $r[0] === 'drawing'))[1] ?? null;
    $re = '#<xdr:twoCellAnchor[^>]*>(<xdr:from>.*?</xdr:to>)(?:(?!</xdr:twoCellAnchor>).)*?name="' . preg_quote($name, '#') . '"#s';
    if (!$drawing || !preg_match($re, $b['z'][$drawing], $m)) return null;
    $i = 0;
    return preg_replace_callback('#<xdr:(colOff|rowOff)>(\d+)</xdr:\1>#', function ($o) use (&$i, $ix, $iy) {
        $d = $o[1] === 'colOff' ? $ix : $iy;
        $v = max(0, $o[2] + ($i++ < 2 ? $d : -$d));
        return "<xdr:$o[1]>$v</xdr:$o[1]>";
    }, $m[1]);
}

// Blanks the text inside a drawn shape (e.g. the photo frame's instructions once a photo covers it).
function xlsx_clear_shape_text(array &$b, string $sheet, string $name): void
{
    $drawing = current(array_filter(xlsx_rels($b['z'], $b['sheets'][$sheet]), fn($r) => $r[0] === 'drawing'))[1] ?? null;
    if (!$drawing) return;
    $b['z'][$drawing] = preg_replace_callback('#<xdr:twoCellAnchor[^>]*>(?:(?!</xdr:twoCellAnchor>).)*?name="' . preg_quote($name, '#') . '".*?</xdr:twoCellAnchor>#s',
        fn($m) => preg_replace('#<a:t>[^<]*</a:t>#', '<a:t></a:t>', $m[0]), $b['z'][$drawing]);
}

// Places a PNG or JPEG anchored to a cell, offset and sized in EMUs, creating the sheet's drawing part if needed.
// With $anchor (from/to XML) the picture is stretched over that area instead.
function xlsx_add_image(array &$b, string $sheet, string $bytes, string $ext, string $ref, int $x, int $y, int $cx, int $cy, ?string $anchor = null): void
{
    $path = $b['sheets'][$sheet];
    $drawing = current(array_filter(xlsx_rels($b['z'], $path), fn($r) => $r[0] === 'drawing'))[1] ?? null;
    if (!$drawing) {
        for ($n = 1; isset($b['z']["xl/drawings/drawing$n.xml"]); $n++);
        $drawing = "xl/drawings/drawing$n.xml";
        $b['z'][$drawing] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"></xdr:wsDr>';
        xlsx_content_type($b, "/$drawing\"", "<Override PartName=\"/$drawing\" ContentType=\"application/vnd.openxmlformats-officedocument.drawing+xml\"/>");
        $rid = xlsx_add_rel($b, $path, 'drawing', "../drawings/drawing$n.xml");
        $xp = xlsx_xpath($b, $sheet);
        $root = $xp->document->documentElement;
        $el = $xp->document->createElementNS(XLSX_NS, 'drawing');
        $el->setAttributeNS(XLSX_REL, 'r:id', $rid);
        $next = $xp->query('x:legacyDrawing|x:legacyDrawingHF|x:picture|x:oleObjects|x:controls|x:webPublishItems|x:tableParts|x:extLst', $root)->item(0);
        $root->insertBefore($el, $next);
    }
    for ($n = 1; isset($b['z']["xl/media/image$n.png"]) || isset($b['z']["xl/media/image$n.jpeg"]); $n++);
    $b['z']["xl/media/image$n.$ext"] = $bytes;
    xlsx_content_type($b, "Extension=\"$ext\"", "<Default Extension=\"$ext\" ContentType=\"image/$ext\"/>");
    $rid = xlsx_add_rel($b, $drawing, 'image', "../media/image$n.$ext");
    $id = 9000 + $n;
    [$col, $row] = [xlsx_col($ref) - 1, (int)preg_replace('/\D/', '', $ref) - 1];
    $tag = $anchor ? 'twoCellAnchor' : 'oneCellAnchor';
    $open = $anchor ? "<xdr:twoCellAnchor editAs=\"oneCell\">$anchor"
        : "<xdr:oneCellAnchor><xdr:from><xdr:col>$col</xdr:col><xdr:colOff>$x</xdr:colOff><xdr:row>$row</xdr:row><xdr:rowOff>$y</xdr:rowOff></xdr:from><xdr:ext cx=\"$cx\" cy=\"$cy\"/>";
    $b['z'][$drawing] = str_replace('</xdr:wsDr>', $open
        . "<xdr:pic><xdr:nvPicPr><xdr:cNvPr id=\"$id\" name=\"Picture $n\"/><xdr:cNvPicPr><a:picLocks noChangeAspect=\"1\"/></xdr:cNvPicPr></xdr:nvPicPr>"
        . "<xdr:blipFill><a:blip xmlns:r=\"" . XLSX_REL . "\" r:embed=\"$rid\"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>"
        . "<xdr:spPr><a:xfrm><a:off x=\"0\" y=\"0\"/><a:ext cx=\"$cx\" cy=\"$cy\"/></a:xfrm><a:prstGeom prst=\"rect\"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>"
        . "<xdr:clientData/></xdr:$tag></xdr:wsDr>", $b['z'][$drawing]);
}

// Selects a dropdown item (1-based) via its linked cell and control properties.
function xlsx_select(array &$b, string $sheet, string $ref, int $index): void
{
    xlsx_set($b, $sheet, $ref, $index);
    $ctl = xlsx_controls($b, $sheet)[$ref] ?? null;
    if ($ctl) $b['z'][$ctl['prop']] = preg_replace('/ sel="\d+"/', " sel=\"$index\"", $b['z'][$ctl['prop']]);
}
