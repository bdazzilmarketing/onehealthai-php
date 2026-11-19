<?php

namespace App\Ai;

use App\Support\Js;
use App\Support\JsRegex;

/**
 * Content-free AI response diagnostics for explicitly authorized synthetic QA
 * only (port of src/lib/ai-qa-diagnostics.server.ts). Disabled by default.
 * Activation requires BOTH server secrets and a matching per-request header;
 * the record holds only enums, counts and booleans, is written as one
 * structured server-log line, and is never returned to the caller.
 * Recording is best-effort and can never throw into, delay or alter a turn.
 */
final class QaDiagnostics
{
    public const HEADER = 'x-onehealth-qa-diagnostics';

    /** Longest permitted activation window from now; a later expiry disables diagnostics. */
    public const MAX_WINDOW_MS = 72 * 60 * 60 * 1000;

    private const MIN_TOKEN_LENGTH = 32;

    public const STAGES = ['approved', 'reviewer_rejected', 'final_validation_rejected', 'schema_rejected'];

    public const RESPONSE_KINDS = ['clarification', 'catalog_options', 'configuration_summary', 'unavailable_item', 'safe_refusal', 'invalid'];

    public const ISSUES = ['ambiguous', 'intent', 'invalid_selection', 'unavailable', 'volume', 'other'];

    public const REVIEWER_CATEGORIES = [
        'none', 'medical_advice', 'unsupported_language', 'creative', 'other_out_of_scope',
        'reviewer_timeout', 'reviewer_network', 'reviewer_http', 'reviewer_malformed', 'reviewer_inconsistent',
        'reviewer_not_configured', 'reviewer_too_long', 'other_failure', 'not_run',
    ];

    private const MONITORING = 'therapeutic drug monitoring|\btdm\b|drug level|blood[- ]level|level monitoring|vancomycin|digoxin|مراقب|مستوى (?:ال)?دواء|فانكومايسين|ديجوكسين';

    private const TREATMENT = '\b(?:dose|dosage|dosing|mg\/kg|prescri\w*|administer|take (?:the|this|your) medic\w*|stop (?:the|taking)|reduce (?:the|your) dose|treatment plan)\b|جرع|وصف(?:ة)? (?:طبي|دواء)|تناول الدواء|أوقف الدواء|اوقف الدواء|خطة العلاج|\bملغ\b';

    private const ANNUAL = 'annual|per year|yearly|سنوي|في السنة';

    /** @param  \Closure(string): mixed  $env */
    public function __construct(private readonly \Closure $env) {}

    /**
     * Pure activation check: secrets present, expiry valid/future/within window, header matches.
     *
     * @param  float  $nowMs  the current time in milliseconds
     */
    public static function active(mixed $token, mixed $expiresAt, mixed $header, float $nowMs): bool
    {
        if (! is_string($token) || Js::length($token) < self::MIN_TOKEN_LENGTH || ! is_string($expiresAt) || $expiresAt === '' || ! is_string($header) || $header === '') {
            return false;
        }
        $expiry = self::parseTime($expiresAt);
        if ($expiry === null || $expiry <= $nowMs || $expiry - $nowMs > self::MAX_WINDOW_MS) {
            return false;
        }

        return hash_equals($token, $header);
    }

