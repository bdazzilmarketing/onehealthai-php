<?php

namespace Tests\Database;

use App\Admin\AdminUsers;
use App\Auth\AuthConfig;
use App\Auth\AuthService;
use App\Auth\Crypto;
use App\Auth\Identity;
use App\Auth\PasswordHasher;
use App\Auth\UnauthorizedException;
use App\Database\Db;
use App\Mail\TemplateMailer;
use App\Support\Audit;
use PDO;
use PHPUnit\Framework\Attributes\Depends;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestDatabase;

/**
 * Port of src/lib/auth/auth.server.test.ts: staff authentication end to end on
 * a real PostgreSQL with the original schema: activation links, sessions,
 * two-step sign-in, disabling, endpoint allowlist, rate limiting, least
 * privilege and preserved user UUIDs.
 */
final class AuthTest extends TestCase
{
    private const ORIGIN = 'https://admin.onehealth.test';

    private static TestDatabase $database;

    private static AuthService $auth;

    private static Identity $identity;

    private static AdminUsers $admin;

    /** @var list<array{to: string, data: array}> */
    public static array $sent = [];

    private static string $superAdmin;

    private static string $otherSuper;

    private static string $importedAdmin;

    private static string $auditor;

    private static ?string $pinnedIp = null;

    private static bool $pinned = false;

    private static int $nextIp = 0;

    private static string $totpSecret = '';

