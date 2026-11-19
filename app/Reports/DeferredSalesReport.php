<?php

namespace App\Reports;

/** Leaves the submission pending for the scheduled recovery sweep. */
final class DeferredSalesReport implements SalesReportStarter
{
    public function start(string $submissionId): void {}
}
