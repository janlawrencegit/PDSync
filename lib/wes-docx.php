<?php
// Work Experience Sheet (CS Form No. 212 Attachment) filled into the official CSC Word template
// (lib/templates/wes.docx), so the result has the form's exact layout, fonts, bullets and shading.
//
// The template's table has four rows: the title, the instructions, and two sample positions. The samples are
// replaced by one row per entry, each a copy of the second sample row (the one without the "Sample:" heading), with
// the labels filled in, one sub-bullet per accomplishment and per duty line. The printed name goes above
// "(Signature over Printed Name of Employee/Applicant)" and the date replaces the blank after "Date:".
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/config.php';

const WES_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const WES_TEMPLATE = __DIR__ . '/templates/wes.docx';
const WES_MAX = 40;

// Keeps only the known fields of each entry, as trimmed strings (long text keeps its line breaks).
function wes_clean($entries): array
{
    $str = fn($v, $n) => is_scalar($v) && preg_match('/^.{0,' . $n . '}/su', trim(str_replace("\r", '', (string)$v)), $m) ? $m[0] : '';
    $out = [];
    foreach (array_slice(array_values(array_filter(is_array($entries) ? $entries : [], 'is_array')), 0, WES_MAX) as $e) {
        $row = [];
        foreach (['from', 'to', 'position', 'unit', 'supervisor', 'agency'] as $k) $row[$k] = $str($e[$k] ?? '', 500);
        foreach (['accomplishments', 'duties'] as $k) $row[$k] = $str($e[$k] ?? '', 3000);
        $out[] = $row;
    }
    return $out;
}

// "2011-02-11" -> "February 11, 2011" (as in the official sample); "PRESENT" -> "Present"; anything else as typed.
function wes_date(string $v): string
{
    if (strtoupper($v) === 'PRESENT') return 'Present';
    $d = DateTime::createFromFormat('!Y-m-d', $v);
    return $d ? $d->format('F j, Y') : $v;
}

// Lines of a list field, without bullets typed by the person.
function wes_lines(string $v): array
{
    return array_values(array_filter(array_map(fn($l) => trim(preg_replace('/^\s*[-•*o]\s+/u', '', $l)), explode("\n", $v)), fn($l) => $l !== ''));
}

function wes_text(DOMElement $p): string
{
    $t = '';
    foreach ($p->getElementsByTagNameNS(WES_NS, 't') as $n) $t .= $n->textContent;
    return $t;
}

// A deep copy of a paragraph (cloneNode is typed as returning any node).
function wes_copy(DOMElement $p): DOMElement
{
    $c = $p->cloneNode(true);
    if (!$c instanceof DOMElement) throw new RuntimeException('Could not copy a paragraph of the WES template.');
    return $c;
}

// Replaces a paragraph's text with $text in the paragraph's own formatting; $tail, if given, follows underlined.
function wes_set(DOMElement $p, string $text, ?string $tail = null): void
{
    $doc = $p->ownerDocument;
    $runs = iterator_to_array($p->getElementsByTagNameNS(WES_NS, 'r'));
    // The formatting of the run with the most text: some template lines start with a tiny spacer run in another font.
    $main = null;
    foreach ($runs as $r) {
        if (!$main || mb_strlen(trim($r->textContent, " \u{a0}")) > mb_strlen(trim($main->textContent, " \u{a0}"))) $main = $r;
    }
    $rPr = $main ? $main->getElementsByTagNameNS(WES_NS, 'rPr')->item(0) : null;
    foreach ($runs as $r) $r->parentNode->removeChild($r);
    $add = function (string $t, bool $underline = false) use ($doc, $p, $rPr) {
        $r = $doc->createElementNS(WES_NS, 'w:r');
        $props = $rPr ? $rPr->cloneNode(true) : $doc->createElementNS(WES_NS, 'w:rPr');
        if ($underline) {
            $u = $doc->createElementNS(WES_NS, 'w:u');
            $u->setAttributeNS(WES_NS, 'w:val', 'single');
            $props->appendChild($u);
        }
        $r->appendChild($props);
        $te = $doc->createElementNS(WES_NS, 'w:t');
        $te->setAttribute('xml:space', 'preserve');
        $te->appendChild($doc->createTextNode($t));
        $r->appendChild($te);
        $p->appendChild($r);
    };
    $add($text);
    if ($tail !== null) $add($tail, true);
}

// LibreOffice's command, when the server has it (the Docker image does): SOFFICE in the config, else found on PATH.
function wes_soffice(): ?string
{
    $cfg = cfg('SOFFICE');
    if ($cfg !== '') return is_file($cfg) ? $cfg : null;
    foreach (['soffice', 'libreoffice'] as $cmd) {
        $found = trim((string)@shell_exec('command -v ' . $cmd . ' 2>/dev/null'));
        if ($found !== '') return $found;
    }
    return null;
}

