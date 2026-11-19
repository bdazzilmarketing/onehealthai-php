<?php

namespace App\Reports;

use App\Database\Db;
use App\Database\Tx;
use App\Mail\TemplateMailer;
use App\Storage\FileStore;
use App\Support\AppError;
use App\Support\Audit;
use App\Support\Js;
use RuntimeException;

/**
 * The sales-report workflow (port of src/lib/sales-report.server.ts): PDF
 * from the stored snapshot, private storage, email notification to the
 * configured recipients. Runs after the public estimate is returned; failures
 * never affect the stored submission or the customer's result.
 */
final class SalesReports
{
    public const REPORT_BUCKET = 'submission-reports';

    public const MAX_AUTO_ATTEMPTS = 5;

    private const DEFAULT_PORTAL_URL = 'https://onehealth.bdazzil.dev';

    private const STALE_CLAIM_MS = 15 * 60 * 1000;

    private const RECOVERY_WINDOW_SECONDS = 14 * 24 * 60 * 60;

    private const CLAIM_COLUMNS = 'report_status, report_auto_attempts, report_claimed_at, email_status, email_attempt_count, email_claimed_at';

    /** @var \Closure(array): string */
    private \Closure $buildPdf;

    /**
     * @param  \Closure(): FileStore  $files  resolved on first use
     * @param  \Closure(string): mixed  $env
     * @param  (\Closure(array): string)|null  $buildPdf  replaces the PDF builder (tests)
     */
    public function __construct(
        private readonly Db $db,
        private readonly Audit $audit,
        private readonly \Closure $files,
        private readonly TemplateMailer $mailer,
        private readonly \Closure $env,
        ?\Closure $buildPdf = null,
    ) {
        $this->buildPdf = $buildPdf ?? fn (array $input) => SalesReportPdf::build($input);
    }

    public static function reportPath(string $referenceId): string
    {
        return "{$referenceId}/{$referenceId}-internal-report.pdf";
    }

    private function portalUrl(): string
    {
        $configured = ($this->env)('PUBLIC_SITE_URL');
        $url = is_string($configured) && Js::trim($configured) !== '' ? Js::trim($configured) : self::DEFAULT_PORTAL_URL;

        return str_ends_with($url, '/') ? substr($url, 0, -1) : $url;
    }

    private static function summarize(\Throwable $error): string
    {
        return Js::slice($error->getMessage(), 0, 500);
    }

