<?php

namespace App\Ai;

use App\Database\Db;
use App\Database\Tx;
use App\Support\Js;
use RuntimeException;

/**
 * Server-side AI conversation state (port of src/lib/ai-session.server.ts),
 * persisted in the database so limits hold across server instances. Every
 * read/write is scoped to the signed visitor identity: a conversation ID
 * alone grants nothing.
 */
final class Sessions
{
    public const HISTORY_MAX_MESSAGES = 24;

    public const SESSION_TTL_MS = 60 * 60 * 1000;

    private const COLUMNS = 'id, turns, updated_at, history, proposed, expanded_section_keys, prompt_version_id, prompt_version_number, model';

    public function __construct(private readonly Db $db) {}

    /**
     * @return array{id: string, turns: int, updatedAt: float, history: list<array{role: string, content: string}>, proposed: array, expandedSectionKeys: list<string>, promptVersionId: ?string, promptVersionNumber: ?int, model: ?string, version: string}
     *                                                                                                                                                                                                                                                        version is the exact stored updated_at, used for optimistic concurrency
     */
    private static function fromRow(array $row): array
    {
        $history = json_decode($row['history'], true);
        $proposed = json_decode($row['proposed'], true);
        $expanded = json_decode($row['expanded_section_keys'], true);

        return [
            'id' => $row['id'],
            'turns' => (int) $row['turns'],
            'updatedAt' => (float) (new \DateTimeImmutable($row['updated_at']))->format('U.u') * 1000,
            'history' => is_array($history) && array_is_list($history) ? $history : [],
            'proposed' => $proposed ?? ['labs' => []],
            'expandedSectionKeys' => is_array($expanded) && array_is_list($expanded) ? $expanded : [],
            'promptVersionId' => $row['prompt_version_id'],
            'promptVersionNumber' => $row['prompt_version_number'] === null ? null : (int) $row['prompt_version_number'],
            'model' => $row['model'],
            'version' => $row['updated_at'],
        ];
    }

    public function get(?string $id, string $visitorHash): ?array
    {
        if ($id === null || $id === '' || ! Db::isUuid($id)) {
            return null;
        }
        try {
            $row = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                'select '.self::COLUMNS.' from public.ai_conversations where id = ? and visitor_hash = ?', [$id, $visitorHash]));
        } catch (\Throwable) {
            throw new RuntimeException('SESSION_UNAVAILABLE');
        }
        if ($row === null) {
            return null;
        }
        $session = self::fromRow($row);

        return microtime(true) * 1000 - $session['updatedAt'] > self::SESSION_TTL_MS ? null : $session;
    }

    public function getOrCreate(?string $id, string $visitorHash): array
    {
        $existing = $this->get($id, $visitorHash);
        if ($existing !== null) {
            return $existing;
        }
        try {
            $row = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                'insert into public.ai_conversations (visitor_hash) values (?) returning '.self::COLUMNS, [$visitorHash]));
        } catch (\Throwable) {
            $row = null;
        }
        if ($row === null) {
            throw new RuntimeException('SESSION_UNAVAILABLE');
        }

        return self::fromRow($row);
    }

    /**
     * Compare-and-set save: only succeeds when the stored row still has the version
     * this request loaded, so overlapping requests can never silently overwrite
     * each other's history or selections. On success the session's version advances.
     */
    public function save(array &$session, string $visitorHash): void
    {
        try {
            $rows = $this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                'update public.ai_conversations set
                    turns = ?,
                    history = ?::jsonb,
                    proposed = ?::jsonb,
                    expanded_section_keys = ?::jsonb,
                    prompt_version_id = ?,
                    prompt_version_number = ?,
                    model = ?,
                    updated_at = clock_timestamp()
                 where id = ? and visitor_hash = ? and updated_at = ?::timestamptz
                 returning updated_at',
                [
                    $session['turns'],
                    Js::stringify(array_slice($session['history'], -self::HISTORY_MAX_MESSAGES)),
                    Js::stringify($session['proposed'] === [] ? new \stdClass : $session['proposed']),
                    Js::stringify(array_slice($session['expandedSectionKeys'], 0, 200)),
                    $session['promptVersionId'], $session['promptVersionNumber'], $session['model'],
                    $session['id'], $visitorHash, $session['version'],
                ]));
        } catch (\Throwable) {
            throw new RuntimeException('SESSION_UNAVAILABLE');
        }
        if (count($rows) !== 1) {
            throw new SessionConflict;
        }
        $session['version'] = $rows[0]['updated_at'];
    }

    public function clear(string $id, string $visitorHash): void
    {
        if (! Db::isUuid($id)) {
            return;
        }
        try {
            $this->db->withServiceContext(fn (Tx $tx) => $tx->execute(
                'delete from public.ai_conversations where id = ? and visitor_hash = ?', [$id, $visitorHash]));
        } catch (\Throwable) {
            // nothing to clear
        }
    }
}
