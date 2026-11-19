<?php

namespace Tests\Unit;

use App\Calculator\CalculationEngine;
use App\Calculator\CalculationError;
use App\Calculator\PricingUnavailableError;
use App\Calculator\Selection;
use App\Support\InvalidInput;
use PHPUnit\Framework\TestCase;

/** Port of src/lib/calculation-engine.test.ts, plus the selection schema. */
final class CalculationEngineTest extends TestCase
{
    private const LAB_ID = '11111111-1111-4111-8111-111111111111';

    private const SUB_LAB_ID = '22222222-2222-4222-8222-222222222222';

    private const PARAM_A = '33333333-3333-4333-8333-333333333333';

    private const PARAM_B = '44444444-4444-4444-8444-444444444444';

    private const HARDWARE_A = '55555555-5555-4555-8555-555555555555';

    /** @param  array<string, mixed>  $overrides  paramAPrice, paramBPrice, hardwarePrice, omitParamAPrice */
    private static function dataset(array $overrides = []): array
    {
        $parameterA = ['id' => self::PARAM_A, 'name' => 'Glucose', 'labId' => self::LAB_ID, 'subLabId' => self::SUB_LAB_ID];
        if (empty($overrides['omitParamAPrice'])) {
            $parameterA['pricePerTest'] = array_key_exists('paramAPrice', $overrides) ? $overrides['paramAPrice'] : 10;
        }

        return [
            'currency' => 'AED',
            'labs' => [
                self::LAB_ID => [
                    'labId' => self::LAB_ID,
                    'labType' => 'core_lab',
                    'labName' => 'Core Lab',
                    'datasetVersionId' => 'version-1',
                    'datasetVersionNumber' => 2,
                    'subLabNames' => [self::SUB_LAB_ID => 'Chemistry'],
                    'parameters' => [
                        self::PARAM_A => $parameterA,
                        self::PARAM_B => [
                            'id' => self::PARAM_B, 'name' => 'Sodium', 'labId' => self::LAB_ID, 'subLabId' => self::SUB_LAB_ID,
                            'pricePerTest' => array_key_exists('paramBPrice', $overrides) ? $overrides['paramBPrice'] : 20,
                        ],
                    ],
                    'hardware' => [
                        self::HARDWARE_A => [
                            'id' => self::HARDWARE_A, 'name' => 'Analyser X', 'labId' => self::LAB_ID, 'subLabId' => self::SUB_LAB_ID,
                            'price' => array_key_exists('hardwarePrice', $overrides) ? $overrides['hardwarePrice'] : 5000,
                        ],
                    ],
                ],
            ],
        ];
    }

    private static function selection(bool $includeHardware = false, array $section = []): array
    {
        return ['labs' => [[
            'labId' => self::LAB_ID,
            'labType' => 'core_lab',
            'sections' => [$section + [
                'subLabId' => self::SUB_LAB_ID,
                'annualTestVolume' => 1000,
                'includeHardware' => $includeHardware,
                'selectedHardwareIds' => $includeHardware ? [self::HARDWARE_A] : [],
                'selectedParameterIds' => [self::PARAM_A, self::PARAM_B],
            ]],
        ]]];
    }

    private static function failure(array $selection, array $dataset): PricingUnavailableError
    {
        try {
            CalculationEngine::calculateLabEstimate($selection, $dataset);
        } catch (PricingUnavailableError $error) {
            return $error;
        }
        self::fail('expected failure');
    }

    public function test_calculates_normally_when_every_price_is_valid(): void
    {
        $result = CalculationEngine::calculateLabEstimate(self::selection(true), self::dataset());
        // 500 tests * 10 + 500 * 20 = 15000, plus 5000 hardware
        $this->assertEquals(15000, $result['totalParameterCost']);
        $this->assertEquals(5000, $result['totalHardwareCost']);
        $this->assertEquals(20000, $result['internalTotal']);
    }

    public function test_fails_when_a_parameter_price_is_null(): void
    {
        $this->assertSame('null', self::failure(self::selection(), self::dataset(['paramAPrice' => null]))->issues[0]['reason']);
    }

