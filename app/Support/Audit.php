<?php

namespace App\Support;

use App\Database\Db;
use App\Database\Tx;

/**
 * Audit events are written by the backend only (service role); administrators
 * hold no INSERT/UPDATE/DELETE privilege on the table.
 */
final class Audit
{
    public function __construct(private readonly Db $db) {}

    /** @param  array<string, mixed>  $details */
    public function record(string $eventType, ?string $actorId = null, ?string $actorEmail = null, array $details = []): void
    {
        // Best effort: a failed audit write never fails the audited action.
        try {
            $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
                'insert into public.audit_log (event_type, actor_id, actor_email, details) values (?, ?, ?, ?::jsonb)',
                [$eventType, $actorId, $actorEmail, json_encode((object) $details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
            ));
        } catch (\Throwable $error) {
            error_log('[audit] write failed '.json_encode(['event_type' => $eventType, 'code' => $error->getCode()]));
        }
    }
}
