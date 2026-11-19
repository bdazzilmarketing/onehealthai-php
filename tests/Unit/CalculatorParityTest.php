<?php

namespace Tests\Unit;

use App\Calculator\CalculationEngine;
use App\Leads\Lead;
use App\Submissions\Submissions;
use App\Support\Js;
use PHPUnit\Framework\TestCase;

/**
 * Results recorded from the original TypeScript implementation
 * (tests/Fixtures/calculator-parity.json: calculation engine, lead
 * validation, submission fingerprint and report payload, number formatting).
 * The PHP port must reproduce them byte for byte.
 */
final class CalculatorParityTest extends TestCase
{
    private static array $fixture;

    public static function setUpBeforeClass(): void
    {
        self::$fixture = json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/calculator-parity.json'), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_formats_and_rounds_numbers_like_javascript(): void
    {
        foreach (self::$fixture['numbers'] as [$input, $string, $rounded2, $rounded]) {
            $number = (float) $input;
            $this->assertSame($string, Js::number($number), "String({$input})");
            $this->assertSame($rounded2, Js::number(Js::round2($number)), "round2({$input})");
            $this->assertSame($rounded, Js::number(Js::round($number)), "Math.round({$input})");
        }
        foreach (self::$fixture['strings'] as [$text, $json]) {
            $this->assertSame($json, Js::stringify($text));
        }
        $this->assertSame('{"a":[1,2.5,null,true,"x"],"b":{},"c":[]}', Js::stringify(['a' => [1, 2.5, null, true, 'x'], 'b' => new \stdClass, 'c' => []]));
        $this->assertSame('null', Js::stringify(NAN));
        $this->assertNan(Js::toNumber('abc'));
        $this->assertNan(Js::toNumber('NaN'));
        $this->assertSame(12.5, Js::toNumber(' 12.50 '));
    }

    public function test_calculates_every_recorded_case_identically(): void
    {
        $this->assertCount(24, self::$fixture['cases']);
        foreach (self::$fixture['cases'] as $index => $case) {
            $expected = json_decode($case['internalJson'], true);
            $internal = CalculationEngine::calculateLabEstimate($case['selection'], $case['dataset']);
            $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $internal['calculationId']);
            $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/', $internal['calculatedAt']);
            // The id and the time are the only values that differ between two runs.
            $internal['calculationId'] = $expected['calculationId'];
            $internal['calculatedAt'] = $expected['calculatedAt'];
            $snapshot = $internal;
            $snapshot['datasetVersions'] = (object) $internal['datasetVersions'];
            $this->assertSame($case['internalJson'], Js::stringify($snapshot), "case {$index}: internal calculation");

            $range = CalculationEngine::applyVariance($internal['internalTotal'], $case['variance'][0], $case['variance'][1]);
            $this->assertSame(Js::stringify($case['range']), Js::stringify($range), "case {$index}: public range");

            $this->assertSame($case['key'], Submissions::idempotencyKey($case['selection'], $internal), "case {$index}: fingerprint");
            $payload = Submissions::buildMakePayload([
                'referenceId' => 'OH-2026-ABCDEF12',
                'submittedAt' => '2026-11-19T00:00:00.000Z',
                'mode' => 'manual',
                'lead' => ['fullName' => 'A', 'company' => 'B', 'phone' => '1', 'email' => 'a@b.co'],
                'internal' => $internal,
                'publicEstimate' => $range,
            ]);
            $this->assertSame($case['payloadJson'], Js::stringify($payload), "case {$index}: report payload");
        }
    }

    public function test_the_fingerprint_ignores_the_order_of_a_selection(): void
    {
        $case = self::$fixture['cases'][0];
        $internal = json_decode($case['internalJson'], true);
        $shuffled = $case['selection'];
        $shuffled['labs'] = array_reverse($shuffled['labs']);
        foreach ($shuffled['labs'] as &$lab) {
            $lab['sections'] = array_reverse($lab['sections']);
            foreach ($lab['sections'] as &$section) {
                $section['selectedParameterIds'] = array_reverse($section['selectedParameterIds']);
            }
        }
        $this->assertSame($case['key'], Submissions::idempotencyKey($shuffled, $internal));
    }

    public function test_normalizes_and_validates_leads_identically(): void
    {
        foreach (self::$fixture['leads'] as $index => $lead) {
            $normalized = Lead::normalize($lead['input']);
            $this->assertSame($lead['normalized'], $normalized, "lead {$index}");
            $this->assertSame($lead['errors'], Lead::validate($normalized), "lead {$index}");
            $this->assertSame($lead['phone'], Lead::normalizePhone($normalized['phone']), "lead {$index}");
        }
        $this->assertTrue(Lead::isCalculatorMode('ai'));
        $this->assertFalse(Lead::isCalculatorMode('AI'));
    }
}
