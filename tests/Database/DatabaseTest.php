<?php

namespace Tests\Database;

use App\Database\Db;
use App\Database\SqlMigrator;
use App\Database\Tx;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/**
 * Port of src/lib/db.server.test.ts: the SQL schema and the per-transaction
 * user context, on a real PostgreSQL server.
 */
final class DatabaseTest extends TestCase
{
    private static TestDatabase $database;

    private static Db $db;

    private static string $admin;

    private static string $superOnly;

    private static string $auditor;

    private static string $plain;

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55433, 'test');
        $result = self::$database->install();
        self::assertCount(9, $result['applied']);

        self::$db = self::db();
        [self::$admin, self::$superOnly, self::$auditor, self::$plain] = [self::uuid(), self::uuid(), self::uuid(), self::uuid()];
        self::$db->withServiceContext(function (Tx $tx) {
            foreach ([[self::$admin, 'admin'], [self::$superOnly, 'super_admin'], [self::$auditor, 'security_auditor'], [self::$plain, null]] as [$id, $role]) {
                $tx->execute('insert into auth.users (id, email) values (?, ?)', [$id, "{$id}@example.test"]);
                $tx->execute('insert into public.profiles (id, email) values (?, ?)', [$id, "{$id}@example.test"]);
                if ($role) {
                    $tx->execute('insert into public.user_roles (user_id, role) values (?, ?)', [$id, $role]);
                }
            }
        });
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->stop();
    }

    private static function uuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';
        $hex[16] = '89ab'[random_int(0, 3)];

        return vsprintf('%s-%s-%s-%s-%s', [substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20)]);
    }

    private static function db(?PDO $pdo = null): Db
    {
        $pdo ??= self::$database->connect(self::$database->appUrl);

        return new Db(fn () => $pdo);
    }

    private function migrator(): PDO
    {
        return self::$database->connect(self::$database->migratorUrl);
    }

    private function copyMigrations(): string
    {
        $copy = sys_get_temp_dir().'/onehealth-migrations-'.bin2hex(random_bytes(4));
        mkdir($copy);
        foreach (glob(TestDatabase::migrationsDir().'/*.sql') as $file) {
            copy($file, $copy.'/'.basename($file));
        }

        return $copy;
    }

    private function removeDir(string $dir): void
    {
        array_map('unlink', glob($dir.'/*'));
        rmdir($dir);
    }

    private function assertThrows(string $message, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            $this->assertStringContainsString($message, $error->getMessage());

            return;
        }
        $this->fail("Expected an error containing \"{$message}\"");
    }

    // --- migration runner ---------------------------------------------------

    public function test_migrations_are_byte_identical_to_the_original_application(): void
    {
        // SHA-256 of db/migrations in bdazzilmarketing/onehealthai@migration/portable (ab38afe).
        $expected = json_decode(file_get_contents(__DIR__.'/original-migrations.sha256.json'), true);
        $actual = array_column(SqlMigrator::readMigrations(TestDatabase::migrationsDir()), 'sha256', 'name');
        $this->assertSame($expected, $actual);
    }

    public function test_rerun_is_a_noop(): void
    {
        $result = SqlMigrator::migrate($this->migrator(), TestDatabase::migrationsDir());
        $this->assertSame([], $result['applied']);
        $this->assertContains('0000_roles.bootstrap.sql', $result['skipped']);
        $this->assertContains('0004_seed_config.sql', $result['skipped']);
    }

    public function test_refuses_an_applied_migration_that_was_edited_or_removed(): void
    {
        $copy = $this->copyMigrations();
        try {
            file_put_contents($copy.'/0003_grants.sql', "\n-- edited\n", FILE_APPEND);
            $this->assertThrows('0003_grants.sql was modified', fn () => SqlMigrator::migrate($this->migrator(), $copy));
            unlink($copy.'/0003_grants.sql');
            $this->assertThrows('0003_grants.sql is missing', fn () => SqlMigrator::migrate($this->migrator(), $copy));
        } finally {
            $this->removeDir($copy);
        }
    }

    public function test_rolls_back_a_failing_migration_completely(): void
    {
        $copy = $this->copyMigrations();
        try {
            file_put_contents($copy.'/9999_broken.sql', "CREATE TABLE public.half_done (id int);\nSELECT 1/0;\n");
            try {
                SqlMigrator::migrate($this->migrator(), $copy);
                $this->fail('Expected the broken migration to fail');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('9999_broken.sql failed:', $error->getMessage());
                $this->assertStringContainsString('division by zero', $error->getMessage());
            }
            $this->assertNull($this->migrator()->query("select to_regclass('public.half_done')::text")->fetchColumn());
        } finally {
            $this->removeDir($copy);
        }
    }

    // --- portable schema ----------------------------------------------------

    public function test_schema_is_owned_by_onehealth_owner_and_keeps_every_table_policy_and_setting(): void
    {
        $sql = $this->migrator();
        $owners = $sql->query("
            select distinct pg_get_userbyid(c.relowner) owner from pg_class c join pg_namespace n on n.oid = c.relnamespace
            where n.nspname in ('public', 'auth', 'private') and c.relkind in ('r', 'v', 'S', 'i')
            union select distinct pg_get_userbyid(p.proowner) from pg_proc p join pg_namespace n on n.oid = p.pronamespace
            where n.nspname in ('public', 'auth', 'private')")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['onehealth_owner'], $owners);

        $counts = $sql->query("
            select (select count(*)::int from pg_tables where schemaname = 'public') tables,
                   (select count(*)::int from pg_policies where schemaname = 'public') policies,
                   (select count(*)::int from pg_tables where schemaname in ('public', 'auth') and not rowsecurity) rls_off")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['tables' => 23, 'policies' => 38, 'rls_off' => 0], $counts);

        foreach (['laboratories', 'sub_labs', 'parameters', 'parameter_groups', 'hardware', 'hardware_categories', 'dataset_versions'] as $table) {
            $this->assertSame($table, $sql->query("select to_regclass('public.{$table}')::text")->fetchColumn());
        }
        $settings = $sql->query('
            select (select count(*)::int from public.settings) n, ip_rolling_limit rolling, ip_rolling_window_seconds "window"
            from public.ai_quota_settings')->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['n' => 4, 'rolling' => 20, 'window' => 120], $settings);
    }

    public function test_grants_match_policies_and_never_truncate(): void
    {
        $sql = $this->migrator();
        $mismatched = $sql->query("
            with cmds(cmd) as (values ('SELECT'), ('INSERT'), ('UPDATE'), ('DELETE')), rows as (
              select t.tablename, c.cmd,
                     has_table_privilege('onehealth_authenticated', format('public.%I', t.tablename), c.cmd) granted,
                     exists (select from pg_policies p where p.schemaname = 'public' and p.tablename = t.tablename
                             and 'onehealth_authenticated' = any (p.roles) and p.cmd in (c.cmd, 'ALL')) policy
              from pg_tables t cross join cmds c where t.schemaname = 'public')
            select tablename || ' ' || cmd from rows where granted <> policy")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame([], $mismatched);

        $truncate = $sql->query("
            select count(*)::int from pg_tables t, unnest(array['onehealth_authenticated', 'onehealth_service']) r
            where t.schemaname in ('public', 'auth') and has_table_privilege(r, format('%I.%I', t.schemaname, t.tablename), 'TRUNCATE')")->fetchColumn();
        $this->assertSame(0, $truncate);
    }

    public function test_quota_functions_are_server_only(): void
    {
        $rows = $this->migrator()->query("
            select r, has_function_privilege(r, 'public.ai_reserve_quota(text,text,integer,text,text)', 'EXECUTE')
            from unnest(array['onehealth_authenticated', 'onehealth_service', 'onehealth_app_test', 'public']) r")->fetchAll(PDO::FETCH_KEY_PAIR);
        $this->assertSame([
            'onehealth_authenticated' => false,
            'onehealth_service' => true,
            'onehealth_app_test' => false,
            'public' => false,
        ], $rows);
    }

    // --- per-transaction user context --------------------------------------

    public function test_refuses_everything_outside_a_context(): void
    {
        $sql = self::$database->connect(self::$database->appUrl);
        $this->assertThrows('permission denied', fn () => $sql->query('select count(*) from public.settings'));
        $this->assertThrows('permission denied', fn () => $sql->query('select auth.uid()'));
        $this->assertThrows('permission denied', fn () => $sql->query("select public.ai_reserve_quota('v', 'i', 1, 'p', 'n')"));
    }

    public function test_rejects_a_user_id_that_is_not_a_uuid(): void
    {
        $this->assertThrows('requires a user UUID', fn () => self::$db->withUserContext("' or true --", fn () => 1));
    }

    public function test_isolates_the_user_of_each_transaction_across_overlapping_requests(): void
    {
        // PHP serves one request per process, so overlapping requests are
        // separate connections: open 12 transactions inside one another and
        // check each still sees only its own user after the others ran.
        $users = array_map(fn () => self::uuid(), range(1, 12));
        $seen = [];
        $open = function (int $index) use (&$open, &$seen, $users) {
            if ($index === count($users)) {
                return;
            }
            self::db()->withUserContext($users[$index], function (Tx $tx) use ($index, &$open, &$seen, $users) {
                $before = $tx->first('select auth.uid()::text uid, current_user "role"');
                $open($index + 1);
                $seen[] = [$users[$index], $before, $tx->value('select auth.uid()::text')];
            });
        };
        $open(0);
        $this->assertCount(12, $seen);
        foreach ($seen as [$id, $before, $after]) {
            $this->assertSame(['uid' => $id, 'role' => 'onehealth_authenticated'], $before);
            $this->assertSame($id, $after);
        }
    }

    public function test_leaves_no_role_or_user_on_the_connection_after_commit_or_rollback(): void
    {
        $sql = self::$database->connect(self::$database->appUrl);
        $single = self::db($sql);
        $state = fn () => $sql->query("select current_user \"role\", coalesce(current_setting('app.user_id', true), '') uid")->fetch(PDO::FETCH_ASSOC);
        $clean = ['role' => 'onehealth_app_test', 'uid' => ''];

        $single->withUserContext(self::$admin, fn (Tx $tx) => $tx->value('select 1'));
        $this->assertSame($clean, $state());

        $this->assertThrows('request failed', fn () => $single->withUserContext(self::$admin, function (Tx $tx) {
            $tx->value('select 1');
            throw new \RuntimeException('request failed');
        }));
        $this->assertSame($clean, $state());

        $this->assertThrows('division by zero', fn () => $single->withUserContext(self::$admin, fn (Tx $tx) => $tx->value('select 1/0')));
        $this->assertSame($clean, $state());

        $this->assertNull($single->withServiceContext(fn (Tx $tx) => $tx->value('select auth.uid()')));
    }

    public function test_applies_row_level_security_per_user_and_bypasses_it_for_the_service_context(): void
    {
        $profiles = fn (string $id) => self::$db->withUserContext($id, fn (Tx $tx) => array_column($tx->select('select id from public.profiles'), 'id'));
        $this->assertSame([self::$plain], $profiles(self::$plain));
        $this->assertSame([self::$auditor], $profiles(self::$auditor));
        $this->assertSame(4, self::$db->withServiceContext(fn (Tx $tx) => $tx->value('select count(*)::int from public.profiles')));

        $update = fn (string $id) => self::$db->withUserContext($id, fn (Tx $tx) => $tx->execute("update public.settings set description = description where key = 'ai_model'"));
        $this->assertSame(1, $update(self::$admin));
        $this->assertSame(0, $update(self::$auditor)); // read-only: sees the row, may not change it
        $this->assertSame(0, $update(self::$plain));

        $read = fn (string $id) => self::$db->withUserContext($id, fn (Tx $tx) => count($tx->select('select key from public.settings')));
        $this->assertSame(4, $read(self::$auditor));
        $this->assertSame(0, $read(self::$plain));
        $this->assertThrows('permission denied', fn () => self::$db->withUserContext(self::$plain, fn (Tx $tx) => $tx->select('select * from public.calculator_submissions')));
    }

    public function test_super_admin_without_the_admin_role_has_the_same_catalog_access_as_an_admin(): void
    {
        $draft = fn (string $id) => self::$db->withUserContext($id, fn (Tx $tx) => $tx->value("
            insert into public.dataset_versions (workbook_type, version_number, status, original_filename, validation_summary)
            values ('genetics', 900 + (select count(*)::int from public.dataset_versions), 'validated', 'test.xlsx', '{\"criticalErrors\":0}')
            returning id"));
        $id = $draft(self::$superOnly);
        $this->assertNotEmpty($id);
        self::$db->withUserContext(self::$superOnly, fn (Tx $tx) => $tx->value('select public.publish_dataset_version(?)', [$id]));
        $this->assertSame('published', self::$db->withServiceContext(fn (Tx $tx) => $tx->value('select status from public.dataset_versions where id = ?', [$id])));
        $this->assertThrows('row-level security', fn () => $draft(self::$auditor));
        $this->assertThrows('Forbidden', fn () => self::$db->withUserContext(self::$auditor, fn (Tx $tx) => $tx->value('select public.publish_dataset_version(?)', [$id])));
    }

    public function test_staff_cannot_grant_roles(): void
    {
        $this->assertThrows('permission denied', fn () => self::$db->withUserContext(self::$admin, fn (Tx $tx) => $tx->execute("insert into public.user_roles (user_id, role) values (?, 'super_admin')", [self::$plain])));
    }

    public function test_ai_quota_reservation_runs_only_in_the_service_context(): void
    {
        $args = ['visitor-0000000001', 'network-day-22-aaaaaaaa', 100, 'network-day-21-aaaaaaaa', 'network-day-23-aaaaaaaa'];
        $this->assertThrows('permission denied', fn () => self::$db->withUserContext(self::$admin, fn (Tx $tx) => $tx->value('select public.ai_reserve_quota(?, ?, ?, ?, ?)', $args)));
        $result = self::$db->withServiceContext(function (Tx $tx) use ($args) {
            $reservation = json_decode($tx->value('select public.ai_reserve_quota(?, ?, ?, ?, ?)', $args), true);
            $tx->value('select public.ai_release_quota(?, 80)', [$reservation['reservation_id']]);

            return $reservation;
        });
        $this->assertTrue($result['ok']);
    }
}
