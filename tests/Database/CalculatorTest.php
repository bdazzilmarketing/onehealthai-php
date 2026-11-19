<?php

namespace Tests\Database;

use App\Calculator\CalculationError;
use App\Calculator\CalculatorService;
use App\Calculator\PricingUnavailableError;
use App\Leads\LeadSessions;
use App\Reports\SalesReportStarter;
use App\Submissions\Submissions;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\Support\TestDatabase;
use Tests\TestCase;

/**
 * The public calculator on a real PostgreSQL with the original schema: the
 * catalog without prices, calculation from published prices, lead sessions,
 * idempotent submissions and the two HTTP endpoints. Ports the calculator and
 * lead/submission parts of src/lib/data-access.server.test.ts.
 */
final class CalculatorTest extends TestCase
{
    private const LEAD = ['fullName' => 'Test Lead', 'company' => 'Lab', 'phone' => '+971 50 000 0000', 'email' => 'lead@example.test'];

    private static TestDatabase $database;

    private static PDO $owner;

    /** @var array<string, string> name to id */
    private static array $ids = [];

    /** @var list<string> */
    public static array $started = [];

    public static function setUpBeforeClass(): void
    {
        self::$database = new TestDatabase(55445, 'calc');
        self::$database->install();
        self::$owner = self::$database->connect(self::$database->migratorUrl);
        self::$owner->exec('SET ROLE onehealth_owner');
        $insert = function (string $name, string $table, array $row) {
            $columns = implode(', ', array_keys($row));
            $marks = implode(', ', array_fill(0, count($row), '?'));
            $statement = self::$owner->prepare("insert into public.{$table} ({$columns}) values ({$marks}) returning id");
            $statement->execute(array_values($row));

            return self::$ids[$name] = $statement->fetchColumn();
        };

        // Core Lab: two sub-labs, a shared group, hardware with a category, an inactive test.
        $v = $insert('coreVersion', 'dataset_versions', ['workbook_type' => 'core_lab', 'version_number' => 3, 'status' => 'published', 'original_filename' => 'core.xlsx']);
        $lab = $insert('core', 'laboratories', ['dataset_version_id' => $v, 'code' => 'core_lab', 'name' => 'Core Lab']);
        $base = ['dataset_version_id' => $v, 'laboratory_id' => $lab];
        $chem = $insert('chem', 'sub_labs', $base + ['code' => 'chem', 'name' => 'Chemistry', 'display_order' => 1]);
        $histo = $insert('histo', 'sub_labs', $base + ['code' => 'histo', 'name' => 'Histopathology', 'display_order' => 2]);
        $insert('empty', 'sub_labs', $base + ['code' => 'empty', 'name' => 'Empty', 'display_order' => 3]);
        $group = $insert('group', 'parameter_groups', $base + ['sub_lab_id' => $chem, 'group_name' => 'Electrolytes', 'normalized_group_name' => 'electrolytes']);
        $insert('emptyGroup', 'parameter_groups', $base + ['sub_lab_id' => $chem, 'group_name' => 'Unused', 'normalized_group_name' => 'unused', 'display_order' => 2]);
        $category = $insert('category', 'hardware_categories', $base + ['sub_lab_id' => $chem, 'category_name' => 'Analysers', 'normalized_category_name' => 'analysers']);
        $insert('sodium', 'parameters', $base + ['sub_lab_id' => $chem, 'parameter_group_id' => $group, 'parameter_name' => 'Sodium', 'price_per_test' => '2.675', 'display_order' => 1]);
        $insert('potassium', 'parameters', $base + ['sub_lab_id' => $chem, 'parameter_group_id' => $group, 'parameter_name' => 'Potassium', 'price_per_test' => '3.33', 'display_order' => 2]);
        $insert('glucose', 'parameters', $base + ['sub_lab_id' => $chem, 'parameter_name' => 'Glucose', 'price_per_test' => '10', 'optional_notes' => 'Fasting', 'display_order' => 3]);
        $insert('unpriced', 'parameters', $base + ['sub_lab_id' => $chem, 'parameter_name' => 'No Cost Yet', 'display_order' => 4]);
        $insert('retired', 'parameters', $base + ['sub_lab_id' => $chem, 'parameter_name' => 'Retired', 'price_per_test' => '1', 'active' => 'f', 'display_order' => 5]);
        $insert('analyser', 'hardware', $base + ['sub_lab_id' => $chem, 'hardware_category_id' => $category, 'hardware_name' => 'Analyser X', 'price' => '5000.50']);
        $insert('microtome', 'hardware', $base + ['sub_lab_id' => $histo, 'hardware_name' => 'Microtome', 'price' => '12000']);

        // POCT: a flat laboratory (no sub-labs).
        $v = $insert('poctVersion', 'dataset_versions', ['workbook_type' => 'poct', 'version_number' => 1, 'status' => 'published', 'original_filename' => 'poct.xlsx']);
        $lab = $insert('poct', 'laboratories', ['dataset_version_id' => $v, 'code' => 'poct', 'name' => 'Point of Care Testing']);
        $insert('malaria', 'parameters', ['dataset_version_id' => $v, 'laboratory_id' => $lab, 'parameter_name' => 'Malaria Ag', 'price_per_test' => '20', 'display_order' => 1]);
        $insert('dengue', 'parameters', ['dataset_version_id' => $v, 'laboratory_id' => $lab, 'parameter_name' => 'Dengue NS1', 'price_per_test' => '30', 'display_order' => 2]);

        // A validated draft must never be served.
        $v = $insert('draftVersion', 'dataset_versions', ['workbook_type' => 'genetics', 'version_number' => 1, 'status' => 'validated', 'original_filename' => 'genetics.xlsx']);
        $lab = $insert('genetics', 'laboratories', ['dataset_version_id' => $v, 'code' => 'genetics', 'name' => 'Genetics Lab']);
        $insert('draftTest', 'parameters', ['dataset_version_id' => $v, 'laboratory_id' => $lab, 'parameter_name' => 'Draft Test', 'price_per_test' => '99']);
    }

