<?php

namespace App\Submissions;

use App\Database\Db;
use App\Database\Tx;
use App\Support\AppError;
use App\Support\Audit;
use App\Support\Js;

/**
 * Admin reads of stored submissions and manual Make.com delivery (port of the
 * admin side of src/lib/submissions.server.ts). Callers check the role first:
 * these run in the service context.
 */
final class SubmissionAdmin
{
    public function __construct(private readonly Db $db, private readonly Audit $audit, private readonly MakeWebhook $make) {}

    public function list(int $limit = 500): array
    {
        try {
            return $this->db->withServiceContext(fn (Tx $tx) => $tx->rows(
                'select id, reference_id, created_at, full_name, company, email, phone, selected_mode, currency,
                        public_low, public_high, make_delivery_status, report_status, email_status
                 from public.calculator_submissions order by created_at desc limit ?', [$limit],
            ));
        } catch (\Throwable) {
            throw new AppError('Unable to load submissions');
        }
    }

    public function detail(string $id): ?array
    {
        try {
            return $this->db->withServiceContext(fn (Tx $tx) => $tx->rows('select * from public.calculator_submissions where id = ?', [$id]))[0] ?? null;
        } catch (\Throwable) {
            throw new AppError('Unable to load submission');
        }
    }

    /**
     * Admin-only retry that resends the stored snapshot; never recalculates.
     *
     * @return array{status: string, error: ?string}
     */
    public function retryDelivery(string $id, string $actorId, ?string $actorEmail): array
    {
        try {
            $data = $this->db->withServiceContext(fn (Tx $tx) => $tx->rows(
                'select id, reference_id, make_payload_snapshot from public.calculator_submissions where id = ?', [$id],
            ))[0] ?? null;
        } catch (\Throwable) {
            $data = null;
        }
        if ($data === null) {
            throw new AppError('Submission not found');
        }

        $this->audit->record('make_retry_initiated', $actorId, $actorEmail, ['referenceId' => $data['reference_id']]);
        $result = $this->deliver($data['id'], $data['reference_id'], $data['make_payload_snapshot'], 'manual_retry', $actorId, $actorEmail);

        return ['status' => $result['status'], 'error' => $result['error']];
    }

    /**
     * Sends a stored, immutable submission snapshot to Make.com and records the outcome.
     *
     * @return array{status: string, httpStatus: ?int, error: ?string}
     */
    public function deliver(string $submissionId, string $referenceId, mixed $payload, string $trigger, ?string $actorId = null, ?string $actorEmail = null): array
    {
        $attemptedAt = Js::isoNow();
        $this->audit->record('make_delivery_attempted', $actorId, $actorEmail, [
            'referenceId' => $referenceId, 'trigger' => $trigger, 'configured' => $this->make->isConfigured(),
        ]);

        $result = $this->make->send($payload);
        $status = $result['success'] ? 'sent' : 'failed';

        // The outcome is recorded best effort; the attempt count increments atomically.
        try {
            $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
                'update public.calculator_submissions set
                   make_delivery_status = ?,
                   make_delivery_attempted_at = ?,
                   make_delivery_completed_at = ?,
                   make_http_status = ?,
                   make_error_summary = ?,
                   make_attempt_count = coalesce(make_attempt_count, 0) + 1,
                   make_response_summary = ?::jsonb
                 where id = ?',
                [
                    $status, $attemptedAt, $result['success'] ? $result['deliveredAt'] : null, $result['httpStatus'], $result['error'],
                    $result['response'] === null ? null : Js::stringify($result['response']), $submissionId,
                ],
            ));
        } catch (\Throwable $error) {
            error_log('[submissions] delivery status update failed '.json_encode(['code' => Db::errorCode($error)]));
        }

        $this->audit->record($result['success'] ? 'make_delivery_succeeded' : 'make_delivery_failed', $actorId, $actorEmail, [
            'referenceId' => $referenceId, 'trigger' => $trigger, 'httpStatus' => $result['httpStatus'], 'error' => $result['error'],
        ]);

        return ['status' => $status, 'httpStatus' => $result['httpStatus'], 'error' => $result['error']];
    }
}
