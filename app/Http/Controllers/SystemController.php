<?php

namespace App\Http\Controllers;

use App\Auth\AuthService;
use App\Auth\Roles;
use App\Database\Db;
use App\Database\Tx;
use App\Database\UserScope;
use App\Http\Support\RawCookies;
use App\Reports\SalesReports;
use App\Storage\FileStore;
use App\Storage\SignedLinks;
use App\Submissions\SubmissionAdmin;
use App\Support\CronJobs;
use App\Support\Js;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoints that are not server functions: health checks, the scheduled jobs
 * and the internal report download (ports of src/routes/api/health,
 * src/routes/api/cron and src/lib/storage/report-download.server.ts).
 */
final class SystemController
{
    private const NO_STORE = ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'];

    /** Liveness: the process is up and serving requests. No database call, no detail. */
    public function health(): Response
    {
        return new Response(Js::stringify(['status' => 'ok']), 200, self::NO_STORE);
    }

    /** Readiness: the database answers through the runtime login. The response never says why it does not. */
    public function ready(Db $db): Response
    {
        try {
            $db->withServiceContext(fn (Tx $tx) => $tx->value('select 1'));

            return new Response(Js::stringify(['status' => 'ready']), 200, self::NO_STORE);
        } catch (\Throwable $error) {
            error_log('readiness check failed '.$error::class);

            return new Response(Js::stringify(['status' => 'unavailable']), 503, self::NO_STORE);
        }
    }

    /** Scheduled hourly: AI quota cleanup and expired sign-in records. */
    public function maintenance(Request $request, CronJobs $cron): Response
    {
        return $cron->run($request, 'maintenance', fn () => $cron->maintenance());
    }

    /** Scheduled every few minutes: retries pending, failed and stale sales reports and notifications. */
    public function salesReportRecovery(Request $request, CronJobs $cron): Response
    {
        return $cron->run($request, 'sales-report-recovery', fn () => app(SalesReports::class)->recoverPending());
    }

    /**
     * Internal sales-report PDF download. Needs both a valid short-lived
     * signed link (issued by getSubmissionReportUrl) and a signed-in admin viewer.
     */
    public function reportDownload(Request $request, string $id, SignedLinks $links, AuthService $auth, Db $db, SubmissionAdmin $submissions): Response
    {
        $text = fn (string $body, int $status) => new Response($body, $status, ['Content-Type' => 'text/plain;charset=UTF-8']);
        if (! Db::isUuid($id)) {
            return $text('Not found', 404);
        }
        $link = $links->verifyReportLink($id, $request->query->all());
        if (! $link) {
            return $text('Not found', 404);
        }

        try {
            $user = $auth->sessionUser(RawCookies::from($request));
        } catch (\Throwable) {
            $user = null;
        }
        if (! $user) {
            return $text('Unauthorized', 401);
        }
        try {
            Roles::requireAdminViewer(new UserScope($db, $user['id']), $user['id']);
        } catch (\Throwable) {
            return $text('Forbidden', 403);
        }

        try {
            $row = $submissions->detail($id);
        } catch (\Throwable) {
            $row = null;
        }
        if (! $row || $row['report_status'] !== 'generated' || ! $row['report_storage_path']) {
            return $text('Not found', 404);
        }
        $file = app(FileStore::class)->get(SalesReports::REPORT_BUCKET, $row['report_storage_path']);
        if (! $file) {
            return $text('Not found', 404);
        }

        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', "{$row['reference_id']}-internal-report.pdf");

        return new Response($file['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($link['download'] ? 'attachment' : 'inline')."; filename=\"{$filename}\"",
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
