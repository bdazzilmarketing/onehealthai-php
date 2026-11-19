<?php

namespace App\Http\Controllers;

use App\Calculator\CalculatorService;
use App\Calculator\Selection;
use App\Http\Support\RawCookies;
use App\Http\Support\ServerFn;
use App\Leads\Lead;
use App\Leads\LeadSessions;
use App\Reports\SalesReportStarter;
use App\Submissions\Submissions;
use App\Support\AppError;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/** The public calculator endpoints (port of src/lib/calculator.functions.ts). */
final class CalculatorController
{
    /** Safe public metadata for rendering the Manual Calculator. No prices. */
    public function catalog(CalculatorService $calculator): Response
    {
        return ServerFn::respond(fn () => $calculator->getPublicCatalog());
    }

    /**
     * Final gate: lead details + completed configuration arrive together.
     * Order of operations is fixed: validate lead, deterministic calculation,
     * persist lead, persist submission, return public range, then (in the
     * background) internal PDF + sales email. Nothing is calculated, stored or
     * delivered until the lead validates.
     */
    public function estimate(Request $request, CalculatorService $calculator, LeadSessions $leads, Submissions $submissions, SalesReportStarter $reports): Response
    {
        $sessionId = null;
        $response = ServerFn::respond(function () use ($request, $calculator, $leads, $submissions, $reports, &$sessionId) {
            $raw = json_decode($request->getContent(), true);
            $raw = is_array($raw) ? $raw : [];
            $selection = Selection::parse($raw['selection'] ?? null);
            $lead = Lead::normalize($raw['lead'] ?? []);
            $mode = Lead::isCalculatorMode($raw['mode'] ?? null) ? $raw['mode'] : 'manual';

            $errors = Lead::validate($lead);
            if (count($errors) > 0) {
                return ['ok' => false, 'errors' => $errors, 'message' => null];
            }

            try {
                $outcome = $calculator->runCalculation($selection);
            } catch (AppError $error) {
                return ['ok' => false, 'errors' => null, 'message' => $error->getMessage()];
            } catch (\Throwable $error) {
                error_log('[calculation] failed '.$error::class);

                return ['ok' => false, 'errors' => null, 'message' => 'The estimate could not be calculated. Please review your configuration.'];
            }

            // Persist the lead and the immutable submission. The submission row is the
            // sales-report outbox (report_status/email_status start 'pending'): the
            // report is started without being awaited, and the scheduled recovery
            // sweep delivers anything this start does not finish. Nothing
            // report-related can affect this response.
            $referenceId = null;
            try {
                $session = $leads->upsert($lead, $mode, RawCookies::from($request)[LeadSessions::COOKIE_NAME] ?? null);
                $sessionId = $session['sessionId'];
                $result = $submissions->record($session, $selection, $outcome['internal'], $outcome['publicEstimate']);
                $referenceId = $result['referenceId'];
                if (! $referenceId) {
                    error_log('[submission:persist] submission was not stored');
                }
                if ($result['isNew'] && $result['submissionId']) {
                    $submissionId = $result['submissionId'];
                    app()->terminating(function () use ($reports, $submissionId) {
                        try {
                            $reports->start($submissionId);
                        } catch (\Throwable $error) {
                            error_log('[sales-report:start] deferred to recovery '.json_encode(['submissionId' => $submissionId, 'error' => $error::class]));
                        }
                    });
                }
            } catch (\Throwable $error) {
                error_log('[submission:persist] submission persistence failed '.$error::class);
            }

            return ['ok' => true, 'estimate' => $outcome['publicEstimate'], 'referenceId' => $referenceId];
        });

        if ($sessionId !== null) {
            $response->headers->setCookie(new Cookie(
                LeadSessions::COOKIE_NAME, $sessionId, time() + LeadSessions::COOKIE_MAX_AGE, '/', null, true, true, true, Cookie::SAMESITE_LAX,
            ));
        }

        return $response;
    }
}
