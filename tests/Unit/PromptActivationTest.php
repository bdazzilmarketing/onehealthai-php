<?php

namespace Tests\Unit;

use App\Admin\PromptActivation;
use App\Admin\PromptActivationError;
use PHPUnit\Framework\TestCase;

/** Guarded AI prompt activation (port of src/lib/ai-prompt-activation.test.ts). */
final class PromptActivationTest extends TestCase
{
    private const ACTIVE = '00000000-0000-4000-8000-0000000000a2';

    private const DRAFT = '00000000-0000-4000-8000-0000000000a3';

    private const EMPTY = '00000000-0000-4000-8000-0000000000a4';

    private array $rows;

    private array $audits = [];

    private int $activations = 0;

    protected function setUp(): void
    {
        $row = fn (string $id, string $status, ?string $text, ?string $model, int $n) => ['id' => $id, 'status' => $status, 'prompt_text' => $text, 'model' => $model, 'version_number' => $n];
        $this->rows = [
            self::ACTIVE => $row(self::ACTIVE, 'active', 'v2', 'gpt-4o-mini', 2),
            self::DRAFT => $row(self::DRAFT, 'draft', 'v3', 'gpt-4o-mini', 3),
            self::EMPTY => $row(self::EMPTY, 'draft', '   ', 'gpt-4o-mini', 4),
        ];
    }

    private function attempt(string $id, ?callable $read = null, ?callable $activate = null, ?callable $audit = null): array
    {
        return PromptActivation::activate(
            $read ?? fn (string $id) => $this->rows[$id] ?? null,
            $activate ?? function (string $id) {
                $this->activations++;
                foreach ($this->rows as &$row) {
                    $row['status'] = $row['status'] === 'active' ? 'archived' : $row['status'];
                }
                $this->rows[$id]['status'] = 'active';

                return null;
            },
            $audit ?? function (string $type, array $details) {
                $this->audits[] = [$type, $details];
            },
            $id,
        );
    }

    private function active(): array
    {
        return array_keys(array_filter($this->rows, fn (array $row) => $row['status'] === 'active'));
    }

    private function rejected(string $reason, string $message, callable $run): void
    {
        try {
            $run();
            $this->fail('Expected the activation to be rejected');
        } catch (PromptActivationError $error) {
            $this->assertSame($reason, $error->reason);
            $this->assertSame($message, $error->getMessage());
        }
    }

    public function test_activates_an_existing_valid_prompt_and_audits_success(): void
    {
        $this->assertSame(['ok' => true, 'alreadyActive' => false], $this->attempt(self::DRAFT));
        $this->assertSame([self::DRAFT], $this->active());
        $this->assertSame([['ai_prompt.activate', ['prompt_id' => self::DRAFT, 'version_number' => 3]]], $this->audits);
    }

    public function test_rejects_a_nonexistent_prompt_without_calling_the_database_change(): void
    {
        $missing = '00000000-0000-4000-8000-0000000000ff';
        $this->rejected('not_found', 'This prompt version does not exist. The active prompt was not changed.', fn () => $this->attempt($missing));
        $this->assertSame(0, $this->activations);
        $this->assertSame([self::ACTIVE], $this->active());
        $this->assertSame([['ai_prompt.activate_failed', ['prompt_id' => $missing, 'reason' => 'not_found']]], $this->audits);
    }

    public function test_rejects_an_empty_prompt_or_a_missing_model_and_preserves_the_active_prompt(): void
    {
        $message = 'This prompt version has no valid prompt text or model. The active prompt was not changed.';
        $this->rejected('invalid_prompt', $message, fn () => $this->attempt(self::EMPTY));
        $this->rows[self::DRAFT]['model'] = null;
        $this->rejected('invalid_prompt', $message, fn () => $this->attempt(self::DRAFT));
        $this->assertSame(0, $this->activations);
        $this->assertSame([self::ACTIVE], $this->active());
    }

    public function test_rejects_a_version_that_is_neither_draft_nor_archived(): void
    {
        $this->rows[self::DRAFT]['status'] = 'retired';
        $this->rejected('not_eligible', 'This prompt version cannot be activated. The active prompt was not changed.', fn () => $this->attempt(self::DRAFT));
    }

    public function test_reports_failed_database_operations_clearly_and_audits_them(): void
    {
        $failed = 'Unable to activate the prompt version. The active prompt was not changed.';
        $this->rejected('activation_failed', $failed, fn () => $this->attempt(self::DRAFT, activate: fn () => 'PROMPT_ACTIVATION_FAILED'));
        $this->rejected('activation_failed', $failed, fn () => $this->attempt(self::DRAFT, activate: fn () => throw new \RuntimeException('connection lost')));
        $this->rejected('not_found', 'This prompt version does not exist. The active prompt was not changed.', fn () => $this->attempt(self::DRAFT, activate: fn () => 'PROMPT_NOT_FOUND'));
        $this->rejected('not_eligible', 'This prompt version cannot be activated. The active prompt was not changed.', fn () => $this->attempt(self::DRAFT, activate: fn () => 'PROMPT_NOT_ELIGIBLE'));
        $this->assertSame('ai_prompt.activate_failed', $this->audits[0][0]);
        $this->assertSame([self::ACTIVE], $this->active());
    }

    public function test_treats_a_failed_lookup_as_a_rejection_not_a_success(): void
    {
        $this->rejected('read_error', 'Unable to check the prompt version. The active prompt was not changed.',
            fn () => $this->attempt(self::DRAFT, read: fn () => throw new \RuntimeException('db down')));
        $this->assertSame(0, $this->activations);
    }

    public function test_maps_a_database_authorization_refusal_to_forbidden(): void
    {
        $this->rejected('forbidden', 'Forbidden: administrator role required', fn () => $this->attempt(self::DRAFT, activate: fn () => 'Forbidden: admin role required'));
    }

    public function test_still_rejects_when_the_audit_log_write_fails(): void
    {
        $this->rejected('not_found', 'This prompt version does not exist. The active prompt was not changed.',
            fn () => $this->attempt('00000000-0000-4000-8000-0000000000ff', audit: fn () => throw new \RuntimeException('audit down')));
    }

    public function test_is_a_no_op_for_the_already_active_prompt(): void
    {
        $this->assertSame(['ok' => true, 'alreadyActive' => true], $this->attempt(self::ACTIVE));
        $this->assertSame(0, $this->activations);
        $this->assertSame([], $this->audits);
    }
}
