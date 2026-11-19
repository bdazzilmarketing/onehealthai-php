<?php

namespace App\Support;

/**
 * JavaScript-compatible number and JSON behaviour. The original application
 * ran on Node; totals, stored snapshots and fingerprints must come out
 * identical here, so rounding, number formatting and JSON encoding follow
 * the ECMAScript rules rather than PHP's.
 */
final class Js
{
    /** The characters JavaScript's \s and String.prototype.trim treat as white space. */
    public const SPACE = '\t\n\x{000B}\f\r \x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /** Math.round: nearest integer, halves towards positive infinity. */
    public static function round(float|int $value): float
    {
        $value = (float) $value;
        if (! is_finite($value)) {
            return $value;
        }
        $floor = floor($value);

        return $value - $floor >= 0.5 ? $floor + 1.0 : $floor;
    }

    /** Math.round(value * 100) / 100 */
    public static function round2(float|int $value): float
    {
        return self::round($value * 100) / 100;
    }

    /** Number(value) for the kinds of value JSON and the database produce. */
    public static function toNumber(mixed $value): float
    {
        return match (true) {
            is_int($value), is_float($value) => (float) $value,
            $value === null => 0.0,
            is_bool($value) => $value ? 1.0 : 0.0,
            is_string($value) => self::parseNumber($value),
            default => NAN,
        };
    }

    private static function parseNumber(string $text): float
    {
        $text = preg_replace('/^['.self::SPACE.']+|['.self::SPACE.']+$/u', '', $text) ?? $text;
        if ($text === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?Infinity$/', $text)) {
            return $text[0] === '-' ? -INF : INF;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $text)) {
            return (float) $text;
        }

        return NAN;
    }

    /** String(number): the shortest text that reads back as the same number. */
    public static function number(float|int $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'Infinity' : '-Infinity';
        }
        if ($value == 0.0) {
            return '0';
        }
        $text = '';
        for ($precision = 0; $precision <= 16; $precision++) {
            $text = sprintf("%.{$precision}e", $value);
            if ((float) $text === $value) {
                break;
            }
        }
        [$mantissa, $exponent] = explode('e', $text);
        $negative = $mantissa[0] === '-';
        $digits = rtrim(str_replace(['-', '.'], '', $mantissa), '0');
        $digits = $digits === '' ? '0' : $digits;
        $count = strlen($digits);
        // The decimal point sits after $point digits.
        $point = (int) $exponent + 1;

        if ($point >= $count && $point <= 21) {
            $out = $digits.str_repeat('0', $point - $count);
        } elseif ($point > 0 && $point <= 21) {
            $out = substr($digits, 0, $point).'.'.substr($digits, $point);
        } elseif ($point > -6 && $point <= 0) {
            $out = '0.'.str_repeat('0', -$point).$digits;
        } else {
            $e = $point - 1;
            $out = $digits[0].($count > 1 ? '.'.substr($digits, 1) : '').'e'.($e < 0 ? '-' : '+').abs($e);
        }

        return ($negative ? '-' : '').$out;
    }

    /** String(value) for scalars. */
    public static function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => self::number($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_array($value) && array_is_list($value) => implode(',', array_map(fn ($v) => $v === null ? '' : self::string($v), $value)),
            default => '[object Object]',
        };
    }

    /**
     * JSON.stringify. Lists become arrays, other arrays and objects become
     * objects (an empty PHP array is an empty list; use an object for {}).
     */
    public static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? self::number($value) : 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }
        if ($value instanceof \JsonSerializable) {
            return self::stringify($value->jsonSerialize());
        }
        if (is_array($value) && array_is_list($value)) {
            return '['.implode(',', array_map(self::stringify(...), $value)).']';
        }
        $parts = [];
        foreach ((array) $value as $key => $item) {
            $parts[] = self::stringify((string) $key).':'.self::stringify($item);
        }

        return '{'.implode(',', $parts).'}';
    }

    /** String.prototype.trim */
    public static function trim(string $text): string
    {
        return preg_replace('/^['.self::SPACE.']+|['.self::SPACE.']+$/u', '', $text) ?? $text;
    }

    /** text.replace(/\s+/g, " ") */
    public static function collapseSpaces(string $text): string
    {
        return preg_replace('/['.self::SPACE.']+/u', ' ', $text) ?? $text;
    }

    /** String.prototype.length (UTF-16 code units). */
    public static function length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }

    /** String.prototype.slice on UTF-16 code units (a split surrogate pair is dropped). */
    public static function slice(string $text, int $start, ?int $end = null): string
    {
        if (mb_check_encoding($text, 'ASCII')) {
            $length = strlen($text);
            [$from, $to] = self::bounds($length, $start, $end);

            return $to > $from ? substr($text, $from, $to - $from) : '';
        }
        $units = mb_convert_encoding($text, 'UTF-16BE', 'UTF-8');
        [$from, $to] = self::bounds(intdiv(strlen($units), 2), $start, $end);
        if ($to <= $from) {
            return '';
        }
        $units = substr($units, $from * 2, ($to - $from) * 2);
        if ((ord($units[0]) & 0xFC) === 0xDC) {
            $units = substr($units, 2);
        }
        if ($units !== '' && (ord($units[strlen($units) - 2]) & 0xFC) === 0xD8) {
            $units = substr($units, 0, -2);
        }

        return mb_convert_encoding($units, 'UTF-8', 'UTF-16BE');
    }

    /** @return array{int, int} */
    private static function bounds(int $length, int $start, ?int $end): array
    {
        $from = $start < 0 ? max($length + $start, 0) : min($start, $length);
        $end ??= $length;
        $to = $end < 0 ? max($length + $end, 0) : min($end, $length);

        return [$from, $to];
    }

    /** String.prototype.toLowerCase */
    public static function lower(string $text): string
    {
        return mb_strtolower($text, 'UTF-8');
    }

    /** String.prototype.normalize("NFKC") */
    public static function nfkc(string $text): string
    {
        $normalized = \Normalizer::normalize($text, \Normalizer::FORM_KC);

        return $normalized === false ? $text : $normalized;
    }

    /** text.split(search).join(replacement) */
    public static function replaceAll(string $text, string $search, string $replacement): string
    {
        if ($search === '') {
            return implode($replacement, mb_str_split($text, 1, 'UTF-8'));
        }

        return str_replace($search, $replacement, $text);
    }

    /** number.toLocaleString("en-US") for whole numbers. */
    public static function grouped(int|float $value): string
    {
        return number_format((float) $value, 0, '.', ',');
    }

    /** new Date().toISOString() */
    public static function isoNow(): string
    {
        $now = microtime(true);
        $ms = (int) floor(($now - floor($now)) * 1000);

        return gmdate('Y-m-d\TH:i:s', (int) $now).sprintf('.%03dZ', $ms);
    }
}
