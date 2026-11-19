<?php

namespace App\Admin;

use App\Support\Js;

/**
 * Guarded AI prompt activation (port of src/lib/ai-prompt-activation.server.ts).
 * The checks here are defence in depth; the database function re-checks
 * everything atomically.
 */
final class PromptActivation
{
    private const MESSAGES = [
        'not_found' => 'This prompt version does not exist. The active prompt was not changed.',
        'not_eligible' => 'This prompt version cannot be activated. The active prompt was not changed.',
        'invalid_prompt' => 'This prompt version has no valid prompt text or model. The active prompt was not changed.',
        'forbidden' => 'Forbidden: administrator role required',
        'read_error' => 'Unable to check the prompt version. The active prompt was not changed.',
        'activation_failed' => 'Unable to activate the prompt version. The active prompt was not changed.',
    ];

    /**
     * @param  callable(string): ?array  $readPrompt  the prompt row (id, status, prompt_text, model, version_number) or null
     * @param  callable(string): ?string  $activate  runs the database activation; returns its error message, or null on success
     * @param  callable(string, array): void  $audit  event type and details
     * @return array{ok: true, alreadyActive: bool}
     *
     * @throws PromptActivationError
     */
    public static function activate(callable $readPrompt, callable $activate, callable $audit, string $promptId): array
    {
        $fail = function (string $reason) use ($audit, $promptId): never {
            try {
                $audit('ai_prompt.activate_failed', ['prompt_id' => $promptId, 'reason' => $reason]);
            } catch (\Throwable) {
                // An auditing failure must not mask the rejection.
            }
            throw new PromptActivationError($reason, self::MESSAGES[$reason]);
        };

        try {
            $target = $readPrompt($promptId);
        } catch (\Throwable) {
            $fail('read_error');
        }
        if ($target === null) {
            $fail('not_found');
        }
        if ($target['status'] === 'active') {
            return ['ok' => true, 'alreadyActive' => true];
        }
        if ($target['status'] !== 'draft' && $target['status'] !== 'archived') {
            $fail('not_eligible');
        }
        if (Js::trim((string) ($target['prompt_text'] ?? '')) === '' || Js::trim((string) ($target['model'] ?? '')) === '') {
            $fail('invalid_prompt');
        }

        try {
            $error = $activate($promptId);
        } catch (\Throwable) {
            $fail('activation_failed');
        }
        if ($error !== null) {
            $fail(match (true) {
                str_contains($error, 'PROMPT_NOT_FOUND') => 'not_found',
                str_contains($error, 'PROMPT_NOT_ELIGIBLE') => 'not_eligible',
                str_contains($error, 'PROMPT_INVALID') => 'invalid_prompt',
                stripos($error, 'forbidden') !== false => 'forbidden',
                default => 'activation_failed',
            });
        }

        $audit('ai_prompt.activate', ['prompt_id' => $promptId, 'version_number' => $target['version_number']]);

        return ['ok' => true, 'alreadyActive' => false];
    }
}
