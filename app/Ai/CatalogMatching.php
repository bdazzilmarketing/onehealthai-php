<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;

/** Matching visitor wording against catalog names (port of src/lib/ai-catalog-matching.ts). */
final class CatalogMatching
{
    public static function normalizeLabText(string $text): string
    {
        $text = Js::nfkc(Js::lower($text));
        $text = JsRegex::replace('[ً-ٰٟـ]', '', $text, '');
        $text = JsRegex::replace('[أإآ]', '', $text, 'ا');
        $text = str_replace('ى', 'ي', $text);
        $text = JsRegex::replace('[٠-٩]', '', $text, fn (array $m) => (string) (mb_ord($m[0], 'UTF-8') - 1632));

        return JsRegex::replace('[۰-۹]', '', $text, fn (array $m) => (string) (mb_ord($m[0], 'UTF-8') - 1776));
    }

    /** @return list<string> */
    public static function laboratoryTerms(string $text): array
    {
        $normalized = self::normalizeLabText($text);
        $terms = [];
        foreach (Data::get('ALIASES') as $alias) {
            if (JsRegex::test($alias['re'][0], $alias['re'][1], $normalized)) {
                $terms[] = $alias['term'];
            }
        }
        foreach (Data::get('ANALYTES') as $analyte) {
            if (JsRegex::test($analyte['re'][0], $analyte['re'][1], $text) || JsRegex::test($analyte['re'][0], $analyte['re'][1], $normalized)) {
                $terms[] = $analyte['key'];
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * Whole alphanumeric tokens, including short codes such as "ca", "ua", "tp".
     *
     * @return list<string>
     */
    private static function tokens(string $text): array
    {
        return JsRegex::all('[a-z0-9]+', '', self::normalizeLabText($text));
    }

    /**
     * Exact catalog name occurring in the request as a whole, delimited phrase.
     * "Ca" never matches inside "Atellica"; "Atellica C" never matches inside "Atellica CH".
     */
    public static function containsCatalogName(string $request, string $name): bool
    {
        $r = self::normalizeLabText($request);
        $n = Js::trim(self::normalizeLabText($name));
        if ($n === '') {
            return false;
        }
        $from = 0;
        while (true) {
            $at = strpos($r, $n, $from);
            if ($at === false) {
                return false;
            }
            if (! self::isWordCharacter(self::characterBefore($r, $at)) && ! self::isWordCharacter(self::characterAt($r, $at + strlen($n)))) {
                return true;
            }
            $from = $at + 1;
        }
    }

    private static function characterBefore(string $text, int $offset): string
    {
        $start = $offset;
        while ($start > 0 && (ord($text[--$start]) & 0xC0) === 0x80) {
        }

        return substr($text, $start, $offset - $start);
    }

    private static function characterAt(string $text, int $offset): string
    {
        return $offset < strlen($text) ? mb_substr(substr($text, $offset, 4), 0, 1, 'UTF-8') : '';
    }

    /** A letter or digit. The original read one UTF-16 unit, so characters outside the basic plane never count. */
    private static function isWordCharacter(string $character): bool
    {
        return $character !== '' && strlen($character) < 4 && preg_match('/[\p{L}\p{N}]/u', $character) === 1;
    }

    /**
     * Names of catalog items explicitly named in full, longest first, with nested names removed.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function exactlyNamedItems(string $request, array $names): array
    {
        $found = array_values(array_filter(array_values(array_unique($names)), fn (string $n) => self::containsCatalogName($request, $n)));
        usort($found, fn (string $a, string $b) => Js::length($b) <=> Js::length($a));
        // A shorter name counts only if it still occurs once longer named items are removed
        // ("Alinity C" inside "Alinity c Glucose…" alone is not a separate request).
        $kept = [];
        $rest = self::normalizeLabText($request);
        foreach ($found as $n) {
            $nn = Js::trim(self::normalizeLabText($n));
            if (! self::containsCatalogName($rest, $nn)) {
                continue;
            }
            $kept[] = $n;
            $rest = Js::replaceAll($rest, $nn, ' | ');
        }

        return $kept;
    }

    public static function matchesCatalogName(string $request, string $name): bool
    {
        if (self::containsCatalogName($request, $name)) {
            return true;
        }
        $terms = self::laboratoryTerms($request);
        $itemTerms = self::laboratoryTerms($name);
        $sectionTerms = Data::get('SECTION_TERMS');
        $analyteTerms = array_values(array_filter($terms, fn (string $t) => ! in_array($t, $sectionTerms, true)));
        if (array_intersect($analyteTerms, $itemTerms) !== []) {
            return self::technologyMatches($request, $name);
        }
        if ($analyteTerms !== [] && array_diff($itemTerms, $sectionTerms) !== []) {
            return false;
        }
        // Identity tokens: every non-generic, non-numeric token of the name, short codes
        // included. Platform words or pack numbers alone can never select an item.
        $requestTokens = self::tokens($request);
        $identity = array_filter(self::tokens($name), fn (string $w) => ! Data::has('STOP', $w) && ! Data::has('PLATFORM', $w) && ! JsRegex::test('\d', '', $w));

        return $identity !== [] && array_diff($identity, $requestTokens) === [] && self::technologyMatches($request, $name);
    }

    public static function technologyMatches(string $request, string $name): bool
    {
        $r = self::normalizeLabText($request);
        $n = self::normalizeLabText($name);
        $pcr = JsRegex::test('\bpcr\b|molecular|جزيئي', '', $r);
        $antigen = JsRegex::test('antigen|\bag\b|strip|مستضد|شريط', '', $r);
        if ($pcr && ! JsRegex::test('\bpcr\b|molecular', '', $n)) {
            return false;
        }
        if ($antigen && JsRegex::test('\bpcr\b|molecular', '', $n)) {
            return false;
        }
        if (JsRegex::test('\burine\b|بول', '', $r) && JsRegex::test('\bserum\b|\bplasma\b', '', $n)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, true>  $selectedIds
     * @return array{parameterIds: array<string, true>, hardwareIds: array<string, true>, sectionKeys: array<string, true>}
     */
    public static function targetedCatalog(array $catalog, string $request, array $selectedIds): array
    {
        $parameterIds = [];
        $hardwareIds = [];
        $sectionKeys = [];
        $normalized = self::normalizeLabText($request);
        $terms = self::laboratoryTerms($request);
        $monitoring = in_array('therapeutic drug monitoring', $terms, true);
        foreach ($catalog['labs'] as $lab) {
            foreach ($lab['sections'] as $s) {
                $sectionName = self::normalizeLabText($s['name']);
                $scoped = str_contains($normalized, $sectionName) || in_array($sectionName, $terms, true);
                $all = JsRegex::test('\ball\b|جميع|كل', '', $normalized) && $scoped;
                foreach ($s['parameters'] as $p) {
                    if (isset($selectedIds[$p['id']]) || self::matchesCatalogName($request, $p['name'])
                        || ($monitoring && array_intersect(self::laboratoryTerms($p['name']), ['digoxin', 'vancomycin']) !== [])
                        || $all || ($scoped && count($s['parameters']) <= 40)) {
                        $parameterIds[$p['id']] = true;
                        $sectionKeys[$s['key']] = true;
                    }
                }
                foreach ($s['hardware'] as $h) {
                    if (isset($selectedIds[$h['id']]) || self::matchesCatalogName($request, $h['name'])) {
                        $hardwareIds[$h['id']] = true;
                        $sectionKeys[$s['key']] = true;
                    }
                }
            }
        }

        return ['parameterIds' => $parameterIds, 'hardwareIds' => $hardwareIds, 'sectionKeys' => $sectionKeys];
    }

    /** A laboratory mention never authorizes every section within that lab. */
    public static function sectionRequested(string $request, string $labName, array $section): bool
    {
        $name = self::normalizeLabText($section['name']);

        return str_contains(self::normalizeLabText($request), $name) || in_array($name, self::laboratoryTerms($request), true);
    }
}
