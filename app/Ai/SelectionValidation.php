<?php

namespace App\Ai;

use App\Calculator\Selection;
use App\Support\Js;

/**
 * Pure validation of AI-proposed selections against the safe published
 * catalog (port of src/lib/ai-selection-validation.ts). No pricing is
 * involved. AI-generated IDs are NEVER trusted: anything not found in the
 * published catalog is discarded.
 */
final class SelectionValidation
{
    private const MAX_VOLUME = 1_000_000_000;

    private static function normaliseVolume(mixed $value): float
    {
        $n = Js::toNumber($value);
        if (! is_finite($n) || $n < 0) {
            return 0.0;
        }

        return $n <= self::MAX_VOLUME ? $n : 0.0;
    }

    /**
     * @param  mixed  $raw  the untrusted {labs: [...]} shape the model is asked to produce
     * @return array{labs: list<array>, rejected: list<string>, missingFields: list<string>, complete: bool}
     */
    public static function validate(mixed $raw, array $catalog): array
    {
        $rejected = [];
        $labs = [];
        $seenLabs = [];
        $rawLabs = is_array($raw) && is_array($raw['labs'] ?? null) ? $raw['labs'] : [];

        foreach ($rawLabs as $rawLab) {
            $rawLab = is_array($rawLab) ? $rawLab : [];
            $rawLabId = $rawLab['labId'] ?? null;
            $lab = null;
            foreach ($catalog['labs'] as $candidate) {
                if ($candidate['labId'] === $rawLabId) {
                    $lab = $candidate;
                    break;
                }
            }
            if ($lab === null) {
                if ($rawLabId) {
                    $rejected[] = $rawLabId;
                }

                continue;
            }
            if (($rawLab['labType'] ?? null) !== $lab['labType']) {
                $rejected[] = $rawLabId;

                continue;
            }
            if (isset($seenLabs[$lab['labId']])) {
                continue;
            }
            $seenLabs[$lab['labId']] = true;

            $sections = [];
            $seenSections = [];
            foreach (is_array($rawLab['sections'] ?? null) ? $rawLab['sections'] : [] as $rawSection) {
                $rawSection = is_array($rawSection) ? $rawSection : [];
                $hasSubLab = array_key_exists('subLabId', $rawSection);
                $rawSubLabId = $rawSection['subLabId'] ?? null;
                $section = null;
                if ($lab['flat']) {
                    $section = $hasSubLab && $rawSubLabId === null ? ($lab['sections'][0] ?? null) : null;
                } else {
                    foreach ($lab['sections'] as $candidate) {
                        if ($hasSubLab && $candidate['subLabId'] === $rawSubLabId) {
                            $section = $candidate;
                            break;
                        }
                    }
                }
                if ($section === null) {
                    if ($rawSubLabId) {
                        $rejected[] = $rawSubLabId;
                    }

                    continue;
                }
                if (isset($seenSections[$section['key']])) {
                    continue;
                }
                $seenSections[$section['key']] = true;

                $catalogHardware = array_column($section['hardware'], 'id');
                $catalogParameters = array_column($section['parameters'], 'id');
                $hardwareIds = [];
                foreach (is_array($rawSection['selectedHardwareIds'] ?? null) ? $rawSection['selectedHardwareIds'] : [] as $id) {
                    if ($section['supportsHardware'] && ($section['hardwareOnly'] || ($rawSection['includeHardware'] ?? null) === true) && in_array($id, $catalogHardware, true)) {
                        if (! in_array($id, $hardwareIds, true)) {
                            $hardwareIds[] = $id;
                        }
                    } else {
                        $rejected[] = $id;
                    }
                }
                $parameterIds = [];
                foreach (is_array($rawSection['selectedParameterIds'] ?? null) ? $rawSection['selectedParameterIds'] : [] as $id) {
                    if (! $section['hardwareOnly'] && in_array($id, $catalogParameters, true)) {
                        if (! in_array($id, $parameterIds, true)) {
                            $parameterIds[] = $id;
                        }
                    } else {
                        $rejected[] = $id;
                    }
                }

                $includeHardware = ((bool) ($rawSection['includeHardware'] ?? false)) && count($hardwareIds) > 0;
                $volume = $section['hardwareOnly'] ? 0.0 : self::normaliseVolume($rawSection['annualTestVolume'] ?? NAN);

                $missing = [];
                if ($section['hardwareOnly']) {
                    if ($hardwareIds === []) {
                        $missing[] = "{$section['name']}: hardware selection";
                    }
                } else {
                    if ($volume <= 0) {
                        $missing[] = "{$section['name']}: expected tests per year";
                    }
                    if ($parameterIds === []) {
                        $missing[] = "{$section['name']}: test/parameter selection";
                    }
                }

                $sections[] = [
                    'key' => $section['key'],
                    'subLabId' => $section['subLabId'],
                    'sectionName' => $section['name'],
                    'hardwareOnly' => $section['hardwareOnly'],
                    'annualTestVolume' => $volume,
                    'includeHardware' => $section['hardwareOnly'] ? count($hardwareIds) > 0 : $includeHardware,
                    'selectedHardwareIds' => $hardwareIds,
                    'selectedParameterIds' => $parameterIds,
                    'hardwareNames' => array_column(array_filter($section['hardware'], fn (array $h) => in_array($h['id'], $hardwareIds, true)), 'name'),
                    'parameterNames' => array_column(array_filter($section['parameters'], fn (array $p) => in_array($p['id'], $parameterIds, true)), 'name'),
                    'missing' => $missing,
                ];
            }

            if ($sections !== []) {
                $labs[] = ['labId' => $lab['labId'], 'labType' => $lab['labType'], 'labName' => $lab['labName'], 'sections' => $sections];
            }
        }

        $missingFields = [];
        foreach ($labs as $lab) {
            foreach ($lab['sections'] as $section) {
                foreach ($section['missing'] as $missing) {
                    $missingFields[] = "{$lab['labName']} — {$missing}";
                }
            }
        }

        return ['labs' => $labs, 'rejected' => $rejected, 'missingFields' => $missingFields, 'complete' => $labs !== [] && $missingFields === []];
    }

    /** Converts validated state into the authoritative calculator selection (same schema the calculator accepts). */
    public static function toCalculatorSelection(array $result): array
    {
        return Selection::parse(Reconciliation::rawState($result));
    }
}