// The filled Word file as a PDF, converted by LibreOffice so it matches the Word layout. Each conversion gets its
// own folder and LibreOffice profile, so two at once do not collide; everything is deleted afterwards.
function wes_pdf(string $docx): string
{
    $soffice = wes_soffice();
    if (!$soffice) throw new RuntimeException('PDF is not available on this server (LibreOffice is not installed). Download the Word file instead.');
    $dir = sys_get_temp_dir() . '/pdsync-wes-' . bin2hex(random_bytes(8));
    mkdir($dir, 0700, true);
    try {
        file_put_contents("$dir/wes.docx", $docx);
        $cmd = sprintf('timeout 60 %s --headless --norestore --nologo -env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1',
            escapeshellarg($soffice), "$dir/profile", escapeshellarg($dir), escapeshellarg("$dir/wes.docx"));
        exec($cmd, $out, $code);
        if ($code !== 0 || !is_file("$dir/wes.pdf")) {
            error_log('WES PDF conversion failed: ' . implode(' ', $out));
            throw new RuntimeException('The PDF could not be made. Download the Word file instead.');
        }
        return (string)file_get_contents("$dir/wes.pdf");
    } finally {
        $rm = function (string $path) use (&$rm) {
            if (is_dir($path) && !is_link($path)) {
                foreach (scandir($path) ?: [] as $f) if ($f !== '.' && $f !== '..') $rm("$path/$f");
                @rmdir($path);
            } else {
                @unlink($path);
            }
        };
        $rm($dir);
    }
}

function wes_docx(array $entries, string $name, string $date): string
{
    $z = xlsx_unzip(file_get_contents(WES_TEMPLATE));
    $doc = new DOMDocument();
    $doc->preserveWhiteSpace = true;
    $doc->loadXML($z['word/document.xml'], LIBXML_NONET);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', WES_NS);

    $tbl = $xp->query('//w:body/w:tbl')->item(0);
    $rows = $xp->query('w:tr', $tbl);
    if (!$tbl instanceof DOMElement || $rows->length < 4) throw new RuntimeException('The WES template is not as expected.');
    $sampleWithItems = $rows->item(2);   // has sub-bullets under "List of Accomplishments"
    $proto = $rows->item(3);             // a plain position row, without the "Sample:" heading
    $itemProto = null;
    foreach ($xp->query('.//w:p', $sampleWithItems) as $p) {
        if ($p instanceof DOMElement && wes_text($p) === 'Developed recruitment plan') $itemProto = $p;
    }
    if (!$itemProto) throw new RuntimeException('The WES template is not as expected.');

    foreach ($entries as $e) {
        $row = $proto->cloneNode(true);
        $paras = array_values(array_filter(iterator_to_array($xp->query('.//w:p', $row)), fn($p) => $p instanceof DOMElement));
        $labels = [
            'Duration:' => 'Duration:  ' . wes_date($e['from']) . ' – ' . wes_date($e['to']),
            'Position:' => 'Position:  ' . $e['position'],
            'Name of Office/Unit:' => 'Name of Office/Unit: ' . $e['unit'],
            'Immediate Supervisor:' => 'Immediate Supervisor: ' . $e['supervisor'],
            'Name of Agency/Organization and Location:' => 'Name of Agency/Organization and Location: ' . $e['agency'],
        ];
        $accomplishments = $duties = null;
        foreach ($paras as $p) {
            $t = trim(str_replace("\u{a0}", ' ', wes_text($p)));
            foreach ($labels as $label => $value) {
                if (str_starts_with($t, $label)) wes_set($p, $value);
            }
            if (str_starts_with($t, 'List of Accomplishments')) $accomplishments = $p;
            if (str_starts_with($t, 'Responsible in performing')) $duties = $p;
        }
        if (!$accomplishments || !$duties) throw new RuntimeException('The WES template is not as expected.');
        // Accomplishments: one sub-bullet each, right under their heading.
        $after = $accomplishments;
        foreach (wes_lines($e['accomplishments']) as $line) {
            $item = wes_copy($itemProto);
            wes_set($item, $line);
            $after->parentNode->insertBefore($item, $after->nextSibling);
            $after = $item;
        }
        // Duties: the sample's duty paragraph becomes the first line; more lines are copies of it.
        $lines = wes_lines($e['duties']) ?: [''];
        wes_set($duties, array_shift($lines));
        $after = $duties;
        foreach ($lines as $line) {
            $more = wes_copy($duties);
            wes_set($more, $line);
            $after->parentNode->insertBefore($more, $after->nextSibling);
            $after = $more;
        }
        $tbl->appendChild($row);
    }
    $tbl->removeChild($sampleWithItems);
    $tbl->removeChild($proto);

    // Signature block: the printed name above the caption, and the date after "Date:".
    foreach ($xp->query('//w:body/w:p') as $p) {
        if (!$p instanceof DOMElement) continue;
        $t = wes_text($p);
        if (str_starts_with(trim($t), '(Signature over Printed Name') && $name !== '') {
            $line = wes_copy($p);
            wes_set($line, $name);
            foreach ($xp->query('.//w:rPr', $line) as $rPr) $rPr->insertBefore($doc->createElementNS(WES_NS, 'w:b'), $rPr->firstChild);
            $p->parentNode->insertBefore($line, $p);
        }
        if (str_contains($t, 'Date:') && str_contains($t, '___')) wes_set($p, '  Date: ', $date !== '' ? $date : str_repeat('_', 14));
    }
    $z['word/document.xml'] = $doc->saveXML();

    // The file's own properties carry no author from the template.
    if (isset($z['docProps/core.xml'])) {
        $z['docProps/core.xml'] = preg_replace('#<(dc:creator|cp:lastModifiedBy)>[^<]*</\1>#', '<$1>PDSync</$1>', $z['docProps/core.xml']);
    }
    return xlsx_zip($z);
}
