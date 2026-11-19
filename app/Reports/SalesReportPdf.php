<?php

namespace App\Reports;

use App\Reports\Pdf\PdfCanvas;
use App\Reports\Pdf\StandardFont;
use App\Support\Js;

/**
 * The internal sales report PDF (port of src/lib/sales-report-pdf.server.ts).
 * Built exclusively from the immutable stored submission snapshot: nothing is
 * recalculated, re-priced or written by AI. The layout arithmetic is the
 * original's, so every line lands where it did.
 */
final class SalesReportPdf
{
    private const LAB_LABELS = [
        'core_lab' => 'Core Lab',
        'poct' => 'POCT',
        'molecular' => 'Molecular',
        'microbiology' => 'Microbiology',
        'genetics' => 'Genetics',
    ];

    private const PAGE_W = 595.28;

    private const PAGE_H = 841.89;

    private const M = 48;

    private const CONTENT_W = self::PAGE_W - self::M * 2;

    private const TOP = self::PAGE_H - 92; // below running header

    private const BOTTOM = 64; // above running footer

    private const NAVY = [0.043, 0.122, 0.302];

    private const BLUE = [0.098, 0.333, 0.8];

    private const INK = [0.11, 0.13, 0.17];

    private const MUTED = [0.42, 0.45, 0.5];

    private const RULE = [0.85, 0.87, 0.9];

    private const TINT = [0.955, 0.965, 0.98];

    private const WHITE = [1, 1, 1];

    private const PALE = [0.8, 0.85, 0.95];

    private const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sept', 'Oct', 'Nov', 'Dec'];

    private PdfCanvas $pdf;

    private float $y;

    /** Re-drawn at the top of a continuation page (for example a table header). */
    private ?\Closure $continuation = null;

    private function __construct() {}

    /** Standard PDF fonts only encode WinAnsi; replace anything else safely. */
    public static function safe(mixed $text): string
    {
        $value = Js::string($text ?? '');
        $value = preg_replace(
            ['/[\x{2010}-\x{2013}]/u', '/\x{2014}/u', '/[\x{2018}\x{2019}]/u', '/[\x{201C}\x{201D}]/u', '/[\r\n\t]+/u'],
            ['-', '-', "'", '"', ' '],
            $value,
        ) ?? '';
        $out = '';
        foreach (mb_str_split($value, 1, 'UTF-8') as $ch) {
            $code = mb_ord($ch, 'UTF-8');
            $out .= $code >= 0x20 && $code <= 0xFF ? $ch : '?';
        }

        return $out;
    }

    public static function formatMoney(mixed $amount, string $currency): string
    {
        $n = Js::toNumber($amount);

        return $currency.' '.(is_finite($n) ? Js::localeNumber($n, 2, 2) : '-');
    }

    /** A moment as shown in the report: Dubai time, day month year, 24-hour clock. */
    public static function formatDate(\DateTimeInterface $date): string
    {
        $dubai = \DateTimeImmutable::createFromInterface($date)->setTimezone(new \DateTimeZone('Asia/Dubai'));

        return $dubai->format('d').' '.self::MONTHS[(int) $dubai->format('n') - 1].' '.$dubai->format('Y, H:i').' (Dubai, UTC+4)';
    }

