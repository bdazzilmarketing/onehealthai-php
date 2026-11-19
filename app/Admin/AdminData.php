<?php

namespace App\Admin;

use App\Calculator\LabMeta;
use App\Database\Db;
use App\Database\Tx;
use App\Database\UserScope;
use App\Import\WorkbookParser;
use App\Storage\FileStore;
use App\Support\AppError;
use App\Support\Audit;
use App\Support\Js;

/**
 * Data access for the Admin Portal (port of src/lib/admin.server.ts, without
 * the role gates and account administration, which live in App\Auth\Roles and
 * AdminUsers). Reads and writes made on behalf of the signed-in user run in
 * that user's database context, so row-level security still decides what they
 * may touch; only audit logging uses the service context.
 */
final class AdminData
{
    public const IMPORT_BUCKET = 'dataset-imports';

    /**
     * @param  \Closure(): FileStore  $files
     * @param  \Closure(string): ?string  $env
     */
    public function __construct(
        private readonly Audit $audit,
        private readonly \Closure $files,
        private readonly \Closure $env,
    ) {}

    public function getCurrentUserProfile(UserScope $db, string $userId): ?array
    {
        try {
            $rows = $db->run(fn (Tx $tx) => $tx->rows('select id, email, full_name from public.profiles where id = ?', [$userId]));

            return count($rows) === 1 ? $rows[0] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ----------------------------------------------------------------- audit

    public function listAuditLog(UserScope $db, int $limit = 200): array
    {
        return self::guard('Unable to load audit log', fn () => $db->run(
            fn (Tx $tx) => $tx->rows('select * from public.audit_log order by created_at desc limit ?', [$limit]),
        ));
    }

    // ------------------------------------------------------ dataset versions

    public function listDatasetVersions(UserScope $db): array
    {
        return self::guard('Unable to load dataset versions', fn () => $db->run(
            fn (Tx $tx) => $tx->rows('select * from public.dataset_versions order by uploaded_at desc'),
        ));
    }

    public function getDatasetVersion(UserScope $db, string $id): ?array
    {
        return self::guard('Unable to load dataset version', fn () => $db->run(fn (Tx $tx) => self::readDatasetVersion($tx, $id)));
    }

    public function getPublishedVersions(UserScope $db): array
    {
        return self::guard('Unable to load published datasets', fn () => $db->run(
            fn (Tx $tx) => $tx->rows("select * from public.dataset_versions where status = 'published'"),
        ));
    }

    private static function readDatasetVersion(Tx $tx, string $id): ?array
    {
        return $tx->rows('select * from public.dataset_versions where id = ?', [$id])[0] ?? null;
    }

    /**
     * Upload, parse, validate. Creates a draft dataset version; never publishes.
     *
     * @param  array{workbookType: string, filename: string, bytes: string, notes: ?string}  $input
     * @return array{versionId: string, summary: ?array, issues: array}
     */
    public function importWorkbook(UserScope $db, string $userId, ?string $userEmail, array $input): array
    {
        $bytes = $input['bytes'];
        $type = $input['workbookType'];
        $checksum = hash('sha256', $bytes);
        $versionNumber = (int) $db->run(fn (Tx $tx) => $tx->value(
            'select version_number from public.dataset_versions where workbook_type = ? order by version_number desc limit 1',
            [$type],
        )) + 1;
        // The original filename is kept in the database; the object key uses a safe form of it.
        $safeName = substr(preg_replace('/^\.+/', '_', preg_replace('/[^A-Za-z0-9._-]+/', '_', $input['filename'])), 0, 120);
        $storagePath = "{$type}/v{$versionNumber}-".(int) floor(microtime(true) * 1000)."-{$safeName}";

        try {
            ($this->files)()->put(self::IMPORT_BUCKET, $storagePath, $bytes, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        } catch (\Throwable $error) {
            error_log('[import] file store put failed '.$error::class);
            throw new AppError('Unable to store the uploaded workbook');
        }

        try {
            $version = $db->run(fn (Tx $tx) => $tx->rows(
                'insert into public.dataset_versions
                   (workbook_type, version_number, status, original_filename, storage_path, file_size, checksum, uploaded_by, notes)
                 values (?, ?, ?, ?, ?, ?, ?, ?, ?) returning *',
                [$type, $versionNumber, 'validating', $input['filename'], $storagePath, strlen($bytes), $checksum, $userId, $input['notes']],
            ))[0] ?? null;
        } catch (\Throwable) {
            $version = null;
        }
        if ($version === null) {
            throw new AppError('Unable to create the draft dataset version');
        }
        $versionId = $version['id'];

        // The file register is informational; a failure here does not stop the import.
        try {
            $db->run(fn (Tx $tx) => $tx->execute(
                'insert into public.uploaded_files
                   (dataset_version_id, workbook_type, filename, storage_path, file_size, file_type, checksum, uploaded_by)
                 values (?, ?, ?, ?, ?, ?, ?, ?)',
                [$versionId, $type, $input['filename'], $storagePath, strlen($bytes), 'xlsx', $checksum, $userId],
            ));
        } catch (\Throwable $error) {
            error_log('[import] uploaded_files insert failed '.json_encode(['code' => Db::errorCode($error)]));
        }

        $this->audit->record('dataset.upload', $userId, $userEmail, [
            'workbook_type' => $type,
            'version_number' => $versionNumber,
            'filename' => $input['filename'],
            'checksum' => $checksum,
        ]);

        try {
            $parsed = WorkbookParser::parse($bytes, $type);
        } catch (\Throwable $error) {
            $message = $error instanceof AppError ? $error->getMessage() : 'Workbook could not be parsed';
            $issues = [['severity' => 'critical', 'sheet' => '(workbook)', 'message' => $message]];
            $db->run(fn (Tx $tx) => $tx->execute(
                "update public.dataset_versions set status = 'validation_failed', validated_at = now(),
                   validation_summary = ?::jsonb, validation_issues = ?::jsonb where id = ?",
                [Js::stringify(['criticalErrors' => 1, 'warnings' => 0]), Js::stringify($issues), $versionId],
            ));
            $this->audit->record('dataset.validation_failed', $userId, $userEmail, ['version_id' => $versionId, 'reason' => $message]);

            return ['versionId' => $versionId, 'summary' => null, 'issues' => $issues];
        }

        $this->persistParsedWorkbook($db, $versionId, $parsed);

        $status = $parsed['summary']['criticalErrors'] > 0 ? 'validation_failed' : 'validated';
        $db->run(fn (Tx $tx) => $tx->execute(
            'update public.dataset_versions set status = ?, validated_at = now(),
               validation_summary = ?::jsonb, validation_issues = ?::jsonb, updated_at = now() where id = ?',
            [$status, Js::stringify($parsed['summary']), Js::stringify($parsed['issues']), $versionId],
        ));

        $this->audit->record(
            $status === 'validated' ? 'dataset.validated' : 'dataset.validation_failed',
            $userId, $userEmail, ['version_id' => $versionId, 'summary' => $parsed['summary']],
        );

        return ['versionId' => $versionId, 'summary' => $parsed['summary'], 'issues' => $parsed['issues']];
    }

    private function persistParsedWorkbook(UserScope $db, string $versionId, array $parsed): void
    {
        $meta = LabMeta::META[$parsed['workbookType']];
        // One transaction: a failure leaves no partial catalog behind.
        $db->run(function (Tx $tx) use ($versionId, $parsed, $meta) {
            $insert = function (string $message, string $table, array $rows, string $returning = '') use ($tx): array {
                try {
                    return self::insertMany($tx, $table, $rows, $returning);
                } catch (\Throwable $error) {
                    error_log('[import] persist failed '.json_encode(['code' => Db::errorCode($error)]));
                    throw new AppError($message);
                }
            };
            $lab = $insert('Unable to store laboratory record', 'laboratories', [[
                'dataset_version_id' => $versionId,
                'code' => $meta['code'],
                'name' => $meta['name'],
                'display_order' => $meta['order'],
            ]], 'id')[0] ?? null;
            if ($lab === null) {
                throw new AppError('Unable to store laboratory record');
            }
            $base = ['dataset_version_id' => $versionId, 'laboratory_id' => $lab['id']];

            $subLabIds = [];
            $rows = array_map(fn (array $s) => $base + [
                'code' => $s['code'],
                'name' => $s['name'],
                'source_sheet_name' => $s['sourceSheet'],
                'display_order' => $s['order'],
            ], $parsed['subLabs']);
            foreach ($insert('Unable to store sub-labs', 'sub_labs', $rows, 'id, code') as $row) {
                $subLabIds[$row['code']] = $row['id'];
            }
            $subLabId = fn (?string $code) => $code !== null && $code !== '' ? ($subLabIds[$code] ?? null) : null;

            $groupIds = [];
            $rows = array_map(fn (array $g) => $base + [
                'sub_lab_id' => $subLabId($g['subLabCode']),
                'group_name' => $g['groupName'],
                'normalized_group_name' => $g['normalized'],
                'display_order' => $g['order'],
            ], $parsed['parameterGroups']);
            foreach ($insert('Unable to store parameter groups', 'parameter_groups', $rows, 'id, normalized_group_name, sub_lab_id') as $row) {
                $groupIds[($row['sub_lab_id'] ?? '').'|'.$row['normalized_group_name']] = $row['id'];
            }
            $groupId = fn (?string $subLabCode, ?string $normalized) => $normalized !== null && $normalized !== ''
                ? ($groupIds[($subLabId($subLabCode) ?? '').'|'.$normalized] ?? null)
                : null;

            $categoryIds = [];
            $rows = array_map(fn (array $c) => $base + [
                'sub_lab_id' => $subLabId($c['subLabCode']),
                'category_name' => $c['categoryName'],
                'normalized_category_name' => $c['normalized'],
                'display_order' => $c['order'],
            ], $parsed['hardwareCategories']);
            foreach ($insert('Unable to store hardware categories', 'hardware_categories', $rows, 'id, normalized_category_name') as $row) {
                $categoryIds[$row['normalized_category_name']] = $row['id'];
            }

            $insert('Unable to store parameters', 'parameters', array_map(fn (array $p) => $base + [
                'sub_lab_id' => $subLabId($p['subLabCode']),
                'parameter_group_id' => $groupId($p['subLabCode'], $p['groupNormalized']),
                'parameter_name' => $p['parameterName'],
                'price_per_test' => $p['pricePerTest'],
                'optional_notes' => $p['optionalNotes'],
                'source_sheet' => $p['sourceSheet'],
                'source_row' => $p['sourceRow'],
                'display_order' => $p['order'],
            ], $parsed['parameters']));

            $insert('Unable to store hardware', 'hardware', array_map(fn (array $h) => $base + [
                'sub_lab_id' => $subLabId($h['subLabCode']),
                'parameter_group_id' => $groupId($h['subLabCode'], $h['groupNormalized']),
                'hardware_category_id' => $h['categoryNormalized'] !== null && $h['categoryNormalized'] !== ''
                    ? ($categoryIds[$h['categoryNormalized']] ?? null)
                    : null,
                'hardware_name' => $h['hardwareName'],
                'price' => $h['price'],
                'source_sheet' => $h['sourceSheet'],
                'source_row' => $h['sourceRow'],
                'display_order' => $h['order'],
            ], $parsed['hardware']));
        });
    }

    /**
     * Inserts rows that all have the same columns, 500 at a time.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>> the returned columns, when asked for
     */
    private static function insertMany(Tx $tx, string $table, array $rows, string $returning = ''): array
    {
        $out = [];
        foreach (array_chunk($rows, 500) as $chunk) {
            $columns = array_keys($chunk[0]);
            $marks = '('.implode(', ', array_fill(0, count($columns), '?')).')';
            $sql = "insert into public.{$table} (".implode(', ', $columns).') values '.implode(', ', array_fill(0, count($chunk), $marks));
            $params = array_merge(...array_map(array_values(...), $chunk));
            if ($returning === '') {
                $tx->execute($sql, $params);
            } else {
                array_push($out, ...$tx->select("{$sql} returning {$returning}", $params));
            }
        }

        return $out;
    }

    // --------------------------------------------------------- dataset trees

    /** @return array{version: ?array, subLabs: array, groups: array, categories: array, parameters: array, hardware: array} */
    public function getDatasetTree(UserScope $db, string $versionId): array
    {
        return $db->run(fn (Tx $tx) => [
            'version' => self::readDatasetVersion($tx, $versionId),
            'subLabs' => $tx->rows('select id, code, name from public.sub_labs where dataset_version_id = ? order by display_order', [$versionId]),
            'groups' => $tx->rows('select id, sub_lab_id, group_name from public.parameter_groups where dataset_version_id = ? order by display_order', [$versionId]),
            'categories' => $tx->rows('select id, sub_lab_id, category_name from public.hardware_categories where dataset_version_id = ? order by display_order', [$versionId]),
            'parameters' => $tx->rows(
                'select id, sub_lab_id, parameter_group_id, parameter_name, price_per_test, optional_notes
                 from public.parameters where dataset_version_id = ? order by display_order', [$versionId]),
            'hardware' => $tx->rows(
                'select id, sub_lab_id, parameter_group_id, hardware_category_id, hardware_name, price
                 from public.hardware where dataset_version_id = ? order by display_order', [$versionId]),
        ]);
    }

    /** What publishing the draft would change, against the published (or last published) version of the same workbook. */
    public function compareWithPublished(UserScope $db, string $draftId, string $workbookType): ?array
    {
        // Never compare a version against itself: leave the candidate out, and if
        // it is already the published one, fall back to the version it superseded.
        $published = $db->run(function (Tx $tx) use ($draftId, $workbookType) {
            return $tx->first(
                "select id, version_number from public.dataset_versions
                 where workbook_type = ? and status = 'published' and id <> ?
                 order by version_number desc limit 1", [$workbookType, $draftId],
            ) ?? $tx->first(
                "select id, version_number from public.dataset_versions
                 where workbook_type = ? and status = 'archived' and id <> ? and published_at is not null
                 order by version_number desc limit 1", [$workbookType, $draftId],
            );
        });
        if ($published === null) {
            return null;
        }

        $draft = $this->getDatasetTree($db, $draftId);
        $current = $this->getDatasetTree($db, $published['id']);

        $groupNames = fn (array $tree) => array_column($tree['groups'], 'group_name', 'id');
        $draftGroups = $groupNames($draft);
        $currentGroups = $groupNames($current);
        $parameters = function (array $tree, array $groups): array {
            $map = [];
            foreach ($tree['parameters'] as $p) {
                $map[($groups[$p['parameter_group_id'] ?? ''] ?? '').' / '.$p['parameter_name']] = $p;
            }

            return $map;
        };
        $draftParams = $parameters($draft, $draftGroups);
        $currentParams = $parameters($current, $currentGroups);
        $draftHw = array_column($draft['hardware'], null, 'hardware_name');
        $currentHw = array_column($current['hardware'], null, 'hardware_name');
        $names = fn (array $map) => array_map(strval(...), array_keys($map));
        $missingFrom = fn (array $map, array $other) => array_values(array_filter($names($map), fn (string $key) => ! array_key_exists($key, $other)));
        $number = fn (mixed $value) => (float) ($value ?? 0);

        $parameterPriceChanges = [];
        foreach ($draftParams as $key => $p) {
            $previous = $currentParams[$key] ?? null;
            if ($previous !== null && $number($previous['price_per_test']) !== $number($p['price_per_test'])) {
                $parameterPriceChanges[] = ['name' => (string) $key, 'from' => $previous['price_per_test'], 'to' => $p['price_per_test']];
            }
        }
        $hardwarePriceChanges = [];
        $groupChanges = [];
        foreach ($draftHw as $name => $h) {
            $previous = $currentHw[$name] ?? null;
            if ($previous === null) {
                continue;
            }
            if ($number($previous['price']) !== $number($h['price'])) {
                $hardwarePriceChanges[] = ['name' => (string) $name, 'from' => $previous['price'], 'to' => $h['price']];
            }
            $before = $currentGroups[$previous['parameter_group_id'] ?? ''] ?? '';
            $after = $draftGroups[$h['parameter_group_id'] ?? ''] ?? '';
            if ($before !== $after) {
                $groupChanges[] = "{$name}: \"".($before ?: '—').'" → "'.($after ?: '—').'"';
            }
        }

        $draftCats = array_values(array_unique(array_column($draft['categories'], 'category_name')));
        $currentCats = array_values(array_unique(array_column($current['categories'], 'category_name')));

        return [
            'parametersAdded' => $missingFrom($draftParams, $currentParams),
            'parametersRemoved' => $missingFrom($currentParams, $draftParams),
            'parameterPriceChanges' => $parameterPriceChanges,
            'hardwareAdded' => $missingFrom($draftHw, $currentHw),
            'hardwareRemoved' => $missingFrom($currentHw, $draftHw),
            'hardwarePriceChanges' => $hardwarePriceChanges,
            'groupChanges' => $groupChanges,
            'categoryChanges' => [
                ...array_map(fn (string $c) => "Added: {$c}", array_values(array_diff($draftCats, $currentCats))),
                ...array_map(fn (string $c) => "Removed: {$c}", array_values(array_diff($currentCats, $draftCats))),
            ],
            'comparedWithVersion' => $published['version_number'],
        ];
    }

    // ------------------------------------------------------------ publishing

    public function publishVersion(UserScope $db, string $userId, ?string $userEmail, string $versionId, bool $isRollback = false): array
    {
        $version = $this->getDatasetVersion($db, $versionId);
        if ($version === null) {
            throw new AppError('Dataset version not found');
        }
        $critical = $version['validation_summary']->criticalErrors ?? 0;
        if ($critical > 0) {
            throw new AppError('This dataset has critical validation errors and cannot be published');
        }
        $previous = $db->run(fn (Tx $tx) => $tx->select(
            "select version_number from public.dataset_versions where workbook_type = ? and status = 'published'",
            [$version['workbook_type']],
        ));
        $previous = count($previous) === 1 ? $previous[0] : null;

        try {
            $db->run(fn (Tx $tx) => $tx->value('select public.publish_dataset_version(?)', [$versionId]));
        } catch (\Throwable $error) {
            throw new AppError(preg_replace('/^.*Forbidden/', 'Forbidden', Db::errorMessage($error)));
        }

        $this->audit->record($isRollback ? 'dataset.rollback' : 'dataset.publish', $userId, $userEmail, [
            'workbook_type' => $version['workbook_type'],
            'restored_version' => $version['version_number'],
            'replaced_version' => $previous['version_number'] ?? null,
        ]);

        return ['ok' => true];
    }

    // -------------------------------------------------------------- settings

    public function getSettings(UserScope $db): array
    {
        return self::guard('Unable to load settings', fn () => $db->run(fn (Tx $tx) => $tx->rows('select * from public.settings')));
    }

    /** @param  object  $value a JSON object of text, number and true/false values */
    public function updateSetting(UserScope $db, string $userId, ?string $userEmail, string $key, object $value): array
    {
        try {
            $previous = $db->run(fn (Tx $tx) => $tx->rows('select value from public.settings where key = ?', [$key]))[0] ?? null;
        } catch (\Throwable) {
            $previous = null;
        }
        self::guard('Unable to save setting', fn () => $db->run(fn (Tx $tx) => $tx->execute(
            'insert into public.settings (key, value, updated_by, updated_at) values (?, ?::jsonb, ?, now())
             on conflict (key) do update set
               value = excluded.value, updated_by = excluded.updated_by, updated_at = excluded.updated_at',
            [$key, Js::stringify($value), $userId],
        )));
        $this->audit->record('settings.update', $userId, $userEmail, [
            'key' => $key,
            'previous_value' => $previous['value'] ?? null,
            'new_value' => $value,
        ]);

        return ['ok' => true];
    }

    // ------------------------------------------------------------ AI prompts

    public function listPrompts(UserScope $db): array
    {
        return self::guard('Unable to load AI prompt versions', fn () => $db->run(
            fn (Tx $tx) => $tx->rows('select * from public.ai_prompt_versions order by version_number desc'),
        ));
    }

    /** @param  array{promptName: string, promptText: string, model: string, activate: bool}  $input */
    public function createPromptVersion(UserScope $db, string $userId, ?string $userEmail, array $input): array
    {
        $versionNumber = (int) $db->run(fn (Tx $tx) => $tx->value(
            'select version_number from public.ai_prompt_versions order by version_number desc limit 1',
        )) + 1;
        try {
            $id = $db->run(fn (Tx $tx) => $tx->value(
                'insert into public.ai_prompt_versions (prompt_name, prompt_text, model, version_number, status, created_by)
                 values (?, ?, ?, ?, ?, ?) returning id',
                [$input['promptName'], $input['promptText'], $input['model'], $versionNumber, 'draft', $userId],
            ));
        } catch (\Throwable) {
            $id = null;
        }
        if ($id === null) {
            throw new AppError('Unable to save the prompt version');
        }
        $this->audit->record('ai_prompt.create', $userId, $userEmail, ['version_number' => $versionNumber, 'model' => $input['model']]);
        if ($input['activate']) {
            $this->activatePrompt($db, $userId, $userEmail, $id);
        }

        return ['id' => $id, 'versionNumber' => $versionNumber];
    }

    /** @return array{ok: true, alreadyActive: bool} */
    public function activatePrompt(UserScope $db, string $userId, ?string $userEmail, string $promptId): array
    {
        return PromptActivation::activate(
            fn (string $id) => $db->run(fn (Tx $tx) => $tx->first(
                'select id, status, prompt_text, model, version_number from public.ai_prompt_versions where id = ?', [$id],
            )),
            function (string $id) use ($db): ?string {
                try {
                    $db->run(fn (Tx $tx) => $tx->value('select public.activate_ai_prompt(?)', [$id]));

                    return null;
                } catch (\Throwable $error) {
                    return Db::errorMessage($error);
                }
            },
            fn (string $eventType, array $details) => $this->audit->record($eventType, $userId, $userEmail, $details),
            $promptId,
        );
    }

    // ---------------------------------------------------------- integrations

    /** Which outside services are set up, for the dashboard. Never returns a value, only whether one is present. */
    public function getIntegrationStatus(): array
    {
        $env = $this->env;
        $configured = function (string ...$names) use ($env): bool {
            foreach ($names as $name) {
                $value = $env($name);
                if (! is_string($value) || Js::trim($value) === '') {
                    return false;
                }
            }

            return true;
        };
        $emailDriver = $env('EMAIL_DRIVER');
        $storageDriver = $env('FILE_STORE_DRIVER');

        return [
            [
                'key' => 'openai',
                'label' => 'OpenAI',
                'configured' => $configured('OPENAI_API_KEY'),
                'note' => 'Used by the AI Calculator',
            ],
            [
                'key' => 'email',
                'label' => 'Email',
                'configured' => match ($emailDriver) {
                    'smtp' => $configured('SMTP_HOST'),
                    'graph' => $configured('GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET', 'GRAPH_SENDER'),
                    default => false,
                },
                'note' => $emailDriver === 'graph'
                    ? 'Sales report notifications and account links via Microsoft Graph'
                    : 'Sales report notifications and account links via SMTP',
            ],
            [
                'key' => 'report_storage',
                'label' => 'Internal Report Storage',
                'configured' => match ($storageDriver) {
                    'local' => $configured('FILE_STORE_PATH'),
                    's3' => $configured('FILE_STORE_S3_ENDPOINT', 'FILE_STORE_S3_BUCKET', 'FILE_STORE_S3_ACCESS_KEY_ID', 'FILE_STORE_S3_SECRET_ACCESS_KEY'),
                    default => false,
                },
                'note' => 'Private storage for internal sales report PDFs and dataset uploads',
            ],
            [
                'key' => 'scheduler',
                'label' => 'Scheduled Jobs',
                'configured' => strlen((string) ($env('CRON_SECRET') ?? '')) >= 32,
                'note' => 'Sales report recovery and hourly maintenance endpoints',
            ],
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private static function guard(string $message, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            throw new AppError($message);
        }
    }
}
