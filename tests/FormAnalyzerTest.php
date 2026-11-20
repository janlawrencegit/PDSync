<?php

use PHPUnit\Framework\TestCase;

// The form analyzer finds each question of a revised CS Form 212 Excel file by its printed label. Given the current
// official template itself, it must find every question where it already is.
final class FormAnalyzerTest extends TestCase
{
    private static array $result;

    public static function setUpBeforeClass(): void
    {
        self::$result = fa_analyze(file_get_contents(__DIR__ . '/../lib/templates/pds-2026.xlsx'));
    }

    public function testTheOfficialTemplateMatchesItself(): void
    {
        $items = self::$result['report']['items'];
        $this->assertGreaterThan(150, count($items));
        $notMatched = array_filter($items, fn($i) => $i['status'] !== 'matched');
        $this->assertSame([], array_map(fn($i) => "$i[id]: $i[status] ($i[how])", array_values($notMatched)));
        $moved = array_filter($items, fn($i) => $i['old'] !== $i['new']);
        $this->assertSame([], array_values($moved), 'nothing moves when the file is unchanged');
    }

    public function testNoLabelsAreReportedAsNewOrGone(): void
    {
        $this->assertSame([], self::$result['report']['added']);
        $this->assertSame([], self::$result['report']['gone']);
    }

    public function testColumnLettersRoundTrip(): void
    {
        foreach (['A' => 1, 'Z' => 26, 'AA' => 27, 'AZ' => 52, 'BA' => 53, 'XFD' => 16384] as $letters => $n) {
            $this->assertSame($n, xg_col($letters));
            $this->assertSame($letters, xg_letters($n));
        }
    }

    public function testLabelsAreNormalizedForMatching(): void
    {
        $this->assertSame(fa_norm('2.  FIRST NAME'), fa_norm('2. First   Name'));
        $this->assertNotSame(fa_norm('FIRST NAME'), fa_norm('MIDDLE NAME'));
    }
}
