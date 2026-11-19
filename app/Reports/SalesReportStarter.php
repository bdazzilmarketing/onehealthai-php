<?php

namespace App\Reports;

/**
 * Best-effort immediate start of the sales report for a new submission. The
 * submission row is the outbox (report_status / email_status start 'pending'),
 * and the scheduled recovery sweep is the guarantee.
 */
interface SalesReportStarter
{
    public function start(string $submissionId): void;
}
