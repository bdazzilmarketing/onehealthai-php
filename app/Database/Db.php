<?php

namespace App\Database;

use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Database access for the application (port of src/lib/db.server.ts). Every
 * query runs inside a transaction that first fixes who is asking:
 *
 *   withUserContext($userId, $fn)  SET LOCAL ROLE onehealth_authenticated and
 *                                  app.user_id = $userId, so row-level security
 *                                  and auth.uid() see that user.
 *   withServiceContext($fn)        SET LOCAL ROLE onehealth_service (bypasses
 *                                  row-level security).
 *
 * Both settings are transaction-local: they end with COMMIT or ROLLBACK. The
 * runtime login is NOINHERIT and has no privileges of its own, so a query that
 * somehow runs outside these helpers is refused rather than running with
 * someone's rights.
 */
final class Db
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /** @param \Closure(): PDO $pdo */
    public function __construct(private readonly \Closure $pdo) {}

    public static function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::UUID, $value) === 1;
    }

    /**
     * @template T
     * @param  callable(Tx): T  $fn
     * @return T
     */
    public function withUserContext(string $userId, callable $fn): mixed
    {
        if (! self::isUuid($userId)) {
            throw new InvalidArgumentException('withUserContext requires a user UUID');
        }

        return $this->transaction(function (PDO $pdo) use ($userId, $fn) {
            $pdo->exec('SET LOCAL ROLE onehealth_authenticated');
            $set = $pdo->prepare("select set_config('app.user_id', ?, true)");
            $set->execute([strtolower($userId)]);

            return $fn(new Tx($pdo));
        });
    }

    /**
     * Trusted server work (row-level security bypassed). Callers authorize first.
     *
     * @template T
     * @param  callable(Tx): T  $fn
     * @return T
     */
    public function withServiceContext(callable $fn): mixed
    {
        return $this->transaction(function (PDO $pdo) use ($fn) {
            $pdo->exec('SET LOCAL ROLE onehealth_service');
            // Explicitly no user, whatever a misconfigured server default might say.
            $pdo->exec("select set_config('app.user_id', '', true)");

            return $fn(new Tx($pdo));
        });
    }

    private function transaction(callable $fn): mixed
    {
        $pdo = ($this->pdo)();
        if ($pdo->inTransaction()) {
            throw new \LogicException('Database contexts cannot be nested');
        }
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();

            return $result;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }
}
