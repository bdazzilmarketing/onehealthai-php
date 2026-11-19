<?php

namespace App\Submissions;

use App\Database\Db;
use App\Database\Tx;
use App\Support\Audit;
use App\Support\Js;

/**
 * Server-only submission persistence (port of the write side of
 * src/lib/submissions.server.ts). Internal pricing snapshots and lead details
 * stay server-side and admin-only.
 */
final class Submissions
{
    public function __construct(private readonly Db $db, private readonly Audit $audit) {}

    public static function generateReference(): string
    {
        return 'OH-'.gmdate('Y').'-'.strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * Stable fingerprint of one completed calculator run, used for idempotency.
     * Byte-compatible with the original application.
     */
    public static function idempotencyKey(array $selection, array $internal): string
    {
        $labs = [];
        foreach ($selection['labs'] as $lab) {
            $sections = [];
            foreach ($lab['sections'] as $s) {
                $hardware = $s['selectedHardwareIds'];
                $parameters = $s['selectedParameterIds'];
                sort($hardware, SORT_STRING);
                sort($parameters, SORT_STRING);
                $sections[] = [
                    'subLabId' => $s['subLabId'],
                    'annualTestVolume' => $s['annualTestVolume'],
                    'includeHardware' => $s['includeHardware'],
                    'hardware' => $hardware,
                    'parameters' => $parameters,
                ];
            }
            usort($sections, fn ($a, $b) => strcmp(Js::string($a['subLabId']), Js::string($b['subLabId'])));
            $labs[] = ['labId' => $lab['labId'], 'sections' => $sections];
        }
        usort($labs, fn ($a, $b) => strcmp($a['labId'], $b['labId']));

        return hash('sha256', Js::stringify([
            'version' => $internal['calculationVersion'],
            'total' => $internal['internalTotal'],
            'datasetVersions' => (object) $internal['datasetVersions'],
            'labs' => $labs,
        ]));
    }

    /**
     * The immutable report payload stored with a submission.
     *
     * @param  array{referenceId: string, submittedAt: string, mode: string, lead: array, internal: array, publicEstimate: array}  $args
     */
    public static function buildMakePayload(array $args): array
    {
        $internal = $args['internal'];

        return [
            'referenceId' => $args['referenceId'],
            'submittedAt' => $args['submittedAt'],
            'mode' => $args['mode'],
            'lead' => $args['lead'],
            'estimate' => [
                'currency' => $internal['currency'],
                'publicLow' => $args['publicEstimate']['lowerRange'],
                'publicHigh' => $args['publicEstimate']['upperRange'],
                'internalTotal' => $internal['internalTotal'],
                'calculationVersion' => $internal['calculationVersion'],
            ],
            'datasetVersions' => (object) $internal['datasetVersions'],
            'labs' => array_map(fn ($lab) => [
                'labName' => $lab['labName'],
                'labType' => $lab['labType'],
                'labSubtotal' => $lab['subtotal'],
                'sections' => array_map(fn ($section) => [
                    'sectionName' => $section['subLabName'],
                    'annualTestVolume' => $section['annualTestVolume'],
                    'hardware' => array_map(fn ($h) => [
                        'hardwareId' => $h['hardwareId'],
                        'hardwareName' => $h['hardwareName'],
                        'price' => $h['price'],
                    ], $section['hardware']),
                    'parameters' => array_map(fn ($p) => [
                        'parameterId' => $p['parameterId'],
                        'parameterName' => $p['parameterName'],
                        'pricePerTest' => $p['pricePerTest'],
                        'allocatedAnnualVolume' => $p['allocatedAnnualVolume'],
                        'annualCalculatedCost' => $p['annualCalculatedCost'],
                    ], $section['parameters']),
                    'parameterSubtotal' => $section['parameterSubtotal'],
                    'hardwareSubtotal' => $section['hardwareSubtotal'],
                    'subtotal' => $section['subtotal'],
                ], $lab['subLabs']),
            ], $internal['labs']),
        ];
    }

    /**
     * Persists one completed calculator run. Never throws into the public
     * flow: a storage failure must not prevent the visitor from seeing their
     * approved public estimate.
     *
     * @return array{referenceId: ?string, submissionId: ?string, isNew: bool}
     */
    public function record(array $session, array $selection, array $internal, array $publicEstimate): array
    {
        try {
            $key = self::idempotencyKey($selection, $internal);
            $findExisting = function () use ($session, $key) {
                try {
                    $rows = $this->db->withServiceContext(fn (Tx $tx) => $tx->select(
                        'select id, reference_id from public.calculator_submissions
                         where calculator_session_id = ? and idempotency_key = ?',
                        [$session['sessionId'], $key],
                    ));

                    return count($rows) === 1 ? $rows[0] : null;
                } catch (\Throwable) {
                    return null;
                }
            };

            // Duplicate protection: double-clicks, browser retries and network retries
            // all resolve to the same fingerprint for the same session.
            $existing = $findExisting();
            if ($existing) {
                return ['referenceId' => $existing['reference_id'], 'submissionId' => $existing['id'], 'isNew' => false];
            }

            $referenceId = self::generateReference();
            $payload = self::buildMakePayload([
                'referenceId' => $referenceId,
                'submittedAt' => Js::isoNow(),
                'mode' => $session['selectedMode'],
                'lead' => [
                    'fullName' => $session['fullName'],
                    'company' => $session['company'],
                    'phone' => $session['phone'],
                    'email' => $session['email'],
                ],
                'internal' => $internal,
                'publicEstimate' => $publicEstimate,
            ]);
            $snapshot = $internal;
            $snapshot['datasetVersions'] = (object) $internal['datasetVersions'];

            $error = null;
            $inserted = null;
            try {
                $inserted = $this->db->withServiceContext(fn (Tx $tx) => $tx->first(
                    "insert into public.calculator_submissions (
                        reference_id, calculator_session_id, idempotency_key, selected_mode,
                        full_name, company, phone, email,
                        calculation_version, calculation_id, currency, internal_total, public_low, public_high,
                        dataset_versions, configuration_snapshot, internal_calculation_snapshot, make_payload_snapshot,
                        make_delivery_status, report_status, email_status)
                     values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, ?::jsonb, ?::jsonb, ?::jsonb,
                        'disabled', 'pending', 'pending')
                     returning id, reference_id",
                    [
                        $referenceId, $session['sessionId'], $key, $session['selectedMode'],
                        $session['fullName'], $session['company'], $session['phone'], $session['email'],
                        $internal['calculationVersion'], $internal['calculationId'], $internal['currency'],
                        (float) $internal['internalTotal'], (float) $publicEstimate['lowerRange'], (float) $publicEstimate['upperRange'],
                        Js::stringify((object) $internal['datasetVersions']),
                        Js::stringify($selection),
                        Js::stringify($snapshot),
                        // Automatic Make.com delivery is retired; the native sales-report
                        // workflow (PDF + built-in email) runs after the response.
                        Js::stringify($payload),
                    ],
                ));
            } catch (\Throwable $caught) {
                $error = $caught;
            }

            if ($error || ! $inserted) {
                // Unique violation = a concurrent duplicate won the race.
                if ($error && (string) $error->getCode() === '23505') {
                    $race = $findExisting();

                    return ['referenceId' => $race['reference_id'] ?? null, 'submissionId' => $race['id'] ?? null, 'isNew' => false];
                }
                error_log('[submissions] persist failed '.json_encode(['code' => $error?->getCode()]));

                return ['referenceId' => null, 'submissionId' => null, 'isNew' => false];
            }

            $this->audit->record('submission_created', details: [
                'referenceId' => $referenceId,
                'submissionId' => $inserted['id'],
                'mode' => $session['selectedMode'],
                'currency' => $internal['currency'],
            ]);

            return ['referenceId' => $referenceId, 'submissionId' => $inserted['id'], 'isNew' => true];
        } catch (\Throwable $error) {
            error_log('[submissions] unexpected persistence error '.$error::class);

            return ['referenceId' => null, 'submissionId' => null, 'isNew' => false];
        }
    }
}
