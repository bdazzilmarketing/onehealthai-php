<?php

namespace App\Calculator;

/** The five laboratory workbooks (port of the lab constants in src/lib/workbook-types.ts). */
final class LabMeta
{
    public const TYPES = ['core_lab', 'poct', 'molecular', 'microbiology', 'genetics'];

    public const LABELS = [
        'core_lab' => 'Core Lab',
        'poct' => 'Point of Care Testing (POCT)',
        'molecular' => 'Molecular Lab',
        'microbiology' => 'Microbiology Lab',
        'genetics' => 'Genetics Lab',
    ];

    public const META = [
        'core_lab' => ['code' => 'core_lab', 'name' => 'Core Lab', 'order' => 1],
        'poct' => ['code' => 'poct', 'name' => 'Point of Care Testing', 'order' => 2],
        'molecular' => ['code' => 'molecular', 'name' => 'Molecular Lab', 'order' => 3],
        'microbiology' => ['code' => 'microbiology', 'name' => 'Microbiology Lab', 'order' => 4],
        'genetics' => ['code' => 'genetics', 'name' => 'Genetics Lab', 'order' => 5],
    ];
}