    /** Partial update of one submission (service context). */
    private function updateSubmission(string $id, array $fields): void
    {
        $sets = implode(', ', array_map(fn (string $column) => "{$column} = ?", array_keys($fields)));
        $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
            "update public.calculator_submissions set {$sets} where id = ?", [...array_values($fields), $id]));
    }

    private function loadSubmission(string $id): array
    {
        try {
            $row = Db::isUuid($id) ? $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                'select id, reference_id, created_at, selected_mode, full_name, company, phone, email, currency,
                        internal_total, public_low, public_high, calculation_version, dataset_versions,
                        internal_calculation_snapshot, report_status, report_storage_path, report_version,
                        email_status, email_attempt_count
                 from public.calculator_submissions where id = ?', [$id])) : null;
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            throw new AppError('Submission not found');
        }

        return $row;
    }

    /**
     * Generates and stores the PDF. Returns true on success; status is persisted either way.
     *
     * @param  array{trigger: string, actorId?: ?string, actorEmail?: ?string}  $meta
     */
    private function generate(array $row, array $meta): bool
    {
        $snapshot = json_decode($row['internal_calculation_snapshot'] ?? 'null', true);
        $datasetVersions = json_decode($row['dataset_versions'] ?? 'null', true);
        $path = self::reportPath($row['reference_id']);
        $version = (int) ($row['report_version'] ?? 0) + 1;
        try {
            $bytes = ($this->buildPdf)([
                'referenceId' => $row['reference_id'],
                'submittedAt' => new \DateTimeImmutable($row['created_at']),
                'mode' => $row['selected_mode'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'currency' => $row['currency'],
                'internalTotal' => Js::toNumber($row['internal_total']),
                'publicLow' => Js::toNumber($row['public_low']),
                'publicHigh' => Js::toNumber($row['public_high']),
                'calculationVersion' => $row['calculation_version'],
                'datasetVersions' => is_array($datasetVersions) ? $datasetVersions : [],
                'labs' => is_array($snapshot) && is_array($snapshot['labs'] ?? null) ? $snapshot['labs'] : [],
                'generatedAt' => new \DateTimeImmutable,
            ]);
            try {
                ($this->files)()->put(self::REPORT_BUCKET, $path, $bytes, 'application/pdf', true);
            } catch (\Throwable $error) {
                throw new RuntimeException("Report storage failed: {$error->getMessage()}");
            }
            $this->updateSubmission($row['id'], [
                'report_status' => 'generated',
                'report_storage_path' => $path,
                'report_generated_at' => Js::isoNow(),
                'report_version' => $version,
                'report_error' => null,
            ]);
            $this->audit->record('sales_report_generated', $meta['actorId'] ?? null, $meta['actorEmail'] ?? null,
                ['referenceId' => $row['reference_id'], 'trigger' => $meta['trigger'], 'version' => $version]);

            return true;
        } catch (\Throwable $error) {
            try {
                $this->updateSubmission($row['id'], ['report_status' => 'failed', 'report_error' => self::summarize($error)]);
            } catch (\Throwable $failed) {
                error_log('[sales-report:pdf] status update failed '.Js::stringify(['code' => Db::errorCode($failed)]));
            }
            $this->audit->record('sales_report_failed', $meta['actorId'] ?? null, $meta['actorEmail'] ?? null,
                ['referenceId' => $row['reference_id'], 'trigger' => $meta['trigger'], 'error' => self::summarize($error)]);

            return false;
        }
    }

    /** @return list<string> */
    private function activeRecipients(): array
    {
        try {
            $rows = $this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                'select email from public.sales_report_recipients where active order by created_at'));
        } catch (\Throwable) {
            throw new RuntimeException('Unable to load sales report recipients');
        }

        return array_column($rows, 'email');
    }

    /**
     * @param  array{trigger: 'automatic'|'manual_resend', actorId?: ?string, actorEmail?: ?string}  $meta
     * @return array{status: 'sent'|'failed', error: ?string}
     */
    private function notify(array $row, array $meta): array
    {
        $attempt = (int) ($row['email_attempt_count'] ?? 0) + 1;
        $results = [];
        $error = null;
        try {
            $recipients = $this->activeRecipients();
            if (count($recipients) === 0) {
                throw new RuntimeException('No active sales report recipients are configured');
            }
            $data = [
                'referenceId' => $row['reference_id'],
                'fullName' => $row['full_name'],
                'company' => $row['company'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'publicRange' => SalesReportPdf::formatMoney($row['public_low'], $row['currency']).' – '.SalesReportPdf::formatMoney($row['public_high'], $row['currency']),
                'adminUrl' => $this->portalUrl()."/adminpanel/submissions/{$row['id']}",
            ];
            foreach ($recipients as $to) {
                // Automatic sends share one key per recipient so retries never duplicate;
                // an explicit admin resend uses a per-attempt key.
                $key = $meta['trigger'] === 'automatic'
                    ? "sales-report:{$row['id']}:{$to}"
                    : "sales-report:{$row['id']}:{$to}:resend:{$attempt}";
                try {
                    $sent = $this->mailer->send('sales-report-notification', $to, $data, $key);
                    $results[] = ['email' => $to, 'outcome' => $sent ? 'sent' : 'not_sent'];
                } catch (\Throwable $sendError) {
                    $results[] = ['email' => $to, 'outcome' => 'failed'];
                    $error = self::summarize($sendError);
                }
            }
            $undelivered = array_filter($results, fn (array $r) => $r['outcome'] !== 'sent');
            if ($error === null && count($undelivered) > 0) {
                $error = 'Not delivered to: '.implode(', ', array_map(fn (array $r) => "{$r['email']} ({$r['outcome']})", $undelivered));
            }
        } catch (\Throwable $outer) {
            $error = self::summarize($outer);
        }

        $status = $error !== null ? 'failed' : 'sent';
        try {
            $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
                'update public.calculator_submissions set
                    email_status = ?,
                    email_sent_at = ?,
                    email_error = ?,
                    email_attempt_count = ?,
                    email_recipients = ?::jsonb
                 where id = ?',
                [$status, $status === 'sent' ? Js::isoNow() : null, $error, $attempt, Js::stringify($results), $row['id']]));
        } catch (\Throwable $failed) {
            error_log('[sales-report:email] status update failed '.Js::stringify(['code' => Db::errorCode($failed)]));
        }
        $this->audit->record(
            $status === 'sent' ? 'sales_notification_sent' : 'sales_notification_failed',
            $meta['actorId'] ?? null, $meta['actorEmail'] ?? null,
            ['referenceId' => $row['reference_id'], 'trigger' => $meta['trigger'], 'attempt' => $attempt, 'recipients' => $results, 'error' => $error],
        );

        return ['status' => $status, 'error' => $error];
    }

    private static function olderThan(?string $timestamp, float $ms): bool
    {
        return $timestamp === null || microtime(true) * 1000 - (float) (new \DateTimeImmutable($timestamp))->format('U.u') * 1000 > $ms;
    }

    /** Exponential backoff between automatic attempts: 5, 10, 20, 40 minutes. */
    private static function backoffMs(int $attempts): float
    {
        return 5 * 60 * 1000 * 2 ** max(0, $attempts - 1);
    }

    private static function reportClaimable(array $s): bool
    {
        if ((int) $s['report_auto_attempts'] >= self::MAX_AUTO_ATTEMPTS) {
            return false;
        }

        return match ($s['report_status']) {
            'pending' => true,
            'generating' => self::olderThan($s['report_claimed_at'], self::STALE_CLAIM_MS),
            'failed' => self::olderThan($s['report_claimed_at'], self::backoffMs((int) $s['report_auto_attempts'])),
            default => false,
        };
    }

    private static function emailClaimable(array $s): bool
    {
        if ($s['report_status'] !== 'generated' || (int) $s['email_attempt_count'] >= self::MAX_AUTO_ATTEMPTS) {
            return false;
        }

        return match ($s['email_status']) {
            'pending' => true,
            'sending' => self::olderThan($s['email_claimed_at'], self::STALE_CLAIM_MS),
            'failed' => self::olderThan($s['email_claimed_at'], self::backoffMs((int) $s['email_attempt_count'])),
            default => false,
        };
    }

    private function loadClaimState(string $id): ?array
    {
        try {
            return Db::isUuid($id) ? $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                'select '.self::CLAIM_COLUMNS.' from public.calculator_submissions where id = ?', [$id])) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Automatic, idempotent workflow for one stored submission. Each step is
     * claimed with a compare-and-set update so concurrent or repeated triggers
     * never produce a second PDF or a second normal notification. Automatic
     * email sends reuse one idempotency key per recipient.
     *
     * @return array{skipped: bool, reason?: string, report?: ?string, email?: ?string}
     */
    public function runAutomatic(string $submissionId): array
    {
        $state = $this->loadClaimState($submissionId);
        if ($state === null) {
            return ['skipped' => true, 'reason' => 'not_found'];
        }

        $report = null;
        if (self::reportClaimable($state)) {
            // Compare-and-set claim: matches only if nobody claimed since we read the state.
            try {
                $claimed = count($this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                    "update public.calculator_submissions set
                        report_status = 'generating',
                        report_auto_attempts = ?,
                        report_claimed_at = ?
                     where id = ?
                       and report_auto_attempts = ?
                       ".($state['report_status'] ? 'and report_status = ?' : '').'
                     returning id',
                    [(int) $state['report_auto_attempts'] + 1, Js::isoNow(), $submissionId, (int) $state['report_auto_attempts'],
                        ...($state['report_status'] ? [$state['report_status']] : [])]))) === 1;
            } catch (\Throwable) {
                $claimed = false;
            }
            if ($claimed) {
                $ok = $this->generate($this->loadSubmission($submissionId), ['trigger' => 'automatic']);
                $report = $ok ? 'generated' : 'failed';
                if (! $ok) {
                    error_log('[sales-report:pdf] automatic generation failed '.Js::stringify(['submissionId' => $submissionId]));
                }
                $state = $this->loadClaimState($submissionId);
                if ($state === null) {
                    return ['skipped' => false, 'report' => $report, 'email' => null];
                }
            }
        }

        if (! self::emailClaimable($state)) {
            return ['skipped' => $report === null, 'report' => $report, 'email' => null];
        }
        try {
            $emailClaim = count($this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                "update public.calculator_submissions set
                    email_status = 'sending', email_claimed_at = ?
                 where id = ?
                   and email_status = ?
                   and email_attempt_count = ?
                 returning id",
                [Js::isoNow(), $submissionId, $state['email_status'] ?? '', (int) $state['email_attempt_count']]))) === 1;
        } catch (\Throwable) {
            $emailClaim = false;
        }
        if (! $emailClaim) {
            return ['skipped' => $report === null, 'report' => $report, 'email' => null];
        }
        $result = $this->notify($this->loadSubmission($submissionId), ['trigger' => 'automatic']);
        if ($result['status'] === 'failed') {
            error_log('[sales-report:email] delivery failed '.Js::stringify(['submissionId' => $submissionId, 'error' => $result['error']]));
        }

        return ['skipped' => false, 'report' => $report, 'email' => $result['status']];
    }

    /**
     * Recovery sweep: retries pending, failed and stale reports and notifications within limits.
     *
     * @return array{checked: int, processed: list<array{id: string, outcome: string}>}
     */
    public function recoverPending(): array
    {
        try {
            $data = $this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                'select id, '.self::CLAIM_COLUMNS."
                 from public.calculator_submissions
                 where created_at >= ?
                   and (report_status in ('pending', 'generating', 'failed') or email_status in ('pending', 'sending', 'failed'))
                 limit 25",
                [gmdate('Y-m-d\TH:i:s\Z', time() - self::RECOVERY_WINDOW_SECONDS)]));
        } catch (\Throwable $error) {
            throw new RuntimeException("Recovery query failed: {$error->getMessage()}");
        }
        $results = [];
        foreach ($data as $row) {
            if (! self::reportClaimable($row) && ! self::emailClaimable($row)) {
                continue;
            }
            try {
                $r = $this->runAutomatic($row['id']);
                $results[] = ['id' => $row['id'], 'outcome' => 'report='.($r['report'] ?? '-').' email='.($r['email'] ?? '-')];
            } catch (\Throwable $error) {
                error_log('[sales-report:recovery] job failed '.Js::stringify(['submissionId' => $row['id'], 'error' => self::summarize($error)]));
                $results[] = ['id' => $row['id'], 'outcome' => 'error'];
            }
        }

        return ['checked' => count($data), 'processed' => $results];
    }

    /**
     * Admin action: rebuild the PDF from the stored snapshot. Does not email.
     *
     * @return array{status: 'generated'|'failed'}
     */
    public function regenerate(string $id, ?string $actorId, ?string $actorEmail): array
    {
        $row = $this->loadSubmission($id);
        $this->audit->record('sales_report_regenerate_requested', $actorId, $actorEmail, ['referenceId' => $row['reference_id']]);
        try {
            $this->updateSubmission($id, ['report_status' => 'generating']);
        } catch (\Throwable) {
            // the outcome below is what counts
        }
        $ok = $this->generate($row, ['actorId' => $actorId, 'actorEmail' => $actorEmail, 'trigger' => 'manual_regenerate']);

        return ['status' => $ok ? 'generated' : 'failed'];
    }

    /**
     * Admin action: resend the notification for an already generated report.
     *
     * @return array{status: 'sent'|'failed', error: ?string}
     */
    public function resend(string $id, ?string $actorId, ?string $actorEmail): array
    {
        $row = $this->loadSubmission($id);
        if ($row['report_status'] !== 'generated') {
            throw new AppError('Generate the report before resending the notification');
        }
        $this->audit->record('sales_notification_resend_requested', $actorId, $actorEmail, ['referenceId' => $row['reference_id']]);
        try {
            $this->updateSubmission($id, ['email_status' => 'sending']);
        } catch (\Throwable) {
            // the outcome below is what counts
        }

        return $this->notify($row, ['actorId' => $actorId, 'actorEmail' => $actorEmail, 'trigger' => 'manual_resend']);
    }
}
