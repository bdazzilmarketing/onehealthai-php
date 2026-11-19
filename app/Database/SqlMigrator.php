<?php

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Applies database/sql/migrations/*.sql in name order, each file in its own
 * transaction (port of scripts/db/migrate.ts). Files named *.bootstrap.sql run
 * as the migration login; every other file runs as onehealth_owner. Applied
 * files are recorded with their SHA-256; editing an applied file stops the run.
 */
final class SqlMigrator
{
    private const LOCK_KEY = 4301000001;

    /** @return list<array{name: string, body: string, sha256: string}> */
    public static function readMigrations(string $dir): array
    {
        $names = array_values(array_filter(scandir($dir) ?: [], fn ($name) => str_ends_with($name, '.sql')));
        sort($names, SORT_STRING);

        return array_map(function ($name) use ($dir) {
            $body = file_get_contents($dir.'/'.$name);

            return ['name' => $name, 'body' => $body, 'sha256' => hash('sha256', $body)];
        }, $names);
    }

    /**
     * @param  callable(string): void|null  $log
     * @return array{applied: list<string>, skipped: list<string>}
     */
    public static function migrate(PDO $pdo, string $dir, ?callable $log = null): array
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $files = self::readMigrations($dir);
        $result = ['applied' => [], 'skipped' => []];
        // Session lock: a second runner waits instead of interleaving files.
        $pdo->exec('select pg_advisory_lock('.self::LOCK_KEY.')');
        try {
            $pdo->exec('
                CREATE SCHEMA IF NOT EXISTS onehealth_migrations;
                REVOKE ALL ON SCHEMA onehealth_migrations FROM PUBLIC;
                CREATE TABLE IF NOT EXISTS onehealth_migrations.schema_migrations (
                  name text PRIMARY KEY,
                  sha256 text NOT NULL,
                  applied_at timestamptz NOT NULL DEFAULT now()
                );
            ');
            $known = $pdo->query('select name, sha256 from onehealth_migrations.schema_migrations')
                ->fetchAll(PDO::FETCH_KEY_PAIR);
            $present = array_column($files, 'name');
            foreach (array_keys($known) as $name) {
                if (! in_array($name, $present, true)) {
                    throw new RuntimeException("Applied migration {$name} is missing from {$dir}");
                }
            }
            foreach ($files as $file) {
                if (array_key_exists($file['name'], $known)) {
                    if ($known[$file['name']] !== $file['sha256']) {
                        throw new RuntimeException("Applied migration {$file['name']} was modified (checksum mismatch)");
                    }
                    $result['skipped'][] = $file['name'];

                    continue;
                }
                $pdo->exec('BEGIN');
                try {
                    if (! str_ends_with($file['name'], '.bootstrap.sql')) {
                        $pdo->exec('SET LOCAL ROLE onehealth_owner');
                    }
                    $pdo->exec($file['body']);
                    $pdo->exec('RESET ROLE');
                    $insert = $pdo->prepare('insert into onehealth_migrations.schema_migrations (name, sha256) values (?, ?)');
                    $insert->execute([$file['name'], $file['sha256']]);
                    $pdo->exec('COMMIT');
                } catch (Throwable $error) {
                    $pdo->exec('ROLLBACK');
                    throw new RuntimeException("Migration {$file['name']} failed: ".$error->getMessage(), 0, $error);
                }
                $result['applied'][] = $file['name'];
                if ($log) {
                    $log("applied {$file['name']}");
                }
            }

            return $result;
        } finally {
            try {
                $pdo->exec('select pg_advisory_unlock('.self::LOCK_KEY.')');
            } catch (Throwable) {
            }
        }
    }

    /** PDO for a postgres:// URL (the migration login, never the runtime login). */
    public static function connect(string $url): PDO
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'], $parts['path'])) {
            throw new RuntimeException('Invalid database URL');
        }
        parse_str($parts['query'] ?? '', $query);
        $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s', $query['host'] ?? $parts['host'], $parts['port'] ?? ($query['port'] ?? 5432), ltrim($parts['path'], '/'));
        if (isset($query['sslmode'])) {
            $dsn .= ';sslmode='.$query['sslmode'];
        }

        return new PDO($dsn, urldecode($parts['user'] ?? ''), urldecode($parts['pass'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            // No server-side prepared statements: valid behind PgBouncer/Neon transaction pooling.
            PDO::ATTR_EMULATE_PREPARES => true,
        ]);
    }
}
