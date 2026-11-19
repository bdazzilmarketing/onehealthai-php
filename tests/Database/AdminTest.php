<?php

namespace Tests\Database;

use App\Admin\AdminData;
use App\Admin\AiQuotaAdmin;
use App\Auth\AuthService;
use App\Auth\Crypto;
use App\Auth\Roles;
use App\Database\Db;
use App\Database\UserScope;
use App\Support\AppError;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * The Admin Portal endpoints on a real PostgreSQL with the original schema,
 * called over HTTP by signed-in staff: role gates, row-level security, the
 * workbook import, publishing, settings, prompts, recipients, submissions and
 * AI limits. Ports the admin parts of src/lib/data-access.server.test.ts.
 */
final class AdminTest extends TestCase
{
    private const ORIGIN = 'https://admin.onehealth.test';

    private const PASSWORD = 'Long enough password 1!';

    private const FORBIDDEN = ['message' => 'Forbidden: administrator role required'];

    private static TestDatabase $database;

    private static PDO $owner;

    private static string $store;

    /** @var array<string, string> role => user id */
    private static array $users = [];

    /** @var array<string, array<string, string>> role => cookies */
    private static array $jars = [];

    /** @var array<string, mixed> values handed from one test to the next */
    private static array $kept = [];

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55446, 'admin');
        self::$database->install();
        self::$store = sys_get_temp_dir().'/onehealth-admin-'.bin2hex(random_bytes(6));
        self::$owner = self::$database->connect(self::$database->migratorUrl);
        self::$owner->exec('SET ROLE onehealth_owner');
        foreach (['super_admin', 'admin', 'security_auditor', 'none'] as $role) {
            $id = self::$users[$role] = Crypto::uuid();
            self::$owner->prepare('insert into auth.users (id, email) values (?, ?)')->execute([$id, "{$role}@example.test"]);
            self::$owner->prepare('insert into public.profiles (id, email, full_name) values (?, ?, ?)')->execute([$id, "{$role}@example.test", ucfirst($role)]);
            if ($role !== 'none') {
                self::$owner->prepare('insert into public.user_roles (user_id, role) values (?, ?)')->execute([$id, $role]);
            }
        }
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
            // Two-step sign-in has its own tests; here every role signs in with a password alone.
            'onehealth.env.AUTH_MFA_REQUIRED_ROLES' => '',
            'onehealth.env.FILE_STORE_DRIVER' => 'local',
            'onehealth.env.FILE_STORE_PATH' => self::$store,
            'onehealth.env.MAKE_WEBHOOK_URL' => 'https://hook.example.test/abc',
            'onehealth.env.MAKE_WEBHOOK_SECRET' => 'make-secret',
        ]);
        if (self::$jars === []) {
            $auth = $this->app->make(AuthService::class);
            foreach (['super_admin', 'admin', 'security_auditor'] as $role) {
                $auth->setInitialPassword(self::$users[$role], self::PASSWORD);
                self::$jars[$role] = [];
                $this->send('POST', '/api/auth/sign-in/email', null, ['email' => "{$role}@example.test", 'password' => self::PASSWORD], ['Origin' => self::ORIGIN], self::$jars[$role])
                    ->assertStatus(200);
            }
        }
    }

    /** Calls an endpoint as the given role (null: not signed in). */
    private function send(string $method, string $uri, ?string $as = null, ?array $body = null, array $headers = [], ?array &$jar = null): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json', 'REMOTE_ADDR' => '203.0.113.9'];
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

    private function fn(string $name, string $as, ?array $body = null): TestResponse
    {
        return $this->send($body === null ? 'GET' : 'POST', "/api/fn/{$name}", $as, $body);
    }

    private static function workbook(string $file): string
    {
        return base64_encode(file_get_contents(__DIR__.'/../Fixtures/workbooks/'.$file));
    }

    private static function audit(string $eventType): array
    {
        $statement = self::$owner->prepare('select actor_id, actor_email, details from public.audit_log where event_type = ? order by created_at desc limit 1');
        $statement->execute([$eventType]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row ? ['actor_id' => $row['actor_id'], 'actor_email' => $row['actor_email'], 'details' => json_decode($row['details'], true)] : [];
    }

    public function test_every_endpoint_requires_a_signed_in_staff_member(): void
    {
        foreach (app('router')->getRoutes() as $route) {
            if (in_array('staff', $route->middleware(), true) || in_array('staff:session', $route->middleware(), true)) {
                $this->send($route->methods()[0], '/'.$route->uri(), null, [])->assertStatus(401)->assertExactJson(['message' => 'Unauthorized']);
            }
        }
    }

    public function test_session_summary_for_each_role(): void
    {
        $this->fn('getSession', 'admin')->assertStatus(200)->assertExactJson([
            'admin' => true, 'superAdmin' => false, 'readOnly' => false, 'email' => 'admin@example.test',
            'profile' => ['id' => self::$users['admin'], 'email' => 'admin@example.test', 'full_name' => 'Admin'],
            'mfa' => ['required' => false, 'enrolled' => false],
        ]);
        $this->fn('getSession', 'super_admin')->assertJsonPath('admin', true)->assertJsonPath('superAdmin', true)->assertJsonPath('readOnly', false);
        $this->fn('getSession', 'security_auditor')->assertJsonPath('admin', true)->assertJsonPath('superAdmin', false)->assertJsonPath('readOnly', true);

        $this->fn('recordSignIn', 'admin', [])->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->assertSame(self::$users['admin'], self::audit('auth.login')['actor_id']);
    }

    public function test_user_administration_is_for_super_admins(): void
    {
        $list = $this->fn('listUsers', 'super_admin')->assertStatus(200)->json();
        $this->assertEmpty(array_diff(['super_admin', 'admin', 'security_auditor'], array_column($list, 'role')));
        $this->assertArrayNotHasKey('password', $list[0]);
        $this->fn('listUsers', 'security_auditor')->assertStatus(200);
        $forbidden = ['message' => 'Forbidden: super administrator role required'];
        $this->fn('listUsers', 'admin')->assertStatus(500)->assertExactJson($forbidden);
        $this->fn('createUser', 'admin', ['email' => 'new@example.test', 'sendInvite' => false, 'temporaryPassword' => 'A long temporary 1!'])->assertStatus(500)->assertExactJson($forbidden);
        $this->fn('setUserEnabled', 'admin', ['userId' => self::$users['security_auditor'], 'enabled' => false])->assertStatus(500)->assertExactJson($forbidden);
        $this->fn('sendUserAccountEmail', 'security_auditor', ['userId' => self::$users['admin'], 'kind' => 'reset'])->assertStatus(500)->assertExactJson($forbidden);

        $this->fn('createUser', 'super_admin', ['email' => 'not-an-email', 'sendInvite' => false, 'temporaryPassword' => 'A long temporary 1!'])
            ->assertStatus(500)->assertExactJson(['message' => 'Invalid input: email must be an email address']);
        $this->fn('createUser', 'super_admin', ['email' => 'new@example.test', 'sendInvite' => false, 'temporaryPassword' => 'short'])
            ->assertStatus(500)->assertJsonPath('message', 'Invalid input: temporaryPassword must be 12 to 200 characters');
        $created = $this->fn('createUser', 'super_admin', ['email' => 'new@example.test', 'sendInvite' => false, 'temporaryPassword' => 'A long temporary 1!', 'role' => 'security_auditor'])
            ->assertStatus(200)->json();
        $this->assertTrue(Db::isUuid($created['id']));
        $this->fn('setUserEnabled', 'super_admin', ['userId' => $created['id'], 'enabled' => false])->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->fn('setUserEnabled', 'super_admin', ['userId' => self::$users['super_admin'], 'enabled' => false])
            ->assertStatus(500)->assertExactJson(['message' => 'You cannot disable your own account']);
    }

    public function test_an_administrator_updates_a_setting_and_it_is_audited(): void
    {
        $value = ['lower_percent' => 15, 'upper_percent' => 25.5, 'currency' => 'AED', 'rounded' => true];
        $this->fn('saveSetting', 'admin', ['key' => 'calculator_variance', 'value' => $value])->assertStatus(200)->assertExactJson(['ok' => true]);

        $response = $this->fn('getAdminSettings', 'security_auditor')->assertStatus(200);
        $setting = collect($response->json('settings'))->firstWhere('key', 'calculator_variance');
        $this->assertEquals($value, $setting['value']);
        $this->assertSame(self::$users['admin'], $setting['updated_by']);
        // Timestamps and numbers are written the way the original answered them.
        $this->assertMatchesRegularExpression('/"updated_at":"\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?\+00:00"/', $response->getContent());
        $this->assertStringContainsString('"upper_percent":25.5', $response->getContent());
        $this->assertSame(['openai', 'email', 'report_storage', 'scheduler'], array_column($response->json('integrations'), 'key'));
        $this->assertSame([false, false, true, false], array_column($response->json('integrations'), 'configured'));

        $audit = self::audit('settings.update');
        $this->assertSame('admin@example.test', $audit['actor_email']);
        $this->assertSame('calculator_variance', $audit['details']['key']);
        $this->assertSame(20, $audit['details']['previous_value']['lower_percent']);
        $this->assertEquals($value, $audit['details']['new_value']);

        $this->fn('saveSetting', 'security_auditor', ['key' => 'ai_model', 'value' => ['model' => 'x']])->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('saveSetting', 'admin', ['key' => 'other', 'value' => []])->assertStatus(500);
        $this->fn('saveSetting', 'admin', ['key' => 'ai_model', 'value' => ['nested' => ['a' => 1]]])->assertStatus(500);
    }

    public function test_a_user_without_a_role_sees_nothing_and_cannot_write(): void
    {
        $db = new UserScope($this->app->make(Db::class), self::$users['none']);
        $data = $this->app->make(AdminData::class);
        $this->assertFalse(Roles::isUserAdmin($db, self::$users['none']));
        $this->assertSame([], $data->getSettings($db));
        $this->assertSame([], $data->listAuditLog($db));
        $this->assertSame([], $data->listDatasetVersions($db));
        try {
            $data->updateSetting($db, self::$users['none'], null, 'ai_model', (object) ['model' => 'x']);
            $this->fail('Expected row-level security to refuse the write');
        } catch (AppError $error) {
            $this->assertSame('Unable to save setting', $error->getMessage());
        }
    }

    public function test_imports_a_workbook_as_a_draft_with_its_tree(): void
    {
        $upload = ['workbookType' => 'core_lab', 'filename' => '../Core prices (final).xlsx', 'fileBase64' => self::workbook('core-lab-valid.xlsx'), 'notes' => 'first'];
        $this->fn('uploadWorkbook', 'security_auditor', $upload)->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('uploadWorkbook', 'admin', ['fileBase64' => '***'] + $upload)->assertStatus(500)->assertExactJson(['message' => 'Invalid input: fileBase64 must be base64']);

        $result = $this->fn('uploadWorkbook', 'admin', $upload)->assertStatus(200)->json();
        $expected = json_decode(collect(json_decode(file_get_contents(__DIR__.'/../Fixtures/workbook-parser-parity.json'), true)['cases'])
            ->first(fn ($case) => $case['file'] === 'core-lab-valid.xlsx' && $case['type'] === 'core_lab')['json'], true);
        $this->assertSame($expected['summary'], $result['summary']);
        $this->assertSame($expected['issues'], $result['issues']);
        $this->assertSame(0, $result['summary']['criticalErrors']);
        self::$kept['v1'] = $result['versionId'];

        $version = collect($this->fn('listVersions', 'security_auditor')->assertStatus(200)->json())->firstWhere('id', $result['versionId']);
        $this->assertSame('validated', $version['status']);
        $this->assertSame(1, $version['version_number']);
        $this->assertSame('../Core prices (final).xlsx', $version['original_filename']);
        $this->assertSame('first', $version['notes']);
        $this->assertEquals($expected['summary'], $version['validation_summary']);
        $this->assertSame(hash('sha256', base64_decode($upload['fileBase64'])), $version['checksum']);
        // The stored object key is a safe form of the filename, inside the bucket.
        $this->assertMatchesRegularExpression('#^core_lab/v1-\d{13}-__Core_prices_final_\.xlsx$#', $version['storage_path']);
        $this->assertFileExists(self::$store.'/dataset-imports/'.$version['storage_path']);

        $detail = $this->fn('getVersionDetail', 'admin', ['id' => $result['versionId']])->assertStatus(200)->json();
        $this->assertNull($detail['diff']);
        $this->assertSame($result['versionId'], $detail['tree']['version']['id']);
        foreach (['subLabs' => 'subLabs', 'groups' => 'parameterGroups', 'categories' => 'hardwareCategories', 'parameters' => 'parameters', 'hardware' => 'hardware'] as $key => $summaryKey) {
            $this->assertCount($expected['summary'][$summaryKey], $detail['tree'][$key], $key);
        }
        // Every test keeps its price, and its group is the one of its own sub-lab.
        $parsed = array_column($expected['parameters'], null, 'parameterName');
        $groups = array_column($detail['tree']['groups'], null, 'id');
        $this->assertEqualsCanonicalizing(array_keys($parsed), array_column($detail['tree']['parameters'], 'parameter_name'));
        foreach ($detail['tree']['parameters'] as $parameter) {
            $source = $parsed[$parameter['parameter_name']];
            // The price column keeps two decimals.
            $this->assertEqualsWithDelta($source['pricePerTest'], $parameter['price_per_test'], 0.0051);
            $group = $parameter['parameter_group_id'] ? $groups[$parameter['parameter_group_id']] : null;
            $this->assertSame($source['groupNormalized'], $group === null ? null : mb_strtolower($group['group_name']));
            if ($group) {
                $this->assertSame($parameter['sub_lab_id'], $group['sub_lab_id']);
            }
        }

        $this->assertSame(1, self::audit('dataset.upload')['details']['version_number']);
        $this->assertSame($result['versionId'], self::audit('dataset.validated')['details']['version_id']);
        $this->assertSame(1, (int) self::$owner->query('select count(*) from public.uploaded_files')->fetchColumn());
    }

    public function test_a_file_that_is_not_a_workbook_becomes_a_failed_version(): void
    {
        $message = 'The workbook could not be read. It may be corrupted or not a valid .xlsx file.';
        $result = $this->fn('uploadWorkbook', 'admin', ['workbookType' => 'genetics', 'filename' => 'old.xls', 'fileBase64' => base64_encode('not a workbook')])
            ->assertStatus(200)->json();
        $this->assertNull($result['summary']);
        $this->assertSame([['severity' => 'critical', 'sheet' => '(workbook)', 'message' => $message]], $result['issues']);
        $version = collect($this->fn('listVersions', 'admin')->json())->firstWhere('id', $result['versionId']);
        $this->assertSame('validation_failed', $version['status']);
        $this->assertEquals(['warnings' => 0, 'criticalErrors' => 1], $version['validation_summary']);
        $this->assertSame($message, self::audit('dataset.validation_failed')['details']['reason']);

        $this->fn('publishVersionFn', 'admin', ['id' => $result['versionId']])->assertStatus(500)
            ->assertExactJson(['message' => 'This dataset has critical validation errors and cannot be published']);

        $problems = $this->fn('uploadWorkbook', 'admin', ['workbookType' => 'core_lab', 'filename' => 'core.xlsx', 'fileBase64' => self::workbook('core-lab-problems.xlsx')])
            ->assertStatus(200)->json();
        $this->assertGreaterThan(0, $problems['summary']['criticalErrors']);
        $this->assertSame('validation_failed', collect($this->fn('listVersions', 'admin')->json())->firstWhere('id', $problems['versionId'])['status']);
    }

    public function test_publishing_is_refused_for_an_auditor_and_allowed_for_an_administrator(): void
    {
        $id = self::$kept['v1'];
        $this->fn('publishVersionFn', 'security_auditor', ['id' => $id])->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('getPublishedData', 'admin', ['workbookType' => 'core_lab'])->assertStatus(200)->assertExactJson(['tree' => null]);
        $this->fn('publishVersionFn', 'admin', ['id' => '00000000-0000-4000-8000-000000000000'])->assertStatus(500)->assertExactJson(['message' => 'Dataset version not found']);

        $this->fn('publishVersionFn', 'admin', ['id' => $id])->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->assertEquals(['workbook_type' => 'core_lab', 'restored_version' => 1, 'replaced_version' => null], self::audit('dataset.publish')['details']);
        $this->fn('getPublishedData', 'security_auditor', ['workbookType' => 'core_lab'])->assertStatus(200)->assertJsonPath('tree.version.status', 'published');
        // Publishing the published version again is refused by the database function, with its own message.
        $this->fn('publishVersionFn', 'admin', ['id' => $id])->assertStatus(500)->assertExactJson(['message' => 'Only validated or archived versions can be published']);

        $dashboard = $this->fn('getDashboard', 'security_auditor')->assertStatus(200)->json();
        $this->assertSame([$id], array_column($dashboard['published'], 'id'));
        $this->assertNull($dashboard['activePrompt']);
        $this->assertLessThanOrEqual(10, count($dashboard['audit']));
        $this->assertSame('dataset.publish', $dashboard['audit'][0]['event_type']);
    }

    public function test_a_new_draft_is_compared_with_the_published_version(): void
    {
        $second = $this->fn('uploadWorkbook', 'admin', ['workbookType' => 'core_lab', 'filename' => 'core.xlsx', 'fileBase64' => self::workbook('core-lab-valid.xlsx')])
            ->assertStatus(200)->json('versionId');
        $same = $this->fn('getVersionDetail', 'admin', ['id' => $second])->assertStatus(200)->json('diff');
        $this->assertSame([
            'parametersAdded' => [], 'parametersRemoved' => [], 'parameterPriceChanges' => [],
            'hardwareAdded' => [], 'hardwareRemoved' => [], 'hardwarePriceChanges' => [],
            'groupChanges' => [], 'categoryChanges' => [], 'comparedWithVersion' => 1,
        ], $same);

        // Change the draft: one price, one test renamed, one hardware item removed.
        $rows = self::$owner->query("select p.id, p.parameter_name, p.price_per_test, g.group_name from public.parameters p
            left join public.parameter_groups g on g.id = p.parameter_group_id where p.dataset_version_id = '{$second}' and p.price_per_test is not null order by p.display_order limit 2")->fetchAll(PDO::FETCH_ASSOC);
        self::$owner->exec("update public.parameters set price_per_test = price_per_test + 1.5 where id = '{$rows[0]['id']}'");
        self::$owner->exec("update public.parameters set parameter_name = 'Renamed Test' where id = '{$rows[1]['id']}'");
        $hardware = self::$owner->query("select id, hardware_name from public.hardware where dataset_version_id = '{$second}' order by display_order limit 1")->fetch(PDO::FETCH_ASSOC);
        self::$owner->exec("delete from public.hardware where id = '{$hardware['id']}'");

        $diff = $this->fn('getVersionDetail', 'admin', ['id' => $second])->assertStatus(200)->json('diff');
        $key = fn (array $row, ?string $name = null) => ($row['group_name'] ?? '').' / '.($name ?? $row['parameter_name']);
        $this->assertSame([['name' => $key($rows[0]), 'from' => (float) $rows[0]['price_per_test'], 'to' => $rows[0]['price_per_test'] + 1.5]], array_map(
            fn ($change) => ['name' => $change['name'], 'from' => (float) $change['from'], 'to' => (float) $change['to']], $diff['parameterPriceChanges']));
        $this->assertSame([$key($rows[1], 'Renamed Test')], $diff['parametersAdded']);
        $this->assertSame([$key($rows[1])], $diff['parametersRemoved']);
        $this->assertSame([$hardware['hardware_name']], $diff['hardwareRemoved']);
        $this->assertSame([], $diff['hardwareAdded']);

        // Publishing the draft archives version 1; rolling back restores it.
        $this->fn('publishVersionFn', 'admin', ['id' => $second])->assertStatus(200);
        $this->assertSame(1, self::audit('dataset.publish')['details']['replaced_version']);
        $this->assertSame(1, $this->fn('getVersionDetail', 'admin', ['id' => $second])->json('diff.comparedWithVersion'));
        $this->fn('publishVersionFn', 'admin', ['id' => self::$kept['v1'], 'rollback' => true])->assertStatus(200);
        $this->assertEquals(['workbook_type' => 'core_lab', 'restored_version' => 1, 'replaced_version' => 3], self::audit('dataset.rollback')['details']);
    }

    public function test_prompt_versions_are_created_and_activated_by_administrators_only(): void
    {
        $prompt = ['promptName' => 'Assistant', 'promptText' => 'You are helpful.', 'model' => 'gpt-4o-mini'];
        $this->fn('savePromptVersion', 'security_auditor', $prompt)->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $first = $this->fn('savePromptVersion', 'admin', $prompt + ['activate' => true])->assertStatus(200)->json();
        $this->assertSame(1, $first['versionNumber']);
        $second = $this->fn('savePromptVersion', 'admin', $prompt)->assertStatus(200)->json();
        $this->assertSame(2, $second['versionNumber']);

        $list = $this->fn('listPromptVersions', 'security_auditor')->assertStatus(200)->json();
        $this->assertSame([[2, 'draft'], [1, 'active']], array_map(fn ($p) => [$p['version_number'], $p['status']], $list));
        $this->assertSame($first['id'], $this->fn('getDashboard', 'admin')->json('activePrompt.id'));

        $this->fn('activatePromptVersion', 'security_auditor', ['id' => $second['id']])->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('activatePromptVersion', 'admin', ['id' => '00000000-0000-4000-8000-000000000000'])->assertStatus(500)
            ->assertExactJson(['message' => 'This prompt version does not exist. The active prompt was not changed.']);
        $this->assertSame('not_found', self::audit('ai_prompt.activate_failed')['details']['reason']);
        $this->fn('activatePromptVersion', 'admin', ['id' => $first['id']])->assertStatus(200)->assertExactJson(['ok' => true, 'alreadyActive' => true]);
        $this->fn('activatePromptVersion', 'admin', ['id' => $second['id']])->assertStatus(200)->assertExactJson(['ok' => true, 'alreadyActive' => false]);
        $this->assertSame([[2, 'active'], [1, 'archived']], array_map(fn ($p) => [$p['version_number'], $p['status']], $this->fn('listPromptVersions', 'admin')->json()));

        // The database function refuses an auditor even when called directly in their context.
        $db = new UserScope($this->app->make(Db::class), self::$users['security_auditor']);
        try {
            $this->app->make(AdminData::class)->activatePrompt($db, self::$users['security_auditor'], null, $first['id']);
            $this->fail('Expected the database to refuse');
        } catch (AppError $error) {
            $this->assertSame('Forbidden: administrator role required', $error->getMessage());
        }
    }

    public function test_sales_report_recipients(): void
    {
        $this->fn('addSalesRecipient', 'security_auditor', ['email' => 'sales@example.test'])->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('addSalesRecipient', 'admin', ['email' => '  Sales@Example.test '])->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->fn('addSalesRecipient', 'admin', ['email' => 'sales@example.test'])->assertStatus(500)->assertExactJson(['message' => 'This email is already a recipient']);
        $this->fn('addSalesRecipient', 'admin', ['email' => 'nope'])->assertStatus(500);

        $list = $this->fn('listSalesRecipients', 'security_auditor')->assertStatus(200)->json();
        $this->assertSame(['sales@example.test'], array_column($list, 'email'));
        $this->assertSame(['id', 'email', 'active', 'created_at'], array_keys($list[0]));
        $this->assertTrue($list[0]['active']);

        $this->fn('removeSalesRecipient', 'security_auditor', ['id' => $list[0]['id']])->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('removeSalesRecipient', 'admin', ['id' => $list[0]['id']])->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->fn('removeSalesRecipient', 'admin', ['id' => $list[0]['id']])->assertStatus(500)->assertExactJson(['message' => 'Unable to remove recipient']);
        $this->assertSame(['email' => 'sales@example.test'], self::audit('sales_recipient_removed')['details']);
        $this->assertSame([], $this->fn('listSalesRecipients', 'admin')->json());
    }

    public function test_submissions_are_listed_read_and_resent_to_make(): void
    {
        $this->fn('listCalculatorSubmissions', 'admin')->assertStatus(200)->assertExactJson([]);
        $missing = ['id' => '00000000-0000-4000-8000-000000000000'];
        $this->fn('getCalculatorSubmission', 'admin', $missing)->assertStatus(200)->assertContent('null');
        $this->fn('getSubmissionReportUrl', 'admin', $missing)->assertStatus(500)->assertExactJson(['message' => 'Report is not available']);
        $this->fn('retryMakeDelivery', 'admin', $missing)->assertStatus(500)->assertExactJson(['message' => 'Submission not found']);

        $payload = '{"referenceId":"OH-2026-ABCD1234","estimate":{"internalTotal":1234.5,"publicLow":1000},"labs":[]}';
        $statement = self::$owner->prepare("insert into public.calculator_submissions
            (reference_id, calculator_session_id, idempotency_key, full_name, company, phone, email, calculation_version, internal_total, public_low, public_high, make_payload_snapshot)
            values ('OH-2026-ABCD1234', ?, 'k1', 'Lead', 'Lab', '+971', 'lead@example.test', 'v1', 1234.5, 1000, 1500.25, ?::jsonb) returning id");
        $session = self::$owner->query("insert into public.calculator_sessions (full_name, company, phone, email) values ('Lead', 'Lab', '+971', 'lead@example.test') returning id")->fetchColumn();
        $statement->execute([$session, $payload]);
        $id = $statement->fetchColumn();

        $response = $this->fn('listCalculatorSubmissions', 'security_auditor')->assertStatus(200);
        $this->assertSame([[
            'id' => $id, 'reference_id' => 'OH-2026-ABCD1234', 'full_name' => 'Lead', 'company' => 'Lab', 'email' => 'lead@example.test', 'phone' => '+971',
            'selected_mode' => 'manual', 'currency' => 'AED', 'public_low' => 1000, 'public_high' => 1500.25,
            'make_delivery_status' => 'pending', 'report_status' => $response->json('0.report_status'), 'email_status' => $response->json('0.email_status'),
        ]], array_map(fn ($row) => array_diff_key($row, ['created_at' => 1]), $response->json()));
        $this->assertStringContainsString('"public_low":1000,"public_high":1500.25,', $response->getContent());

        $detail = $this->fn('getCalculatorSubmission', 'security_auditor', ['id' => $id])->assertStatus(200);
        $this->assertSame(1234.5, $detail->json('internal_total'));
        $this->assertEquals(json_decode($payload, true), $detail->json('make_payload_snapshot'));
        $this->assertStringContainsString('"configuration_snapshot":{}', $detail->getContent());

        $this->fn('retryMakeDelivery', 'security_auditor', ['id' => $id])->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        Http::preventStrayRequests();
        Http::fake(['hook.example.test/*' => Http::sequence()->push('{"success":true,"executionId":"e1","secret":"x"}', 200)->push('nope', 503)]);
        $this->fn('retryMakeDelivery', 'admin', ['id' => $id])->assertStatus(200)->assertExactJson(['status' => 'sent', 'error' => null]);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://hook.example.test/abc'
            && $request->header('x-make-apikey') === ['make-secret']
            && json_decode($request->body(), true) == json_decode($payload, true));
        $row = self::$owner->query("select make_delivery_status, make_http_status, make_attempt_count, make_response_summary, make_delivery_completed_at from public.calculator_submissions where id = '{$id}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(['sent', 200, 1], [$row['make_delivery_status'], $row['make_http_status'], $row['make_attempt_count']]);
        $this->assertEquals(['success' => true, 'executionId' => 'e1'], json_decode($row['make_response_summary'], true));
        $this->assertNotNull($row['make_delivery_completed_at']);

        $this->fn('retryMakeDelivery', 'admin', ['id' => $id])->assertStatus(200)->assertExactJson(['status' => 'failed', 'error' => 'Webhook responded with status 503']);
        $this->assertEquals(['referenceId' => 'OH-2026-ABCD1234', 'trigger' => 'manual_retry', 'httpStatus' => 503, 'error' => 'Webhook responded with status 503'], self::audit('make_delivery_failed')['details']);
        $this->assertSame(2, (int) self::$owner->query("select make_attempt_count from public.calculator_submissions where id = '{$id}'")->fetchColumn());

        // A report link is only given for a generated report, and expires.
        self::$owner->exec("update public.calculator_submissions set report_status = 'generated', report_storage_path = 'OH/x.pdf' where id = '{$id}'");
        $url = $this->fn('getSubmissionReportUrl', 'security_auditor', ['id' => $id, 'download' => true])->assertStatus(200)->json('url');
        $this->assertMatchesRegularExpression("#^/api/files/report/{$id}\\?exp=\\d+&dl=1&sig=[A-Za-z0-9_-]{43}$#", $url);
    }

    public function test_ai_limits_overview_and_update(): void
    {
        $overview = $this->fn('getAiQuotaOverview', 'security_auditor')->assertStatus(200);
        $settings = $overview->json('settings');
        $this->assertArrayNotHasKey('updated_by', $settings);
        $this->assertSame(['requests' => 0, 'reserved_tokens' => 0, 'actual_tokens' => 0, 'rejected' => 0, 'tokenPct' => 0, 'requestPct' => 0, 'alert' => 'ok'], $overview->json('usage'));
        $this->assertStringContainsString('"rejectedLast24h":{},"recentEvents":[]', $overview->getContent());

        $update = array_intersect_key($settings, ['enabled' => 1] + AiQuotaAdmin::LIMITS);
        $update['visitor_per_minute'] = 7;
        $this->fn('updateAiQuotaSettings', 'security_auditor', $update)->assertStatus(500)->assertExactJson(self::FORBIDDEN);
        $this->fn('updateAiQuotaSettings', 'admin', ['visitor_per_minute' => 0] + $update)->assertStatus(500);
        $this->fn('updateAiQuotaSettings', 'admin', $update + ['id' => false])->assertStatus(500)->assertExactJson(['message' => 'Invalid input: id is not a setting']);
        $this->fn('updateAiQuotaSettings', 'admin', array_diff_key($update, ['max_turns' => 1]))->assertStatus(500);
        $this->fn('updateAiQuotaSettings', 'admin', $update)->assertStatus(200)->assertExactJson(['ok' => true]);
        $this->assertSame(7, $this->fn('getAiQuotaOverview', 'admin')->json('settings.visitor_per_minute'));
        $this->assertSame(7, self::audit('ai_quota_settings_updated')['details']['visitor_per_minute']);

        // Usage and refusals of the day are summarised.
        self::$owner->exec("insert into public.ai_global_usage (day, requests, reserved_tokens) values ((now() at time zone 'utc')::date, 5, ".(int) ($settings['global_tokens_per_day'] * 0.9).')');
        $usage = $this->fn('getAiQuotaOverview', 'admin')->assertStatus(200)->json('usage');
        $this->assertSame(90, $usage['tokenPct']);
        $this->assertContains($usage['alert'], ['warning', 'critical']);
    }

    public function test_the_audit_log_is_readable_by_every_portal_role(): void
    {
        $log = $this->fn('getAuditLog', 'security_auditor')->assertStatus(200)->json();
        $this->assertGreaterThan(10, count($log));
        $this->assertSame(['id', 'event_type', 'actor_id', 'actor_email', 'details', 'created_at'], array_keys($log[0]));
    }
}
