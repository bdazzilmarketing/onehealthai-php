<?php

namespace Tests\Support;

use App\Database\SqlMigrator;
use PDO;
use RuntimeException;

/**
 * Throwaway PostgreSQL for tests (port of src/lib/test-support/postgres.ts):
 * initdb on PATH, the SQL migrations applied as a non-superuser CREATEROLE
 * login (as on Neon), and the runtime login created with the real script.
 */
final class TestDatabase
{
    public string $appUrl;

    public string $migratorUrl;

    public string $appLogin;

    private string $dir;

    private bool $started = false;

    public function __construct(private readonly int $port, string $suffix)
    {
        $bin = getenv('ONEHEALTH_PG_BIN') ?: '/usr/lib/postgresql/16/bin';
        if (is_dir($bin)) {
            putenv('PATH='.$bin.':'.getenv('PATH'));
        }
        $this->dir = sys_get_temp_dir()."/onehealth-{$suffix}-".getmypid().'-'.bin2hex(random_bytes(3));
        mkdir($this->dir, 0777, true);
        chmod($this->dir, 0777);
        $database = "onehealth_{$suffix}";
        $migrator = "onehealth_migrator_{$suffix}";
        $this->appLogin = "onehealth_app_{$suffix}";

        $this->run('initdb', ['-D', "{$this->dir}/data", '-A', 'trust', '-U', 'db_admin', '--no-locale']);
        $this->run('pg_ctl', ['-D', "{$this->dir}/data", '-l', "{$this->dir}/server.log", '-o', "-k {$this->dir} -p {$this->port} -h 127.0.0.1", '-w', 'start']);
        $this->started = true;

        $admin = new PDO("pgsql:host=127.0.0.1;port={$this->port};dbname=postgres", 'db_admin', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $admin->exec("CREATE ROLE {$migrator} LOGIN CREATEROLE BYPASSRLS PASSWORD 'migrator'");
        $admin->exec("CREATE DATABASE {$database} OWNER {$migrator}");
        $admin = null;

        $this->migratorUrl = "postgres://{$migrator}:migrator@127.0.0.1:{$this->port}/{$database}";
        $this->appUrl = "postgres://{$this->appLogin}:app@127.0.0.1:{$this->port}/{$database}";
    }

    public static function migrationsDir(): string
    {
        return dirname(__DIR__, 2).'/database/sql/migrations';
    }

    /** Applies the migrations and creates the runtime login. */
    public function install(): array
    {
        $result = SqlMigrator::migrate($this->connect($this->migratorUrl), self::migrationsDir());
        $this->psql(dirname(__DIR__, 2).'/database/sql/scripts/create-app-login.sql', ['login' => $this->appLogin, 'password' => 'app']);

        return $result;
    }

    public function connect(string $url): PDO
    {
        return SqlMigrator::connect($url);
    }

    public function psql(string $file, array $vars): string
    {
        $args = [$this->migratorUrl, '-q', '-X', '-f', $file];
        foreach ($vars as $key => $value) {
            array_push($args, '-v', "{$key}={$value}");
        }

        return $this->exec('psql', $args);
    }

    public function stop(): void
    {
        if ($this->started) {
            try {
                $this->run('pg_ctl', ['-D', "{$this->dir}/data", '-m', 'immediate', '-w', 'stop']);
            } catch (RuntimeException) {
            }
            $this->started = false;
        }
        // The cluster's files belong to the postgres user; the directory to us.
        $this->exec('rm', ['-rf', "{$this->dir}/data", "{$this->dir}/server.log"], asPostgresUser: true);
        exec('rm -rf '.escapeshellarg($this->dir));
    }

    /** initdb and pg_ctl refuse to run as root. */
    private function run(string $command, array $args): string
    {
        return $this->exec($command, $args, asPostgresUser: true);
    }

    private function exec(string $command, array $args, bool $asPostgresUser = false): string
    {
        if ($asPostgresUser && function_exists('posix_getuid') && posix_getuid() === 0) {
            $args = ['--reuid=1000', '--regid=1000', '--clear-groups', '--', $command, ...$args];
            $command = 'setpriv';
        }
        $line = implode(' ', array_map('escapeshellarg', [$command, ...$args])).' 2>&1';
        exec($line, $output, $status);
        if ($status !== 0) {
            throw new RuntimeException("{$command} failed: ".implode("\n", $output));
        }

        return implode("\n", $output);
    }
}
