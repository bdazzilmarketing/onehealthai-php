<?php

namespace App\Database;

/** The signed-in user of one request. Every run() is one transaction in that user's context. */
final class UserScope
{
    public function __construct(private readonly Db $db, public readonly string $userId)
    {
        if (! Db::isUuid($userId)) {
            throw new \InvalidArgumentException('userScope requires a user UUID');
        }
    }

    /**
     * @template T
     *
     * @param  callable(Tx): T  $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        return $this->db->withUserContext($this->userId, $fn);
    }
}
