<?php

namespace App\Ai;

use App\Database\Db;
use App\Database\Tx;
use App\Http\Support\ClientIp;
use App\Support\Js;
use RuntimeException;

/**
 * Server-side AI quota enforcement (Finding 4301; port of
 * src/lib/ai-quota.server.ts).
 *
 * Identity: a server-signed anonymous visitor cookie plus a trusted caller IP.
 * Raw IPs and raw visitor IDs are never stored; only HMAC hashes keyed with a
 * server-only secret. All counting happens atomically in the database
 * (ai_reserve_quota) BEFORE any OpenAI call, and fails closed.
 */
final class Quota
{
    public const VISITOR_COOKIE = 'oh_ai_vid';

    public const VISITOR_MAX_AGE = 60 * 60 * 24 * 365;

    /** @param  \Closure(string): ?string  $env */
    public function __construct(private readonly Db $db, private readonly \Closure $env) {}

    private function secret(): string
    {
        $secret = ($this->env)('AI_QUOTA_HASH_SECRET');
        if (! is_string($secret) || Js::length($secret) < 32) {
            throw new RuntimeException('QUOTA_SECRET_MISSING');
        }

        return $secret;
    }

    public function hmac(string $message): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $message, $this->secret(), true)), '+/', '-_'), '=');
    }

    /** Reads (or issues) the signed anonymous visitor ID. Tampered values are replaced. */
    private function visitorId(AiRequest $request): string
    {
        if ($request->visitorCookie !== null) {
            $parts = explode('.', $request->visitorCookie);
            $id = $parts[0];
            $signature = $parts[1] ?? '';
            if ($id !== '' && $signature !== '' && preg_match('/^[0-9a-f-]{36}\z/', $id) === 1 && hash_equals($this->hmac("vid:{$id}"), $signature)) {
                return $id;
            }
        }
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
        $request->issuedVisitorCookie = "{$id}.".$this->hmac("vid:{$id}");

        return $id;
    }

    /**
     * Caller IP for the network quota comes only from the configured trusted
     * ingress (ClientIp: TRUSTED_PROXY_MODE). Every other header is ignored.
     * Without a verified IP, all such callers share one conservative bucket.
     *
     * @return array{visitorHash: string, ipHash: string, ipPreviousHash: string, ipNextHash: string, ipSource: string}
     *                                                                                                                  the adjacent-day hashes are transient: never persisted or logged
     */
    public function identifyCaller(AiRequest $request): array
    {
        $visitorId = $this->visitorId($request);
        $resolved = ClientIp::resolveWithSource($request->remoteAddress, $request->header);
        [$ip, $source] = $resolved['ip'] !== null ? [$resolved['ip'], $resolved['source']] : ['untrusted-shared', 'untrusted_fallback'];
        $now = time();
        $day = fn (int $offset) => gmdate('Y-m-d', $now + $offset * 86400);

        // Persist only today's rotating hash. Adjacent hashes bridge midnight, including
        // a request identified just before midnight but reserved just after it.
        return [
            'visitorHash' => $this->hmac("v:{$visitorId}"),
            'ipHash' => $this->hmac("ip:{$day(0)}:{$ip}"),
            'ipPreviousHash' => $this->hmac("ip:{$day(-1)}:{$ip}"),
            'ipNextHash' => $this->hmac("ip:{$day(1)}:{$ip}"),
            'ipSource' => $source,
        ];
    }

    /** @return array{enabled: bool, max_input_chars: int, max_output_tokens: int, max_turns: int} */
    public function settings(): array
    {
        try {
            $rows = $this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                'select enabled, max_input_chars, max_output_tokens, max_turns from public.ai_quota_settings where id = true'));
        } catch (\Throwable) {
            $rows = null;
        }
        if ($rows === null || count($rows) !== 1) {
            throw new RuntimeException('QUOTA_UNAVAILABLE');
        }

        return [
            'enabled' => (bool) $rows[0]['enabled'],
            'max_input_chars' => (int) $rows[0]['max_input_chars'],
            'max_output_tokens' => (int) $rows[0]['max_output_tokens'],
            'max_turns' => (int) $rows[0]['max_turns'],
        ];
    }

    /** Conservative upper bound: ~2 chars/token (catalog IDs tokenize densely) for all input plus the full output allowance. */
    public static function estimateTokens(int $inputChars, int $maxOutputTokens): int
    {
        return intdiv($inputChars + 1, 2) + $maxOutputTokens;
    }

    /**
     * @return array{ok: true, reservationId: string}|array{ok: false, limit: string, retryAfter: int|float}
     */
    public function reserve(array $caller, int $estimatedTokens): array
    {
        if (($caller['ipPreviousHash'] ?? '') === '' || ($caller['ipNextHash'] ?? '') === '') {
            throw new RuntimeException('QUOTA_UNAVAILABLE');
        }
        // Named arguments select the five-argument function. No retry against a
        // legacy function on migration mismatch: fail closed before any AI.
        try {
            $raw = $this->db->withServiceContext(fn (Tx $tx) => $tx->value(
                'select public.ai_reserve_quota(
                    _visitor => ?::text,
                    _ip => ?::text,
                    _est_tokens => ?::integer,
                    _ip_previous => ?::text,
                    _ip_next => ?::text
                ) as r',
                [$caller['visitorHash'], $caller['ipHash'], $estimatedTokens, $caller['ipPreviousHash'], $caller['ipNextHash']]));
        } catch (\Throwable) {
            $raw = null;
        }
        $r = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($r) || $r === []) {
            throw new RuntimeException('QUOTA_UNAVAILABLE');
        }
        if (($r['ok'] ?? null) === true && is_string($r['reservation_id'] ?? null) && Db::isUuid($r['reservation_id'])) {
            return ['ok' => true, 'reservationId' => $r['reservation_id']];
        }
        if (($r['ok'] ?? null) === false) {
            return ['ok' => false, 'limit' => Js::string($r['limit'] ?? 'unknown'), 'retryAfter' => self::plainNumber(Js::toNumber($r['retry_after'] ?? 60))];
        }
        throw new RuntimeException('QUOTA_UNAVAILABLE');
    }

    /** $actualTokens null = usage unknown: the full reservation stays charged. */
    public function release(string $reservationId, int|float|null $actualTokens): void
    {
        try {
            $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
                'select public.ai_release_quota(_reservation => ?::uuid, _actual_tokens => ?::integer)',
                [$reservationId, $actualTokens === null ? null : (int) $actualTokens]));
        } catch (\Throwable $error) {
            AiLog::write('[ai-quota] release failed', ['code' => Db::errorCode($error)]);
        }
    }

    /**
     * @param  array{caller: ?array, outcome: string, limit?: ?string, estimatedTokens?: int|float|null, inputTokens?: int|float|null, outputTokens?: int|float|null, latencyMs?: int|float|null, errorCategory?: ?string}  $event
     */
    public function logUsageEvent(array $event): void
    {
        $whole = fn (string $key) => isset($event[$key]) ? (int) $event[$key] : null;
        try {
            $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
                'insert into public.ai_usage_events
                    (visitor_hash, ip_hash, ip_source, outcome, limit_triggered, estimated_tokens, input_tokens, output_tokens, latency_ms, error_category)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $event['caller']['visitorHash'] ?? null, $event['caller']['ipHash'] ?? null, $event['caller']['ipSource'] ?? null,
                    $event['outcome'], $event['limit'] ?? null, $whole('estimatedTokens'), $whole('inputTokens'), $whole('outputTokens'),
                    $whole('latencyMs'), $event['errorCategory'] ?? null,
                ]));
        } catch (\Throwable) {
            AiLog::write('[ai-quota] usage event logging failed');
        }
    }

    public static function friendlyLimitMessage(int|float $retryAfterSeconds): string
    {
        $minutes = max(1, (int) ceil($retryAfterSeconds / 60));
        $when = $minutes >= 120
            ? 'in about '.Js::number(Js::round($minutes / 60)).' hours'
            : "in about {$minutes} minute".($minutes === 1 ? '' : 's');

        return "The AI Calculator is temporarily unavailable due to usage limits. Please try again {$when}, or continue with Manual Mode.";
    }

    /** A whole number as an integer, so it is written without a decimal point. */
    private static function plainNumber(float $value): int|float
    {
        return is_finite($value) && floor($value) === $value && abs($value) < PHP_INT_MAX ? (int) $value : $value;
    }
}
