<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../lib/wes-docx.php';

// The Work Experience Sheet is filled into the official CSC Word template.
final class WesDocxTest extends TestCase
{
    private static function documentXml(string $docx): string
    {
        return xlsx_unzip($docx)['word/document.xml'];
    }

    private static function texts(string $xml): string
    {
        $doc = new DOMDocument();
        $doc->loadXML($xml);
        $out = [];
        foreach ($doc->getElementsByTagNameNS(WES_NS, 'p') as $p) $out[] = wes_text($p);
        return implode("\n", $out);
    }

    public function testEntriesReplaceTheSamplesInTheOfficialLayout(): void
    {
        $entries = wes_clean([
            ['from' => '2021-03-01', 'to' => 'PRESENT', 'position' => 'Administrative Officer II', 'unit' => 'Records Section',
             'supervisor' => 'Maria Reyes', 'agency' => 'DepEd, Caloocan City', 'accomplishments' => "- Digitized 4,000 records\nCut processing time", 'duties' => "Prepares payroll\nMaintains 201 files"],
            ['from' => '2018-06-01', 'to' => '2021-02-28', 'position' => 'Clerk III', 'agency' => 'City Government of Caloocan', 'duties' => 'Encodes documents'],
        ]);
        $xml = self::documentXml(wes_docx($entries, 'JUAN S. DELA CRUZ', 'November 19, 2026'));
        $text = self::texts($xml);

        $this->assertStringContainsString('WORK EXPERIENCE SHEET', $text);
        $this->assertStringContainsString('Include only the work experiences relevant to the position being applied to.', $text);
        $this->assertStringContainsString('Duration:  March 1, 2021 – Present', $text);
        $this->assertStringContainsString('Duration:  June 1, 2018 – February 28, 2021', $text);
        $this->assertStringContainsString('Immediate Supervisor: Maria Reyes', $text);
        $this->assertStringContainsString("Digitized 4,000 records\nCut processing time", $text, 'one sub-bullet per accomplishment, typed dashes removed');
        $this->assertStringContainsString("Prepares payroll\nMaintains 201 files", $text);
        $this->assertStringContainsString('JUAN S. DELA CRUZ', $text);
        $this->assertStringContainsString('November 19, 2026', $text);

        // The official sample people and the "Sample:" heading are gone; title + instructions + one row per entry.
        foreach (['Maria Estrada', 'Celia Romano', 'Sample:', 'Developed recruitment plan', '______________'] as $sample) {
            $this->assertStringNotContainsString($sample, $text);
        }
        $this->assertSame(4, substr_count($xml, '<w:tr '), 'title, instructions and two entries') ;
    }

    public function testTheResultIsAValidWordPackage(): void
    {
        $z = xlsx_unzip(wes_docx(wes_clean([['position' => 'Clerk', 'duties' => 'Files']]), '', ''));
        foreach (['[Content_Types].xml', 'word/document.xml', 'word/styles.xml', 'word/numbering.xml'] as $part) {
            $this->assertArrayHasKey($part, $z);
        }
        $doc = new DOMDocument();
        $this->assertTrue($doc->loadXML($z['word/document.xml']));
        $this->assertStringContainsString('<dc:creator>PDSync</dc:creator>', $z['docProps/core.xml']);
    }

    public function testPdfIsConvertedFromTheWordFileWhereLibreOfficeIsInstalled(): void
    {
        if (wes_soffice() === null) {
            $this->markTestSkipped('LibreOffice is not installed here (the Docker image has it).');
        }
        $pdf = wes_pdf(wes_docx(wes_clean([['position' => 'Clerk', 'duties' => 'Files']]), 'JUAN DELA CRUZ', 'November 19, 2026'));
        $this->assertStringStartsWith('%PDF-', $pdf);
        $this->assertGreaterThan(5000, strlen($pdf));
    }

    public function testInputIsCleaned(): void
    {
        $clean = wes_clean([['position' => str_repeat('A', 900), 'bogus' => 'x', 'duties' => "a\r\nb"], 'not an entry']);
        $this->assertCount(1, $clean);
        $this->assertSame(500, strlen($clean[0]['position']));
        $this->assertArrayNotHasKey('bogus', $clean[0]);
        $this->assertSame("a\nb", $clean[0]['duties']);
        $this->assertSame('Present', wes_date('PRESENT'));
        $this->assertSame('January 2, 2002', wes_date('2002-01-02'));
        $this->assertSame('1998', wes_date('1998'));
    }
}