    public function test_fails_when_a_parameter_price_is_missing_entirely(): void
    {
        $issue = self::failure(self::selection(), self::dataset(['omitParamAPrice' => true]))->issues[0];
        $this->assertSame(
            ['kind' => 'parameter', 'itemId' => self::PARAM_A, 'itemName' => 'Glucose', 'datasetVersionId' => 'version-1', 'datasetVersionNumber' => 2, 'reason' => 'missing', 'rawValue' => 'undefined'],
            array_intersect_key($issue, array_flip(['kind', 'itemId', 'itemName', 'datasetVersionId', 'datasetVersionNumber', 'reason', 'rawValue'])),
        );
    }

    public function test_fails_when_a_parameter_price_is_non_numeric_nan_or_negative(): void
    {
        $this->assertSame('non_numeric', self::failure(self::selection(), self::dataset(['paramAPrice' => 'abc']))->issues[0]['reason']);
        $this->assertSame('non_numeric', self::failure(self::selection(), self::dataset(['paramAPrice' => NAN]))->issues[0]['reason']);
        $this->assertSame('non_numeric', self::failure(self::selection(), self::dataset(['paramAPrice' => INF]))->issues[0]['reason']);
        $this->assertSame('negative', self::failure(self::selection(), self::dataset(['paramAPrice' => -1]))->issues[0]['reason']);
    }

    public function test_fails_when_selected_hardware_has_a_null_price(): void
    {
        $issue = self::failure(self::selection(true), self::dataset(['hardwarePrice' => null]))->issues[0];
        $this->assertSame(['hardware', self::HARDWARE_A, 'null'], [$issue['kind'], $issue['itemId'], $issue['reason']]);
    }

    public function test_ignores_an_invalid_hardware_price_when_that_hardware_is_not_selected(): void
    {
        $result = CalculationEngine::calculateLabEstimate(self::selection(false), self::dataset(['hardwarePrice' => null]));
        $this->assertEquals(15000, $result['internalTotal']);
        // Listed but not included: still ignored.
        $result = CalculationEngine::calculateLabEstimate(self::selection(false, ['selectedHardwareIds' => [self::HARDWARE_A]]), self::dataset(['hardwarePrice' => null]));
        $this->assertEquals(15000, $result['internalTotal']);
    }

    public function test_accepts_an_intentional_published_price_of_zero(): void
    {
        // 500 * 0 + 500 * 20
        $this->assertEquals(10000, CalculationEngine::calculateLabEstimate(self::selection(), self::dataset(['paramAPrice' => 0]))['internalTotal']);
    }

    public function test_returns_no_partial_estimate_and_a_safe_public_message_on_failure(): void
    {
        $error = self::failure(self::selection(true), self::dataset(['paramAPrice' => null]));
        $this->assertSame(PricingUnavailableError::MESSAGE, $error->getMessage());
        $this->assertDoesNotMatchRegularExpression('/price|version-1|Glucose/i', $error->getMessage());
        $this->assertNotEmpty($error->issues);
    }

    public function test_reports_every_affected_item_not_just_the_first(): void
    {
        $error = self::failure(self::selection(true), self::dataset(['paramAPrice' => null, 'paramBPrice' => 'x', 'hardwarePrice' => null]));
        $this->assertEqualsCanonicalizing([self::PARAM_A, self::PARAM_B, self::HARDWARE_A], array_column($error->issues, 'itemId'));
    }

    public function test_splits_annual_volume_across_selected_parameters_and_charges_no_hardware(): void
    {
        $selection = self::selection(false, ['annualTestVolume' => 10_000]);
        $selection['labs'][0]['labType'] = 'poct';
        $result = CalculationEngine::calculateLabEstimate($selection, self::dataset());
        $section = $result['labs'][0]['subLabs'][0];
        $this->assertEquals(5000, $section['allocatedVolumePerParameter']);
        $this->assertEquals(0, $section['hardwareSubtotal']);
        $this->assertEquals(5000 * 10 + 5000 * 20, $section['parameterSubtotal']);
        $this->assertEquals(150_000, $result['internalTotal']);
    }

    public function test_never_prices_a_parameter_twice(): void
    {
        $selection = self::selection(false, ['selectedParameterIds' => [self::PARAM_A, self::PARAM_B, self::PARAM_A]]);
        $this->assertEquals(15000, CalculationEngine::calculateLabEstimate($selection, self::dataset())['internalTotal']);
    }

