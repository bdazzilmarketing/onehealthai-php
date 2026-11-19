<?php

namespace Tests\Database;

use App\Auth\AuthService;
use App\Auth\Crypto;
use App\Database\Db;
use App\Database\Tx;
use App\Mail\EmailTransport;
use App\Mail\GraphTransport;
use App\Mail\LogTransport;
use App\Mail\RestrictedTransport;
use App\Mail\TemplateEmails;
use App\Mail\TemplateMailer;
use App\Mail\Transports;
use App\Reports\SalesReports;
use App\Storage\FileStore;
use App\Storage\SignedLinks;
use App\Support\Audit;
use App\Support\CronJobs;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * Sales reports, email and the scheduled jobs on a real PostgreSQL with the
 * original schema and no network (port of the outbox, ledger, transport, cron
 * and download parts of src/lib/supporting-services.server.test.ts).
 */
final class ReportsTest extends TestCase
{
    private const ORIGIN = 'https://admin.onehealth.test';

    private const PASSWORD = 'Long enough password 1!';

    private const CRON_SECRET = 'cccccccccccccccccccccccccccccccccccccccc';

    private const LINK = ['kind' => 'reset', 'url' => 'https://x.test/r?token=t', 'validFor' => '1 hour'];

    private static TestDatabase $database;

    private static PDO $owner;

    private static string $store;

    /** @var array<string, string> role => user id */
    private static array $users = [];

    /** @var array<string, array<string, string>> role => cookies */
    private static array $jars = [];

    /** @var list<array<string, mixed>> messages the fake transport accepted in the current test */
    private array $outbox = [];