    public static function tearDownAfterClass(): void
    {
        self::$database->stop();
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.pgsql.url' => self::$database->appUrl, 'database.connections.pgsql.sslmode' => 'disable']);
        $this->app->instance(SalesReportStarter::class, new class implements SalesReportStarter
        {
            public function start(string $submissionId): void
            {
                CalculatorTest::$started[] = $submissionId;
            }
        });
    }

    private static function section(string $subLab, int|float $volume, array $parameters, array $hardware = []): array
    {
        return [
            'subLabId' => self::$ids[$subLab] ?? null,
            'annualTestVolume' => $volume,
            'includeHardware' => $hardware !== [],
            'selectedHardwareIds' => array_map(fn ($name) => self::$ids[$name], $hardware),
            'selectedParameterIds' => array_map(fn ($name) => self::$ids[$name], $parameters),
        ];
    }

    private static function coreSelection(int|float $volume = 1000): array
    {
        return ['labs' => [[
            'labId' => self::$ids['core'],
            'labType' => 'core_lab',
            'sections' => [self::section('chem', $volume, ['sodium', 'potassium', 'glucose'], ['analyser'])],
        ]]];
    }

    private function send(string $uri, array $body, array $cookies = []): TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($cookies) {
            $server['HTTP_COOKIE'] = implode('; ', array_map(fn ($k, $v) => "{$k}={$v}", array_keys($cookies), $cookies));
        }

