<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;

/**
 * AI scope guard (port of src/lib/ai-guard.ts). Pure functions, no I/O.
 *
 * Layers:
 *   1. classifyInputDeterministic  runs BEFORE any OpenAI call.
 *   2. (semantic classifier in Classifier, a constrained model call)
 *   3. validateAiOutput            runs on every reply BEFORE it reaches the browser.
 * Rejected input/output never enters the stored conversation history.
 *
 * The rules themselves live in Data/generated.php, generated from the original.
 */
final class Guard
{
    public const BLOCKED_CATEGORIES = [
        'unsupported_language', 'professional_advice', 'medical_advice', 'creative', 'code', 'harmful',
        'prompt_disclosure', 'model_disclosure',
    ];

    /** Non-scripted reply words that must be copied verbatim from confidential text to count as a leak. */
    public const LEAK_WORD_THRESHOLD = 16;

    private const LEAK_SHINGLE = 8;

    // ------------------------------------------------------------------ language

    /** @return array{supported: bool, language: string} language is "en", "ar" or "other" */
    public static function detectLanguage(string $text): array
    {
        $letters = JsRegex::count('\p{L}', 'u', $text);
        if ($letters < 2) {
            return ['supported' => true, 'language' => 'en'];
        }
        $latin = self::countOf('RE_LATIN', $text);
        $arabic = self::countOf('RE_ARABIC', $text);
        $other = $letters - $latin - $arabic;

        // Non-Latin, non-Arabic scripts (Cyrillic, CJK, Devanagari, Hebrew, Greek, Thai...).
        // A few such characters (e.g. "μL", "β-hCG") are fine inside technical text.
        if ($other > 3 && $other / $letters > 0.2) {
            return ['supported' => false, 'language' => 'other'];
        }
        // Persian / Urdu use Arabic script with extra letters.
        $persianUrdu = JsRegex::count('[پچژگکیٹڈڑںے]', '', $text);
        if ($arabic > 0 && $persianUrdu / $arabic > 0.08 && $persianUrdu >= 3) {
            return ['supported' => false, 'language' => 'other'];
        }
        // Latin-script clauses are checked even inside a mainly Arabic message, so a
        // substantive foreign-language instruction cannot be hidden by dilution (4303).
        if (self::hasForeignLatinContent($text)) {
            return ['supported' => false, 'language' => 'other'];
        }
        if ($arabic >= $latin) {
            return ['supported' => true, 'language' => 'ar'];
        }

        // Latin script: distinguish English from other Latin-script languages.
        ['en' => $en, 'foreign' => $foreign] = self::countWords($text);
        $accented = self::countOf('RE_ACCENTED', $text);
        if ($foreign >= 2 && $foreign > $en) {
            return ['supported' => false, 'language' => 'other'];
        }
        if ($accented >= 3 && $accented / $latin > 0.04 && $en < 2) {
            return ['supported' => false, 'language' => 'other'];
        }

        return ['supported' => true, 'language' => 'en'];
    }

    /** @return array{en: int, foreign: int} */
    private static function countWords(string $text): array
    {
        $en = 0;
        $foreign = 0;
        foreach (JsRegex::all('[a-zÀ-ɏ\']+', '', Js::lower($text)) as $word) {
            if (Data::has('EN_WORDS', $word)) {
                $en++;
            } elseif (Data::has('FOREIGN_WORDS', $word)) {
                $foreign++;
            }
        }

        return ['en' => $en, 'foreign' => $foreign];
    }

    /**
     * Clause-level check (4303): a sentence/line of four or more words that is
     * mostly foreign function words is a substantive unsupported-language
     * instruction, even when surrounded by English laboratory text. Whole-message
     * dilution is also caught.
     */
    private static function hasForeignLatinContent(string $text): bool
    {
        foreach (JsRegex::split('[.!?;\n؟،]+', '', $text) as $clause) {
            if (JsRegex::count('[A-Za-zÀ-ɏ\']+', '', $clause) < 4) {
                continue;
            }
            ['en' => $en, 'foreign' => $foreign] = self::countWords($clause);
            if ($foreign >= 2 && $foreign > $en) {
                return true;
            }
        }
        $total = self::countWords($text);

        return $total['foreign'] >= 4 && $total['en'] <= $total['foreign'] * 2;
    }