    public function test_refuses_selections_that_do_not_match_the_published_data(): void
    {
        $cases = [
            'no longer available' => ['labs' => [['labId' => self::SUB_LAB_ID] + self::selection()['labs'][0]]],
            'sub-laboratory is no longer part' => self::selection(false, ['subLabId' => self::LAB_ID]),
            'selected test is no longer valid' => self::selection(false, ['selectedParameterIds' => [self::HARDWARE_A]]),
            'hardware item is no longer valid' => self::selection(true, ['selectedHardwareIds' => [self::PARAM_A]]),
            'Enter an expected number of tests per year for Chemistry.' => self::selection(false, ['annualTestVolume' => 0]),
            'Select at least one test/parameter before calculating Chemistry.' => self::selection(false, ['selectedParameterIds' => []]),
        ];
        foreach ($cases as $message => $selection) {
            try {
                CalculationEngine::calculateLabEstimate($selection, self::dataset());
                $this->fail("expected: {$message}");
            } catch (CalculationError $error) {
                $this->assertStringContainsString($message, $error->getMessage());
            }
        }
        $this->expectExceptionMessage('Unknown calculation strategy: other');
        CalculationEngine::calculateLabEstimate(self::selection(), self::dataset(), 'other');
    }

    public function test_hardware_only_section_needs_no_volume(): void
    {
        $selection = self::selection(true, ['annualTestVolume' => 0, 'selectedParameterIds' => []]);
        $result = CalculationEngine::calculateLabEstimate($selection, self::dataset());
        $this->assertEquals(5000, $result['internalTotal']);
        $this->assertEquals(0, $result['labs'][0]['subLabs'][0]['annualTestVolume']);
    }

    public function test_selection_schema_applies_defaults_and_drops_unknown_keys(): void
    {
        $parsed = Selection::parse(['labs' => [[
            'labId' => self::LAB_ID, 'labType' => 'poct', 'price' => 1,
            'sections' => [['subLabId' => null, 'pricePerTest' => 0.01]],
        ]], 'internalTotal' => 5]);
        $this->assertSame(['labs' => [[
            'labId' => self::LAB_ID, 'labType' => 'poct',
            'sections' => [['subLabId' => null, 'annualTestVolume' => 0, 'includeHardware' => false, 'selectedHardwareIds' => [], 'selectedParameterIds' => []]],
        ]]], $parsed);
    }

    public function test_selection_schema_rejects_malformed_input(): void
    {
        $section = ['subLabId' => self::SUB_LAB_ID, 'annualTestVolume' => 10, 'includeHardware' => false, 'selectedHardwareIds' => [], 'selectedParameterIds' => [self::PARAM_A]];
        $lab = fn (array $overrides = [], array $sectionOverrides = []) => ['labs' => [$overrides + ['labId' => self::LAB_ID, 'labType' => 'core_lab', 'sections' => [$sectionOverrides + $section]]]];
        $bad = [
            null, 'x', [], ['labs' => []], ['labs' => 'x'],
            $lab(['labId' => 'not-an-id']), $lab(['labType' => 'radiology']), $lab(['sections' => []]),
            $lab([], ['subLabId' => 'x']), $lab([], ['annualTestVolume' => '10']), $lab([], ['annualTestVolume' => -1]),
            $lab([], ['annualTestVolume' => 1_000_000_001]), $lab([], ['annualTestVolume' => null]), $lab([], ['includeHardware' => 'yes']),
            $lab([], ['selectedParameterIds' => [self::PARAM_A."\n"]]), $lab([], ['selectedHardwareIds' => 'x']),
            ['labs' => [['labId' => self::LAB_ID, 'labType' => 'core_lab', 'sections' => [array_diff_key($section, ['subLabId' => 1])]]]],
        ];
        foreach ($bad as $index => $input) {
            try {
                Selection::parse($input);
                $this->fail("input {$index} was accepted");
            } catch (InvalidInput $error) {
                $this->assertStringStartsWith('Invalid input: selection', $error->getMessage());
            }
        }
    }
}
