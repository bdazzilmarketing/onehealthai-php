<?php

namespace Tests\Unit;

use App\Mail\EmailTemplates;
use App\Reports\Pdf\PdfCanvas;
use App\Reports\SalesReportPdf;
use App\Support\Js;
use PHPUnit\Framework\TestCase;

/**
 * The internal sales report against the original: every piece of text, box
 * and rule lands on the same page at the same position, in the same font,
 * size and colour, for reports recorded from the original code.
 */
final class ReportParityTest extends TestCase
{
    private static array $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/report-parity.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public static function fixture(): array
    {
        return self::$fixture ??= json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/report-parity.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function input(array $recorded, int $index): array
    {
        $recorded['submittedAt'] = new \DateTimeImmutable($recorded['submittedAt']);
        $recorded['generatedAt'] = new \DateTimeImmutable($recorded['generatedAt']);
        // JSON has no NaN: the third recorded report was built with one.
        if ($index === 2) {
            $recorded['internalTotal'] = NAN;
        }

        return $recorded;
    }

    public function test_layout_matches_the_original_draw_for_draw(): void
    {
        $compared = 0;
        foreach (self::$fixture['pdfs'] as $index => $case) {
            if (isset($case['error'])) {
                try {
                    SalesReportPdf::build(self::input($case['input'], $index));
                    $this->fail('expected the report to be refused');
                } catch (\RuntimeException $error) {
                    $this->assertSame($case['error'], $error->getMessage());
                }

                continue;
            }
            $canvas = null;
            $bytes = SalesReportPdf::build(self::input($case['input'], $index), $canvas);
            $this->assertInstanceOf(PdfCanvas::class, $canvas);
            $this->assertSame($case['pages'], $canvas->pageCount(), "report {$index}: pages");

            // The original drew page by page, then the running header and footer.
            $expected = $case['ops'];
            usort($expected, fn ($a, $b) => $a['page'] <=> $b['page']);
            $actual = $canvas->operations();
            $this->assertCount(count($expected), $actual, "report {$index}: operations");
            foreach ($expected as $i => $op) {
                foreach ($op as $key => $value) {
                    $what = "report {$index}, operation {$i} ({$op['op']}): {$key}";
                    if (is_string($value) || is_int($value)) {
                        $this->assertEquals($value, $actual[$i][$key], $what);
                    } elseif (is_array($value)) {
                        $this->assertEqualsWithDelta($value, $actual[$i][$key], 1e-6, $what);
                    } else {
                        $this->assertEqualsWithDelta($value, $actual[$i][$key], 1e-5, $what);
                    }
                    $compared++;
                }
            }

            $this->assertStringStartsWith('%PDF-1.7', $bytes);
            $this->assertStringEndsWith("%%EOF\n", $bytes);
            $this->assertSame($case['pages'], substr_count($bytes, '/Type /Page '));
            $this->assertSame($bytes, SalesReportPdf::build(self::input($case['input'], $index)), 'the same input gives the same file');
        }
        $this->assertGreaterThan(5000, $compared);
    }

    public function test_the_file_is_a_well_formed_pdf(): void
    {
        $bytes = SalesReportPdf::build(self::input(self::$fixture['pdfs'][1]['input'], 1));
        // Every cross-reference entry points at the object it names.
        $this->assertSame(1, preg_match('/startxref\n(\d+)\n%%EOF\n\z/', $bytes, $m));
        $xref = substr($bytes, (int) $m[1]);
        $this->assertStringStartsWith("xref\n0 ", $xref);
        preg_match_all('/^(\d{10}) 00000 n $/m', $xref, $entries);
        $this->assertGreaterThan(10, count($entries[1]));
        foreach ($entries[1] as $i => $offset) {
            $this->assertStringStartsWith(($i + 1)." 0 obj\n", substr($bytes, (int) $offset, 20));
        }
        // Streams inflate to the drawn text, encoded for the standard fonts.
        preg_match_all('/stream\n(.*?)\nendstream/s', $bytes, $streams);
        $content = implode('', array_map('gzuncompress', $streams[1]));
        $this->assertStringContainsString('<'.strtoupper(bin2hex('Internal Sales Report')).'> Tj', $content);
        $this->assertStringContainsString(strtoupper(bin2hex(mb_convert_encoding('Ünïcödé Çompany with tabs and newline ? ??', 'Windows-1252', 'UTF-8'))), $content);
        $this->assertStringNotContainsString('pdf-lib', $bytes);
    }

    public function test_amounts_and_volumes_are_formatted_as_the_original_did(): void
    {
        foreach (self::$fixture['money'] as $case) {
            $n = is_string($case['n']) ? ['NaN' => NAN, 'Infinity' => INF][$case['n']] : $case['n'];
            $this->assertSame($case['s'], SalesReportPdf::formatMoney($n, 'AED'));
        }
        foreach (self::$fixture['volumes'] as $case) {
            $this->assertSame($case['s'], Js::localeNumber($case['n']));
        }
        $this->assertSame('06 Sept 2026, 00:07 (Dubai, UTC+4)', SalesReportPdf::formatDate(new \DateTimeImmutable('2026-09-05T20:07:00Z')));
    }

    public function test_email_templates_render_the_same_subject_html_and_text(): void
    {
        $this->assertCount(5, self::$fixture['emails']);
        foreach (self::$fixture['emails'] as $index => $case) {
            $rendered = EmailTemplates::render($case['name'], $case['data']);
            $this->assertSame($case['subject'], $rendered['subject'], "email {$index}: subject");
            $this->assertSame($case['html'], $rendered['html'], "email {$index}: html");
            $this->assertSame($case['text'], $rendered['text'], "email {$index}: text");
        }
        // A long preview line is cut at 150 characters and gets no filler.
        $long = EmailTemplates::render('sales-report-notification', ['referenceId' => 'R', 'company' => str_repeat('c', 200)]);
        $this->assertStringContainsString('data-skip-in-text="true">New Lab Calculator submission R — '.str_repeat('c', 116).'</div><!--body-->', $long['html']);
    }
}
