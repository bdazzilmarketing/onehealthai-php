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
 *   withAuthContext($fn)           SET LOCAL ROLE onehealth_auth (authentication
 *                                  tables only).
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
     *
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
     *
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

    /**
     * The authentication tables (sessions, credentials, link tokens, two-step
     * secrets, rate limits) as role onehealth_auth, which can touch those
     * tables and nothing else. Only App\Auth uses this.
     *
     * @template T
     *
     * @param  callable(Tx): T  $fn
     * @return T
     */
    public function withAuthContext(callable $fn): mixed
    {
        return $this->transaction(function (PDO $pdo) use ($fn) {
            $pdo->exec('SET LOCAL ROLE onehealth_auth');
            $pdo->exec("select set_config('app.user_id', '', true)");

            return $fn(new Tx($pdo));
        });
    }

    /** The database's own message for a failed statement (what RAISE EXCEPTION said), without driver decoration. */
    public static function errorMessage(Throwable $error): string
    {
        $text = $error instanceof \PDOException && is_string($error->errorInfo[2] ?? null) ? $error->errorInfo[2] : $error->getMessage();
        $text = preg_replace('/^(ERROR|FATAL|PANIC):\s+/', '', $text);

        return preg_split('/\n(DETAIL|HINT|CONTEXT|LINE \d+|QUERY|PL\/pgSQL function)\b/', $text)[0];
    }

    /** The five-character SQLSTATE of a failed statement, or null. */
    public static function errorCode(Throwable $error): ?string
    {
        return $error instanceof \PDOException && is_string($error->errorInfo[0] ?? null) ? $error->errorInfo[0] : null;
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
