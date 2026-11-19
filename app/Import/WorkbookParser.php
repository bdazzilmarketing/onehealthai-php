<?php

namespace App\Import;

use App\Calculator\LabMeta;
use App\Support\AppError;
use App\Support\Js;

/**
 * Excel workbook parser and validation engine (port of
 * src/lib/import-parser.server.ts). The result has the same shape, key order
 * and messages as the original, because it is stored as the version's
 * validation report and shown to admins as is.
 */
final class WorkbookParser
{
    private const HARDWARE_SHEET = 'hardware';

    private const HISTO_HARDWARE_SHEET = 'histopathology hardware';

    /**
     * POCT sections that do not depend on hardware. Their sheets keep the
     * layout of the other POCT parameter tabs, but a blank Parameter Group
     * (column A) is valid there. An allow-list: blank groups anywhere else
     * stay a critical error.
     */
    private const HARDWARE_INDEPENDENT_SHEETS = ['rapid diagnostic test kits'];

    private const SHEET_NAME_ALIASES = ['rapid diagostic test kits' => 'rapid diagnostic test kits'];

    private string $labCode;

    private array $issues = [];

    private array $subLabs = [];

    private array $groups = [];

    private array $categories = [];

    private array $hardware = [];

    private array $parameters = [];

    private function __construct() {}

    /** Workbook tab name as used for classification checks. */
    public static function normalizeSheetName(string $sheetName): string
    {
        $normalized = WorkbookTypes::normalizeKey($sheetName);

        return self::SHEET_NAME_ALIASES[$normalized] ?? $normalized;
    }

