<?php

namespace App\Reports;

/**
 * Starts the sales report for a new submission. The calculator calls this
 * after its response has been sent; anything it does not finish stays pending
 * for the scheduled recovery sweep.
 */
final class AutomaticSalesReport implements SalesReportStarter
{
    public function __construct(private readonly SalesReports $reports) {}

    public function start(string $submissionId): void
    {
        $this->reports->runAutomatic($submissionId);
    }
}
