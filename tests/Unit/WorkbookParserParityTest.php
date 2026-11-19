<?php

namespace Tests\Unit;

use App\Import\WorkbookParser;
use App\Import\WorkbookTypes;
use App\Support\AppError;
use App\Support\Js;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The workbook importer must produce exactly what the original importer
 * produced. tests/Fixtures/workbook-parser-parity.json was recorded by running
 * the original parser over the workbooks in tests/Fixtures/workbooks.
 */
class WorkbookParserParityTest extends TestCase
{
    private static function fixture(): array
    {
        return json_decode(file_get_contents(__DIR__.'/../Fixtures/workbook-parser-parity.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function cases(): iterable
    {
        foreach (self::fixture()['cases'] as $case) {
            // The original library read any text file as a one-sheet CSV; this port accepts .xlsx only.
            if ($case['file'] !== 'not-a-workbook.xlsx') {
                yield $case['file'].' as '.$case['type'] => [$case];
            }
        }
    }

    #[DataProvider('cases')]
    public function test_parses_exactly_like_the_original(array $case): void
    {
        $bytes = file_get_contents(__DIR__.'/../Fixtures/workbooks/'.$case['file']);

        $this->assertSame($case['json'], Js::stringify(WorkbookParser::parse($bytes, $case['type'])));
    }

    public function test_name_helpers_match_the_original(): void
    {
        foreach (self::fixture()['helpers'] as [$name, $key, $sheet, $slug, $label]) {
            $this->assertSame($key, WorkbookTypes::normalizeKey($name));
            $this->assertSame($sheet, WorkbookParser::normalizeSheetName($name));
            $this->assertSame($slug, WorkbookTypes::slugCode($name));
            $this->assertSame($label, WorkbookTypes::subLabLabel($name));
        }
        foreach (self::fixture()['detect'] as [$sheetNames, $type]) {
            $this->assertSame($type, WorkbookParser::detectWorkbookType($sheetNames));
        }
    }

    public function test_a_file_that_is_not_a_workbook_is_refused(): void
    {
        foreach ([file_get_contents(__DIR__.'/../Fixtures/workbooks/not-a-workbook.xlsx'), '', "PK\x03\x04 truncated", random_bytes(2048)] as $bytes) {
            try {
                WorkbookParser::parse($bytes, 'genetics');
                $this->fail('Expected the file to be refused');
            } catch (AppError $error) {
                $this->assertSame('The workbook could not be read. It may be corrupted or not a valid .xlsx file.', $error->getMessage());
            }
        }
    }
}