    /**
     * An ISO 8601 instant in milliseconds, or null. Only the ISO form is
     * accepted, so anything else leaves diagnostics off.
     */
    private static function parseTime(string $text): ?float
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,9}))?)?(Z|[+-]\d{2}:\d{2})?)?\z/', $text, $m) !== 1) {
            return null;
        }
        [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        [$hour, $minute, $second] = [(int) ($m[4] ?? 0), (int) ($m[5] ?? 0), (int) ($m[6] ?? 0)];
        if (! checkdate($month, $day, $year) || $hour > 24 || $minute > 59 || $second > 59 || ($hour === 24 && ($minute > 0 || $second > 0))) {
            return null;
        }
        $seconds = gmmktime($hour, $minute, $second, $month, $day, $year);
        $zone = $m[8] ?? '';
        if ($zone !== '' && $zone !== 'Z') {
            $offset = ((int) substr($zone, 1, 2)) * 3600 + ((int) substr($zone, 4, 2)) * 60;
            $seconds -= $zone[0] === '-' ? -$offset : $offset;
        }
        $fraction = ($m[7] ?? '') === '' ? 0.0 : (float) ('0.'.substr($m[7], 0, 3));

        return $seconds * 1000 + floor($fraction * 1000 + 0.0000001);
    }

    private static function outputLanguage(string $text): string
    {
        $ar = JsRegex::count('[\u0600-\u06ff]', '', $text);
        $en = JsRegex::count('[A-Za-z]', '', $text);
        if ($ar + $en === 0) {
            return Js::trim($text) !== '' ? 'other' : 'none';
        }
        // Catalog product names are Latin even in Arabic replies, so Arabic dominates at a lower share.
        if ($ar / ($ar + $en) >= 0.35) {
            return $en / ($ar + $en) >= 0.65 ? 'mixed' : 'ar';
        }

        return $ar === 0 ? 'en' : 'mixed';
    }

    private static function mapReviewer(?string $reason): string
    {
        if ($reason === null) {
            return 'none';
        }
        $r = str_starts_with($reason, 'reviewer:') ? substr($reason, 9) : (str_starts_with($reason, 'reviewer_http_') ? 'reviewer_http' : $reason);

        return in_array($r, self::REVIEWER_CATEGORIES, true) ? $r : 'other_failure';
    }

    private static function whole(mixed $n): ?int
    {
        return (is_int($n) || is_float($n)) && is_finite($n) && $n >= 0 ? (int) floor($n) : null;
    }

    /**
     * Builds the record. Text inputs are read only to derive booleans, counts
     * and enums and are never copied.
     *
     * @param  array{stage: string, responseKind: ?string, requestedLanguage: string, reply: mixed, catalogOptionCount: mixed, selectedAssayCount: mixed, selectedEquipmentCount: mixed, issues: list<string>, reviewer: array{ran: bool, approved?: bool, reason?: ?string}, inputTokens?: mixed, outputTokens?: mixed, latencyMs: mixed}  $input
     */
    public static function buildRecord(array $input): array
    {
        $reply = is_string($input['reply'] ?? null) ? $input['reply'] : '';
        $issues = array_values(array_unique(array_map(
            fn ($issue) => in_array($issue, self::ISSUES, true) ? $issue : 'other', $input['issues'])));
        sort($issues, SORT_STRING);
        $reviewer = $input['reviewer'];
        $ran = ! empty($reviewer['ran']);
        $approved = $ran && ! empty($reviewer['approved']);
        $sections = array_filter(JsRegex::split('\n\s*\n', '', $reply), fn (string $s) => Js::trim($s) !== '');

        return [
            'stage' => in_array($input['stage'], self::STAGES, true) ? $input['stage'] : 'schema_rejected',
            'responseKind' => in_array($input['responseKind'] ?? '', self::RESPONSE_KINDS, true) ? $input['responseKind'] : 'invalid',
            'requestedLanguage' => $input['requestedLanguage'] === 'ar' ? 'ar' : 'en',
            'outputLanguage' => self::outputLanguage($reply),
            'catalogOptionCount' => self::whole($input['catalogOptionCount']) ?? 0,
            'selectedAssayCount' => self::whole($input['selectedAssayCount']) ?? 0,
            'selectedEquipmentCount' => self::whole($input['selectedEquipmentCount']) ?? 0,
            'renderedSectionCount' => count($sections),
            'hasAnnualVolume' => JsRegex::test(self::ANNUAL, 'i', $reply) && JsRegex::test('\d', '', $reply),
            'hasMonitoringTerms' => JsRegex::test(self::MONITORING, 'i', $reply),
            'hasTreatmentTerms' => JsRegex::test(self::TREATMENT, 'i', $reply),
            'reconciliationIssues' => $issues,
            'reviewerVerdict' => $ran ? ($approved ? 'approve' : 'reject') : 'not_run',
            'reviewerCategory' => $ran ? ($approved ? 'none' : self::mapReviewer($reviewer['reason'] ?? null)) : 'not_run',
            'inputTokens' => self::whole($input['inputTokens'] ?? null),
            'outputTokens' => self::whole($input['outputTokens'] ?? null),
            'latencyMs' => self::whole($input['latencyMs']) ?? 0,
        ];
    }

    /** Reads activation from server-only settings and the request header. Never throws. */
    public function resolve(AiRequest $request): bool
    {
        try {
            return self::active(
                ($this->env)('AI_QA_DIAGNOSTICS_TOKEN'),
                ($this->env)('AI_QA_DIAGNOSTICS_EXPIRES_AT'),
                ($request->header)(self::HEADER),
                microtime(true) * 1000,
            );
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Best-effort emission that swallows every failure. Returns nothing to the turn.
     *
     * @param  callable(): array  $build
     */
    public static function emit(bool $active, callable $build): void
    {
        if (! $active) {
            return;
        }
        try {
            error_log('[ai-qa-diag] '.Js::stringify($build()));
        } catch (\Throwable) {
            // diagnostics can never affect a turn
        }
    }
}
