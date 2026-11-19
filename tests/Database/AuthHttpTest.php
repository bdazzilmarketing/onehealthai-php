<?php

namespace Tests\Database;

use App\Auth\AuthService;
use App\Auth\Crypto;
use App\Auth\Roles;
use App\Auth\Staff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * The HTTP layer in front of staff authentication: the /api/auth route, the
 * cookies it sets, and the guards for signed-in staff, on a real PostgreSQL
 * with the original schema.
 */
final class AuthHttpTest extends TestCase
{
    private const ORIGIN = 'https://admin.onehealth.test';

    private const PASSWORD = 'Long enough password 1!';

    private static TestDatabase $database;

    private static string $admin;

    private static string $auditor;

    private static bool $seeded = false;

    /** @var array<string, string> */
    private static array $jar = [];

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55444, 'http');
        self::$database->install();
        [self::$admin, self::$auditor] = [Crypto::uuid(), Crypto::uuid()];
        $owner = self::$database->connect(self::$database->migratorUrl);
        $owner->exec('BEGIN; SET LOCAL ROLE onehealth_owner');
        foreach ([[self::$admin, 'admin@example.test', 'admin'], [self::$auditor, 'auditor@example.test', 'security_auditor']] as [$id, $email, $role]) {
            $owner->prepare('insert into auth.users (id, email) values (?, ?)')->execute([$id, $email]);
            $owner->prepare('insert into public.profiles (id, email) values (?, ?)')->execute([$id, $email]);
            $owner->prepare('insert into public.user_roles (user_id, role) values (?, ?)')->execute([$id, $role]);
        }
        $owner->exec('COMMIT');
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
            'onehealth.env.AUTH_BASE_URL' => self::ORIGIN,
        ]);
        if (! self::$seeded) {
            self::$seeded = true;
            $auth = $this->app->make(AuthService::class);
            $auth->setInitialPassword(self::$admin, self::PASSWORD);
            $auth->setInitialPassword(self::$auditor, self::PASSWORD);
        }
        Route::middleware('staff:session')->get('/api/_test/session', fn (Request $request) => self::describe($request));
        Route::middleware('staff')->get('/api/_test/staff', fn (Request $request) => self::describe($request));
    }

    private static function describe(Request $request): array
    {
        /** @var Staff $staff */
        $staff = $request->attributes->get('staff');

        return [
            'userId' => $staff->userId,
            'email' => $staff->email,
            'mfa' => $staff->mfa,
            'admin' => Roles::isUserAdmin($staff->db, $staff->userId),
            'auditor' => Roles::isSecurityAuditor($staff->db, $staff->userId),
            'uid' => $staff->db->run(fn ($tx) => $tx->value('select auth.uid()::text')),
        ];
    }

    /** @param  array<string, string>  $headers */
    private function send(string $method, string $uri, ?array $body = null, array $headers = [], ?array &$jar = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        if ($jar) {
            $server['HTTP_COOKIE'] = implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($jar), $jar));
        }
        $response = $this->call($method, $uri, [], [], [], $server, $body === null ? null : json_encode($body));
        if ($jar !== null) {
            foreach ($response->headers->getCookies() as $cookie) {
                if ($cookie->getValue() !== '' && $cookie->getValue() !== null && ! $cookie->isCleared()) {
                    $jar[$cookie->getName()] = $cookie->getValue();
                } else {
                    unset($jar[$cookie->getName()]);
                }
            }
        }

        return $response;
    }

    public function test_answers_404_outside_the_allowlist_and_403_for_another_origin(): void
    {
        $this->send('POST', '/api/auth/sign-up/email', ['email' => 'x@example.test'], ['Origin' => self::ORIGIN])->assertStatus(404);
        $this->send('GET', '/api/auth/list-sessions')->assertStatus(404);
        $this->send('GET', '/api/auth')->assertStatus(404);
        $this->send('POST', '/api/auth/sign-in/email', ['email' => 'admin@example.test', 'password' => self::PASSWORD], ['Origin' => 'https://evil.example'])
            ->assertStatus(403);
        $this->send('POST', '/api/auth/sign-in/email', ['email' => 'admin@example.test', 'password' => self::PASSWORD])->assertStatus(403);
    }

    public function test_guards_refuse_requests_without_a_session(): void
    {
        $this->send('GET', '/api/_test/staff')->assertStatus(401)->assertExactJson(['message' => 'Unauthorized']);
        $forged = ['__Secure-onehealth.session_token' => 'forged.c2lnbmF0dXJl'];
        $this->send('GET', '/api/_test/session', jar: $forged)->assertStatus(401);
        $this->send('GET', '/api/auth/get-session')->assertStatus(200)->assertContent('null');
    }

    public function test_signs_in_over_http_with_a_hardened_cookie(): void
    {
        $response = $this->send('POST', '/api/auth/sign-in/email', ['email' => 'auditor@example.test', 'password' => self::PASSWORD], [
            'Origin' => self::ORIGIN,
            // A client cannot choose the address the rate limiter and the session record see.
            'X-Forwarded-For' => '1.2.3.4',
        ], self::$jar);
        $response->assertStatus(200)->assertJsonPath('user.email', 'auditor@example.test');
        $this->assertArrayNotHasKey('password', $response->json('user'));

        $cookie = collect($response->headers->getCookies())->firstWhere(fn ($c) => $c->getName() === '__Secure-onehealth.session_token');
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertSame('/', $cookie->getPath());
        // The value is passed through untouched: signed by the application, not re-encoded or encrypted by the framework.
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}\.[A-Za-z0-9%]+$/', $cookie->getValue());
        $this->assertStringContainsString('__Secure-onehealth.session_token='.$cookie->getValue().';', (string) $cookie);

        $stored = self::$database->connect(self::$database->migratorUrl)
            ->query("select ip_address from auth.sessions where user_id = '".self::$auditor."'")->fetchColumn();
        $this->assertNotSame('1.2.3.4', $stored);

        $this->send('GET', '/api/auth/get-session', jar: self::$jar)->assertStatus(200)->assertJsonPath('user.id', self::$auditor);
    }

    public function test_guards_hand_the_handler_the_callers_own_database_scope(): void
    {
        $this->send('GET', '/api/_test/staff', jar: self::$jar)->assertStatus(200)->assertExactJson([
            'userId' => self::$auditor,
            'email' => 'auditor@example.test',
            'mfa' => ['required' => false, 'enrolled' => false],
            'admin' => false,
            'auditor' => true,
            'uid' => self::$auditor,
        ]);
    }

    public function test_holds_an_admin_without_two_step_sign_in_at_the_setup_step(): void
    {
        $jar = [];
        $this->send('POST', '/api/auth/sign-in/email', ['email' => 'admin@example.test', 'password' => self::PASSWORD], ['Origin' => self::ORIGIN], $jar)
            ->assertStatus(200);
        $this->send('GET', '/api/_test/staff', jar: $jar)->assertStatus(403)->assertExactJson(['message' => 'MFA_SETUP_REQUIRED']);
        $this->send('GET', '/api/_test/session', jar: $jar)->assertStatus(200)
            ->assertJsonPath('mfa', ['required' => true, 'enrolled' => false])
            ->assertJsonPath('admin', true);
    }

    public function test_sign_out_ends_the_session(): void
    {
        $jar = self::$jar;
        $this->send('POST', '/api/auth/sign-out', [], ['Origin' => self::ORIGIN], $jar)->assertStatus(200);
        $this->assertArrayNotHasKey('__Secure-onehealth.session_token', $jar);
        $this->send('GET', '/api/_test/staff', jar: self::$jar)->assertStatus(401);
    }
}
