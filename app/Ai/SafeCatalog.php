<?php

namespace App\Ai;

use App\Calculator\LabMeta;
use App\Database\Db;
use App\Database\Tx;
use App\Support\AppError;

/**
 * Safe AI catalog: the ONLY data access layer the OpenAI integration may use
 * (port of src/lib/ai-catalog.server.ts).
 *
 * SECURITY: every query below lists explicit non-pricing columns. "select *"
 * is forbidden in this file, and the pricing columns of the parameters and
 * hardware tables must never appear here. Any future column added to those
 * tables is excluded by construction.
 */
final class SafeCatalog
{
    public function __construct(private readonly Db $db) {}

    /**
     * Loads the currently published dataset with non-pricing columns only.
     * Draft / validated / archived / failed versions are never included.
     *
     * @return array{labs: list<array>, datasetVersions: array<string, string>}
     */
    public function load(): array
    {
        // Failed reads must never masquerade as an empty/partial published catalog.
        try {
            $loaded = $this->db->withServiceContext(function (Tx $tx) {
                $versions = $tx->select("select id, workbook_type, version_number from public.dataset_versions where status = 'published'");
                if ($versions === []) {
                    return null;
                }
                $ids = '{'.implode(',', array_column($versions, 'id')).'}';

                return [
                    'versions' => $versions,
                    'labs' => $tx->select('select id, name, dataset_version_id, display_order from public.laboratories
                        where dataset_version_id = any(?::uuid[]) and active', [$ids]),
                    'subLabs' => $tx->select('select id, name, laboratory_id, display_order from public.sub_labs
                        where dataset_version_id = any(?::uuid[]) and active order by display_order', [$ids]),
                    'groups' => $tx->select('select id, sub_lab_id, laboratory_id, group_name, display_order from public.parameter_groups
                        where dataset_version_id = any(?::uuid[]) order by display_order', [$ids]),
                    'categories' => $tx->select('select id, sub_lab_id, laboratory_id, category_name, display_order from public.hardware_categories
                        where dataset_version_id = any(?::uuid[]) order by display_order', [$ids]),
                    // NOTE: the per-test pricing column is deliberately omitted.
                    'parameters' => $tx->select('select id, sub_lab_id, laboratory_id, parameter_group_id, parameter_name, optional_notes, display_order
                        from public.parameters
                        where dataset_version_id = any(?::uuid[]) and active order by display_order', [$ids]),
                    // NOTE: the hardware pricing column is deliberately omitted.
                    'hardware' => $tx->select('select id, sub_lab_id, laboratory_id, parameter_group_id, hardware_category_id, hardware_name, display_order
                        from public.hardware
                        where dataset_version_id = any(?::uuid[]) and active order by display_order', [$ids]),
                ];
            });
        } catch (\Throwable) {
            throw new AppError('Laboratory data is temporarily unavailable.');
        }
        if ($loaded === null) {
            return ['labs' => [], 'datasetVersions' => []];
        }

        $result = [];
        $datasetVersions = [];
        $of = fn (array $rows, string $labId) => array_values(array_filter($rows, fn (array $row) => $row['laboratory_id'] === $labId));
        foreach ($loaded['labs'] as $lab) {
            $version = null;
            foreach ($loaded['versions'] as $candidate) {
                if ($candidate['id'] === $lab['dataset_version_id']) {
                    $version = $candidate;
                    break;
                }
            }
            if ($version === null) {
                continue;
            }
            $labType = $version['workbook_type'];
            $labName = $lab['name'] ?? LabMeta::META[$labType]['name'];

            $labSubLabs = $of($loaded['subLabs'], $lab['id']);
            $labParams = $of($loaded['parameters'], $lab['id']);
            $labHardware = $of($loaded['hardware'], $lab['id']);
            $labGroups = $of($loaded['groups'], $lab['id']);
            $labCategories = $of($loaded['categories'], $lab['id']);

            $buildSection = function (?string $subLabId, string $name) use ($lab, $labParams, $labHardware, $labGroups, $labCategories) {
                $inSection = fn (array $row) => $row['sub_lab_id'] === $subLabId;
                $sectionParams = array_values(array_filter($labParams, $inSection));
                $sectionHardware = array_values(array_filter($labHardware, $inSection));
                $sectionGroups = [];
                foreach (array_filter($labGroups, $inSection) as $g) {
                    $parameterIds = array_column(array_filter($sectionParams, fn (array $p) => $p['parameter_group_id'] === $g['id']), 'id');
                    if ($parameterIds !== []) {
                        $sectionGroups[] = ['id' => $g['id'], 'name' => $g['group_name'], 'parameterIds' => $parameterIds];
                    }
                }

                return [
                    'key' => $subLabId ?? "lab:{$lab['id']}",
                    'subLabId' => $subLabId,
                    'name' => $name,
                    'hardwareOnly' => $sectionParams === [] && $sectionHardware !== [],
                    'supportsHardware' => $sectionHardware !== [],
                    'groups' => $sectionGroups,
                    'categories' => array_values(array_map(fn (array $c) => ['id' => $c['id'], 'name' => $c['category_name']], array_filter($labCategories, $inSection))),
                    'parameters' => array_map(fn (array $p) => ['id' => $p['id'], 'name' => $p['parameter_name'], 'groupId' => $p['parameter_group_id'], 'notes' => $p['optional_notes']], $sectionParams),
                    'hardware' => array_map(fn (array $h) => ['id' => $h['id'], 'name' => $h['hardware_name'], 'groupId' => $h['parameter_group_id'], 'categoryId' => $h['hardware_category_id']], $sectionHardware),
                ];
            };

            $sections = $labSubLabs !== []
                ? array_map(fn (array $s) => $buildSection($s['id'], $s['name']), $labSubLabs)
                : [$buildSection(null, $labName)];
            $usable = array_values(array_filter($sections, fn (array $s) => $s['parameters'] !== [] || $s['hardware'] !== []));
            if ($usable === []) {
                continue;
            }

            $datasetVersions[$lab['id']] = (string) $version['version_number'];
            $result[] = [
                'labId' => $lab['id'],
                'labType' => $labType,
                'labName' => $labName,
                'datasetVersionId' => $version['id'],
                'datasetVersionNumber' => (int) $version['version_number'],
                'flat' => $labSubLabs === [],
                'sections' => $usable,
            ];
        }

        usort($result, fn (array $a, array $b) => LabMeta::META[$a['labType']]['order'] <=> LabMeta::META[$b['labType']]['order']);

        return ['labs' => $result, 'datasetVersions' => $datasetVersions];
    }
}
