<?php

namespace App\Database;

use App\Support\Js;
use PDO;

/**
 * One open transaction, already switched to its role by Db. Values are bound,
 * never interpolated.
 */
final class Tx
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string, mixed>|null */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Rows shaped as the original application returned them to the browser:
     * numeric and float columns as numbers, timestamps as ISO text with a
     * colon in the offset, JSON columns decoded (objects stay objects).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $params = []): array
    {
        $statement = $this->run($sql, $params);
        $kinds = [];
        for ($i = 0, $count = $statement->columnCount(); $i < $count; $i++) {
            $meta = $statement->getColumnMeta($i);
            $kind = match ($meta['pgsql:oid'] ?? 0) {
                1700, 700, 701, 20 => 'number',
                1114, 1184 => 'time',
                114, 3802 => 'json',
                default => null,
            };
            if ($kind !== null) {
                $kinds[$meta['name']] = $kind;
            }
        }
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        if ($kinds !== []) {
            foreach ($rows as &$row) {
                foreach ($kinds as $name => $kind) {
                    if ($row[$name] !== null) {
                        $row[$name] = match ($kind) {
                            'number' => is_int($row[$name]) ? $row[$name] : Js::toNumber((string) $row[$name]),
                            'time' => preg_replace('/([+-]\d\d)$/', '$1:00', preg_replace('/ /', 'T', $row[$name], 1)),
                            'json' => json_decode($row[$name], false, 512, JSON_THROW_ON_ERROR),
                        };
                    }
                }
            }
            unset($row);
        }

        return $rows;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->run($sql, $params)->fetch(PDO::FETCH_NUM);

        return $row === false ? null : $row[0];
    }

    /** Rows affected. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    private function run(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($params) as $index => $value) {
            $type = match (true) {
                $value === null => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, is_float($value) ? Js::number($value) : $value, $type);
        }
        $statement->execute();

        return $statement;
    }
}
