<?php

namespace App\Leads;

use App\Database\Db;
use App\Database\Tx;
use App\Support\AppError;

/**
 * Server-only lead persistence (port of src/lib/lead-session.server.ts). A
 * lead record is written only when the visitor submits the final lead form;
 * nothing is stored when a calculator is merely opened. The browser never
 * receives another visitor's record, and no pricing data is stored or read
 * here.
 */
final class LeadSessions
{
    public const COOKIE_NAME = 'oh_calc_session';

    public const COOKIE_MAX_AGE = 60 * 60 * 8;

    private const COLUMNS = 'id, full_name, company, phone, email, selected_mode';

    public function __construct(private readonly Db $db) {}

    /**
     * Records the submitted lead. If this browser already submitted a lead with
     * the same email (httpOnly cookie), that record is reused and refreshed so a
     * double-click or repeat calculation resolves to the same session, which is
     * what the submission idempotency key is scoped to.
     *
     * The caller sets the session cookie to the returned sessionId.
     *
     * @param  array{fullName: string, company: string, phone: string, email: string}  $lead
     * @param  ?string  $cookie  the visitor's current session cookie, if any
     * @return array{sessionId: string, fullName: string, company: string, phone: string, email: string, selectedMode: string}
     */
    public function upsert(array $lead, string $mode, ?string $cookie): array
    {
        $existingId = is_string($cookie) && $cookie !== '' ? $cookie : null;
        // A malformed cookie is treated like no cookie, as before.
        if ($existingId !== null && Db::isUuid($existingId)) {
            try {
                $existing = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                    'select '.self::COLUMNS.' from public.calculator_sessions where id = ?',
                    [$existingId],
                ));
            } catch (\Throwable) {
                $existing = null;
            }

            if ($existing && $existing['email'] === $lead['email']) {
                try {
                    $updated = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                        'update public.calculator_sessions set
                            full_name = ?,
                            company = ?,
                            phone = ?,
                            phone_normalized = ?,
                            selected_mode = ?,
                            last_seen_at = now()
                          where id = ?
                          returning '.self::COLUMNS,
                        [$lead['fullName'], $lead['company'], $lead['phone'], Lead::normalizePhone($lead['phone']), $mode, $existingId],
                    ));
                } catch (\Throwable) {
                    $updated = null;
                }
                if ($updated) {
                    return self::toPublic($updated);
                }
            }
        }

        $data = null;
        try {
            $data = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                'insert into public.calculator_sessions (full_name, company, phone, phone_normalized, email, selected_mode)
                 values (?, ?, ?, ?, ?, ?)
                 returning '.self::COLUMNS,
                [$lead['fullName'], $lead['company'], $lead['phone'], Lead::normalizePhone($lead['phone']), $lead['email'], $mode],
            ));
        } catch (\Throwable $error) {
            error_log('[lead-session] create failed '.json_encode(['code' => $error->getCode()]));
        }

        if (! $data) {
            throw new AppError("We couldn't save your details. Please try again.");
        }

        return self::toPublic($data);
    }

    private static function toPublic(array $row): array
    {
        return [
            'sessionId' => $row['id'],
            'fullName' => $row['full_name'],
            'company' => $row['company'],
            'phone' => $row['phone'],
            'email' => $row['email'],
            'selectedMode' => Lead::isCalculatorMode($row['selected_mode']) ? $row['selected_mode'] : 'manual',
        ];
    }
}
