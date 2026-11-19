<?php

namespace App\Support;

use App\Database\Db;
use App\Database\Tx;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scheduled maintenance (port of src/lib/cron.server.ts). Each job is an HTTP
 * endpoint under /api/cron/ that any scheduler can call with
 *   Authorization: Bearer $CRON_SECRET
 * Without a configured secret (at least 32 characters) every call is refused.
 */
final class CronJobs
{
    public const MIN_CRON_SECRET_LENGTH = 32;

    /** @param  \Closure(string): mixed  $env */
    public function __construct(private readonly Db $db, private readonly \Closure $env) {}

    /** True only for a request carrying the configured secret. Constant-time. */
    public function isAuthorized(Request $request): bool
    {
        $secret = ($this->env)('CRON_SECRET');
        if (! is_string($secret) || Js::length($secret) < self::MIN_CRON_SECRET_LENGTH) {
            return false;
        }
        $header = $request->headers->get('authorization') ?? '';
        if (preg_match('/^Bearer (.+)$/D', $header, $match) !== 1) {
            return false;
        }

        return hash_equals(hash('sha256', $secret, true), hash('sha256', $match[1], true));
    }

    /**
     * Authorises, runs, and answers without leaking error detail.
     *
     * @param  callable(): mixed  $job
     */
    public function run(Request $request, string $name, callable $job): Response
    {
        if (! $this->isAuthorized($request)) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain;charset=UTF-8']);
        }
        $headers = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];
        try {
            return new Response(Js::stringify(['ok' => true, 'result' => $job()]), 200, $headers);
        } catch (\Throwable $error) {
            error_log("[cron:{$name}] failed ".preg_replace('#//[^/@\s]*@#', '//***@', $error->getMessage()));

            return new Response(Js::stringify(['ok' => false]), 500, $headers);
        }
    }

    /**
     * Hourly housekeeping: AI quota windows, expired sign-in records, old email ledger rows.
     *
     * @return array{expiredSessions: int, expiredLinks: int, emailRecords: int}
     */
    public function maintenance(): array
    {
        return $this->db->withServiceContext(function (Tx $tx) {
            $tx->select('select public.ai_quota_cleanup()');
            $sessions = $tx->execute('delete from auth.sessions where expires_at < now()');
            $verifications = $tx->execute('delete from auth.verifications where expires_at < now()');
            $emails = $tx->execute("delete from public.email_deliveries where created_at < now() - interval '30 days'");

            return ['expiredSessions' => $sessions, 'expiredLinks' => $verifications, 'emailRecords' => $emails];
        });
    }
}
