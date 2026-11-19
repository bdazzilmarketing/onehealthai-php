<?php

namespace App\Calculator;

use App\Support\InvalidInput;

/**
 * The calculator selection a browser may send (port of
 * calculatorSelectionSchema in src/lib/calculator-types.ts). Unknown keys are
 * dropped, so nothing price-shaped in a request can reach the engine.
 */
final class Selection
{
    private const GUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/';

    /**
     * @return array{labs: list<array{labId: string, labType: string, sections: list<array{subLabId: ?string, annualTestVolume: int|float, includeHardware: bool, selectedHardwareIds: list<string>, selectedParameterIds: list<string>}>}>}
     */
    public static function parse(mixed $input): array
    {
        $labs = self::items(self::object($input, 'selection')['labs'] ?? null, 'selection.labs');
        $out = [];
        foreach ($labs as $i => $lab) {
            $at = "selection.labs[{$i}]";
            $lab = self::object($lab, $at);
            $labType = $lab['labType'] ?? null;
            if (! is_string($labType) || ! in_array($labType, LabMeta::TYPES, true)) {
                throw new InvalidInput("{$at}.labType", 'is not a laboratory type');
            }
            $sections = [];
            foreach (self::items($lab['sections'] ?? null, "{$at}.sections") as $j => $section) {
                $sat = "{$at}.sections[{$j}]";
                $section = self::object($section, $sat);
                if (! array_key_exists('subLabId', $section)) {
                    throw new InvalidInput("{$sat}.subLabId", 'is required');
                }
                $volume = $section['annualTestVolume'] ?? null;
                if (! array_key_exists('annualTestVolume', $section)) {
                    $volume = 0;
                } elseif ((! is_int($volume) && ! is_float($volume)) || ! is_finite((float) $volume) || $volume < 0 || $volume > 1_000_000_000) {
                    throw new InvalidInput("{$sat}.annualTestVolume", 'must be a number between 0 and 1000000000');
                }
                $include = array_key_exists('includeHardware', $section) ? $section['includeHardware'] : false;
                if (! is_bool($include)) {
                    throw new InvalidInput("{$sat}.includeHardware", 'must be true or false');
                }
                $sections[] = [
                    'subLabId' => $section['subLabId'] === null ? null : self::guid($section['subLabId'], "{$sat}.subLabId"),
                    'annualTestVolume' => $volume,
                    'includeHardware' => $include,
                    'selectedHardwareIds' => self::guids($section, 'selectedHardwareIds', $sat),
                    'selectedParameterIds' => self::guids($section, 'selectedParameterIds', $sat),
                ];
            }
            $out[] = ['labId' => self::guid($lab['labId'] ?? null, "{$at}.labId"), 'labType' => $labType, 'sections' => $sections];
        }

        return ['labs' => $out];
    }

    /** @return array<string, mixed> */
    private static function object(mixed $value, string $path): array
    {
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidInput($path, 'must be an object');
        }

        return $value;
    }

    /** @return list<mixed> at least one item */
    private static function items(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw new InvalidInput($path, 'must be a list with at least one item');
        }

        return $value;
    }

    private static function guid(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match(self::GUID, $value) !== 1) {
            throw new InvalidInput($path, 'must be an id');
        }

        return $value;
    }

    /** @return list<string> */
    private static function guids(array $section, string $key, string $path): array
    {
        if (! array_key_exists($key, $section)) {
            return [];
        }
        $value = $section[$key];
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidInput("{$path}.{$key}", 'must be a list');
        }
        foreach ($value as $i => $id) {
            self::guid($id, "{$path}.{$key}[{$i}]");
        }

        return $value;
    }
}
