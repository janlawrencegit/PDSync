<?php

use PHPUnit\Framework\TestCase;

// pds_clean() is the server's gate for everything a browser sends: only known items, as trimmed strings, with the
// form's own rules applied.
final class PdsCleanTest extends TestCase
{
    private function clean(array $fields = [], array $tables = [], array $extra = []): array
    {
        return pds_clean(['fields' => $fields, 'tables' => $tables] + $extra);
    }

    public function testUnknownKeysAreDropped(): void
    {
        $out = $this->clean(['surname' => 'SANTOS', 'is_admin' => 'yes'], ['hackers' => [['x' => 1]]], ['role' => 'admin']);
        $this->assertSame('SANTOS', $out['fields']['surname']);
        $this->assertArrayNotHasKey('is_admin', $out['fields']);
        $this->assertArrayNotHasKey('hackers', $out['tables']);
        $this->assertArrayNotHasKey('role', $out);
    }

    public function testEveryKnownItemIsPresentAsAString(): void
    {
        $out = $this->clean(['height' => 1.65, 'weight' => ['nested']]);
        $this->assertSame('1.65', $out['fields']['height']);
        $this->assertSame('', $out['fields']['weight']);
        $this->assertSame('', $out['fields']['first_name']);
    }

    public function testTextIsTrimmedAndCapped(): void
    {
        $out = $this->clean(['surname' => '  REYES  ', 'pob' => str_repeat('A', 900)]);
        $this->assertSame('REYES', $out['fields']['surname']);
        $this->assertSame(500, mb_strlen($out['fields']['pob']));
    }

    public function testSuggestionsTakeTheirOfficialSpelling(): void
    {
        $out = $this->clean(['name_ext' => 'jr.'], ['work' => [['status' => 'permanent']]]);
        $this->assertSame('JR.', $out['fields']['name_ext']);
        $this->assertSame('Permanent', $out['tables']['work'][0]['status']);
    }

    public function testConditionalItemsAreBlankedUnlessTheirConditionHolds(): void
    {
        $filipino = $this->clean(['citizenship' => 'Filipino', 'dual_country' => 'Japan']);
        $this->assertSame('', $filipino['fields']['dual_country']);

        $dual = $this->clean(['citizenship' => 'Dual Citizenship', 'dual_country' => 'Japan']);
        $this->assertSame('Japan', $dual['fields']['dual_country']);
    }

    public function testPermanentAddressFollowsResidentialWhenSame(): void
    {
        $out = $this->clean(['perm_same' => 'Yes', 'res_city' => 'CALOOCAN CITY', 'perm_city' => 'SOMEWHERE ELSE']);
        $this->assertSame('CALOOCAN CITY', $out['fields']['perm_city']);
    }

    public function testOnlyPngOrJpegImagesAreKept(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgo=';
        $this->assertSame($png, $this->clean(['photo' => $png])['fields']['photo']);
        $this->assertSame('', $this->clean(['photo' => 'data:image/svg+xml;base64,PHN2Zz4='])['fields']['photo']);
        $this->assertSame('', $this->clean(['photo' => 'javascript:alert(1)'])['fields']['photo']);
        $this->assertSame('', $this->clean(['photo' => 'data:image/png;base64,' . str_repeat('A', MAX_IMAGE)])['fields']['photo']);
    }

    public function testTableRowsAreCappedAtTheFormsRows(): void
    {
        $out = $this->clean([], ['references' => array_fill(0, 10, ['name' => 'X'])]);
        $this->assertCount(3, $out['tables']['references']);
    }

    public function testWorkExperienceSheetKeepsLineBreaksAndKnownKeysOnly(): void
    {
        $out = $this->clean([], [], ['wes' => [
            ['position' => 'Clerk', 'duties' => "Files records\r\nAnswers calls", 'bogus' => 'x'],
            'not an entry',
        ]]);
        $this->assertCount(1, $out['wes']);
        $this->assertSame("Files records\nAnswers calls", $out['wes'][0]['duties']);
        $this->assertArrayNotHasKey('bogus', $out['wes'][0]);
        $this->assertCount(40, $this->clean([], [], ['wes' => array_fill(0, 60, ['position' => 'P'])])['wes']);
    }

    public function testProgressCountsAnsweredItems(): void
    {
        $this->assertSame(0, pds_progress($this->clean()));
        $some = pds_progress($this->clean(['surname' => 'A', 'first_name' => 'B', 'dob' => '1990-01-01']));
        $this->assertGreaterThan(0, $some);
        $this->assertLessThan(100, $some);
    }

    public function testCopyingSectionsKeepsOnlyThoseSections(): void
    {
        $data = $this->clean(['surname' => 'A'], ['work' => [['position' => 'Clerk']]], ['wes' => [['position' => 'Clerk']]]);
        $work = pds_pick($data, ['work']);
        $this->assertArrayNotHasKey('surname', $work['fields']);
        $this->assertSame('Clerk', $work['tables']['work'][0]['position']);
        $this->assertSame('Clerk', $work['wes'][0]['position'], 'the Work Experience Sheet goes with Section V');
        $this->assertArrayNotHasKey('wes', pds_pick($data, ['personal']));
    }
}
