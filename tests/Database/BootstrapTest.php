<?php

namespace Tests\Database;

use App\Auth\AuthService;
use Illuminate\Support\Facades\Artisan;
use PDO;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/** The first Super Admin of an environment gets a single-use activation link from the console. */
final class BootstrapTest extends TestCase
{
    private static TestDatabase $database;

    private static PDO $owner;

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55449, 'bootstrap');
        self::$database->install();
        self::$owner = self::$database->connect(self::$database->migratorUrl);
        self::$owner->exec('SET ROLE onehealth_owner');
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->stop();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.pgsql.url' => self::$database->appUrl,
            'database.connections.pgsql.sslmode' => 'disable',
            'onehealth.env.AUTH_SECRET' => str_pad('test-secret-', 48, 'x'),
            'onehealth.env.ADMIN_SITE_URL' => 'https://admin.onehealth.test/',
        ]);
    }

    /** @return array{0: int, 1: string} */
    private function run_(array $arguments): array
    {
        $code = Artisan::call('onehealth:bootstrap-super-admin', $arguments);

        return [$code, Artisan::output()];
    }

    public function test_the_first_super_admin_is_created_and_activated_once(): void
    {
        [$code, $output] = $this->run_(['email' => 'First@Example.test']);
        $this->assertSame([1, true], [$code, str_contains($output, 'No Super Admin account with that email.')]);

        [$code, $output] = $this->run_(['email' => 'First@Example.test', '--create' => true]);
        $this->assertSame(0, $code, $output);
        $this->assertMatchesRegularExpression('#^Activation link for first@example\.test \(valid 72 hours, single use\):\nhttps://admin\.onehealth\.test/admin-activate\?token=[A-Za-z0-9_-]{43}\n$#', $output);
        $user = self::$owner->query("select u.id, r.role from auth.users u join public.user_roles r on r.user_id = u.id where u.email = 'first@example.test'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('super_admin', $user['role']);
        $audit = self::$owner->query("select details from public.audit_log where event_type = 'admin_user.bootstrap_link' order by created_at desc limit 1")->fetchColumn();
        $this->assertEquals(['target_user' => $user['id'], 'email' => 'first@example.test', 'created' => true], json_decode($audit, true));

        // A second Super Admin is never created this way; a new link for the existing one is fine.
        [$code, $output] = $this->run_(['email' => 'second@example.test', '--create' => true]);
        $this->assertSame([1, true], [$code, str_contains($output, 'A Super Admin account already exists.')]);
        $this->assertSame(0, $this->run_(['email' => 'first@example.test'])[0]);

        // The link sets the password; after that the command refuses.
        preg_match('/token=(\S+)/', $this->run_(['email' => 'first@example.test'])[1], $match);
        $response = $this->app->make(AuthService::class)->handle('POST', '/reset-password', 'https://admin.onehealth.test', [], ['token' => $match[1], 'newPassword' => 'A long enough password 1!'], '203.0.113.9', 'test');
        $this->assertSame(200, $response->status);
        [$code, $output] = $this->run_(['email' => 'first@example.test']);
        $this->assertSame([1, true], [$code, str_contains($output, 'A Super Admin can already sign in.')]);
        $this->assertSame(1, (int) self::$owner->query('select count(*) from auth.users')->fetchColumn());
    }
}
