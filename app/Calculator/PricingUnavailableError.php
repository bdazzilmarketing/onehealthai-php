<?php

namespace App\Calculator;

/**
 * Defensive control: a selected item has no valid published price.
 * The public message never contains pricing or database detail; `issues`
 * stays server-side for troubleshooting.
 */
final class PricingUnavailableError extends CalculationError
{
    public const MESSAGE = "We're unable to calculate this configuration because some pricing information is currently unavailable. Please try again later.";

    /**
     * @param  list<array{kind: string, itemId: string, itemName: string, labId: string, labType: string, datasetVersionId: string, datasetVersionNumber: int, reason: string, rawValue: string}>  $issues
     */
    public function __construct(public readonly array $issues)
    {
        parent::__construct(self::MESSAGE);
    }
}