        return $this->call('POST', $uri, [], [], [], $server, json_encode($body));
    }

    private static function submissions(): array
    {
        return self::$owner->query('select * from public.calculator_submissions order by created_at')->fetchAll(PDO::FETCH_ASSOC);
    }

    public function test_serves_a_public_catalog_without_prices(): void
    {
        $catalog = $this->app->make(CalculatorService::class)->getPublicCatalog();
        $this->assertSame('AED', $catalog['currency']);
        // Published labs only, in the fixed laboratory order.
        $this->assertSame(['core_lab', 'poct'], array_column($catalog['labs'], 'labType'));
        $json = json_encode($catalog);
        $this->assertDoesNotMatchRegularExpression('/price|2\.675|5000|Draft Test|Retired/i', $json);

        [$core, $poct] = $catalog['labs'];
        $this->assertSame(3, $core['datasetVersionNumber']);
        $this->assertFalse($core['flat']);
        // The sub-lab without tests or hardware is left out.
        $this->assertSame(['Chemistry', 'Histopathology'], array_column($core['sections'], 'name'));
        [$chem, $histo] = $core['sections'];
        $this->assertSame(self::$ids['chem'], $chem['key']);
        $this->assertFalse($chem['hardwareOnly']);
        $this->assertTrue($chem['supportsHardware']);
        // A group without tests is left out.
        $this->assertSame([['id' => self::$ids['group'], 'name' => 'Electrolytes', 'parameterIds' => [self::$ids['sodium'], self::$ids['potassium']]]], $chem['groups']);
        $this->assertSame([self::$ids['glucose'], self::$ids['unpriced']], $chem['ungroupedParameterIds']);
        $this->assertSame(['id' => self::$ids['glucose'], 'name' => 'Glucose', 'groupId' => null, 'notes' => 'Fasting'], $chem['parameters'][2]);
        $this->assertSame([['id' => self::$ids['analyser'], 'name' => 'Analyser X', 'groupId' => null, 'categoryId' => self::$ids['category']]], $chem['hardware']);
        $this->assertSame([['id' => self::$ids['category'], 'name' => 'Analysers']], $chem['categories']);
        $this->assertTrue($histo['hardwareOnly']);

        $this->assertTrue($poct['flat']);
        $this->assertSame('lab:'.self::$ids['poct'], $poct['sections'][0]['key']);
        $this->assertNull($poct['sections'][0]['subLabId']);
        $this->assertSame('Point of Care Testing', $poct['sections'][0]['name']);
    }

    public function test_calculates_from_published_prices_and_applies_the_variance(): void
    {
        $selection = self::coreSelection();
        $selection['labs'][0]['sections'][] = self::section('histo', 0, [], ['microtome']);
        $selection['labs'][] = ['labId' => self::$ids['poct'], 'labType' => 'poct', 'sections' => [self::section('none', 500, ['malaria', 'dengue'])]];
        $outcome = $this->app->make(CalculatorService::class)->runCalculation($selection);
        $internal = $outcome['internal'];

        // 1000 / 3 tests each at 2.68, 3.33 and 10 (numeric(14,2) stores 2.675 as 2.68).
        $this->assertSame(893.33, $internal['labs'][0]['subLabs'][0]['parameters'][0]['annualCalculatedCost']);
        $this->assertSame(5336.66, $internal['labs'][0]['subLabs'][0]['parameterSubtotal']);
        $this->assertSame(5000.5, $internal['labs'][0]['subLabs'][0]['hardwareSubtotal']);
        $this->assertSame(12000.0, $internal['labs'][0]['subLabs'][1]['subtotal']);
        $this->assertSame(12500.0, $internal['labs'][1]['subtotal']);
        $this->assertSame(34837.16, $internal['internalTotal']);
        $this->assertSame(['core_lab' => 3, 'poct' => 1], $internal['datasetVersions']);

        $public = $outcome['publicEstimate'];
        // Default variance: 20% either side, rounded to whole units.
        $this->assertSame(27870.0, $public['lowerRange']);
        $this->assertSame(41805.0, $public['upperRange']);
        $this->assertSame($internal['calculationId'], $public['calculationId']);
        $this->assertSame(['name' => 'Chemistry', 'annualTestVolume' => 1000.0, 'parameterCount' => 3, 'hardwareCount' => 1], $public['summary'][0]['sections'][0]);
        $this->assertDoesNotMatchRegularExpression('/internalTotal|pricePerTest|subtotal|34837/i', json_encode($public));

        self::$owner->exec("update public.settings set value = '{\"lower_percent\": 10, \"upper_percent\": 12.5, \"currency\": \"USD\"}' where key = 'calculator_variance'");
        $public = $this->app->make(CalculatorService::class)->runCalculation($selection)['publicEstimate'];
        $this->assertSame([31353.0, 39192.0, 'USD'], [$public['lowerRange'], $public['upperRange'], $public['currency']]);
        self::$owner->exec("update public.settings set value = '{\"lower_percent\": 20, \"upper_percent\": 20, \"currency\": \"AED\"}' where key = 'calculator_variance'");
    }

    public function test_refuses_unpublished_data_and_never_substitutes_a_missing_price(): void
    {
        $calculator = $this->app->make(CalculatorService::class);
        try {
            $calculator->runCalculation(['labs' => [['labId' => self::$ids['genetics'], 'labType' => 'genetics', 'sections' => [self::section('none', 10, ['draftTest'])]]]]);
            $this->fail('a draft dataset was priced');
        } catch (CalculationError $error) {
            $this->assertStringContainsString('published laboratory data has changed', $error->getMessage());
        }

        $selection = ['labs' => [['labId' => self::$ids['core'], 'labType' => 'core_lab', 'sections' => [self::section('chem', 100, ['glucose', 'unpriced'])]]]];
        try {
            $calculator->runCalculation($selection);
            $this->fail('a missing price was accepted');
        } catch (PricingUnavailableError $error) {
            $this->assertSame([['parameter', self::$ids['unpriced'], 'null']], array_map(fn ($i) => [$i['kind'], $i['itemId'], $i['reason']], $error->issues));
        }
        // An inactive test is not part of the published data.
        $this->expectExceptionMessage('A selected test is no longer valid');
        $calculator->runCalculation(['labs' => [['labId' => self::$ids['core'], 'labType' => 'core_lab', 'sections' => [self::section('chem', 100, ['retired'])]]]]);
    }

    public function test_reuses_the_cookie_session_and_stores_an_idempotent_submission(): void
    {
        $leads = $this->app->make(LeadSessions::class);
        $session = $leads->upsert(self::LEAD, 'manual', null);
        $again = $leads->upsert(['fullName' => 'Test Lead II'] + self::LEAD, 'ai', $session['sessionId']);
        $this->assertSame($session['sessionId'], $again['sessionId']);
        $this->assertSame(['Test Lead II', 'ai'], [$again['fullName'], $again['selectedMode']]);
        $this->assertSame('+971500000000', self::$owner->query("select phone_normalized from public.calculator_sessions where id = '{$session['sessionId']}'")->fetchColumn());

        // A malformed cookie, an unknown id or another email starts a new session.
        $fresh = $leads->upsert(self::LEAD, 'manual', 'not-a-uuid');
        $this->assertNotSame($session['sessionId'], $fresh['sessionId']);
        $this->assertNotSame($session['sessionId'], $leads->upsert(self::LEAD, 'manual', '00000000-0000-4000-8000-000000000000')['sessionId']);
        $this->assertNotSame($session['sessionId'], $leads->upsert(['email' => 'other@example.test'] + self::LEAD, 'manual', $session['sessionId'])['sessionId']);

        $selection = self::coreSelection(500);
        $outcome = $this->app->make(CalculatorService::class)->runCalculation($selection);
        $submissions = $this->app->make(Submissions::class);
        $stored = $submissions->record($fresh, $selection, $outcome['internal'], $outcome['publicEstimate']);
        $this->assertTrue($stored['isNew']);
        $this->assertMatchesRegularExpression('/^OH-\d{4}-[0-9A-F]{8}$/', $stored['referenceId']);
        $duplicate = $submissions->record($fresh, $selection, $outcome['internal'], $outcome['publicEstimate']);
        $this->assertEquals(['isNew' => false] + $stored, $duplicate);
        // The same run in another session is a different submission.
        $this->assertTrue($submissions->record($session, $selection, $outcome['internal'], $outcome['publicEstimate'])['isNew']);

        $row = self::$owner->query("select * from public.calculator_submissions where id = '{$stored['submissionId']}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals($selection, json_decode($row['configuration_snapshot'], true));
        $this->assertEquals($outcome['internal'], json_decode($row['internal_calculation_snapshot'], true));
        $this->assertSame((float) $row['internal_total'], $outcome['internal']['internalTotal']);
        $this->assertSame(['disabled', 'pending', 'pending'], [$row['make_delivery_status'], $row['report_status'], $row['email_status']]);
        $payload = json_decode($row['make_payload_snapshot'], true);
        $this->assertSame($stored['referenceId'], $payload['referenceId']);
        $this->assertEquals(self::LEAD, $payload['lead']);
        $this->assertSame($outcome['internal']['internalTotal'], (float) $payload['estimate']['internalTotal']);

        $audit = self::$owner->query("select details from public.audit_log where event_type = 'submission_created' order by created_at limit 1")->fetchColumn();
        $this->assertSame($stored['submissionId'], json_decode($audit, true)['submissionId']);
    }

    public function test_catalog_endpoint_answers_json_without_prices(): void
    {
        $response = $this->get('/api/fn/getCalculatorCatalog')->assertStatus(200);
        $this->assertSame('application/json', $response->headers->get('content-type'));
        $this->assertStringContainsString('no-store', $response->headers->get('cache-control'));
        $this->assertCount(2, $response->json('labs'));
        $this->assertDoesNotMatchRegularExpression('/price/i', $response->getContent());
    }

    public function test_estimate_endpoint_validates_the_lead_before_anything_is_stored(): void
    {
        $before = count(self::submissions());
        $sessions = self::$owner->query('select count(*) from public.calculator_sessions')->fetchColumn();
        $response = $this->send('/api/fn/calculateEstimate', ['selection' => self::coreSelection(), 'lead' => ['fullName' => 'A', 'email' => 'nope'], 'mode' => 'manual']);
        $response->assertStatus(200)->assertExactJson(['ok' => false, 'message' => null, 'errors' => [
            'fullName' => 'Please enter your full name.',
            'company' => 'Please enter your company name.',
            'email' => 'Please enter a valid email address.',
            'phone' => 'Please enter your phone number.',
        ]]);
        $this->assertEmpty($response->headers->getCookies());
        $this->assertCount($before, self::submissions());
        $this->assertSame($sessions, self::$owner->query('select count(*) from public.calculator_sessions')->fetchColumn());

        // A malformed selection is refused outright; a calculation problem is a message.
        $this->send('/api/fn/calculateEstimate', ['selection' => ['labs' => []], 'lead' => self::LEAD])->assertStatus(500)->assertJsonPath('message', 'Invalid input: selection.labs must be a list with at least one item');
        $unpriced = ['labs' => [['labId' => self::$ids['core'], 'labType' => 'core_lab', 'sections' => [self::section('chem', 100, ['unpriced'])]]]];
        $this->send('/api/fn/calculateEstimate', ['selection' => $unpriced, 'lead' => self::LEAD])
            ->assertStatus(200)->assertExactJson(['ok' => false, 'errors' => null, 'message' => PricingUnavailableError::MESSAGE]);
        $this->assertCount($before, self::submissions());
    }

    public function test_estimate_endpoint_returns_only_the_public_range_and_stores_the_submission_once(): void
    {
        self::$started = [];
        $before = count(self::submissions());
        // Anything price-shaped a client adds is ignored.
        $selection = self::coreSelection(250);
        $selection['labs'][0]['sections'][0]['pricePerTest'] = 0.01;
        $body = ['selection' => $selection, 'lead' => ['fullName' => '  Http   Lead ', 'email' => 'HTTP@Example.Test'] + self::LEAD, 'mode' => 'ai', 'internalTotal' => 1];

        $response = $this->send('/api/fn/calculateEstimate', $body)->assertStatus(200);
        $data = $response->json();
        $this->assertSame(['ok', 'estimate', 'referenceId'], array_keys($data));
        $this->assertTrue($data['ok']);
        $this->assertSame(['calculationId', 'calculationVersion', 'calculatedAt', 'currency', 'lowerRange', 'upperRange', 'datasetVersions', 'summary'], array_keys($data['estimate']));
        $this->assertSame('equal_distribution_v1', $data['estimate']['calculationVersion']);
        // 250 / 3 tests at 2.68, 3.33 and 10 = 1334.16, plus 5000.50 hardware = 6334.66; 20% either side.
        $this->assertSame([5068, 7602], [$data['estimate']['lowerRange'], $data['estimate']['upperRange']]);
        $this->assertDoesNotMatchRegularExpression('/6334|1334|5000\.5|price|subtotal/i', $response->getContent());

        $cookie = $response->headers->getCookies()[0];
        $this->assertSame([LeadSessions::COOKIE_NAME, '/', true, true, 'lax', 28800], [$cookie->getName(), $cookie->getPath(), $cookie->isHttpOnly(), $cookie->isSecure(), $cookie->getSameSite(), $cookie->getMaxAge()]);

        $rows = self::submissions();
        $this->assertCount($before + 1, $rows);
        $row = end($rows);
        $this->assertSame([$data['referenceId'], $cookie->getValue(), 'Http Lead', 'http@example.test', 'ai', '6334.66', '5068', '7602'],
            [$row['reference_id'], $row['calculator_session_id'], $row['full_name'], $row['email'], $row['selected_mode'], $row['internal_total'], $row['public_low'], $row['public_high']]);
        $this->assertStringNotContainsString('pricePerTest', $row['configuration_snapshot']);
        // The report is started after the response has been sent.
        $this->assertSame([$row['id']], self::$started);

        // A double-click or retry with the session cookie resolves to the same submission.
        $again = $this->send('/api/fn/calculateEstimate', $body, [LeadSessions::COOKIE_NAME => $cookie->getValue()])->assertStatus(200);
        $this->assertSame($data['referenceId'], $again->json('referenceId'));
        $this->assertCount($before + 1, self::submissions());
        // No second report is started for it.
        $this->assertSame([$row['id']], array_values(array_unique(self::$started)));
        $this->assertLessThanOrEqual(2, count(self::$started));
    }
}