    /** @return list<string> */
    private static function wrap(string $text, string $font, float $size, float $width): array
    {
        $fits = fn (string $candidate) => StandardFont::widthOfTextAtSize($font, $candidate, $size) <= $width;
        $lines = [];
        $line = '';
        foreach (explode(' ', self::safe($text)) as $word) {
            $candidate = $line !== '' ? "{$line} {$word}" : $word;
            if ($fits($candidate)) {
                $line = $candidate;

                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            // Hard-break single words wider than the column.
            $rest = $word;
            while (! $fits($rest) && mb_strlen($rest, 'UTF-8') > 1) {
                $cut = mb_strlen($rest, 'UTF-8') - 1;
                while ($cut > 1 && ! $fits(mb_substr($rest, 0, $cut, 'UTF-8'))) {
                    $cut--;
                }
                $lines[] = mb_substr($rest, 0, $cut, 'UTF-8');
                $rest = mb_substr($rest, $cut, null, 'UTF-8');
            }
            $line = $rest;
        }
        if ($line !== '' || count($lines) === 0) {
            $lines[] = $line;
        }

        return $lines;
    }

    private function text(string $value, float $x, float $y, ?string $font = null, float $size = 9.5, ?array $color = null): void
    {
        $this->pdf->text(self::safe($value), $x, $y, $font ?? StandardFont::HELVETICA, $size, $color ?? self::INK);
    }

    private function textRight(string $value, float $right, float $y, ?string $font = null, float $size = 9.5, ?array $color = null): void
    {
        $font ??= StandardFont::HELVETICA;
        $this->text($value, $right - StandardFont::widthOfTextAtSize($font, self::safe($value), $size), $y, $font, $size, $color);
    }

    private function rule(float $y, array $color = self::RULE, float $thickness = 0.6): void
    {
        $this->pdf->line(self::M, $y, self::PAGE_W - self::M, $y, $thickness, $color);
    }

    private function newPage(): void
    {
        $this->pdf->addPage();
        $this->y = self::TOP;
        if ($this->continuation !== null) {
            ($this->continuation)();
        }
    }

    private function ensure(float $height): void
    {
        if ($this->y - $height < self::BOTTOM) {
            $this->newPage();
        }
    }

    private function sectionTitle(string $label): void
    {
        $this->ensure(40);
        $this->y -= 10;
        $this->text(strtoupper($label), self::M, $this->y, StandardFont::HELVETICA_BOLD, 8.5, self::BLUE);
        $this->y -= 6;
        $this->rule($this->y, self::NAVY, 0.9);
        $this->y -= 14;
    }

    private function field(string $label, mixed $value): void
    {
        $value = Js::string($value ?? '');
        $lines = self::wrap($value !== '' ? $value : '-', StandardFont::HELVETICA, 9.5, self::CONTENT_W - 150);
        $this->ensure(count($lines) * 13 + 2);
        $this->text($label, self::M, $this->y, null, 9, self::MUTED);
        foreach ($lines as $i => $line) {
            $this->text($line, self::M + 150, $this->y - $i * 13);
        }
        $this->y -= count($lines) * 13 + 3;
    }

    /** Name on the left (wrapped), one amount right-aligned. */
    private function amountRow(mixed $name, string $amount, bool $shade): void
    {
        $nameW = self::CONTENT_W - 0 - 130;
        $lines = self::wrap(Js::string($name ?? ''), StandardFont::HELVETICA, 9, $nameW);
        $h = count($lines) * 12 + 6;
        $this->ensure($h);
        if ($shade) {
            $this->pdf->rectangle(self::M, $this->y - $h + 9, self::CONTENT_W, $h, self::TINT);
        }
        foreach ($lines as $i => $line) {
            $this->text($line, self::M + 0 + 6, $this->y - $i * 12, null, 9);
        }
        $this->textRight($amount, self::PAGE_W - self::M - 6, $this->y, null, 9);
        $this->y -= $h;
    }

    private function tableHeader(string $left, string $right): void
    {
        $this->ensure(44); // keep the header together with at least one row
        $this->pdf->rectangle(self::M, $this->y - 6, self::CONTENT_W, 18, self::NAVY);
        $this->text($left, self::M + 6, $this->y, StandardFont::HELVETICA_BOLD, 8, self::WHITE);
        $this->textRight($right, self::PAGE_W - self::M - 6, $this->y, StandardFont::HELVETICA_BOLD, 8, self::WHITE);
        $this->y -= 20;
    }

    private function totalRow(string $label, string $amount): void
    {
        $this->ensure(18);
        $this->rule($this->y + 9);
        $this->text($label, self::M + 6, $this->y - 2, StandardFont::HELVETICA_BOLD, 9);
        $this->textRight($amount, self::PAGE_W - self::M - 6, $this->y - 2, StandardFont::HELVETICA_BOLD, 9);
        $this->y -= 18;
    }

    /**
     * @param  array{referenceId: string, submittedAt: \DateTimeInterface, mode: string, fullName: ?string, company: ?string, email: ?string, phone: ?string, currency: string, internalTotal: mixed, publicLow: mixed, publicHigh: mixed, calculationVersion: ?string, datasetVersions: array, labs: array, generatedAt: \DateTimeInterface}  $input
     * @param  PdfCanvas|null  $canvas  receives the drawn pages (for layout tests)
     * @return string the PDF bytes
     */
    public static function build(array $input, ?PdfCanvas &$canvas = null): string
    {
        $report = new self;
        $canvas = $report->pdf = new PdfCanvas(self::PAGE_W, self::PAGE_H);
        $report->draw($input);

        return $report->pdf->render([
            'title' => "{$input['referenceId']} — Internal Sales Report",
            'author' => 'One Health AI Lab Calculator',
            'subject' => 'Internal One Health sales document — confidential',
            'created' => $input['generatedAt'],
        ]);
    }

    private function draw(array $input): void
    {
        $cur = $input['currency'];
        $money = fn (mixed $amount) => self::formatMoney($amount, $cur);
        $bold = StandardFont::HELVETICA_BOLD;
        $this->pdf->addPage();
        $this->y = self::TOP;

        // ---------------------------------------------------------------- cover block
        $this->text('ONE HEALTH', self::M, $this->y, $bold, 22, self::NAVY);
        $this->y -= 18;
        $this->text('AI LAB CALCULATOR', self::M, $this->y, $bold, 10, self::BLUE);
        $this->y -= 26;
        $this->text('Internal Sales Report', self::M, $this->y, $bold, 17);
        $this->y -= 14;
        $this->text('Confidential - for One Health internal sales and admin use only.', self::M, $this->y, null, 9, self::MUTED);
        $this->y -= 14;

        $this->sectionTitle('Reference details');
        $this->field('Reference ID', $input['referenceId']);
        $this->field('Submission date', self::formatDate($input['submittedAt']));
        $this->field('Calculator mode', $input['mode'] === 'ai' ? 'AI Mode' : 'Manual Mode');

        $this->sectionTitle('Client details');
        $this->field('Full name', $input['fullName']);
        $this->field('Company', $input['company']);
        $this->field('Email', $input['email']);
        $this->field('Phone', $input['phone']);

        $this->sectionTitle('Laboratory configuration');

        foreach ($input['labs'] ?? [] as $lab) {
            $labName = Js::string($lab['labName'] ?? null);
            $this->ensure(60);
            $this->y -= 4;
            $this->pdf->rectangle(self::M, $this->y - 8, 3, 22, self::BLUE);
            $this->text($labName, self::M + 10, $this->y, $bold, 13, self::NAVY);
            $labLabel = ! empty($lab['labType']) && is_string($lab['labType']) ? (self::LAB_LABELS[$lab['labType']] ?? null) : null;
            if ($labLabel !== null && Js::lower($labLabel) !== Js::lower(Js::trim($labName))) {
                $this->text($labLabel, self::M + 10, $this->y - 12, null, 8.5, self::MUTED);
            }
            $this->y -= 30;

            foreach ($lab['subLabs'] ?? [] as $section) {
                $name = Js::string($section['subLabName'] ?? null);
                $hardware = $section['hardware'] ?? [];
                $parameters = $section['parameters'] ?? [];
                $volume = Js::toNumber($section['annualTestVolume'] ?? 0);
                $this->continuation = null;
                $this->ensure(90);
                $this->text($name, self::M, $this->y, $bold, 11);
                $this->y -= 13;
                $hardwareOnly = count($parameters) === 0 && (is_nan($volume) || $volume == 0);
                $this->text(
                    $hardwareOnly ? 'Equipment-only section' : 'Annual test volume: '.Js::localeNumber($volume),
                    self::M, $this->y, null, 9, self::MUTED,
                );
                $this->y -= 16;

                if (count($hardware) > 0) {
                    $this->tableHeader('SELECTED EQUIPMENT', "HARDWARE PRICE ({$cur})");
                    $this->continuation = function () use ($name, $cur) {
                        $this->text("{$name} - selected equipment (continued)", self::M, $this->y, null, 8.5, self::MUTED);
                        $this->y -= 14;
                        $this->tableHeader('SELECTED EQUIPMENT', "HARDWARE PRICE ({$cur})");
                    };
                    foreach (array_values($hardware) as $i => $h) {
                        $this->amountRow($h['hardwareName'] ?? null, $money($h['price'] ?? null), $i % 2 === 1);
                    }
                    $this->continuation = null;
                    $this->y -= 6;
                }

                if (count($parameters) > 0) {
                    $this->tableHeader('SELECTED TESTS / PARAMETERS', "ANNUAL COST ({$cur})");
                    $this->continuation = function () use ($name, $cur) {
                        $this->text("{$name} - selected tests (continued)", self::M, $this->y, null, 8.5, self::MUTED);
                        $this->y -= 14;
                        $this->tableHeader('SELECTED TESTS / PARAMETERS', "ANNUAL COST ({$cur})");
                    };
                    foreach (array_values($parameters) as $i => $p) {
                        $this->amountRow($p['parameterName'] ?? null, $money($p['annualCalculatedCost'] ?? null), $i % 2 === 1);
                    }
                    $this->continuation = null;
                    $this->y -= 6;
                }

                $this->ensure(64); // keep the three section totals together
                $this->totalRow('Hardware subtotal', $money($section['hardwareSubtotal'] ?? null));
                $this->totalRow('Parameter / test subtotal', $money($section['parameterSubtotal'] ?? null));
                $this->totalRow("Section subtotal - {$name}", $money($section['subtotal'] ?? null));
                $this->y -= 12;
            }

            $this->ensure(26);
            $this->pdf->rectangle(self::M, $this->y - 8, self::CONTENT_W, 22, self::TINT);
            $this->text("Laboratory subtotal - {$labName}", self::M + 6, $this->y, $bold, 10, self::NAVY);
            $this->textRight($money($lab['subtotal'] ?? null), self::PAGE_W - self::M - 6, $this->y, $bold, 10, self::NAVY);
            $this->y -= 34;
        }

        $this->sectionTitle('Investment summary');
        $this->ensure(60);
        $this->pdf->rectangle(self::M, $this->y - 44, self::CONTENT_W, 58, self::NAVY);
        $this->text('Internal calculated total', self::M + 12, $this->y - 4, null, 9, self::PALE);
        $this->textRight($money($input['internalTotal']), self::PAGE_W - self::M - 12, $this->y - 6, $bold, 15, self::WHITE);
        $this->text('Public estimate range (shown to client)', self::M + 12, $this->y - 32, null, 9, self::PALE);
        $this->textRight($money($input['publicLow']).' - '.$money($input['publicHigh']), self::PAGE_W - self::M - 12, $this->y - 32, $bold, 10, self::WHITE);
        $this->y -= 64;

        $this->sectionTitle('Technical reference');
        $versions = [];
        foreach ($input['datasetVersions'] ?? [] as $type => $version) {
            $versions[] = (self::LAB_LABELS[$type] ?? $type).' v'.Js::string($version);
        }
        $this->field('Dataset version(s)', implode(', ', $versions));
        $this->field('Calculation version', $input['calculationVersion']);
        $this->field('Report generated', self::formatDate($input['generatedAt']));

        $this->sectionTitle('Confidentiality');
        $notice = self::wrap(
            'Internal One Health sales document. Contains confidential customer and commercial information. Do not forward to the client or any third party.',
            StandardFont::HELVETICA, 9, self::CONTENT_W,
        );
        $this->ensure(count($notice) * 12);
        foreach ($notice as $i => $line) {
            $this->text($line, self::M, $this->y - $i * 12, null, 9, self::MUTED);
        }

        // ------------------------------------------------------- running header/footer
        $pages = $this->pdf->pageCount();
        for ($index = 0; $index < $pages; $index++) {
            $this->pdf->selectPage($index);
            $this->text('ONE HEALTH  |  AI LAB CALCULATOR', self::M, self::PAGE_H - 40, $bold, 8, self::NAVY);
            $this->textRight("Internal Sales Report  |  {$input['referenceId']}", self::PAGE_W - self::M, self::PAGE_H - 40, null, 8, self::MUTED);
            $this->rule(self::PAGE_H - 50, self::NAVY, 0.9);
            $this->rule(44);
            $this->text('Confidential - internal One Health sales document', self::M, 30, null, 7.5, self::MUTED);
            $this->textRight("{$input['referenceId']}  |  Page ".($index + 1)." of {$pages}", self::PAGE_W - self::M, 30, null, 7.5, self::MUTED);
        }
    }
}