    /** @param  list<string>  $sheetNames */
    public static function detectWorkbookType(array $sheetNames): ?string
    {
        $names = array_map(WorkbookTypes::normalizeKey(...), $sheetNames);
        if (in_array(self::HISTO_HARDWARE_SHEET, $names, true) || in_array('chemistry', $names, true)) {
            return 'core_lab';
        }
        foreach ($names as $name) {
            if (str_contains($name, 'poc')) {
                return 'poct';
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed> the parsed workbook
     *
     * @throws AppError when the file is not a readable workbook
     */
    public static function parse(string $bytes, string $workbookType): array
    {
        try {
            $workbook = XlsxReader::read($bytes);
        } catch (\Throwable) {
            throw new AppError('The workbook could not be read. It may be corrupted or not a valid .xlsx file.');
        }

        return (new self)->run($workbook, $workbookType);
    }

    private function run(XlsxReader $workbook, string $workbookType): array
    {
        $meta = LabMeta::META[$workbookType];
        $this->labCode = $meta['code'];
        $sheetNames = $workbook->sheetNames;

        $detected = self::detectWorkbookType($sheetNames);
        if ($detected !== null && $detected !== $workbookType) {
            $this->issues[] = [
                'severity' => 'warning',
                'sheet' => '(workbook)',
                'message' => "Workbook structure looks like \"{$detected}\" but \"{$workbookType}\" was selected",
                'suggestion' => 'Confirm the selected workbook type is correct',
            ];
        }

        $sheetsByNorm = [];
        foreach ($sheetNames as $name) {
            $sheetsByNorm[self::normalizeSheetName($name)] = $name;
        }

        if ($workbookType === 'core_lab' || $workbookType === 'poct') {
            if ($workbookType === 'core_lab') {
                foreach (['chemistry', 'immunology'] as $required) {
                    if (! isset($sheetsByNorm[$required])) {
                        $this->issues[] = [
                            'severity' => 'critical',
                            'sheet' => $required,
                            'message' => "Required sheet \"{$required}\" is missing from the Core Lab workbook",
                        ];
                    }
                }
                if (! isset($sheetsByNorm['cogulation']) && ! isset($sheetsByNorm['coagulation'])) {
                    $this->issues[] = [
                        'severity' => 'critical',
                        'sheet' => 'Coagulation',
                        'message' => 'Required Coagulation sheet is missing (accepted spellings: "Cogulation", "Coagulation")',
                    ];
                }
            }

            foreach ($sheetNames as $sheetName) {
                $norm = self::normalizeSheetName($sheetName);
                if ($norm === self::HARDWARE_SHEET || $norm === self::HISTO_HARDWARE_SHEET) {
                    continue;
                }
                $label = WorkbookTypes::subLabLabel($sheetName);
                $code = WorkbookTypes::slugCode($label);
                $this->addSubLab($code, $label, $sheetName);
                $requiresHardware = $workbookType !== 'poct' || ! in_array($norm, self::HARDWARE_INDEPENDENT_SHEETS, true);
                $this->parseParameterSheet($sheetName, self::sheetRows($workbook, $sheetName), $code, $requiresHardware);
            }

            $hardwareSheet = $sheetsByNorm[self::HARDWARE_SHEET] ?? null;
            if ($hardwareSheet !== null && $hardwareSheet !== '') {
                $this->parseHardwareSheet($hardwareSheet, self::sheetRows($workbook, $hardwareSheet));
            } else {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => 'Hardware',
                    'message' => 'Required Hardware sheet is missing',
                ];
            }

            $histoSheet = $sheetsByNorm[self::HISTO_HARDWARE_SHEET] ?? null;
            if ($histoSheet !== null && $histoSheet !== '') {
                $this->parseHistopathologySheet($histoSheet, self::sheetRows($workbook, $histoSheet));
            } elseif ($workbookType === 'core_lab') {
                $this->issues[] = [
                    'severity' => 'warning',
                    'sheet' => 'Histopathology Hardware',
                    'message' => 'No Histopathology Hardware sheet found in this Core Lab workbook',
                ];
            }
        } else {
            $sheetName = $sheetsByNorm['sheet1'] ?? ($sheetNames[0] ?? null);
            if ($sheetName === null || $sheetName === '') {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => '(workbook)',
                    'message' => 'Workbook contains no sheets',
                ];
            } else {
                $this->parseFlatSheet($sheetName, self::sheetRows($workbook, $sheetName));
            }
        }

        // Parameter Groups no hardware refers to: a warning, never removed.
        foreach ($this->groups as $group) {
            $referenced = false;
            foreach ($this->hardware as $item) {
                if ($item['groupNormalized'] === $group['normalized'] && $item['subLabCode'] === $group['subLabCode']) {
                    $referenced = true;
                    break;
                }
            }
            if (! $referenced) {
                $this->issues[] = [
                    'severity' => 'warning',
                    'sheet' => $group['groupName'],
                    'message' => "Parameter Group \"{$group['groupName']}\" is not referenced by any hardware item",
                    'suggestion' => 'Confirm this group is intentional; it will still be imported',
                ];
            }
        }

        $criticalErrors = count(array_filter($this->issues, fn (array $issue) => $issue['severity'] === 'critical'));

        return [
            'workbookType' => $workbookType,
            'laboratories' => [['code' => $meta['code'], 'name' => $meta['name'], 'order' => $meta['order']]],
            'subLabs' => $this->subLabs,
            'parameterGroups' => $this->groups,
            'hardwareCategories' => $this->categories,
            'hardware' => $this->hardware,
            'parameters' => $this->parameters,
            'issues' => $this->issues,
            'summary' => [
                'laboratories' => 1,
                'subLabs' => count($this->subLabs),
                'parameterGroups' => count($this->groups),
                'parameters' => count($this->parameters),
                'hardware' => count($this->hardware),
                'hardwareCategories' => count($this->categories),
                'criticalErrors' => $criticalErrors,
                'warnings' => count($this->issues) - $criticalErrors,
            ],
        ];
    }

    // ---------------------------------------------------------------- sheets

    /** Parameter sheet: A = Parameter Group, B = Parameter/Test, C = Price, D = optional notes */
    private function parseParameterSheet(string $sheetName, array $rows, ?string $subLabCode, bool $requiresHardware): void
    {
        if ($rows === []) {
            $this->issues[] = [
                'severity' => 'critical',
                'sheet' => $sheetName,
                'message' => 'Required sheet is empty',
                'suggestion' => 'Provide parameter rows or remove the sheet',
            ];

            return;
        }
        $index = 0;
        $seen = [];
        foreach ($rows as $i => $row) {
            $excelRow = $i + 1;
            if ($i === 0 && self::isHeaderRow($row)) {
                continue;
            }
            $groupName = self::cellText($row[0] ?? null);
            $parameterName = self::cellText($row[1] ?? null);
            $notes = self::cellText($row[3] ?? null);
            if ($groupName === '' && $parameterName === '') {
                continue;
            }
            if ($parameterName === '') {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Parameter / Test',
                    'message' => 'Missing parameter name',
                    'suggestion' => 'Add the test name or delete the row',
                ];

                continue;
            }
            if ($groupName === '' && $requiresHardware) {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Parameter Group',
                    'message' => 'Missing Parameter Group',
                    'suggestion' => 'Column A must contain the Parameter Group name',
                ];

                continue;
            }
            // Hardware-independent sections may leave the group out.
            $groupNormalized = $groupName === '' ? null : $this->addGroup($subLabCode, $groupName);
            $key = ($groupNormalized ?? '').'|'.WorkbookTypes::normalizeKey($parameterName);
            if (isset($seen[$key])) {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Parameter / Test',
                    'message' => "Duplicate parameter \"{$parameterName}\"".($groupName !== '' ? " within group \"{$groupName}\"" : ''),
                    'suggestion' => 'Remove the duplicate row',
                ];

                continue;
            }
            $seen[$key] = true;
            $price = $this->price($row[2] ?? null, 'Price Per Test', $sheetName, $excelRow);
            $this->parameters[] = [
                'labCode' => $this->labCode,
                'subLabCode' => $subLabCode,
                'groupNormalized' => $groupNormalized,
                'parameterName' => $parameterName,
                'pricePerTest' => $price,
                'optionalNotes' => $notes !== '' ? $notes : null,
                'sourceSheet' => $sheetName,
                'sourceRow' => $excelRow,
                'order' => $index++,
            ];
        }
    }

    /** Hardware sheet: A = Lab Type, B = Hardware, C = Paramater Group, D = Price */
    private function parseHardwareSheet(string $sheetName, array $rows): void
    {
        if ($rows === []) {
            $this->issues[] = ['severity' => 'critical', 'sheet' => $sheetName, 'message' => 'Hardware sheet is empty'];

            return;
        }
        $index = 0;
        foreach ($rows as $i => $row) {
            $excelRow = $i + 1;
            if ($i === 0 && self::isHeaderRow($row)) {
                continue;
            }
            $labType = self::cellText($row[0] ?? null);
            $hardwareName = self::cellText($row[1] ?? null);
            $groupName = self::cellText($row[2] ?? null);
            if ($hardwareName === '' && $groupName === '' && $labType === '') {
                continue;
            }
            if ($hardwareName === '') {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Hardware',
                    'message' => 'Missing hardware name',
                ];

                continue;
            }
            $subLabCode = null;
            if ($labType !== '') {
                $label = WorkbookTypes::subLabLabel($labType);
                $subLabCode = WorkbookTypes::slugCode($label);
                $this->addSubLab($subLabCode, $label, $sheetName);
            }
            $groupNormalized = null;
            if ($groupName !== '') {
                $groupNormalized = WorkbookTypes::normalizeKey($groupName);
                $match = false;
                $anyLab = false;
                foreach ($this->groups as $group) {
                    if ($group['normalized'] === $groupNormalized) {
                        $anyLab = true;
                        $match = $match || $group['subLabCode'] === $subLabCode;
                    }
                }
                if (! $match) {
                    $this->issues[] = $anyLab ? [
                        'severity' => 'critical',
                        'sheet' => $sheetName,
                        'row' => $excelRow,
                        'field' => 'Paramater Group',
                        'message' => "Parameter Group \"{$groupName}\" exists but belongs to a different sub-lab",
                        'suggestion' => 'Check the Lab Type column matches the parameter sheet',
                    ] : [
                        'severity' => 'critical',
                        'sheet' => $sheetName,
                        'row' => $excelRow,
                        'field' => 'Paramater Group',
                        'message' => "Hardware references non-existent Parameter Group \"{$groupName}\"",
                        'suggestion' => 'Add the group to the relevant parameter sheet or fix the spelling',
                    ];
                }
            } else {
                $this->issues[] = [
                    'severity' => 'warning',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Paramater Group',
                    'message' => "Hardware \"{$hardwareName}\" has no Parameter Group",
                ];
            }
            $price = $this->price($row[3] ?? null, 'Price', $sheetName, $excelRow);
            $this->hardware[] = [
                'labCode' => $this->labCode,
                'subLabCode' => $subLabCode,
                'hardwareName' => $hardwareName,
                'groupNormalized' => $groupNormalized,
                'categoryNormalized' => null,
                'price' => $price,
                'sourceSheet' => $sheetName,
                'sourceRow' => $excelRow,
                'order' => $index++,
            ];
        }
    }

    /** Histopathology Hardware sheet: A = Category, B = Hardware, C = Price */
    private function parseHistopathologySheet(string $sheetName, array $rows): void
    {
        if ($rows === []) {
            $this->issues[] = ['severity' => 'critical', 'sheet' => $sheetName, 'message' => 'Histopathology Hardware sheet is empty'];

            return;
        }
        $subLabCode = 'histopathology';
        $this->addSubLab($subLabCode, 'Histopathology', $sheetName);
        $index = 0;
        $currentCategory = '';
        foreach ($rows as $i => $row) {
            $excelRow = $i + 1;
            if ($i === 0 && self::isHeaderRow($row)) {
                continue;
            }
            $categoryName = self::cellText($row[0] ?? null);
            $hardwareName = self::cellText($row[1] ?? null);
            if ($categoryName !== '') {
                $currentCategory = $categoryName;
            }
            if ($hardwareName === '') {
                continue;
            }
            if ($currentCategory === '') {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Category',
                    'message' => "Hardware \"{$hardwareName}\" has no Hardware Category",
                    'suggestion' => 'Every Histopathology hardware item must belong to a category',
                ];

                continue;
            }
            $normalized = WorkbookTypes::normalizeKey($currentCategory);
            if (! in_array($normalized, array_column($this->categories, 'normalized'), true)) {
                $this->categories[] = [
                    'labCode' => $this->labCode,
                    'subLabCode' => $subLabCode,
                    'categoryName' => $currentCategory,
                    'normalized' => $normalized,
                    'order' => count($this->categories),
                ];
            }
            $price = $this->price($row[2] ?? null, 'Price', $sheetName, $excelRow);
            $this->hardware[] = [
                'labCode' => $this->labCode,
                'subLabCode' => $subLabCode,
                'hardwareName' => $hardwareName,
                'groupNormalized' => null,
                'categoryNormalized' => $normalized,
                'price' => $price,
                'sourceSheet' => $sheetName,
                'sourceRow' => $excelRow,
                'order' => $index++,
            ];
        }
    }

    /** Flat lab sheet: A = Parameter/Test, B = Price Per Test */
    private function parseFlatSheet(string $sheetName, array $rows): void
    {
        if ($rows === []) {
            $this->issues[] = ['severity' => 'critical', 'sheet' => $sheetName, 'message' => 'Required sheet is empty'];

            return;
        }
        $index = 0;
        $seen = [];
        foreach ($rows as $i => $row) {
            $excelRow = $i + 1;
            if ($i === 0 && self::isHeaderRow($row)) {
                continue;
            }
            $parameterName = self::cellText($row[0] ?? null);
            if ($parameterName === '') {
                continue;
            }
            $key = WorkbookTypes::normalizeKey($parameterName);
            if (isset($seen[$key])) {
                $this->issues[] = [
                    'severity' => 'critical',
                    'sheet' => $sheetName,
                    'row' => $excelRow,
                    'field' => 'Parameter / Test',
                    'message' => "Duplicate parameter \"{$parameterName}\"",
                ];

                continue;
            }
            $seen[$key] = true;
            $price = $this->price($row[1] ?? null, 'Price Per Test', $sheetName, $excelRow);
            $notes = self::cellText($row[2] ?? null);
            $this->parameters[] = [
                'labCode' => $this->labCode,
                'subLabCode' => null,
                'groupNormalized' => null,
                'parameterName' => $parameterName,
                'pricePerTest' => $price,
                'optionalNotes' => $notes !== '' ? $notes : null,
                'sourceSheet' => $sheetName,
                'sourceRow' => $excelRow,
                'order' => $index++,
            ];
        }
    }

    // --------------------------------------------------------------- helpers

    private function addSubLab(string $code, string $name, string $sourceSheet): void
    {
        if (in_array($code, array_column($this->subLabs, 'code'), true)) {
            return;
        }
        $this->subLabs[] = [
            'labCode' => $this->labCode,
            'code' => $code,
            'name' => $name,
            'sourceSheet' => $sourceSheet,
            'order' => count($this->subLabs),
        ];
    }

    /** @return string the group's normalized name */
    private function addGroup(?string $subLabCode, string $name): string
    {
        $normalized = WorkbookTypes::normalizeKey($name);
        foreach ($this->groups as $group) {
            if ($group['normalized'] === $normalized && $group['subLabCode'] === $subLabCode) {
                return $normalized;
            }
        }
        $this->groups[] = [
            'labCode' => $this->labCode,
            'subLabCode' => $subLabCode,
            'groupName' => $name,
            'normalized' => $normalized,
            'order' => count($this->groups),
        ];

        return $normalized;
    }

    /** Reads a price cell, recording an issue when it is missing, not a number or negative. */
    private function price(mixed $raw, string $field, string $sheetName, int $excelRow): ?float
    {
        $shown = self::cellText($raw);
        $text = preg_replace('/^AED/i', '', str_replace([',', ' '], '', $shown));
        $problem = null;
        if ($text === '') {
            $problem = ['Missing required price value', 'Add a numeric price in the source workbook before publishing'];
        } else {
            $number = self::toNumber($text);
            if (! is_finite($number)) {
                $problem = ["Invalid numeric price \"{$shown}\"", 'Use a plain number without text or symbols'];
            } elseif ($number < 0) {
                $problem = ['Negative price ('.Js::number($number).') is not allowed', 'Correct the price in the source workbook'];
            } else {
                return $number;
            }
        }
        $this->issues[] = [
            'severity' => 'critical',
            'field' => $field,
            'message' => $problem[0],
            'suggestion' => $problem[1],
            'sheet' => $sheetName,
            'row' => $excelRow,
        ];

        return null;
    }

    /** Number(text), including the 0x / 0o / 0b forms. */
    private static function toNumber(string $text): float
    {
        if (preg_match('/^0[xX]([0-9a-fA-F]+)$/', $text, $m)) {
            return (float) hexdec($m[1]);
        }
        if (preg_match('/^0[oO]([0-7]+)$/', $text, $m)) {
            return (float) octdec($m[1]);
        }
        if (preg_match('/^0[bB]([01]+)$/', $text, $m)) {
            return (float) bindec($m[1]);
        }

        return Js::toNumber($text);
    }

    private static function cellText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return Js::collapseSpaces(Js::trim(Js::string($value)));
    }

    /** @return list<list<mixed>> the sheet's rows that hold any text */
    private static function sheetRows(XlsxReader $workbook, string $name): array
    {
        return array_values(array_filter(
            $workbook->rows($name),
            function (array $row) {
                foreach ($row as $cell) {
                    if (self::cellText($cell) !== '') {
                        return true;
                    }
                }

                return false;
            },
        ));
    }

    private static function isHeaderRow(array $row): bool
    {
        $joined = implode('|', array_map(fn ($cell) => WorkbookTypes::normalizeKey(self::cellText($cell)), $row));

        return str_contains($joined, 'lab type')
            || str_contains($joined, 'paramater group')
            || str_contains($joined, 'parameter group')
            || str_contains($joined, 'price per test')
            || (str_contains($joined, 'hardware') && str_contains($joined, 'price'))
            || (str_contains($joined, 'category') && str_contains($joined, 'price'));
    }
}
