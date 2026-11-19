<?php

namespace App\Calculator;

use App\Auth\Crypto;
use App\Support\Js;

/**
 * The single authoritative calculation implementation (port of
 * src/lib/calculation-engine.ts). Pure functions only: no database access, no
 * AI. A new methodology is added as a new strategy id; existing results stay
 * explainable because the strategy id is recorded with every calculation.
 *
 * Arithmetic follows the original exactly (IEEE doubles, JavaScript rounding),
 * so the same selection and prices give the same totals to the cent.
 */
final class CalculationEngine
{
    public const VERSION = 'equal_distribution_v1';

    /**
     * equal_distribution_v1
     * Annual expected volume is divided equally across the selected parameters of
     * a section, each allocation is priced with the server-side price per test, and
     * selected hardware is added as a separate one-time cost.
     *
     * @param  array  $selection  as returned by Selection::parse
     * @param  array  $dataset  currency and labs keyed by id, each with subLabNames, parameters and hardware keyed by id
     * @return array the internal calculation (never sent to a browser)
     */
    public static function calculateLabEstimate(array $selection, array $dataset, string $strategy = self::VERSION): array
    {
        if ($strategy !== self::VERSION) {
            throw new CalculationError("Unknown calculation strategy: {$strategy}");
        }

        $datasetVersions = [];
        $labs = [];
        // Defensive control: every selected item must carry a valid published price.
        $pricingIssues = [];

        foreach ($selection['labs'] as $labSelection) {
            $lab = $dataset['labs'][$labSelection['labId']] ?? null;
            if (! $lab) {
                throw new CalculationError('Your configuration refers to laboratory data that is no longer available. Please refresh and review your selections.');
            }
            $datasetVersions[$lab['labType']] = $lab['datasetVersionNumber'];
            $issue = fn (string $kind, array $item, string $reason, bool $present, mixed $raw) => [
                'kind' => $kind,
                'itemId' => $item['id'],
                'itemName' => $item['name'],
                'labId' => $lab['labId'],
                'labType' => $lab['labType'],
                'datasetVersionId' => $lab['datasetVersionId'],
                'datasetVersionNumber' => $lab['datasetVersionNumber'],
                'reason' => $reason,
                'rawValue' => $present ? Js::string($raw) : 'undefined',
            ];

            $sections = [];

            foreach ($labSelection['sections'] as $sectionSelection) {
                $subLabId = $sectionSelection['subLabId'];
                $subLabName = $subLabId ? ($lab['subLabNames'][$subLabId] ?? '') : null;
                if ($subLabId && $subLabName === '') {
                    throw new CalculationError('A selected sub-laboratory is no longer part of the published data. Please refresh and review your selections.');
                }
                $sectionName = $subLabId ? $subLabName : $lab['labName'];

                // Deduplicate: shared Parameter Groups must never price a parameter twice.
                $parameterIds = self::unique($sectionSelection['selectedParameterIds']);
                $hardwareIds = $sectionSelection['includeHardware'] ? self::unique($sectionSelection['selectedHardwareIds']) : [];

                $parameterRecords = [];
                foreach ($parameterIds as $id) {
                    $parameter = $lab['parameters'][$id] ?? null;
                    if (! $parameter || $parameter['subLabId'] !== $subLabId) {
                        throw new CalculationError('A selected test is no longer valid for this laboratory. Please refresh and review your selections.');
                    }
                    $parameterRecords[] = $parameter;
                }

                $hardwareRecords = [];
                foreach ($hardwareIds as $id) {
                    $item = $lab['hardware'][$id] ?? null;
                    if (! $item || $item['subLabId'] !== $subLabId) {
                        throw new CalculationError('A selected hardware item is no longer valid for this laboratory. Please refresh and review your selections.');
                    }
                    $hardwareRecords[] = $item;
                }

                $annualTestVolume = Js::toNumber($sectionSelection['annualTestVolume']);
                if (is_nan($annualTestVolume)) {
                    $annualTestVolume = 0.0;
                }
                $hardwareOnly = count($parameterRecords) === 0 && count($hardwareRecords) > 0;

                if (! $hardwareOnly) {
                    if ($annualTestVolume <= 0) {
                        throw new CalculationError("Enter an expected number of tests per year for {$sectionName}.");
                    }
                    if (count($parameterRecords) === 0) {
                        throw new CalculationError("Select at least one test/parameter before calculating {$sectionName}.");
                    }
                }

                $allocatedVolumePerParameter = count($parameterRecords) > 0 ? $annualTestVolume / count($parameterRecords) : 0.0;

                $parameterLines = [];
                foreach ($parameterRecords as $parameter) {
                    $present = array_key_exists('pricePerTest', $parameter);
                    $raw = $parameter['pricePerTest'] ?? null;
                    $reason = self::priceIssueReason($present, $raw);
                    if ($reason) {
                        $pricingIssues[] = $issue('parameter', $parameter, $reason, $present, $raw);
                    }
                    $pricePerTest = $reason ? NAN : (float) $raw;
                    $parameterLines[] = [
                        'parameterId' => $parameter['id'],
                        'parameterName' => $parameter['name'],
                        'pricePerTest' => $pricePerTest,
                        'allocatedAnnualVolume' => $allocatedVolumePerParameter,
                        'annualCalculatedCost' => Js::round2($allocatedVolumePerParameter * $pricePerTest),
                    ];
                }

                $hardwareLines = [];
                foreach ($hardwareRecords as $item) {
                    $present = array_key_exists('price', $item);
                    $raw = $item['price'] ?? null;
                    $reason = self::priceIssueReason($present, $raw);
                    if ($reason) {
                        $pricingIssues[] = $issue('hardware', $item, $reason, $present, $raw);
                    }
                    $hardwareLines[] = [
                        'hardwareId' => $item['id'],
                        'hardwareName' => $item['name'],
                        'price' => $reason ? NAN : (float) $raw,
                    ];
                }

                $parameterSubtotal = Js::round2(self::sum($parameterLines, 'annualCalculatedCost'));
                $hardwareSubtotal = Js::round2(self::sum($hardwareLines, 'price'));

                $sections[] = [
                    'subLabId' => $subLabId,
                    'subLabName' => $sectionName,
                    'annualTestVolume' => $hardwareOnly ? 0.0 : $annualTestVolume,
                    'selectedParameterCount' => count($parameterLines),
                    'allocatedVolumePerParameter' => $allocatedVolumePerParameter,
                    'parameters' => $parameterLines,
                    'hardware' => $hardwareLines,
                    'parameterSubtotal' => $parameterSubtotal,
                    'hardwareSubtotal' => $hardwareSubtotal,
                    'subtotal' => Js::round2($parameterSubtotal + $hardwareSubtotal),
                ];
            }

            $parameterSubtotal = Js::round2(self::sum($sections, 'parameterSubtotal'));
            $hardwareSubtotal = Js::round2(self::sum($sections, 'hardwareSubtotal'));

            $labs[] = [
                'labId' => $lab['labId'],
                'labType' => $lab['labType'],
                'labName' => $lab['labName'],
                'datasetVersionId' => $lab['datasetVersionId'],
                'datasetVersionNumber' => $lab['datasetVersionNumber'],
                'subLabs' => $sections,
                'parameterSubtotal' => $parameterSubtotal,
                'hardwareSubtotal' => $hardwareSubtotal,
                'subtotal' => Js::round2($parameterSubtotal + $hardwareSubtotal),
            ];
        }

        // Stop before any estimate exists: never return a partial result, never
        // substitute zero for a missing or invalid price.
        if (count($pricingIssues) > 0) {
            throw new PricingUnavailableError($pricingIssues);
        }

        $totalParameterCost = Js::round2(self::sum($labs, 'parameterSubtotal'));
        $totalHardwareCost = Js::round2(self::sum($labs, 'hardwareSubtotal'));

        return [
            'calculationId' => Crypto::uuid(),
            'calculationVersion' => self::VERSION,
            'calculatedAt' => Js::isoNow(),
            'currency' => $dataset['currency'],
            'datasetVersions' => $datasetVersions,
            'labs' => $labs,
            'totalParameterCost' => $totalParameterCost,
            'totalHardwareCost' => $totalHardwareCost,
            'internalTotal' => Js::round2($totalParameterCost + $totalHardwareCost),
        ];
    }

