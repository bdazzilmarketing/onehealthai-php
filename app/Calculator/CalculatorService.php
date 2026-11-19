<?php

namespace App\Calculator;

use App\Database\Db;
use App\Database\Tx;
use App\Support\Js;

/**
 * Server-only data access for the public Manual Calculator (port of
 * src/lib/calculator.server.ts). Pricing is read here in the service context
 * and never leaves the server in any public response; only labels and IDs are
 * returned to the browser.
 */
final class CalculatorService
{
    public function __construct(private readonly Db $db) {}

    /**
     * Reads every currently published dataset, including prices (server-side only).
     *
     * @param  list<string>|null  $labIds
     * @return list<array>
     */
    private function loadPublishedData(?array $labIds = null): array
    {
        try {
            $loaded = $this->db->withServiceContext(function (Tx $tx) {
                $versions = $tx->select("select id, workbook_type, version_number from public.dataset_versions where status = 'published'");
                if ($versions === []) {
                    return null;
                }
                $ids = '{'.implode(',', array_column($versions, 'id')).'}';

                return [
                    'versions' => $versions,
                    'labs' => $tx->select('select id, code, name, dataset_version_id, display_order from public.laboratories
                        where dataset_version_id = any(?::uuid[]) and active', [$ids]),
                    'subLabs' => $tx->select('select id, name, laboratory_id, dataset_version_id, display_order from public.sub_labs
                        where dataset_version_id = any(?::uuid[]) and active order by display_order', [$ids]),
                    'groups' => $tx->select('select id, sub_lab_id, laboratory_id, group_name, display_order from public.parameter_groups
                        where dataset_version_id = any(?::uuid[]) order by display_order', [$ids]),
                    'categories' => $tx->select('select id, sub_lab_id, laboratory_id, category_name, display_order from public.hardware_categories
                        where dataset_version_id = any(?::uuid[]) order by display_order', [$ids]),
                    'parameters' => $tx->select('select id, sub_lab_id, laboratory_id, parameter_group_id, parameter_name, optional_notes,
                               price_per_test, display_order
                        from public.parameters
                        where dataset_version_id = any(?::uuid[]) and active order by display_order', [$ids]),
                    'hardware' => $tx->select('select id, sub_lab_id, laboratory_id, parameter_group_id, hardware_category_id, hardware_name,
                               price, display_order
                        from public.hardware
                        where dataset_version_id = any(?::uuid[]) and active order by display_order', [$ids]),
                ];
            });
        } catch (\Throwable) {
            throw new CalculationError('Laboratory data is temporarily unavailable.');
        }
        if ($loaded === null) {
            return [];
        }

        $versions = array_column($loaded['versions'], null, 'id');
        $ofLab = fn (array $rows, string $labId) => array_values(array_filter($rows, fn ($row) => $row['laboratory_id'] === $labId));
        $result = [];
        foreach ($loaded['labs'] as $lab) {
            if ($labIds !== null && ! in_array($lab['id'], $labIds, true)) {
                continue;
            }
            $version = $versions[$lab['dataset_version_id']];
            $labType = $version['workbook_type'];
            $result[] = [
                'labId' => $lab['id'],
                'labType' => $labType,
                'labName' => $lab['name'] ?? LabMeta::META[$labType]['name'],
                'datasetVersionId' => $version['id'],
                'datasetVersionNumber' => (int) $version['version_number'],
                'subLabs' => array_map(fn ($s) => ['id' => $s['id'], 'name' => $s['name']], $ofLab($loaded['subLabs'], $lab['id'])),
                'groups' => $ofLab($loaded['groups'], $lab['id']),
                'categories' => $ofLab($loaded['categories'], $lab['id']),
                'parameters' => $ofLab($loaded['parameters'], $lab['id']),
                'hardware' => $ofLab($loaded['hardware'], $lab['id']),
            ];
        }

        usort($result, fn ($a, $b) => LabMeta::META[$a['labType']]['order'] <=> LabMeta::META[$b['labType']]['order']);

        return $result;
    }

    /** @return array{lowerPercent: float, upperPercent: float, currency: string} */
    private function varianceSettings(): array
    {
        // As before, an unreadable setting falls back to the defaults below.
        try {
            $raw = $this->db->withServiceContext(fn (Tx $tx) => $tx->value("select value from public.settings where key = 'calculator_variance'"));
            $value = is_string($raw) ? json_decode($raw, true) : null;
        } catch (\Throwable) {
            $value = null;
        }
        $value = is_array($value) ? $value : [];

        return [
            'lowerPercent' => Js::toNumber($value['lower_percent'] ?? 20),
            'upperPercent' => Js::toNumber($value['upper_percent'] ?? 20),
            'currency' => Js::string($value['currency'] ?? 'AED'),
        ];
    }

    private static function buildSection(array $raw, ?string $subLabId, string $name): array
    {
        $parameters = array_values(array_filter($raw['parameters'], fn ($p) => $p['sub_lab_id'] === $subLabId));
        $hardware = array_values(array_filter($raw['hardware'], fn ($h) => $h['sub_lab_id'] === $subLabId));
        $groups = [];
        foreach ($raw['groups'] as $group) {
            if ($group['sub_lab_id'] !== $subLabId) {
                continue;
            }
            $parameterIds = array_values(array_column(array_filter($parameters, fn ($p) => $p['parameter_group_id'] === $group['id']), 'id'));
            if (count($parameterIds) > 0) {
                $groups[] = ['id' => $group['id'], 'name' => $group['group_name'], 'parameterIds' => $parameterIds];
            }
        }
        $groupIds = array_column($groups, 'id');

        return [
            'key' => $subLabId ?? "lab:{$raw['labId']}",
            'subLabId' => $subLabId,
            'name' => $name,
            'hardwareOnly' => count($parameters) === 0 && count($hardware) > 0,
            'supportsHardware' => count($hardware) > 0,
            'groups' => $groups,
            'ungroupedParameterIds' => array_values(array_column(array_filter(
                $parameters,
                fn ($p) => ! $p['parameter_group_id'] || ! in_array($p['parameter_group_id'], $groupIds, true),
            ), 'id')),
            'parameters' => array_map(fn ($p) => [
                'id' => $p['id'],
                'name' => $p['parameter_name'],
                'groupId' => $p['parameter_group_id'],
                'notes' => $p['optional_notes'],
            ], $parameters),
            'hardware' => array_map(fn ($h) => [
                'id' => $h['id'],
                'name' => $h['hardware_name'],
                'groupId' => $h['parameter_group_id'],
                'categoryId' => $h['hardware_category_id'],
            ], $hardware),
            'categories' => array_values(array_map(
                fn ($c) => ['id' => $c['id'], 'name' => $c['category_name']],
                array_filter($raw['categories'], fn ($c) => $c['sub_lab_id'] === $subLabId),
            )),
        ];
    }

    /** Safe public metadata only: never contains a price field. */
    public function getPublicCatalog(): array
    {
        $raws = $this->loadPublishedData();
        $variance = $this->varianceSettings();

        $labs = [];
        foreach ($raws as $raw) {
            $sections = count($raw['subLabs']) > 0
                ? array_map(fn ($s) => self::buildSection($raw, $s['id'], $s['name']), $raw['subLabs'])
                : [self::buildSection($raw, null, $raw['labName'])];
            $sections = array_values(array_filter($sections, fn ($s) => count($s['parameters']) > 0 || count($s['hardware']) > 0));
            if (count($sections) === 0) {
                continue;
            }
            $labs[] = [
                'labId' => $raw['labId'],
                'labType' => $raw['labType'],
                'labName' => $raw['labName'],
                'datasetVersionId' => $raw['datasetVersionId'],
                'datasetVersionNumber' => $raw['datasetVersionNumber'],
                'flat' => count($raw['subLabs']) === 0,
                'sections' => $sections,
            ];
        }

        return ['labs' => $labs, 'currency' => $variance['currency']];
    }

    private static function toPricedDataset(array $raws, string $currency): array
    {
        $labs = [];
        foreach ($raws as $raw) {
            $parameters = [];
            foreach ($raw['parameters'] as $p) {
                $parameters[$p['id']] = [
                    'id' => $p['id'],
                    'name' => $p['parameter_name'],
                    'labId' => $raw['labId'],
                    'subLabId' => $p['sub_lab_id'],
                    'pricePerTest' => $p['price_per_test'] === null ? null : Js::toNumber($p['price_per_test']),
                ];
            }
            $hardware = [];
            foreach ($raw['hardware'] as $h) {
                $hardware[$h['id']] = [
                    'id' => $h['id'],
                    'name' => $h['hardware_name'],
                    'labId' => $raw['labId'],
                    'subLabId' => $h['sub_lab_id'],
                    'price' => $h['price'] === null ? null : Js::toNumber($h['price']),
                ];
            }
            $labs[$raw['labId']] = [
                'labId' => $raw['labId'],
                'labType' => $raw['labType'],
                'labName' => $raw['labName'],
                'datasetVersionId' => $raw['datasetVersionId'],
                'datasetVersionNumber' => $raw['datasetVersionNumber'],
                'subLabNames' => array_column($raw['subLabs'], 'name', 'id'),
                'parameters' => $parameters,
                'hardware' => $hardware,
            ];
        }

        return ['currency' => $currency, 'labs' => $labs];
    }

    /**
     * Resolves prices from the currently published dataset, runs the deterministic
     * engine and applies the configured public variance. The client never supplies
     * a price; anything price-shaped in the request is ignored.
     *
     * @return array{internal: array, publicEstimate: array}
     */
    public function runCalculation(array $selection): array
    {
        $labIds = array_column($selection['labs'], 'labId');
        $raws = $this->loadPublishedData($labIds);
        $variance = $this->varianceSettings();

        if (count($raws) !== count(array_unique($labIds))) {
            throw new CalculationError('The published laboratory data has changed. Please refresh and review your configuration.');
        }

        $dataset = self::toPricedDataset($raws, $variance['currency']);
        try {
            $internal = CalculationEngine::calculateLabEstimate($selection, $dataset, CalculationEngine::VERSION);
        } catch (PricingUnavailableError $error) {
            // Server-side only: identifies the dataset version and item for
            // troubleshooting. Never surfaced to the browser.
            error_log('[calculation] pricing validation failed '.Js::stringify(['calculationVersion' => CalculationEngine::VERSION, 'issues' => $error->issues]));
            throw $error;
        }
        $range = CalculationEngine::applyVariance($internal['internalTotal'], $variance['lowerPercent'], $variance['upperPercent']);

        $publicEstimate = [
            'calculationId' => $internal['calculationId'],
            'calculationVersion' => $internal['calculationVersion'],
            'calculatedAt' => $internal['calculatedAt'],
            'currency' => $internal['currency'],
            'lowerRange' => $range['lowerRange'],
            'upperRange' => $range['upperRange'],
            'datasetVersions' => $internal['datasetVersions'],
            'summary' => array_map(fn ($lab) => [
                'labName' => $lab['labName'],
                'sections' => array_map(fn ($s) => [
                    'name' => $s['subLabName'],
                    'annualTestVolume' => $s['annualTestVolume'],
                    'parameterCount' => $s['selectedParameterCount'],
                    'hardwareCount' => count($s['hardware']),
                ], $lab['subLabs']),
            ], $internal['labs']),
        ];

        return ['internal' => $internal, 'publicEstimate' => $publicEstimate];
    }
}
