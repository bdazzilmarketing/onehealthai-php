<?php

namespace App\Ai;

use App\Calculator\LabMeta;
use App\Support\Js;

/**
 * The structured answer the model must return (port of
 * src/lib/ai-lab-contract.ts). No model-authored prose is displayed: every
 * visitor sentence is rendered from enums, catalog references and validated
 * state. Unknown keys, wrong types and out-of-range values are all refused.
 */
final class LabContract
{
    public const KINDS = ['clarification', 'catalog_options', 'configuration_summary', 'unavailable_item', 'safe_refusal'];

    public const QUESTIONS = ['none', 'item', 'section', 'volume', 'hardware', 'confirm', 'laboratory'];

    /** The Responses API schema (local bounds below remain authoritative). */
    public static function jsonSchema(): object
    {
        return json_decode(Data::get('LABORATORY_JSON_SCHEMA'), false, 512, JSON_THROW_ON_ERROR);
    }

    /** The validated turn as arrays, or null when the text is not exactly the contract. */
    public static function parseJson(string $text): ?array
    {
        try {
            return self::parse(json_decode($text, false, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException) {
            return null;
        }
    }

    /** @param  mixed  $value  decoded with objects as stdClass */
    public static function parse(mixed $value): ?array
    {
        $turn = self::object($value, ['response', 'status', 'scope', 'selection']);
        $response = self::object($turn['response'] ?? null, ['kind', 'question', 'parameterIds', 'hardwareIds']);
        $selection = self::object($turn['selection'] ?? null, ['labs']);
        if ($turn === null || $response === null || $selection === null) {
            return null;
        }
        $parameterIds = self::ids($response['parameterIds'], 300);
        $hardwareIds = self::ids($response['hardwareIds'], 100);
        if (! in_array($response['kind'], self::KINDS, true) || ! in_array($response['question'], self::QUESTIONS, true)
            || $parameterIds === null || $hardwareIds === null
            || ! in_array($turn['status'], ['collecting', 'ready'], true) || ! in_array($turn['scope'], ['laboratory', 'out_of_scope'], true)
            || ! is_array($selection['labs']) || count($selection['labs']) > 10) {
            return null;
        }
        $labs = [];
        foreach ($selection['labs'] as $rawLab) {
            $lab = self::object($rawLab, ['labId', 'labType', 'sections']);
            if ($lab === null || ! self::isId($lab['labId']) || ! in_array($lab['labType'], LabMeta::TYPES, true)
                || ! is_array($lab['sections']) || count($lab['sections']) > 100) {
                return null;
            }
            $sections = [];
            foreach ($lab['sections'] as $rawSection) {
                $section = self::object($rawSection, ['subLabId', 'annualTestVolume', 'includeHardware', 'selectedHardwareIds', 'selectedParameterIds']);
                if ($section === null) {
                    return null;
                }
                $volume = $section['annualTestVolume'];
                $selectedHardware = self::ids($section['selectedHardwareIds'], 100);
                $selectedParameters = self::ids($section['selectedParameterIds'], 300);
                if (($section['subLabId'] !== null && ! self::isId($section['subLabId']))
                    || (! is_int($volume) && ! is_float($volume)) || ! is_finite((float) $volume) || $volume < 0 || $volume > 1_000_000_000
                    || ! is_bool($section['includeHardware']) || $selectedHardware === null || $selectedParameters === null) {
                    return null;
                }
                $sections[] = ['subLabId' => $section['subLabId'], 'annualTestVolume' => $volume, 'includeHardware' => $section['includeHardware'],
                    'selectedHardwareIds' => $selectedHardware, 'selectedParameterIds' => $selectedParameters];
            }
            $labs[] = ['labId' => $lab['labId'], 'labType' => $lab['labType'], 'sections' => $sections];
        }

        return [
            'response' => ['kind' => $response['kind'], 'question' => $response['question'], 'parameterIds' => $parameterIds, 'hardwareIds' => $hardwareIds],
            'status' => $turn['status'],
            'scope' => $turn['scope'],
            'selection' => ['labs' => $labs],
        ];
    }

    /**
     * An object with exactly the given keys.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value, array $keys): ?array
    {
        if (! $value instanceof \stdClass) {
            return null;
        }
        $fields = get_object_vars($value);
        if (count($fields) !== count($keys) || array_diff($keys, array_keys($fields)) !== []) {
            return null;
        }

        return $fields;
    }

    private static function isId(mixed $value): bool
    {
        return is_string($value) && $value !== '' && Js::length($value) <= 64;
    }

    /** @return list<string>|null */
    private static function ids(mixed $value, int $max): ?array
    {
        if (! is_array($value) || count($value) > $max) {
            return null;
        }
        foreach ($value as $id) {
            if (! self::isId($id)) {
                return null;
            }
        }

        return $value;
    }
}