    /**
     * Public variance is applied only after the exact internal total exists.
     *
     * @return array{lowerRange: float, upperRange: float}
     */
    public static function applyVariance(float|int $internalTotal, float|int $lowerPercent, float|int $upperPercent): array
    {
        $lower = $internalTotal * (1 - $lowerPercent / 100);
        $upper = $internalTotal * (1 + $upperPercent / 100);

        return ['lowerRange' => Js::round($lower), 'upperRange' => Js::round($upper)];
    }

    /**
     * A price is valid only when it is a finite, non-negative number.
     * Zero is accepted because the import validation intentionally permits a
     * published price of 0 (only negatives are rejected there). Null, missing,
     * NaN and non-numeric values are never coerced to zero.
     */
    private static function priceIssueReason(bool $present, mixed $value): ?string
    {
        return match (true) {
            ! $present => 'missing',
            $value === null => 'null',
            (! is_int($value) && ! is_float($value)) || ! is_finite((float) $value) => 'non_numeric',
            $value < 0 => 'negative',
            default => null,
        };
    }

    /** Deduplicates while preserving order. */
    private static function unique(array $ids): array
    {
        return array_values(array_unique($ids));
    }

    private static function sum(array $rows, string $key): float
    {
        $sum = 0.0;
        foreach ($rows as $row) {
            $sum += $row[$key];
        }

        return $sum;
    }
}
