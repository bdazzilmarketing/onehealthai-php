<?php

namespace App\Import;

use App\Support\Js;

/** Name normalisation shared by the importer and the catalog (port of the helpers in src/lib/workbook-types.ts). */
final class WorkbookTypes
{
    /**
     * Sheet name -> public sub-lab label. The source Core Lab workbook
     * misspells "Coagulation" as "Cogulation"; both map to the correct label.
     */
    public const SUB_LAB_LABEL_OVERRIDES = [
        'cogulation' => 'Coagulation',
        'coagulation' => 'Coagulation',
        'chemistry' => 'Chemistry',
        'immunology' => 'Immunology',
        'histopathology' => 'Histopathology',
        'histopathology hardware' => 'Histopathology',
        'blood gas portable poc' => 'Blood Gas — Portable (POC)',
        'blood gas benchtop poc' => 'Blood Gas — Benchtop (POC)',
        'urine analyzer - poc' => 'Urine Analyzer (POC)',
        'hba1c analyzer - poc' => 'HbA1c Analyzer (POC)',
        'glucometer - poc' => 'Glucometer (POC)',
        'pcr- respiratory tests - poc' => 'PCR — Respiratory Tests (POC)',
        'rapid diagostic test kits' => 'Rapid Diagnostic Test Kits',
        'rapid diagnostic test kits' => 'Rapid Diagnostic Test Kits',
    ];

    /** Matching value: trimmed, white space collapsed, lower-cased. */
    public static function normalizeKey(string $value): string
    {
        return mb_strtolower(Js::collapseSpaces(Js::trim($value)), 'UTF-8');
    }

    public static function slugCode(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '_', self::normalizeKey($value)), '_');
    }

    public static function subLabLabel(string $sheetName): string
    {
        return self::SUB_LAB_LABEL_OVERRIDES[self::normalizeKey($sheetName)] ?? Js::trim($sheetName);
    }
}