    /** @var list<string> */
    private static array $backupCodes = [];

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55443, 'auth');
        self::$database->install();
        $secret = str_pad('test-secret-', 48, 'x');
        $env = ['AUTH_SECRET' => $secret, 'AUTH_BASE_URL' => self::ORIGIN, 'ADMIN_SITE_URL' => self::ORIGIN];
        $pdo = self::$database->connect(self::$database->appUrl);
        $db = new Db(fn () => $pdo);
        self::$auth = new AuthService($db, AuthConfig::fromEnv($env));
        self::$identity = new Identity($db, self::$auth);
        $mailer = new class implements TemplateMailer
        {
            public function send(string $template, string $to, array $templateData): bool
            {
                AuthTest::$sent[] = ['to' => $to, 'data' => $templateData];

                return true;
            }
        };
        self::$admin = new AdminUsers($db, self::$auth, new Audit($db), $mailer, fn (string $name) => $env[$name] ?? null);

        [self::$superAdmin, self::$otherSuper, self::$importedAdmin, self::$auditor] = [Crypto::uuid(), Crypto::uuid(), Crypto::uuid(), Crypto::uuid()];
        $owner = self::owner();
        $owner->exec('BEGIN; SET LOCAL ROLE onehealth_owner');
        // As the data import will do: same UUIDs, roles and profiles, no password hashes.
        foreach ([
            [self::$superAdmin, 'super@example.test', 'super_admin'],
            [self::$otherSuper, 'super2@example.test', 'super_admin'],
            [self::$importedAdmin, 'imported@example.test', 'admin'],
            [self::$auditor, 'auditor@example.test', 'security_auditor'],
        ] as [$id, $email, $role]) {
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

    // -------------------------------------------------------------- helpers

    private static function owner(): PDO
    {
        return self::$database->connect(self::$database->migratorUrl);
    }

    /**
     * @param  array<string, string>|null  $jar
     * @return array{status: int, json: ?array, setCookie: list<string>}
     */
    private function call(string $method, string $path, ?array $body = null, ?array &$jar = null, ?string $origin = self::ORIGIN): array
    {
        // Each request gets its own address unless a test pins one, so the sign-in
        // rate limit only bites where a test means it to.
        $ip = self::$pinned ? self::$pinnedIp : sprintf('198.51.%d.%d', 100 + intdiv(self::$nextIp, 250), (self::$nextIp++ % 250) + 1);
        $response = self::$auth->handle($method, $path, $origin, $jar ?? [], $body, $ip, 'phpunit');
        if ($jar !== null) {
            foreach ($response->setCookies as $cookie) {
                [$name, $value] = explode('=', explode(';', $cookie)[0], 2);
                if ($value !== '') {
                    $jar[$name] = $value;
                } else {
                    unset($jar[$name]);
                }
            }
        }

        return ['status' => $response->status, 'json' => is_array($response->json) ? $response->json : null, 'setCookie' => $response->setCookies];
    }

    private function totp(string $base32): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper(rtrim($base32, '='))) as $char) {
            $bits .= str_pad(decbin(strpos($alphabet, $char)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8) as $byte) {
            $key .= chr(bindec($byte));
        }
        $mac = hash_hmac('sha1', pack('J', intdiv(time(), 30)), $key, true);
        $offset = ord($mac[19]) & 0xF;

        return str_pad((string) ((unpack('N', substr($mac, $offset, 4))[1] & 0x7FFFFFFF) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /** @return array{url: array, token: string} */
    private function tokenFromLastEmail(string $to): array
    {
        $mail = null;
        foreach (self::$sent as $candidate) {
            if ($candidate['to'] === $to) {
                $mail = $candidate;
            }
        }
        $this->assertNotNull($mail, "no email to {$to}");
        $url = parse_url($mail['data']['url']);
        parse_str($url['query'], $query);

        return ['url' => $url, 'token' => $query['token']];
    }

    private function identityFor(array $jar): array
    {
        return self::$identity->authenticate($jar);
    }

    private function assertUnauthorized(array $jar): void
    {
        try {
            $this->identityFor($jar);
            $this->fail('Expected Unauthorized');
        } catch (UnauthorizedException $error) {
            $this->assertSame('Unauthorized', $error->getMessage());
        }
    }

    private function activate(string $userId, string $email, string $password): array
    {
        self::$admin->sendAccountEmail(self::$superAdmin, 'super@example.test', $userId, 'invite');

        return $this->call('POST', '/reset-password', ['token' => $this->tokenFromLastEmail($email)['token'], 'newPassword' => $password]);
    }

    private function find(string $key, string $value): ?array
    {
        foreach (self::$admin->list() as $user) {
            if ($user[$key] === $value) {
                return $user;
            }
        }

        return null;
    }

    private function assertThrows(string $pattern, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $error) {
            $this->assertMatchesRegularExpression($pattern, $error->getMessage());

            return;
        }
        $this->fail("Expected an error matching {$pattern}");
    }

    // ---------------------- activation of accounts imported with their UUIDs

    public function test_lists_imported_accounts_as_not_activated_and_refuses_sign_in_without_a_password(): void
    {
        $user = $this->find('id', self::$importedAdmin);
        $this->assertSame(['admin', 'active', false], [$user['role'], $user['status'], $user['activated']]);
        $signIn = $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => str_repeat('x', 16)]);
        $this->assertSame(401, $signIn['status']);
    }

    #[Depends('test_lists_imported_accounts_as_not_activated_and_refuses_sign_in_without_a_password')]
    public function test_activates_through_a_single_use_link_and_keeps_the_uuid(): void
    {
        self::$admin->sendAccountEmail(self::$superAdmin, 'super@example.test', self::$importedAdmin, 'invite');
        ['url' => $url, 'token' => $token] = $this->tokenFromLastEmail('imported@example.test');
        $this->assertSame(self::ORIGIN.'/admin-activate', "{$url['scheme']}://{$url['host']}{$url['path']}");

        // The token is stored hashed, never in the clear.
        $stored = self::owner()->query('select identifier, value from auth.verifications')->fetchAll(PDO::FETCH_ASSOC);
        $this->assertSame([], array_filter($stored, fn ($row) => str_contains($row['identifier'], $token)));
        $this->assertNotSame([], array_filter($stored, fn ($row) => $row['value'] === self::$importedAdmin));

        $this->assertSame(400, $this->call('POST', '/reset-password', ['token' => $token, 'newPassword' => 'short'])['status']);
        $this->assertSame(200, $this->call('POST', '/reset-password', ['token' => $token, 'newPassword' => 'Imported-Admin-Pass-1'])['status']);
        // Single use.
        $again = $this->call('POST', '/reset-password', ['token' => $token, 'newPassword' => 'Imported-Admin-Pass-2']);
        $this->assertSame(400, $again['status']);
        $this->assertSame('INVALID_TOKEN', $again['json']['code']);

        $verified = self::owner()->query("select email_verified from auth.users where id = '".self::$importedAdmin."'")->fetchColumn();
        $this->assertTrue($verified);
        $this->assertTrue($this->find('id', self::$importedAdmin)['activated']);

        $jar = [];
        $signIn = $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => 'Imported-Admin-Pass-1'], $jar);
        $this->assertSame(200, $signIn['status']);
        $this->assertSame(self::$importedAdmin, $this->identityFor($jar)['id']);
    }

    #[Depends('test_activates_through_a_single_use_link_and_keeps_the_uuid')]
    public function test_refuses_an_activation_email_for_an_activated_account_and_a_reset_for_an_unactivated_one(): void
    {
        $this->assertThrows('/already activated/', fn () => self::$admin->sendAccountEmail(self::$superAdmin, null, self::$importedAdmin, 'invite'));
        $this->assertThrows('/not been activated/', fn () => self::$admin->sendAccountEmail(self::$superAdmin, null, self::$auditor, 'reset'));
    }

    // --------------------------------------------------------------- sessions

    #[Depends('test_refuses_an_activation_email_for_an_activated_account_and_a_reset_for_an_unactivated_one')]
    public function test_issues_an_httponly_secure_samesite_lax_session_cookie_and_resolves_the_user(): void
    {
        $this->activate(self::$auditor, 'auditor@example.test', 'Auditor-Password-1');
        $jar = [];
        $signIn = $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1'], $jar);
        $this->assertSame(200, $signIn['status']);
        $cookie = current(array_filter($signIn['setCookie'], fn ($c) => str_starts_with($c, '__Secure-onehealth.session_token=')));
        $this->assertNotFalse($cookie);
        $this->assertMatchesRegularExpression('/HttpOnly/i', $cookie);
        $this->assertMatchesRegularExpression('/Secure/i', $cookie);
        $this->assertMatchesRegularExpression('/SameSite=Lax/i', $cookie);
        $this->assertStringContainsString('Max-Age=43200', $cookie);

        $identity = $this->identityFor($jar);
        $this->assertSame([self::$auditor, 'auditor@example.test', false], [$identity['id'], $identity['email'], $identity['mfaRequired']]);

        $session = $this->call('GET', '/get-session', null, $jar);
        $this->assertSame(self::$auditor, $session['json']['user']['id']);
        $this->assertSame(self::$auditor, $session['json']['session']['userId']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $session['json']['session']['expiresAt']);

        $this->assertSame(200, $this->call('POST', '/sign-out', [], $jar)['status']);
        $this->assertUnauthorized($jar);
    }

    public function test_rejects_a_forged_session_cookie(): void
    {
        $this->assertUnauthorized(['__Secure-onehealth.session_token' => 'forged.value']);
        $forged = rawurlencode('sometoken.'.base64_encode(hash_hmac('sha256', 'sometoken', 'another-secret', true)));
        $this->assertUnauthorized(['__Secure-onehealth.session_token' => $forged]);
    }

    #[Depends('test_issues_an_httponly_secure_samesite_lax_session_cookie_and_resolves_the_user')]
    public function test_stores_only_the_address_from_the_trusted_resolver(): void
    {
        [self::$pinned, self::$pinnedIp] = [true, null];
        $jar = [];
        $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1'], $jar);
        self::$pinned = false;
        $rows = self::owner()->query("select ip_address from auth.sessions where user_id = '".self::$auditor."'")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotSame([], $rows);
        $this->assertContains('', $rows);
    }

    // ------------------------------------------------------- two-step sign-in

    #[Depends('test_stores_only_the_address_from_the_trusted_resolver')]
    public function test_holds_an_admin_without_an_authenticator_at_the_setup_step(): void
    {
        $jar = [];
        $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => 'Imported-Admin-Pass-1'], $jar);
        $identity = $this->identityFor($jar);
        $this->assertTrue($identity['mfaRequired']);
        $this->assertTrue(Identity::requiresMfaSetup($identity));

        $this->assertSame(400, $this->call('POST', '/two-factor/enable', ['password' => 'not-the-password'], $jar)['status']);
        $enable = $this->call('POST', '/two-factor/enable', ['password' => 'Imported-Admin-Pass-1'], $jar);
        $this->assertSame(200, $enable['status']);
        $this->assertSame('totp', $enable['json']['method']);
        parse_str(parse_url($enable['json']['totpURI'], PHP_URL_QUERY), $query);
        self::$totpSecret = $query['secret'];
        self::$backupCodes = $enable['json']['backupCodes'];
        $this->assertCount(10, self::$backupCodes);
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9]{5}-[a-zA-Z0-9]{5}$/', self::$backupCodes[0]);
        // Not on until a code from the app is verified.
        $this->assertTrue(Identity::requiresMfaSetup($this->identityFor($jar)));
        $this->assertSame(401, $this->call('POST', '/two-factor/verify-totp', ['code' => '000000'], $jar)['status']);
        $this->assertSame(200, $this->call('POST', '/two-factor/verify-totp', ['code' => $this->totp(self::$totpSecret)], $jar)['status']);
        $this->assertFalse(Identity::requiresMfaSetup($this->identityFor($jar)));

        // The secret is stored encrypted and is not readable by the server's service role.
        $stored = self::owner()->query('select secret, backup_codes from auth.two_factors')->fetch(PDO::FETCH_ASSOC);
        $this->assertMatchesRegularExpression('/^[0-9a-f]+$/', $stored['secret']);
        $this->assertStringNotContainsString(self::$backupCodes[0], $stored['backup_codes']);
        $service = self::$database->connect(self::$database->appUrl);
        $service->exec('BEGIN; SET LOCAL ROLE onehealth_service');
        $this->assertThrows('/permission denied/', fn () => $service->query('select secret from auth.two_factors'));
    }

    #[Depends('test_holds_an_admin_without_an_authenticator_at_the_setup_step')]
    public function test_requires_the_code_at_the_next_sign_in(): void
    {
        $jar = [];
        $signIn = $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => 'Imported-Admin-Pass-1'], $jar);
        $this->assertTrue($signIn['json']['twoFactorRedirect']);
        $this->assertSame(['totp'], $signIn['json']['twoFactorMethods']);
        $this->assertSame([], array_filter(array_keys($jar), fn ($k) => str_ends_with($k, 'session_token')));
        $this->assertUnauthorized($jar);

        $this->assertSame(401, $this->call('POST', '/two-factor/verify-totp', ['code' => '000000'], $jar)['status']);
        $this->assertSame(200, $this->call('POST', '/two-factor/verify-totp', ['code' => $this->totp(self::$totpSecret)], $jar)['status']);
        $this->assertSame(self::$importedAdmin, $this->identityFor($jar)['id']);
        // The challenge is single use.
        $this->assertArrayNotHasKey('__Secure-onehealth.two_factor', $jar);
    }

    #[Depends('test_requires_the_code_at_the_next_sign_in')]
    public function test_abandons_a_challenge_after_five_wrong_codes(): void
    {
        $jar = [];
        $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => 'Imported-Admin-Pass-1'], $jar);
        [self::$pinned, self::$pinnedIp] = [false, null];
        $statuses = [];
        for ($i = 0; $i < 6; $i++) {
            $statuses[] = $this->call('POST', '/two-factor/verify-totp', ['code' => '000000'], $jar)['status'];
        }
        $this->assertSame([401, 401, 401, 401, 401, 400], $statuses);
        $this->assertSame(401, $this->call('POST', '/two-factor/verify-totp', ['code' => $this->totp(self::$totpSecret)], $jar)['status']);
        self::owner()->exec('BEGIN; SET LOCAL ROLE onehealth_owner; update auth.two_factors set failed_verification_count = 0, locked_until = null; COMMIT');
    }

    #[Depends('test_abandons_a_challenge_after_five_wrong_codes')]
    public function test_accepts_a_backup_code_once(): void
    {
        $jar = [];
        $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => 'Imported-Admin-Pass-1'], $jar);
        $this->assertSame(200, $this->call('POST', '/two-factor/verify-backup-code', ['code' => self::$backupCodes[0]], $jar)['status']);
        $this->assertSame(self::$importedAdmin, $this->identityFor($jar)['id']);
        $second = [];
        $this->call('POST', '/sign-in/email', ['email' => 'imported@example.test', 'password' => 'Imported-Admin-Pass-1'], $second);
        $this->assertNotSame(200, $this->call('POST', '/two-factor/verify-backup-code', ['code' => self::$backupCodes[0]], $second)['status']);
    }

    // ------------------------------------------------- account administration

    #[Depends('test_accepts_a_backup_code_once')]
    public function test_creates_an_invited_admin_with_a_new_uuid_and_an_activation_email(): void
    {
        $created = self::$admin->create(self::$superAdmin, 'super@example.test', ['email' => 'New.Admin@Example.test', 'sendInvite' => true, 'role' => 'admin']);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $created['id']);
        $this->assertSame('/admin-activate', $this->tokenFromLastEmail('new.admin@example.test')['url']['path']);
        $this->assertThrows('/already exists/', fn () => self::$admin->create(self::$superAdmin, null, ['email' => 'new.admin@example.test', 'sendInvite' => true]));
    }

    #[Depends('test_creates_an_invited_admin_with_a_new_uuid_and_an_activation_email')]
    public function test_creates_an_account_with_a_temporary_password(): void
    {
        self::$admin->create(self::$superAdmin, null, ['email' => 'temp@example.test', 'sendInvite' => false, 'temporaryPassword' => 'Temporary-Pass-123', 'role' => 'security_auditor']);
        $jar = [];
        $this->assertSame(200, $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Temporary-Pass-123'], $jar)['status']);
    }

    #[Depends('test_creates_an_account_with_a_temporary_password')]
    public function test_disabling_ends_existing_sessions_and_blocks_new_ones_and_enabling_restores_sign_in(): void
    {
        $jar = [];
        $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1'], $jar);
        $this->assertSame(self::$auditor, $this->identityFor($jar)['id']);

        self::$admin->setEnabled(self::$superAdmin, null, self::$auditor, false);
        $this->assertUnauthorized($jar);
        $blocked = $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1']);
        $this->assertSame(403, $blocked['status']);
        $this->assertSame('disabled', $this->find('id', self::$auditor)['status']);
        $this->assertThrows('/disabled/', fn () => self::$admin->sendAccountEmail(self::$superAdmin, null, self::$auditor, 'reset'));

        self::$admin->setEnabled(self::$superAdmin, null, self::$auditor, true);
        $this->assertSame(200, $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1'])['status']);
    }

    #[Depends('test_disabling_ends_existing_sessions_and_blocks_new_ones_and_enabling_restores_sign_in')]
    public function test_protects_the_last_active_super_admin_and_the_callers_own_account(): void
    {
        $this->assertThrows('/own account/', fn () => self::$admin->setEnabled(self::$superAdmin, null, self::$superAdmin, false));
        self::$admin->setEnabled(self::$superAdmin, null, self::$otherSuper, false);
        $this->assertThrows('/last active Super Admin/', fn () => self::$admin->setEnabled(self::$otherSuper, null, self::$superAdmin, false));
        self::$admin->setEnabled(self::$superAdmin, null, self::$otherSuper, true);
    }

    #[Depends('test_protects_the_last_active_super_admin_and_the_callers_own_account')]
    public function test_sends_a_password_reset_link_that_ends_existing_sessions(): void
    {
        $jar = [];
        $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Temporary-Pass-123'], $jar);
        $this->assertSame('temp@example.test', $this->identityFor($jar)['email']);
        $target = $this->find('email', 'temp@example.test');
        self::$admin->sendAccountEmail(self::$superAdmin, null, $target['id'], 'reset');
        ['url' => $url, 'token' => $token] = $this->tokenFromLastEmail('temp@example.test');
        $this->assertSame('/admin-reset-password', $url['path']);
        $this->assertSame(200, $this->call('POST', '/reset-password', ['token' => $token, 'newPassword' => 'Temporary-Pass-456'])['status']);
        $this->assertUnauthorized($jar);
        $this->assertSame(401, $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Temporary-Pass-123'])['status']);
        $this->assertSame(200, $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Temporary-Pass-456'])['status']);
    }

    #[Depends('test_sends_a_password_reset_link_that_ends_existing_sessions')]
    public function test_changes_a_password_and_can_end_the_other_sessions(): void
    {
        $first = [];
        $second = [];
        $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Temporary-Pass-456'], $first);
        $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Temporary-Pass-456'], $second);
        $this->assertSame(400, $this->call('POST', '/change-password', ['currentPassword' => 'wrong-password-123', 'newPassword' => 'Temporary-Pass-789'], $first)['status']);
        $changed = $this->call('POST', '/change-password', ['currentPassword' => 'Temporary-Pass-456', 'newPassword' => 'Temporary-Pass-789', 'revokeOtherSessions' => true], $first);
        $this->assertSame(200, $changed['status']);
        $this->assertSame('temp@example.test', $this->identityFor($first)['email']);
        $this->assertUnauthorized($second);
        $this->assertSame(401, $this->call('POST', '/change-password', ['currentPassword' => 'x', 'newPassword' => 'Temporary-Pass-000'])['status']);
    }

    #[Depends('test_changes_a_password_and_can_end_the_other_sessions')]
    public function test_accepts_a_password_stored_by_the_original_application_and_upgrades_it(): void
    {
        // A hash exactly as the original application stores it (scrypt, "salt:key").
        $legacy = (new PasswordHasher('scrypt'))->hash('Legacy-Password-1');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}:[0-9a-f]{128}$/', $legacy);
        $id = $this->find('email', 'temp@example.test')['id'];
        $owner = self::owner();
        $owner->exec('BEGIN; SET LOCAL ROLE onehealth_owner');
        $owner->prepare('update auth.accounts set password = ? where user_id = ?')->execute([$legacy, $id]);
        $owner->exec('COMMIT');

        $this->assertSame(401, $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Legacy-Password-2'])['status']);
        $this->assertSame(200, $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Legacy-Password-1'])['status']);
        $stored = self::owner()->query("select password from auth.accounts where user_id = '{$id}'")->fetchColumn();
        $this->assertStringStartsWith('$argon2id$', $stored);
        $this->assertSame(200, $this->call('POST', '/sign-in/email', ['email' => 'temp@example.test', 'password' => 'Legacy-Password-1'])['status']);
    }

    // ------------------------------------ endpoint surface and abuse controls

    public function test_answers_404_for_every_endpoint_the_portal_does_not_use(): void
    {
        foreach ([
            ['POST', '/sign-up/email'],
            ['POST', '/request-password-reset'],
            ['POST', '/update-user'],
            ['GET', '/list-sessions'],
            ['POST', '/two-factor/disable'],
            ['GET', '/reset-password/abc'],
            ['POST', '/get-session'],
        ] as [$method, $path]) {
            $this->assertSame(404, $this->call($method, $path, $method === 'POST' ? [] : null)['status'], "{$method} {$path}");
        }
    }

    #[Depends('test_accepts_a_password_stored_by_the_original_application_and_upgrades_it')]
    public function test_rejects_a_cross_site_sign_in(): void
    {
        foreach (['https://evil.example', 'null', '', null] as $origin) {
            $result = $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1'], origin: $origin);
            $this->assertSame(403, $result['status']);
        }
    }

    #[Depends('test_rejects_a_cross_site_sign_in')]
    public function test_rate_limits_repeated_failed_sign_ins_per_client_address(): void
    {
        [self::$pinned, self::$pinnedIp] = [true, '203.0.113.77'];
        $statuses = [];
        for ($i = 0; $i < 7; $i++) {
            $statuses[] = $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => "wrong-{$i}-password"])['status'];
        }
        $this->assertSame([401, 401, 401, 401, 401, 429, 429], $statuses);
        // Another address is unaffected.
        self::$pinnedIp = '203.0.113.78';
        $this->assertSame(200, $this->call('POST', '/sign-in/email', ['email' => 'auditor@example.test', 'password' => 'Auditor-Password-1'])['status']);
        self::$pinned = false;
    }

    // ------------------------------------- least privilege of the auth role

    public function test_auth_role_cannot_read_application_data_or_create_accounts(): void
    {
        $asAuth = function (string $sql) {
            $pdo = self::$database->connect(self::$database->appUrl);
            $pdo->exec('BEGIN; SET LOCAL ROLE onehealth_auth');

            return $pdo->query($sql);
        };
        $this->assertThrows('/permission denied/', fn () => $asAuth('select * from public.user_roles'));
        $this->assertThrows('/permission denied/', fn () => $asAuth('select * from public.calculator_submissions'));
        $this->assertThrows('/permission denied/', fn () => $asAuth("insert into auth.users (id, email) values ('".Crypto::uuid()."', 'x@example.test')"));
        $this->assertThrows('/permission denied/', fn () => $asAuth("update auth.users set disabled_at = null where id = '".self::$auditor."'"));
    }

    public function test_auth_tables_are_not_available_to_signed_in_staff(): void
    {
        $pdo = self::$database->connect(self::$database->appUrl);
        $pdo->exec('BEGIN; SET LOCAL ROLE onehealth_authenticated');
        $this->assertThrows('/permission denied/', fn () => $pdo->query('select * from auth.sessions'));
    }
}
