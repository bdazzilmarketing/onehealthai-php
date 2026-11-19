<?php

namespace App\Reports\Pdf;

use RuntimeException;

/**
 * Helvetica and Helvetica-Bold as built into every PDF reader: measuring and
 * encoding text with the same metrics (advance widths plus pair kerning) the
 * original report library used, so lines wrap and align identically.
 */
final class StandardFont
{
    public const HELVETICA = 'Helvetica';

    public const HELVETICA_BOLD = 'Helvetica-Bold';

    /** @var array<string, array{glyphs: array<int, array{int, int}>, kern: array<string, int>}>|null */
    private static ?array $metrics = null;

    private static function metrics(string $font): array
    {
        self::$metrics ??= require __DIR__.'/metrics.php';

        return self::$metrics[$font];
    }

    /** @return list<int> Unicode code points */
    private static function codePoints(string $text): array
    {
        return $text === '' ? [] : array_map('mb_ord', mb_str_split($text, 1, 'UTF-8'));
    }

    /** @return array{int, int} WinAnsi code and advance width */
    private static function glyph(array $metrics, int $codePoint): array
    {
        return $metrics['glyphs'][$codePoint]
            ?? throw new RuntimeException(sprintf('WinAnsi cannot encode "%s" (0x%04x)', mb_chr($codePoint, 'UTF-8'), $codePoint));
    }

    public static function widthOfTextAtSize(string $font, string $text, float|int $size): float
    {
        $metrics = self::metrics($font);
        $points = self::codePoints($text);
        $total = 0;
        foreach ($points as $index => $point) {
            $total += self::glyph($metrics, $point)[1];
            if (isset($points[$index + 1])) {
                $total += $metrics['kern']["{$point},{$points[$index + 1]}"] ?? 0;
            }
        }

        return $total * ($size / 1000);
    }

    /** The text as WinAnsi bytes. Throws for a character the font cannot show. */
    public static function encode(string $font, string $text): string
    {
        $metrics = self::metrics($font);
        $bytes = '';
        foreach (self::codePoints($text) as $point) {
            $bytes .= chr(self::glyph($metrics, $point)[0]);
        }

        return $bytes;
    }
}
