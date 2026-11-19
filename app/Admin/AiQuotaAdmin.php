<?php

namespace App\Admin;

use App\Database\Tx;
use App\Database\UserScope;
use App\Support\AppError;
use App\Support\Audit;
use App\Support\Js;

/** The AI usage limits page of the Admin Portal (port of the AI quota functions in src/lib/admin.functions.ts). */
final class AiQuotaAdmin
{
    /** Setting name => [lowest, highest] allowed whole number. */
    public const LIMITS = [
        'visitor_per_minute' => [1, 1000],
        'visitor_per_hour' => [1, 10000],
        'visitor_per_day' => [1, 100000],
        'ip_per_minute' => [1, 10000],
        'ip_per_hour' => [1, 100000],
        'ip_per_day' => [1, 1000000],
        'concurrent_per_visitor' => [1, 20],
        'concurrent_per_ip' => [1, 100],
        'global_requests_per_day' => [1, 10000000],
        'global_tokens_per_day' => [1000, 10000000000],
        'alert_threshold_1' => [1, 100],
        'alert_threshold_2' => [1, 100],
        'max_input_chars' => [50, 20000],
        'max_output_tokens' => [256, 32000],
        'max_turns' => [1, 500],
    ];

    public function __construct(private readonly Audit $audit) {}

    public function overview(UserScope $db): array
    {
        $today = gmdate('Y-m-d');
        $since = gmdate('Y-m-d\TH:i:s', time() - 24 * 60 * 60).'.000Z';
        try {
            [$s, $u, $recent, $rejected] = $db->run(fn (Tx $tx) => [
                $tx->rows('select * from public.ai_quota_settings where id = true')[0] ?? null,
                $tx->rows('select * from public.ai_global_usage where day = ?', [$today])[0] ?? null,
                $tx->rows(
                    "select id, created_at, visitor_hash, outcome, limit_triggered, estimated_tokens, error_category, ip_source
                     from public.ai_usage_events where outcome <> 'allowed' order by created_at desc limit 50"),
                $tx->select("select outcome from public.ai_usage_events where outcome <> 'allowed' and created_at >= ? limit 5000", [$since]),
            ]);
        } catch (\Throwable) {
            $s = null;
        }
        if ($s === null) {
            throw new AppError('Unable to load AI quota settings');
        }
        $u ??= ['requests' => 0, 'reserved_tokens' => 0, 'actual_tokens' => 0, 'rejected' => 0];
        $tokenPct = Js::round($u['reserved_tokens'] / $s['global_tokens_per_day'] * 1000) / 10;
        $requestPct = Js::round($u['requests'] / $s['global_requests_per_day'] * 1000) / 10;
        $worst = max($tokenPct, $requestPct);
        $alert = match (true) {
            $worst >= 100 => 'exceeded',
            $worst >= $s['alert_threshold_2'] => 'critical',
            $worst >= $s['alert_threshold_1'] => 'warning',
            default => 'ok',
        };
        $counts = [];
        foreach ($rejected as $row) {
            $counts[$row['outcome']] = ($counts[$row['outcome']] ?? 0) + 1;
        }
        unset($s['updated_by']);

        return [
            'settings' => $s,
            'usage' => $u + ['tokenPct' => $tokenPct, 'requestPct' => $requestPct, 'alert' => $alert],
            'rejectedLast24h' => (object) $counts,
            'recentEvents' => array_map(
                fn (array $event) => array_replace($event, ['visitor_hash' => $event['visitor_hash'] === null ? null : mb_substr($event['visitor_hash'], 0, 10)]),
                $recent,
            ),
        ];
    }

    /** @param  array<string, bool|int>  $data enabled plus every name in LIMITS, already validated */
    public function update(UserScope $db, string $userId, ?string $email, array $data): array
    {
        // Written in the caller's own context: row-level security enforces Admin/Super Admin again.
        try {
            $names = array_keys($data);
            $updated = $db->run(fn (Tx $tx) => $tx->execute(
                'update public.ai_quota_settings set '.implode(', ', array_map(fn (string $name) => "{$name} = ?", $names))
                .', updated_by = ?, updated_at = now() where id = true',
                [...array_values($data), $userId],
            ));
        } catch (\Throwable) {
            $updated = 0;
        }
        if ($updated !== 1) {
            throw new AppError('Unable to update AI quota settings');
        }
        $this->audit->record('ai_quota_settings_updated', $userId, $email, $data);

        return ['ok' => true];
    }
}