    private bool $failNextSend = false;

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55448, 'reports');
        self::$database->install();
        self::$store = sys_get_temp_dir().'/onehealth-reports-'.bin2hex(random_bytes(6));
        self::$owner = self::$database->connect(self::$database->migratorUrl);
        self::$owner->exec('SET ROLE onehealth_owner');
        foreach (['admin', 'security_auditor', 'none'] as $role) {
            $id = self::$users[$role] = Crypto::uuid();
            self::$owner->prepare('insert into auth.users (id, email) values (?, ?)')->execute([$id, "{$role}@example.test"]);
            self::$owner->prepare('insert into public.profiles (id, email, full_name) values (?, ?, ?)')->execute([$id, "{$role}@example.test", ucfirst($role)]);
            if ($role !== 'none') {
                self::$owner->prepare('insert into public.user_roles (user_id, role) values (?, ?)')->execute([$id, $role]);
            }
        }
        self::$owner->exec("insert into public.sales_report_recipients (email, active) values ('sales@example.test', true), ('old@example.test', false)");
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->stop();
        exec('rm -rf '.escapeshellarg(self::$store));
    }

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'database.connections.pgsql.url' => self::$database->appUrl,
            'database.connections.pgsql.sslmode' => 'disable',
            'onehealth.env.AUTH_SECRET' => str_pad('test-secret-', 48, 'x'),
            'onehealth.env.AUTH_BASE_URL' => self::ORIGIN,
            'onehealth.env.AUTH_MFA_REQUIRED_ROLES' => '',
            'onehealth.env.FILE_STORE_DRIVER' => 'local',
            'onehealth.env.FILE_STORE_PATH' => self::$store,
            'onehealth.env.CRON_SECRET' => self::CRON_SECRET,
            'onehealth.env.PUBLIC_SITE_URL' => 'https://onehealth.test/',
        ]);
        $transport = new class($this->outbox, $this->failNextSend) implements EmailTransport
        {
            public function __construct(private array &$outbox, private bool &$failNext) {}

            public function name(): string
            {
                return 'test';
            }

            public function send(array $message): void
            {
                if ($this->failNext) {
                    $this->failNext = false;
                    throw new \RuntimeException('temporary failure');
                }
                $this->outbox[] = $message;
            }
        };
        $this->app->singleton(TemplateMailer::class, fn ($app) => new TemplateEmails(
            $app->make(Db::class), fn () => $transport, fn (string $name) => config("onehealth.env.{$name}"),
        ));
        if (self::$jars === []) {
            $auth = $this->app->make(AuthService::class);
            foreach (array_keys(self::$users) as $role) {
                $auth->setInitialPassword(self::$users[$role], self::PASSWORD);
                self::$jars[$role] = [];
                $this->send('POST', '/api/auth/sign-in/email', null, ['email' => "{$role}@example.test", 'password' => self::PASSWORD], ['Origin' => self::ORIGIN], self::$jars[$role])
                    ->assertStatus(200);
            }
        }
    }

    private function send(string $method, string $uri, ?string $as = null, ?array $body = null, array $headers = [], ?array &$jar = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9', 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $cookies = $as !== null ? self::$jars[$as] : ($jar ?? []);
        if ($cookies) {
            $server['HTTP_COOKIE'] = implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
        }
        $response = $this->call($method, $uri, [], [], [], $server, $body === null ? null : json_encode($body));
        if ($jar !== null) {
            foreach ($response->headers->getCookies() as $cookie) {
                $jar[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /** @return array{id: string, reference: string} */
    private static function insertSubmission(): array
    {
        $id = Crypto::uuid();
        $reference = 'OH-TEST-'.strtoupper(substr($id, 0, 8));
        $session = self::$owner->query("insert into public.calculator_sessions (full_name, company, phone, email) values ('Lead', 'Lab', '+971500000000', 'lead@example.test') returning id")->fetchColumn();
        self::$owner->prepare("insert into public.calculator_submissions
            (id, reference_id, calculator_session_id, idempotency_key, full_name, company, phone, email, calculation_version, internal_total, public_low, public_high, report_status, email_status)
            values (?, ?, ?, ?, 'Lead', 'Lab', '+971500000000', 'lead@example.test', 'test', 1000, 900, 1100, 'pending', 'pending')")
            ->execute([$id, $reference, $session, $id]);

        return ['id' => $id, 'reference' => $reference];
    }

    private static function state(string $id): array
    {
        return self::$owner->query("select report_status, email_status, report_storage_path, report_version, report_auto_attempts, email_attempt_count, email_error, email_recipients
            from public.calculator_submissions where id = '{$id}'")->fetch(PDO::FETCH_ASSOC);
    }

    private static function ledger(string $key): ?array
    {
        $statement = self::$owner->prepare('select status, attempts, recipient_hash from public.email_deliveries where idempotency_key = ?');
        $statement->execute([$key]);

        return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private static function audit(string $eventType): array
    {
        $statement = self::$owner->prepare('select actor_id, actor_email, details from public.audit_log where event_type = ? order by created_at desc limit 1');
        $statement->execute([$eventType]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? ['actor_id' => $row['actor_id'], 'actor_email' => $row['actor_email'], 'details' => json_decode($row['details'], true)] : [];
    }

    private function cron(string $job, ?string $authorization = 'Bearer '.self::CRON_SECRET, string $method = 'GET'): TestResponse
    {
        return $this->send($method, "/api/cron/{$job}", null, null, $authorization === null ? [] : ['Authorization' => $authorization]);
    }

    // ------------------------------------------------------------- the outbox

    public function test_a_pending_submission_is_delivered_once(): void
    {
        ['id' => $id, 'reference' => $reference] = self::insertSubmission();

        $first = $this->cron('sales-report-recovery')->assertStatus(200)->assertHeader('Cache-Control', 'no-store, private');
        $this->assertTrue($first->json('ok'));
        $this->assertContains(['id' => $id, 'outcome' => 'report=generated email=sent'], $first->json('result.processed'));
        $state = self::state($id);
        $this->assertSame(['generated', 'sent', "{$reference}/{$reference}-internal-report.pdf", 1, 1, 1],
            [$state['report_status'], $state['email_status'], $state['report_storage_path'], $state['report_version'], $state['report_auto_attempts'], $state['email_attempt_count']]);
        $this->assertSame([['email' => 'sales@example.test', 'outcome' => 'sent']], json_decode($state['email_recipients'], true));

        $pdf = $this->app->make(FileStore::class)->get('submission-reports', $state['report_storage_path']);
        $this->assertStringStartsWith('%PDF-', $pdf['bytes']);
        $this->assertSame('application/pdf', $pdf['contentType']);
        $this->assertCount(1, $this->outbox);
        $this->assertSame('sales@example.test', $this->outbox[0]['to']);
        $this->assertStringContainsString($reference, $this->outbox[0]['subject']);
        $this->assertStringContainsString("https://onehealth.test/adminpanel/submissions/{$id}", $this->outbox[0]['text']);
        $this->assertStringContainsString('noreply@', $this->outbox[0]['from']);
        $this->assertSame('sent', self::ledger("sales-report:{$id}:sales@example.test")['status']);
        $this->assertEquals(['referenceId' => $reference, 'trigger' => 'automatic', 'version' => 1], self::audit('sales_report_generated')['details']);
        $this->assertSame('automatic', self::audit('sales_notification_sent')['details']['trigger']);

        // A second sweep, and a direct second trigger, find nothing to do and send nothing.
        $this->cron('sales-report-recovery', method: 'POST')->assertStatus(200);
        $this->assertSame(['skipped' => true, 'report' => null, 'email' => null], $this->app->make(SalesReports::class)->runAutomatic($id));
        $this->assertCount(1, $this->outbox);
        $this->assertSame(['skipped' => true, 'reason' => 'not_found'], $this->app->make(SalesReports::class)->runAutomatic(Crypto::uuid()));
    }

    public function test_a_failed_email_waits_for_the_next_sweep(): void
    {
        ['id' => $id] = self::insertSubmission();
        $reports = $this->app->make(SalesReports::class);
        $this->failNextSend = true;
        $this->assertSame(['skipped' => false, 'report' => 'generated', 'email' => 'failed'], $reports->runAutomatic($id));
        $this->assertCount(0, $this->outbox);
        $this->assertSame(['failed', 'temporary failure'], [self::state($id)['email_status'], self::state($id)['email_error']]);

        // Inside the back-off nothing is retried.
        $this->cron('sales-report-recovery')->assertStatus(200);
        $this->assertCount(0, $this->outbox);

        // Back-off has passed (simulated by ageing the claim), then the sweep retries.
        self::$owner->exec("update public.calculator_submissions set email_claimed_at = now() - interval '1 day' where id = '{$id}'");
        $this->cron('sales-report-recovery')->assertStatus(200);
        $this->assertSame(['sent', 2], [self::state($id)['email_status'], self::state($id)['email_attempt_count']]);
        $this->assertCount(1, $this->outbox);
    }

    public function test_a_failed_report_is_retried_with_limits(): void
    {
        ['id' => $id, 'reference' => $reference] = self::insertSubmission();
        $failing = new SalesReports(
            $this->app->make(Db::class), $this->app->make(Audit::class), fn () => $this->app->make(FileStore::class),
            $this->app->make(TemplateMailer::class), fn (string $name) => config("onehealth.env.{$name}"),
            fn () => throw new \RuntimeException('font table missing'),
        );
        $this->assertSame(['skipped' => false, 'report' => 'failed', 'email' => null], $failing->runAutomatic($id));
        $this->assertSame(['failed', 1], [self::state($id)['report_status'], self::state($id)['report_auto_attempts']]);
        $this->assertEquals(['referenceId' => $reference, 'trigger' => 'automatic', 'error' => 'font table missing'], self::audit('sales_report_failed')['details']);
        // Still inside the back-off: not claimed again.
        $this->assertSame(['skipped' => true, 'report' => null, 'email' => null], $failing->runAutomatic($id));

        // After five automatic attempts the sweep leaves it for an administrator.
        self::$owner->exec("update public.calculator_submissions set report_auto_attempts = 5, report_claimed_at = now() - interval '1 day' where id = '{$id}'");
        $this->assertSame(['skipped' => true, 'report' => null, 'email' => null], $this->app->make(SalesReports::class)->runAutomatic($id));
        $this->assertCount(0, $this->outbox);

        // A claim abandoned mid-generation is picked up once it is stale.
        self::$owner->exec("update public.calculator_submissions set report_status = 'generating', report_auto_attempts = 1, report_claimed_at = now() - interval '5 minutes' where id = '{$id}'");
        $this->assertTrue($this->app->make(SalesReports::class)->runAutomatic($id)['skipped']);
        self::$owner->exec("update public.calculator_submissions set report_claimed_at = now() - interval '16 minutes' where id = '{$id}'");
        $this->assertSame(['skipped' => false, 'report' => 'generated', 'email' => 'sent'], $this->app->make(SalesReports::class)->runAutomatic($id));
    }

    public function test_admins_regenerate_the_report_and_resend_the_notification(): void
    {
        ['id' => $id, 'reference' => $reference] = self::insertSubmission();
        $body = ['id' => $id];
        $forbidden = ['message' => 'Forbidden: administrator role required'];
        $this->send('POST', '/api/fn/regenerateSubmissionReport', null, $body)->assertStatus(401);
        $this->send('POST', '/api/fn/regenerateSubmissionReport', 'security_auditor', $body)->assertStatus(500)->assertExactJson($forbidden);
        $this->send('POST', '/api/fn/resendSalesNotification', 'security_auditor', $body)->assertStatus(500)->assertExactJson($forbidden);
        $this->send('POST', '/api/fn/regenerateSubmissionReport', 'admin', ['id' => 'nope'])->assertStatus(500);
        $missing = ['id' => '00000000-0000-4000-8000-000000000000'];
        $this->send('POST', '/api/fn/regenerateSubmissionReport', 'admin', $missing)->assertStatus(500)->assertExactJson(['message' => 'Submission not found']);
        $this->send('POST', '/api/fn/resendSalesNotification', 'admin', $body)->assertStatus(500)
            ->assertExactJson(['message' => 'Generate the report before resending the notification']);

        $this->send('POST', '/api/fn/regenerateSubmissionReport', 'admin', $body)->assertStatus(200)->assertExactJson(['status' => 'generated']);
        $this->send('POST', '/api/fn/regenerateSubmissionReport', 'admin', $body)->assertStatus(200)->assertExactJson(['status' => 'generated']);
        $state = self::state($id);
        // A manual rebuild raises the version, does not use up automatic attempts and does not email.
        $this->assertSame(['generated', 2, 0, 'pending'], [$state['report_status'], $state['report_version'], $state['report_auto_attempts'], $state['email_status']]);
        $this->assertCount(0, $this->outbox);
        $generated = self::audit('sales_report_generated');
        $this->assertSame([self::$users['admin'], 'admin@example.test'], [$generated['actor_id'], $generated['actor_email']]);
        $this->assertEquals(['referenceId' => $reference, 'trigger' => 'manual_regenerate', 'version' => 2], $generated['details']);
        $this->assertEquals(['referenceId' => $reference], self::audit('sales_report_regenerate_requested')['details']);

        // Each explicit resend sends again under its own key.
        $this->send('POST', '/api/fn/resendSalesNotification', 'admin', $body)->assertStatus(200)->assertExactJson(['status' => 'sent', 'error' => null]);
        $this->send('POST', '/api/fn/resendSalesNotification', 'admin', $body)->assertStatus(200)->assertExactJson(['status' => 'sent', 'error' => null]);
        $this->assertCount(2, $this->outbox);
        $this->assertNotNull(self::ledger("sales-report:{$id}:sales@example.test:resend:2"));
        $this->failNextSend = true;
        $this->send('POST', '/api/fn/resendSalesNotification', 'admin', $body)->assertStatus(200)->assertExactJson(['status' => 'failed', 'error' => 'temporary failure']);
        $failed = self::audit('sales_notification_failed');
        $this->assertSame('admin@example.test', $failed['actor_email']);
        $this->assertEquals(['referenceId' => $reference, 'trigger' => 'manual_resend', 'attempt' => 3,
            'recipients' => [['email' => 'sales@example.test', 'outcome' => 'failed']], 'error' => 'temporary failure'], $failed['details']);

        // Without an active recipient the notification fails with a clear reason.
        self::$owner->exec('update public.sales_report_recipients set active = false');
        try {
            $this->send('POST', '/api/fn/resendSalesNotification', 'admin', $body)->assertStatus(200)
                ->assertExactJson(['status' => 'failed', 'error' => 'No active sales report recipients are configured']);
        } finally {
            self::$owner->exec("update public.sales_report_recipients set active = true where email = 'sales@example.test'");
        }
    }

    // ------------------------------------------------------- the email ledger

    public function test_a_keyed_email_is_sent_once(): void
    {
        $mailer = $this->app->make(TemplateMailer::class);
        $key = 'test-'.Crypto::uuid();
        $this->assertTrue($mailer->send('admin-account-link', 'Person@Example.test', self::LINK, $key));
        $this->assertTrue($mailer->send('admin-account-link', 'Person@Example.test', self::LINK, $key));
        $this->assertCount(1, $this->outbox);
        $this->assertStringContainsString('https://x.test/r?token=t', $this->outbox[0]['html']);
        $this->assertStringContainsString('https://x.test/r?token=t', $this->outbox[0]['text']);
        $this->assertStringContainsString('noreply@', $this->outbox[0]['from']);
        $row = self::ledger($key);
        $this->assertSame(['sent', 1], [$row['status'], $row['attempts']]);
        // The ledger holds a hash, never the address.
        $this->assertSame(hash('sha256', 'person@example.test'), $row['recipient_hash']);
    }

    public function test_a_failed_send_is_recorded_and_can_be_retried(): void
    {
        $mailer = $this->app->make(TemplateMailer::class);
        $key = 'test-'.Crypto::uuid();
        $this->failNextSend = true;
        try {
            $mailer->send('admin-account-link', 'a@example.test', self::LINK, $key);
            $this->fail('the send should have failed');
        } catch (\RuntimeException $error) {
            $this->assertSame('temporary failure', $error->getMessage());
        }
        $this->assertSame('failed', self::ledger($key)['status']);
        $mailer->send('admin-account-link', 'a@example.test', self::LINK, $key);
        $this->assertSame(['sent', 2], [self::ledger($key)['status'], self::ledger($key)['attempts']]);
        $this->assertCount(1, $this->outbox);
    }

    public function test_a_concurrent_send_is_refused_and_an_abandoned_one_reclaimed(): void
    {
        $mailer = $this->app->make(TemplateMailer::class);
        $key = 'test-'.Crypto::uuid();
        self::$owner->prepare("insert into public.email_deliveries (idempotency_key, template, recipient_hash, status) values (?, 'admin-account-link', 'h', 'sending')")->execute([$key]);
        try {
            $mailer->send('admin-account-link', 'a@example.test', self::LINK, $key);
            $this->fail('the send should have been refused');
        } catch (\RuntimeException $error) {
            $this->assertSame('Email delivery already in progress', $error->getMessage());
        }
        $this->assertCount(0, $this->outbox);
        self::$owner->prepare("update public.email_deliveries set claimed_at = now() - interval '1 hour' where idempotency_key = ?")->execute([$key]);
        $mailer->send('admin-account-link', 'a@example.test', self::LINK, $key);
        $this->assertCount(1, $this->outbox);
    }

    public function test_unkeyed_mail_is_sent_every_time_and_unknown_templates_are_refused(): void
    {
        $mailer = $this->app->make(TemplateMailer::class);
        $before = (int) self::$owner->query('select count(*) from public.email_deliveries')->fetchColumn();
        $mailer->send('admin-account-link', 'a@example.test', self::LINK);
        $mailer->send('admin-account-link', 'a@example.test', self::LINK);
        $this->assertCount(2, $this->outbox);
        $this->assertSame($before, (int) self::$owner->query('select count(*) from public.email_deliveries')->fetchColumn());
        try {
            $mailer->send('nope', 'a@example.test', []);
            $this->fail('an unknown template should be refused');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString("Template 'nope' not found", $error->getMessage());
        }
    }

    public function test_the_ledger_is_not_reachable_by_staff(): void
    {
        try {
            $this->app->make(Db::class)->withUserContext(self::$users['admin'], fn (Tx $tx) => $tx->select('select * from public.email_deliveries'));
            $this->fail('staff should not read the ledger');
        } catch (\Throwable $error) {
            $this->assertStringContainsString('permission denied', $error->getMessage());
        }
    }

    // ---------------------------------------------------------- transports

    public function test_the_configured_driver_is_selected_and_anything_else_fails_closed(): void
    {
        $make = fn (array $env, bool $production = false) => Transports::fromEnv(fn (string $name) => $env[$name] ?? null, $production);
        $message = fn (callable $fn) => (function () use ($fn) {
            try {
                $fn();
            } catch (\RuntimeException $error) {
                return $error->getMessage();
            }

            return null;
        })();
        $this->assertStringContainsString('Email is not configured', $message(fn () => $make([])));
        $this->assertStringContainsString('SMTP_HOST', $message(fn () => $make(['EMAIL_DRIVER' => 'smtp'])));
        $this->assertStringContainsString('GRAPH_CLIENT_ID', $message(fn () => $make(['EMAIL_DRIVER' => 'graph', 'GRAPH_TENANT_ID' => 't'])));
        $this->assertStringContainsString('not allowed in production', $message(fn () => $make(['EMAIL_DRIVER' => 'log'], true)));
        $this->assertSame('smtp', $make(['EMAIL_DRIVER' => 'smtp', 'SMTP_HOST' => 'mail.example.test'])->name());
        $this->assertSame('log', $make(['EMAIL_DRIVER' => 'log'])->name());

        $restricted = $make(['EMAIL_DRIVER' => 'log', 'EMAIL_ALLOWED_RECIPIENTS' => 'tester@example.test']);
        $this->assertSame('Recipient is not on EMAIL_ALLOWED_RECIPIENTS',
            $message(fn () => $restricted->send(['from' => 'a@b.test', 'to' => 'other@example.test', 'subject' => 's', 'html' => 'h', 'text' => 't'])));
    }

    public function test_recipients_outside_the_allowlist_are_refused(): void
    {
        $this->assertNull(Transports::parseRecipientAllowlist(null));
        $this->assertNull(Transports::parseRecipientAllowlist(' '));
        try {
            Transports::parseRecipientAllowlist('not-an-address');
            $this->fail('an entry without @ should be refused');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('EMAIL_ALLOWED_RECIPIENTS', $error->getMessage());
        }
        $sent = [];
        $inner = new LogTransport(function (string $line) use (&$sent) {
            $sent[] = $line;
        });
        $transport = new RestrictedTransport($inner, Transports::parseRecipientAllowlist('Tester@Example.test, @qa.example.test'));
        $message = ['from' => 'a@b.test', 'subject' => 's', 'html' => 'h', 'text' => 't'];
        $transport->send($message + ['to' => 'tester@example.test']);
        $transport->send($message + ['to' => 'anyone@QA.example.test']);
        foreach (['staff@onehealth.test', 'tester@example.test.evil.test'] as $to) {
            try {
                $transport->send($message + ['to' => $to]);
                $this->fail("{$to} should be refused");
            } catch (\RuntimeException $error) {
                $this->assertSame('Recipient is not on EMAIL_ALLOWED_RECIPIENTS', $error->getMessage());
            }
        }
        // The development driver logs only the recipient and the subject.
        $this->assertSame(['[email:log] to=tester@example.test subject="s"', '[email:log] to=anyone@QA.example.test subject="s"'], $sent);
    }

    public function test_microsoft_graph_sends_with_a_cached_token(): void
    {
        Http::preventStrayRequests();
        $calls = [];
        Http::fake(function (Request $request) use (&$calls) {
            $calls[] = $request;

            return str_contains($request->url(), 'login.microsoftonline.com')
                ? Http::response(['access_token' => 'tok', 'expires_in' => 3600])
                : Http::response(null, 202);
        });
        $transport = new GraphTransport('tenant-1', 'client-1', 'shh', 'noreply@onehealth.test');
        $message = ['from' => 'x', 'to' => 'sales@example.test', 'subject' => 'S', 'html' => '<p>h</p>', 'text' => 'h', 'replyTo' => 'r@example.test'];
        $transport->send($message);
        $transport->send($message);
        $this->assertSame([
            'https://login.microsoftonline.com/tenant-1/oauth2/v2.0/token',
            'https://graph.microsoft.com/v1.0/users/noreply%40onehealth.test/sendMail',
            'https://graph.microsoft.com/v1.0/users/noreply%40onehealth.test/sendMail',
        ], array_map(fn (Request $request) => $request->url(), $calls));
        parse_str($calls[0]->body(), $token);
        $this->assertSame(['client_credentials', 'https://graph.microsoft.com/.default'], [$token['grant_type'], $token['scope']]);
        $this->assertSame(['Bearer tok'], $calls[1]->header('authorization'));
        $body = json_decode($calls[1]->body(), true);
        $this->assertSame([['emailAddress' => ['address' => 'sales@example.test']]], $body['message']['toRecipients']);
        $this->assertSame(['contentType' => 'HTML', 'content' => '<p>h</p>'], $body['message']['body']);
        $this->assertSame([['emailAddress' => ['address' => 'r@example.test']]], $body['message']['replyTo']);

        Http::swap(new Factory);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'login') ? Http::response(['access_token' => 'tok']) : Http::response('{}', 403));
        try {
            (new GraphTransport('t', 'c', 's', 'n@example.test'))->send(['from' => 'x', 'to' => 'a@example.test', 'subject' => 's', 'html' => 'h', 'text' => 'h']);
            $this->fail('a rejection should throw');
        } catch (\RuntimeException $error) {
            $this->assertSame('Microsoft Graph send failed (403)', $error->getMessage());
        }
    }

    // ------------------------------------------------- scheduled jobs, health

    public function test_scheduled_jobs_need_the_secret(): void
    {
        foreach ([null, self::CRON_SECRET, 'Bearer '.self::CRON_SECRET.'x', 'Bearer ', 'bearer '.self::CRON_SECRET] as $authorization) {
            foreach (['maintenance', 'sales-report-recovery'] as $job) {
                $this->cron($job, $authorization)->assertStatus(404)->assertContent('Not found');
            }
        }
        // A missing or short secret refuses every call, even one that carries it.
        foreach ([null, 'short'] as $secret) {
            config(['onehealth.env.CRON_SECRET' => $secret]);
            $this->cron('maintenance', 'Bearer '.$secret)->assertStatus(404);
        }
        config(['onehealth.env.CRON_SECRET' => self::CRON_SECRET]);

        // A failing job answers without detail.
        $cron = $this->app->make(CronJobs::class);
        $request = \Illuminate\Http\Request::create('/api/cron/t', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.self::CRON_SECRET]);
        $failed = $cron->run($request, 't', fn () => throw new \RuntimeException('postgres://user:secret@host/db is down'));
        $this->assertSame([500, '{"ok":false}', 'no-store, private'], [$failed->getStatusCode(), $failed->getContent(), $failed->headers->get('Cache-Control')]);
    }

    public function test_maintenance_cleans_up_expired_records(): void
    {
        $admin = self::$users['admin'];
        self::$owner->exec("insert into auth.sessions (user_id, token, expires_at) values
            ('{$admin}', '".Crypto::uuid()."', now() - interval '1 hour'), ('{$admin}', '".Crypto::uuid()."', now() + interval '1 hour')");
        self::$owner->exec("insert into auth.verifications (identifier, value, expires_at) values ('old', 'v', now() - interval '1 hour')");
        self::$owner->exec("insert into public.ai_rate_buckets (scope, key_hash, window_kind, window_start, count) values ('ip', '".str_repeat('k', 32)."', 'm', now() - interval '3 days', 1)");
        self::$owner->exec("insert into public.email_deliveries (idempotency_key, template, recipient_hash, status, created_at) values ('ancient', 'admin-account-link', 'h', 'sent', now() - interval '31 days')");
        $sessions = fn () => (int) self::$owner->query("select count(*) from auth.sessions where user_id = '{$admin}'")->fetchColumn();
        $before = $sessions();

        $response = $this->cron('maintenance', method: 'POST')->assertStatus(200);
        $this->assertSame('{"ok":true,"result":{"expiredSessions":1,"expiredLinks":1,"emailRecords":1}}', $response->getContent());
        $this->assertSame($before - 1, $sessions());
        $this->assertSame(0, (int) self::$owner->query("select count(*) from public.ai_rate_buckets where window_start < now() - interval '2 days'")->fetchColumn());
        $this->assertNull(self::ledger('ancient'));
    }

    public function test_health_checks_answer_without_detail(): void
    {
        $this->get('/api/health')->assertStatus(200)->assertContent('{"status":"ok"}')->assertHeader('Cache-Control', 'no-store, private');
        $this->call('HEAD', '/api/health')->assertStatus(200);
        $this->get('/api/health/ready')->assertStatus(200)->assertContent('{"status":"ready"}');

        config(['database.connections.pgsql.url' => 'postgres://nobody:wrong@127.0.0.1:1/none']);
        $this->app->forgetInstance(Db::class);
        \Illuminate\Support\Facades\DB::purge('pgsql');
        $this->get('/api/health/ready')->assertStatus(503)->assertContent('{"status":"unavailable"}');
    }

    // ------------------------------------------------------- report download

    public function test_the_report_download_needs_a_link_a_session_and_an_admin_viewer(): void
    {
        ['id' => $id, 'reference' => $reference] = self::insertSubmission();
        $links = $this->app->make(SignedLinks::class);
        $url = $links->signedReportPath($id, true);

        // Not generated yet: the link is valid but there is nothing to serve.
        $this->send('GET', $url, 'admin')->assertStatus(404)->assertContent('Not found');
        $this->app->make(SalesReports::class)->runAutomatic($id);

        $this->send('GET', str_replace($id, 'not-a-uuid', $url), 'admin')->assertStatus(404);
        $this->send('GET', preg_replace('/sig=[^&]+/', 'sig=bad', $url), 'admin')->assertStatus(404);
        $this->send('GET', str_replace('dl=1', 'dl=0', $url), 'admin')->assertStatus(404);
        $this->send('GET', $url)->assertStatus(401)->assertContent('Unauthorized');
        $this->send('GET', $url, 'none')->assertStatus(403)->assertContent('Forbidden');

        $ok = $this->send('GET', $url, 'security_auditor')->assertStatus(200);
        $this->assertSame('application/pdf', $ok->headers->get('Content-Type'));
        $this->assertSame("attachment; filename=\"{$reference}-internal-report.pdf\"", $ok->headers->get('Content-Disposition'));
        $this->assertSame('no-store, private', $ok->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('%PDF-', $ok->getContent());

        $inline = $this->send('GET', $links->signedReportPath($id, false), 'admin')->assertStatus(200);
        $this->assertStringStartsWith('inline; ', $inline->headers->get('Content-Disposition'));

        // The file is gone from storage: nothing is revealed.
        exec('rm -rf '.escapeshellarg(self::$store));
        $this->send('GET', $url, 'admin')->assertStatus(404);
    }
}