    // ------------------------------------------------------------ normalisation

    /** Lower-cases, strips zero-width chars and separators used to split trigger words. */
    public static function normalise(string $text): string
    {
        return Js::lower(JsRegex::replace('[​-‏⁠﻿]', '', Js::nfkc($text), ''));
    }

    /** Collapses "s.y.s.t.e.m p-r-o-m-p-t" style obfuscation for a second pass. */
    private static function despaced(string $text): string
    {
        return JsRegex::replace('(?<=\b\w)[\s._\-*]+(?=\w\b)', '', $text, '');
    }

    /** @return list<string> */
    private static function decodeEmbedded(string $text): array
    {
        $out = [];
        foreach (self::allOf('BASE64_RUN', $text) as $run) {
            $decoded = self::atob(strlen($run) % 4 === 0 ? $run : $run.str_repeat('=', 4 - (strlen($run) % 4)));
            // Bytes, not characters: only printable ASCII counts as a decoded message.
            if ($decoded !== null && preg_match('/^[\x09\x0A\x0D\x20-\x7E]+\z/', $decoded) === 1) {
                $out[] = $decoded;
            }
        }
        // ROT13 of the whole message (cheap; catches "flfgrz cebzcg").
        $out[] = str_rot13($text);

        return $out;
    }

    /** The browser/Node atob(): null where it would throw. */
    private static function atob(string $data): ?string
    {
        if (strlen($data) % 4 === 0) {
            $data = preg_replace('/={1,2}\z/', '', $data);
        }
        if (strlen($data) % 4 === 1 || preg_match('/^[A-Za-z0-9+\/]*\z/', $data) !== 1) {
            return null;
        }
        $decoded = base64_decode($data.str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }

    // ------------------------------------------------------- deterministic rules

    private static function matchRules(string $text): ?string
    {
        $n = self::normalise($text);
        foreach ([$n, self::despaced($n)] as $candidate) {
            foreach (Data::get('RULES') as $rule) {
                if (JsRegex::test($rule['re'][0], $rule['re'][1], $candidate)) {
                    return $rule['category'];
                }
            }
        }
        foreach (Data::get('ARABIC_RULES') as $rule) {
            if (JsRegex::test($rule['re'][0], $rule['re'][1], $text)) {
                return $rule['category'];
            }
        }

        return null;
    }

    /**
     * Deterministic pre-check. Returns a blocked category, or "allowed" meaning
     * "no deterministic objection": the semantic classifier still runs.
     */
    public static function classifyInputDeterministic(string $text): string
    {
        if (! self::detectLanguage($text)['supported']) {
            return 'unsupported_language';
        }
        $direct = self::matchRules($text);
        if ($direct !== null) {
            return $direct;
        }
        // Encoded payloads: decode and re-check; any encoded instruction is treated as an attack.
        foreach (self::decodeEmbedded($text) as $decoded) {
            $hit = self::matchRules($decoded);
            if ($hit !== null) {
                return $hit;
            }
        }
        if (JsRegex::test('\b(base64|rot13|hex(adecimal)?|morse|binary|cipher|caesar|leetspeak|reversed? text)\b', 'i', $text)
            && JsRegex::test('\b(decode|encode|decrypt|encrypt|convert|translate|respond|answer|reply|write)\b', 'i', $text)) {
            return 'prompt_disclosure';
        }

        return 'allowed';
    }

    // ------------------------------------------------------------ safe replies

    public static function safeReply(string $category, string $language): string
    {
        if ($category === 'allowed' || $category === 'clarification') {
            return '';
        }

        return Data::get($language === 'ar' ? 'SAFE_AR' : 'SAFE_EN')[$category];
    }

    public static function outputRejected(string $language): string
    {
        return Data::get($language === 'ar' ? 'OUTPUT_REJECTED_AR' : 'OUTPUT_REJECTED_EN');
    }

    public static function replyLanguageFor(string $text): string
    {
        return self::detectLanguage($text)['language'] === 'ar' ? 'ar' : 'en';
    }

    // --------------------------------------------------------- output validation

    /** @return list<string> */
    private static function wordsOf(string $text): array
    {
        $spaced = JsRegex::replace('[^\p{L}\p{N}\s]', 'u', self::normalise($text), ' ');

        return array_values(array_filter(JsRegex::split('\s+', '', $spaced), fn (string $w) => $w !== ''));
    }

    /** @return array<string, true> */
    private static function shingles(string $text, int $size): array
    {
        $words = self::wordsOf($text);
        $out = [];
        for ($i = 0; $i + $size <= count($words); $i++) {
            $out[implode(' ', array_slice($words, $i, $size))] = true;
        }

        return $out;
    }

    /**
     * Phrases the instructions explicitly script for users (quoted text of 4+ words).
     *
     * @param  list<string>  $sources
     * @return list<list<string>>
     */
    private static function scriptedPhrases(array $sources): array
    {
        $out = [];
        foreach ($sources as $source) {
            foreach (JsRegex::matchAll('["“]([^"”\n]{8,240})["”]', '', $source) as $match) {
                $words = self::wordsOf($match[1] ?? '');
                if (count($words) >= 4) {
                    $out[] = $words;
                }
            }
        }

        return $out;
    }

    /**
     * Count reply words covered by confidential 8-word runs, excluding scripted user-facing wording.
     *
     * @param  list<string>  $confidential
     */
    public static function leakedWordCount(string $reply, array $confidential): int
    {
        $words = self::wordsOf($reply);
        $total = count($words);
        if ($total < self::LEAK_SHINGLE) {
            return 0;
        }
        $secret = [];
        foreach ($confidential as $source) {
            $secret += self::shingles($source, self::LEAK_SHINGLE);
        }
        $covered = array_fill(0, $total, false);
        for ($i = 0; $i + self::LEAK_SHINGLE <= $total; $i++) {
            if (isset($secret[implode(' ', array_slice($words, $i, self::LEAK_SHINGLE))])) {
                for ($j = $i; $j < $i + self::LEAK_SHINGLE; $j++) {
                    $covered[$j] = true;
                }
            }
        }
        $exempt = array_fill(0, $total, false);
        foreach (self::scriptedPhrases($confidential) as $phrase) {
            $size = count($phrase);
            for ($i = 0; $i + $size <= $total; $i++) {
                if (array_slice($words, $i, $size) === $phrase) {
                    for ($k = 0; $k < $size; $k++) {
                        $exempt[$i + $k] = true;
                    }
                }
            }
        }
        $count = 0;
        for ($i = 0; $i < $total; $i++) {
            if ($covered[$i] && ! $exempt[$i]) {
                $count++;
            }
        }

        return $count;
    }

    /** Which code rule matched (for content-free logging), or null. */
    public static function codeRuleHit(string $text): ?string
    {
        return match (true) {
            str_contains($text, '```') => 'fence',
            self::testOf('RE_CODE_STATEMENT', $text) => 'statement',
            self::testOf('RE_CODE_ANYWHERE', $text) => 'anywhere',
            self::testOf('RE_INLINE_CODE', $text) => 'inline',
            default => null,
        };
    }

    public static function looksLikeCode(string $text): bool
    {
        return self::codeRuleHit($text) !== null;
    }

    /**
     * @param  array{confidential: list<string>, modelId: string}  $ctx  every confidential
     *                                                                   instruction string given to the model, and the configured model identifier
     * @return array{ok: true}|array{ok: false, reason: string}
     */
    public static function validateAiOutput(?string $reply, array $ctx, ?string $scope): array
    {
        $text = $reply ?? '';
        $reject = fn (string $reason) => ['ok' => false, 'reason' => $reason];
        if (Js::trim($text) === '') {
            return $reject('empty');
        }
        if ($scope !== null && $scope !== '' && $scope !== 'laboratory') {
            return $reject("model_scope_{$scope}");
        }

        if (! self::detectLanguage($text)['supported']) {
            return $reject('language');
        }
        // Affirmative approval only: a reply with no recognisable English/Arabic words is inconclusive (fail closed).
        if (! self::hasSupportedLanguageEvidence($text)) {
            return $reject('language_inconclusive');
        }

        // Code / executable content.
        $codeHit = self::codeRuleHit($text);
        if ($codeHit !== null) {
            return $reject("code:{$codeHit}");
        }

        // Internal identifiers and prompt markers.
        if (self::testOf('RE_UUID', $text)) {
            return $reject('internal_id');
        }
        if (JsRegex::test('(TECHNICAL GUARDRAILS|CURRENT STATE|CATALOG \(|subLabId|labId=|parameterIds?|hardwareOnly|supportsHardware|json_schema|system prompt)', 'i', $text)) {
            return $reject('prompt_marker');
        }

        // Model / provider disclosure.
        $n = self::normalise($text);
        $model = Js::trim(self::normalise($ctx['modelId']));
        if ($model !== '' && str_contains($n, $model)) {
            return $reject('model_id');
        }
        if (JsRegex::test('\b(gpt-?\d|gpt-?4o|chatgpt|openai|anthropic|claude|gemini|llama|large language model|max_output_tokens|temperature setting)\b', '', $n)) {
            return $reject('model_info');
        }

        // Specific self-disclosure indicators (the model describing its own instructions).
        if (self::testOf('RE_INSTRUCTION_DISCLOSURE', $n)) {
            return $reject('prompt_leak');
        }

        // Verbatim leakage of confidential instructions, measured as reply words covered
        // by 8-word runs shared with a confidential source. Wording the instructions
        // tell the model to say to users (quoted phrases) is exempt.
        if (self::leakedWordCount($text, $ctx['confidential']) >= self::LEAK_WORD_THRESHOLD) {
            return $reject('prompt_leak');
        }

        // Medication / treatment recommendations (4302), independent of the input classifier.
        $med = self::medicalAdviceHit($text);
        if ($med !== null) {
            return $reject("medical:{$med}");
        }

        // Creative writing (4307). Laboratory vocabulary is NOT an exemption.
        if (self::looksCreative($text)) {
            return $reject('creative');
        }

        return ['ok' => true];
    }

    // ------------------------------------------- output: language / medical / creative

    /** At least some recognisable English function words or Arabic script must be present. */
    private static function hasSupportedLanguageEvidence(string $text): bool
    {
        if (self::countOf('RE_ARABIC', $text) >= 2) {
            return true;
        }
        $words = JsRegex::all('[a-z\']+', '', Js::lower($text));
        $en = 0;
        foreach ($words as $word) {
            if (Data::has('EN_WORDS', $word) || Data::has('EN_EXTRA', $word)) {
                $en++;
            }
        }

        // Very short confirmations ("OK.", "Done") are accepted only when they are English words.
        return $en >= 1 && (count($words) < 6 || $en / count($words) >= 0.08);
    }

    /** Which medical-advice output rule fired (content-free logging), or null. */
    public static function medicalAdviceHit(string $text): ?string
    {
        // Match both Arabic digit systems without altering the response or other guards.
        $numericText = JsRegex::replace('[٠-٩۰-۹]', '', $text, function (array $m) {
            $code = mb_ord($m[0], 'UTF-8');

            return (string) ($code - ($code >= 0x06F0 ? 0x06F0 : 0x0660));
        });
        foreach (Data::get('RE_MED_OUT') as $rule) {
            if (JsRegex::test($rule['re'][0], $rule['re'][1], $numericText)) {
                return $rule['key'];
            }
        }

        return null;
    }

    private static function lastWord(string $line): string
    {
        $words = JsRegex::split('\s+', '', Js::trim(JsRegex::replace('[^\p{L}\s]', 'u', Js::lower($line), ' ')));

        return $words[count($words) - 1] ?? '';
    }

    /**
     * Creative structure, judged by form rather than topic: verse markers, rhyming
     * short lines, or a stanza of short unstructured lines. Bullet lists, numbered
     * lists and "Field: value" summary lines are configuration structure and are
     * excluded, so normal summaries pass.
     */
    public static function looksCreative(string $text): bool
    {
        if (self::testOf('RE_CREATIVE_MARKERS', $text)) {
            return true;
        }
        $lines = array_values(array_filter(array_map(Js::trim(...), explode("\n", $text)), fn (string $l) => $l !== ''));
        $verse = array_values(array_filter($lines, fn (string $l) => Js::length($l) < 70
            && ! JsRegex::test('^([-•*–]|\d+[.)]|#)', '', $l) && ! JsRegex::test('[:：]', '', $l) && ! JsRegex::test('\d', '', $l)));
        // Rhymes: consecutive or alternate verse lines whose final words share an ending.
        $rhymes = 0;
        foreach ($verse as $i => $line) {
            foreach ([$i + 1, $i + 2] as $j) {
                $a = self::lastWord($line);
                $b = self::lastWord($verse[$j] ?? '');
                if (Js::length($a) >= 3 && Js::length($b) >= 3 && $a !== $b && Js::slice($a, -3) === Js::slice($b, -3)) {
                    $rhymes++;
                    break;
                }
            }
        }
        if (count($verse) >= 4 && $rhymes >= 2 && count($verse) / count($lines) >= 0.6) {
            return true;
        }

        // A stanza: most lines are short unstructured lines.
        return count($verse) >= 6 && count($verse) / count($lines) >= 0.75;
    }

    // ------------------------------------------------- assay consistency (RSV case)

    /** @return list<string> analyte keys named in the text */
    public static function analytesIn(string $text): array
    {
        $out = [];
        foreach (Data::get('ANALYTES') as $analyte) {
            if (JsRegex::test($analyte['re'][0], $analyte['re'][1], $text)) {
                $out[] = $analyte['key'];
            }
        }

        return $out;
    }

    /**
     * Deterministic guard against silent substitution of similar assays
     * (e.g. an Influenza strip selected when RSV was requested).
     *
     * @param  list<array{id: string, name: string}>  $added
     * @param  list<array{id: string, name: string}>  $catalogItems
     * @return array{dropped: list<array>, unavailable: list<string>, suggestions: array<string, list<array>>}
     */
    public static function checkAssayConsistency(string $userText, array $added, array $catalogItems): array
    {
        $requested = self::analytesIn($userText);
        $dropped = [];
        if ($requested !== []) {
            foreach ($added as $item) {
                $itemAnalytes = self::analytesIn($item['name']);
                if ($itemAnalytes !== [] && array_intersect($itemAnalytes, $requested) === []) {
                    $dropped[] = $item;
                }
            }
        }
        $unavailable = [];
        $suggestions = [];
        foreach (Data::get('ANALYTES') as $analyte) {
            if (! in_array($analyte['key'], $requested, true)) {
                continue;
            }
            $matches = array_values(array_filter($catalogItems, fn (array $i) => JsRegex::test($analyte['re'][0], $analyte['re'][1], $i['name'])));
            if ($matches === []) {
                $unavailable[] = $analyte['label'];
            } else {
                $suggestions[$analyte['label']] = array_slice($matches, 0, 6);
            }
        }

        return ['dropped' => $dropped, 'unavailable' => $unavailable, 'suggestions' => $suggestions];
    }

    private static function testOf(string $name, string $text): bool
    {
        [$source, $flags] = Data::get($name);

        return JsRegex::test($source, $flags, $text);
    }

    private static function countOf(string $name, string $text): int
    {
        [$source, $flags] = Data::get($name);

        return JsRegex::count($source, $flags, $text);
    }

    /** @return list<string> */
    private static function allOf(string $name, string $text): array
    {
        [$source, $flags] = Data::get($name);

        return JsRegex::all($source, $flags, $text);
    }
}
