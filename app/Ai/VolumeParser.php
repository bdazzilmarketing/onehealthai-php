<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;

/** Port of src/lib/ai-volume-parser.ts. */
final class VolumeParser
{
    private const PERIOD = '(?:per year|a year|annually|annual(?:ly)?|yearly|سنو\S*|في السنه|في السنة|per month|a month|monthly|شهري\S*|في الشهر)';

    private const UNIT = '(?:tests?|اختبار(?:ات)?|فحص|فحوص(?:ات)?)';

    private const NUMBER = '(\d[\d,]*(?:\.\d+)?)';

    /**
     * Read only explicit throughput, never pack sizes, assay counts or model numbers.
     *
     * @return array{value: ?float, incompatible: bool}
     */
    public static function parse(string $message): array
    {
        $text = CatalogMatching::normalizeLabText($message);
        [$period, $unit, $number] = [self::PERIOD, self::UNIT, self::NUMBER];
        // Alternatives/ranges sharing one period must not authorize the last number.
        $alternatives = "{$number}\\s*(?:{$unit}\\s*)?(?:{$period}\\s*)?(?:or|او|إلى|الى|to|[-–—])\\s*{$number}";
        if (JsRegex::test($period, '', $text) && JsRegex::test($alternatives, '', $text)) {
            return ['value' => null, 'incompatible' => true];
        }
        $suffix = "{$number}\\s*(?:{$unit}\\s*)?({$period})";
        $prefix = "({$period})\\s*(?:volume|throughput|total|حجم|اجمالي)?\\s*[:=]?\\s*{$number}\\s*(?:{$unit})?";
        $found = [];
        foreach (JsRegex::matchAll($suffix, '', $text) as $match) {
            $found[] = ['value' => Js::toNumber(str_replace(',', '', $match[1] ?? '')), 'monthly' => JsRegex::test('month|شهر', '', $match[2] ?? '')];
        }
        foreach (JsRegex::matchAll($prefix, '', $text) as $match) {
            $found[] = ['value' => Js::toNumber(str_replace(',', '', $match[2] ?? '')), 'monthly' => JsRegex::test('month|شهر', '', $match[1] ?? '')];
        }
        if (JsRegex::test('per day|daily|per week|weekly|يومي|اسبوع|في اليوم', '', $text)) {
            return ['value' => null, 'incompatible' => true];
        }
        if (count($found) !== 1) {
            // Pack-only and assay-count numbers are not requests for throughput.
            $withoutPacks = JsRegex::replace('\d+\s*[x×]\s*\d+\s*(?:tests?\s*\/\s*kit|test\/kit)', '', $text, '');
            $withoutPacks = JsRegex::replace('\(\s*\d+\s*t\s*\)|\b\d+\s*(?:t|tests?\s*\/\s*kit)\b', '', $withoutPacks, '');
            $withoutPacks = JsRegex::replace('\b\d+\s*(?:assays?|parameters?|selected tests?)\b', '', $withoutPacks, '');

            return ['value' => null, 'incompatible' => count($found) > 1 || JsRegex::test('\d|annual|yearly|سنو|month|شهر', '', $withoutPacks)];
        }
        $entry = $found[0];
        $value = $entry['value'] * ($entry['monthly'] ? 12 : 1);

        return is_finite($value) && $value > 0 && $value <= 1_000_000_000
            ? ['value' => $value, 'incompatible' => false] : ['value' => null, 'incompatible' => true];
    }
}
