<?php

namespace App\Ai;

use App\Support\Js;

/**
 * Orchestration of one public AI turn (port of src/lib/ai-turn.server.ts).
 * The order of checks, the single quota reservation around every provider
 * call, and the fail-closed answers are the original's.
 */
final class TurnHandler
{
    public const UNAVAILABLE_MESSAGE = 'The AI assistant is temporarily unavailable. You can try again or continue using Manual Mode.';

    private const NOT_CONFIGURED = ['ok' => false, 'code' => 'not_configured', 'message' => 'The AI assistant is not configured yet. Please use Manual Mode.'];

    public function __construct(
        private readonly Quota $quota,
        private readonly Sessions $sessions,
        private readonly SafeCatalog $catalogs,
        private readonly OpenAi $openAi,
        private readonly Classifier $classifier,
        private readonly Reviewer $reviewer,
        private readonly QaDiagnostics $qa,
    ) {}

    private static function nowMs(): float
    {
        return floor(microtime(true) * 1000);
    }

    private static function lastAssistant(array $history): string
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['role'] === 'assistant') {
                return $history[$i]['content'];
            }
        }

        return '';
    }

    /** @param  array{sessionId: ?string, message: string, plan?: mixed}  $data */
    public function handle(AiRequest $ctx, array $data): array
    {
        $quota = $this->quota;
        $unavailable = function (int $status) use ($ctx): array {
            $ctx->status = $status;

            return ['ok' => false, 'code' => 'unavailable', 'message' => self::UNAVAILABLE_MESSAGE, 'retryAfterSeconds' => null];
        };

        if (! $this->openAi->hasKey()) {
            return self::NOT_CONFIGURED;
        }

        // ---- identity + settings (fail closed) ----
        try {
            $caller = $quota->identifyCaller($ctx);
            $settings = $quota->settings();
        } catch (\Throwable) {
            AiLog::write('[ai-quota] quota check failed (identity/settings)');
            $quota->logUsageEvent(['caller' => null, 'outcome' => 'quota_error', 'errorCategory' => 'settings']);

            return $unavailable(503);
        }

        $userMessage = Js::trim($data['message']);
        if ($userMessage === '') {
            $ctx->status = 400;

            return ['ok' => false, 'code' => 'invalid', 'message' => 'Please enter a message.'];
        }
        if (Js::length($userMessage) > $settings['max_input_chars']) {
            $ctx->status = 413;
            $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'input_rejected', 'limit' => 'max_input_chars']);

            return ['ok' => false, 'code' => 'invalid', 'message' => 'That message is too long. Please shorten it.'];
        }

        try {
            $session = $this->sessions->getOrCreate($data['sessionId'], $caller['visitorHash']);
        } catch (\Throwable) {
            $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'quota_error', 'errorCategory' => 'session']);

            return $unavailable(503);
        }
        if ($session['turns'] >= $settings['max_turns']) {
            $ctx->status = 429;
            $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'rate_limited', 'limit' => 'max_turns']);

            return [
                'ok' => false,
                'code' => 'rate_limited',
                'message' => 'This conversation has reached its length limit. Start over, or continue in Manual Mode.',
            ];
        }

        $config = $this->openAi->activeConfig();
        if ($config === null) {
            return self::NOT_CONFIGURED;
        }

        $catalog = $this->catalogs->load();
        if (count($catalog['labs']) === 0) {
            return ['ok' => false, 'code' => 'unavailable', 'message' => 'No published laboratory data is available yet.'];
        }

        // Existing validated state (revalidated against the CURRENT published data).
        $priorState = SelectionValidation::validate($session['proposed'], $catalog);
        $history = [...$session['history'], ['role' => 'user', 'content' => $userMessage]];

        // ---- scope guard (Findings 4302–4308) ----
        $previousReply = self::lastAssistant($session['history']);
        $names = [];
        foreach ($catalog['labs'] as $lab) {
            foreach ($lab['sections'] as $section) {
                foreach ([...$section['parameters'], ...$section['hardware']] as $item) {
                    $names[] = $item['name'];
                }
            }
        }
        $replyLang = Language::conversationLanguage($userMessage, $previousReply, $names);
        $plan = Picker::sanitizePlan($data['plan'] ?? null, $catalog);

        $scopeReply = function (string $category, string $stage) use (&$session, $caller, $quota, $replyLang, $priorState, $catalog, $plan): array {
            // Rejected exchanges never enter history (no multi-turn poisoning) but do count as turns.
            $session['turns'] += 1;
            try {
                $this->sessions->save($session, $caller['visitorHash']);
            } catch (\Throwable) {
                // state unchanged either way (including a concurrent-save conflict)
            }
            if ($stage === 'deterministic') {
                $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'scope_rejected', 'errorCategory' => "{$stage}:{$category}"]);
            }
            AiLog::write('[ai] scope rejection', ['sessionId' => $session['id'], 'stage' => $stage, 'category' => $category]);
            $reply = $category === 'output_rejected' ? Guard::outputRejected($replyLang) : Guard::safeReply($category, $replyLang);

            return [
                'ok' => true, 'sessionId' => $session['id'], 'reply' => $reply,
                'state' => ChoiceHandler::toWorkingState($priorState),
                'picker' => Picker::build($priorState, $catalog, $replyLang, null, $plan),
            ];
        };
        $preCategory = Guard::classifyInputDeterministic($userMessage);
        if ($preCategory !== 'allowed') {
            return $scopeReply($preCategory, 'deterministic');
        }

        // Sections the visitor has already engaged with get their full parameter list.
        $expanded = [];
        foreach ($session['expandedSectionKeys'] as $key) {
            $expanded[$key] = true;
        }
        foreach ($priorState['labs'] as $lab) {
            foreach ($lab['sections'] as $section) {
                $expanded[$section['key']] = true;
            }
        }
        $lowered = Js::lower($userMessage);
        foreach ($catalog['labs'] as $lab) {
            foreach ($lab['sections'] as $section) {
                if (str_contains($lowered, Js::lower($section['name']))) {
                    $expanded[$section['key']] = true;
                }
            }
        }

        $payload = OpenAi::buildPayload([
            'config' => $config,
            'catalog' => $catalog,
            'history' => $history,
            'state' => $priorState,
            'expandedSectionKeys' => $expanded,
            'maxOutputTokens' => $settings['max_output_tokens'],
        ]);

        // ---- atomic reservation of the estimated maximum usage BEFORE OpenAI ----
        $inputChars = Js::length($payload['instructions']);
        foreach ($payload['input'] as $message) {
            $inputChars += Js::length($message['content'][0]['text']);
        }
        // Every provider call in this turn is covered by ONE reservation made before any of them:
        // classifier + main generation + final-output reviewer (4301 accounting).
        $classifierEstimate = Quota::estimateTokens(
            Js::length(Classifier::instructions()) + Classifier::SAFETY_CONTEXT_MAX_CHARS + 200 + Js::length($userMessage),
            Classifier::MAX_OUTPUT_TOKENS,
        );
        $reviewerEstimate = Quota::estimateTokens(
            Js::length(Reviewer::instructions()) + Classifier::SAFETY_CONTEXT_MAX_CHARS + 2200 + Reviewer::MAX_REPLY_CHARS,
            Reviewer::MAX_OUTPUT_TOKENS,
        );
        $estimatedTokens = Quota::estimateTokens($inputChars, $settings['max_output_tokens']) + $classifierEstimate + $reviewerEstimate;
        try {
            $reservation = $quota->reserve($caller, $estimatedTokens);
        } catch (\Throwable) {
            AiLog::write('[ai-quota] quota check failed (reserve)');
            $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'quota_error', 'estimatedTokens' => $estimatedTokens, 'errorCategory' => 'reserve']);

            return $unavailable(503);
        }
        if (! $reservation['ok']) {
            $budget = str_starts_with($reservation['limit'], 'global') || $reservation['limit'] === 'disabled';
            $ctx->status = $budget ? 503 : 429;
            $ctx->headers['Retry-After'] = Js::number(max(1, Js::round($reservation['retryAfter'])));
            $quota->logUsageEvent([
                'caller' => $caller,
                'outcome' => $budget ? 'budget_exceeded' : 'rate_limited',
                'limit' => $reservation['limit'],
                'estimatedTokens' => $estimatedTokens,
            ]);

            return [
                'ok' => false,
                'code' => 'rate_limited',
                'message' => Quota::friendlyLimitMessage($reservation['retryAfter']),
                'retryAfterSeconds' => $reservation['retryAfter'],
            ];
        }

        // Provider-call accounting for this admitted turn. Unknown classifier/reviewer
        // usage is charged at its full estimate; unknown main usage keeps the whole
        // reservation charged. Never treated as zero.
        $accounting = new TurnAccounting;
        $turnStarted = self::nowMs();
        // Content-free QA diagnostics: off unless server secrets AND request header match.
        $qaDiag = $this->qa->resolve($ctx);
        try {
            // ---- constrained semantic classification (before the main model call) ----
            try {
                $verdict = $this->classifier->classify($config['model'], $session['history'], $userMessage);
            } catch (\Throwable $error) {
                // Fail closed: no classification, no main call. Unknown usage stays fully charged.
                $category = $error instanceof ClassifierError ? $error->category : 'unknown';
                $accounting->add('classifier', $error instanceof ClassifierError ? $error->usage : null, null);
                $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'openai_error', 'estimatedTokens' => $estimatedTokens, 'errorCategory' => "classifier_{$category}"]);

                return $unavailable(503);
            }
            $accounting->add('classifier', $verdict['usage'], $classifierEstimate);
            AiLog::write('[ai] input classification', ['category' => $verdict['category'], 'requestChars' => Js::length($userMessage)]);
            // An explicit classifier rejection is never overridden by a weaker signal.
            if ($verdict['category'] !== 'allowed' && $verdict['category'] !== 'clarification') {
                $quota->logUsageEvent([
                    'caller' => $caller,
                    'outcome' => 'scope_rejected',
                    'estimatedTokens' => $estimatedTokens,
                    'inputTokens' => $verdict['usage']['input'] ?? null,
                    'outputTokens' => $verdict['usage']['output'] ?? null,
                    'errorCategory' => "classifier:{$verdict['category']}",
                ]);

                return $scopeReply($verdict['category'], 'classifier');
            }

            $started = self::nowMs();
            try {
                $result = $this->openAi->call($payload);
                $accounting->add('generation', $result['usage'], null);
            } catch (\Throwable $error) {
                $category = $error instanceof AiCallError ? $error->category : 'unknown';
                $usage = $error instanceof AiCallError ? $error->usage : null;
                $accounting->add('generation', $usage, null);
                $quota->logUsageEvent([
                    'caller' => $caller,
                    'outcome' => $category === 'timeout' ? 'timeout' : 'openai_error',
                    'estimatedTokens' => $estimatedTokens,
                    'inputTokens' => $usage['input'] ?? null,
                    'outputTokens' => $usage['output'] ?? null,
                    'latencyMs' => self::nowMs() - $started,
                    'errorCategory' => $category,
                ]);
                AiLog::write('[ai] turn failed', [
                    'sessionId' => $session['id'],
                    'promptVersion' => $config['promptVersionNumber'],
                    'model' => $config['model'],
                    'success' => false,
                    'reason' => $category,
                ]);

                return $unavailable(503);
            }

            $rejectOutput = function (string $reason) use (&$session, $quota, $caller, $estimatedTokens, $accounting, $turnStarted, $scopeReply): array {
                AiLog::write('[ai] output rejected', ['sessionId' => $session['id'], 'reason' => $reason]);
                $quota->logUsageEvent([
                    'caller' => $caller, 'outcome' => 'output_rejected', 'errorCategory' => $reason,
                    'estimatedTokens' => $estimatedTokens, 'inputTokens' => $accounting->inputTokens(), 'outputTokens' => $accounting->outputTokens(),
                    'latencyMs' => self::nowMs() - $turnStarted,
                ]);

                return $scopeReply('output_rejected', 'output');
            };

            // The answer was validated against the contract when it was read; a
            // turn that is not the contract never reaches reconciliation.
            $turn = $result['turn'];
            if (! is_array($turn) || ! isset($turn['response']['kind'])) {
                QaDiagnostics::emit($qaDiag, fn () => QaDiagnostics::buildRecord([
                    'stage' => 'schema_rejected', 'responseKind' => null, 'requestedLanguage' => $replyLang, 'reply' => '',
                    'catalogOptionCount' => 0, 'selectedAssayCount' => 0, 'selectedEquipmentCount' => 0, 'issues' => [],
                    'reviewer' => ['ran' => false], 'inputTokens' => $accounting->inputTokens(), 'outputTokens' => $accounting->outputTokens(),
                    'latencyMs' => self::nowMs() - $turnStarted,
                ]));

                return $rejectOutput('schema:invalid_output');
            }

            $reconciliation = Reconciliation::reconcileTurn($priorState, $turn, $catalog, $userMessage, $previousReply);
            $nextState = $reconciliation['state'];
            $reply = Reconciliation::renderLaboratoryReply($priorState, $reconciliation, $turn, $replyLang);
            $diag = function (string $stage, array $reviewer) use ($qaDiag, $turn, $replyLang, $reply, $reconciliation, $nextState, $accounting, $turnStarted): void {
                QaDiagnostics::emit($qaDiag, function () use ($stage, $reviewer, $turn, $replyLang, $reply, $reconciliation, $nextState, $accounting, $turnStarted) {
                    $assays = 0;
                    $equipment = 0;
                    foreach ($nextState['labs'] as $lab) {
                        foreach ($lab['sections'] as $s) {
                            $assays += count($s['parameterNames']);
                            $equipment += $s['includeHardware'] ? count($s['hardwareNames']) : 0;
                        }
                    }

                    return QaDiagnostics::buildRecord([
                        'stage' => $stage, 'responseKind' => $turn['response']['kind'], 'requestedLanguage' => $replyLang, 'reply' => $reply,
                        'catalogOptionCount' => count($reconciliation['options']), 'selectedAssayCount' => $assays, 'selectedEquipmentCount' => $equipment,
                        'issues' => $reconciliation['issues'], 'reviewer' => $reviewer,
                        'inputTokens' => $accounting->inputTokens(), 'outputTokens' => $accounting->outputTokens(),
                        'latencyMs' => self::nowMs() - $turnStarted,
                    ]);
                });
            };
            AiLog::write('[ai] validation', [
                'stage' => 'reconciliation', 'responseKind' => $turn['response']['kind'],
                'issueCount' => count($reconciliation['issues']), 'changed' => $reconciliation['changed'],
                'historyMessages' => count($session['history']), 'requestChars' => Js::length($userMessage),
            ]);

            // ---- the final assembled reply (including server-added text) is validated
            // again, deterministically and by the independent reviewer, before it can be
            // returned or committed to history. Any reviewer failure is a rejection.
            $finalVerdict = Guard::validateAiOutput(
                $reply,
                ['confidential' => [$config['promptText'], OpenAi::guardrails()], 'modelId' => $config['model']],
                null,
            );
            if (! $finalVerdict['ok']) {
                $diag('final_validation_rejected', ['ran' => false]);

                return $rejectOutput("final:{$finalVerdict['reason']}");
            }
            $review = $this->reviewer->review($config['model'], $session['history'], $userMessage, $reply);
            $accounting->add('reviewer', $review['usage'], $reviewerEstimate);
            AiLog::write('[ai] independent review', [
                'verdict' => $review['approved'] ? 'approve' : 'reject',
                'category' => $review['approved'] ? 'none' : $review['reason'],
                'replyChars' => Js::length($reply),
            ]);
            if (! $review['approved']) {
                $diag('reviewer_rejected', ['ran' => true, 'approved' => false, 'reason' => $review['reason']]);

                return $rejectOutput($review['reason']);
            }

            // Only canonical effective state and the independently reviewed complete
            // answer enter the compare-and-set save. Nothing is confirmed on save failure.
            $session['proposed'] = Reconciliation::rawState($nextState);
            $session['expandedSectionKeys'] = array_map('strval', array_keys($expanded));
            $session['history'] = [...$history, ['role' => 'assistant', 'content' => $reply]];
            $session['turns'] += 1;
            $session['promptVersionId'] = $config['promptVersionId'];
            $session['promptVersionNumber'] = $config['promptVersionNumber'];
            $session['model'] = $config['model'];
            try {
                $this->sessions->save($session, $caller['visitorHash']);
            } catch (SessionConflict) {
                // Another request saved this conversation first: nothing is overwritten.
                $ctx->status = 409;
                $quota->logUsageEvent(['caller' => $caller, 'outcome' => 'session_conflict', 'errorCategory' => 'session_conflict']);

                return [
                    'ok' => false,
                    'code' => 'session',
                    'message' => 'This conversation was updated by another request. Please send your message again.',
                ];
            } catch (\Throwable) {
                return $unavailable(503);
            }

            $quota->logUsageEvent([
                'caller' => $caller, 'outcome' => 'allowed', 'estimatedTokens' => $estimatedTokens,
                'inputTokens' => $accounting->inputTokens(), 'outputTokens' => $accounting->outputTokens(), 'latencyMs' => self::nowMs() - $turnStarted,
            ]);

            AiLog::write('[ai] turn', [
                'sessionId' => $session['id'],
                'promptVersion' => $config['promptVersionNumber'],
                'model' => $config['model'],
                'success' => true,
                'latencyMs' => $result['latencyMs'],
                'usage' => $result['usage'],
                'rejectedIds' => count($nextState['rejected']),
                'reconciliationIssues' => count($reconciliation['issues']),
                'structuredOutputValid' => true,
            ]);

            $state = ChoiceHandler::toWorkingState($nextState);
            $state['conversationStatus'] = count($reconciliation['issues']) === 0
                && Reconciliation::hasConfirmedConfiguration($priorState, $nextState, $userMessage, $previousReply) ? 'ready' : 'collecting';

            $diag('approved', ['ran' => true, 'approved' => true, 'reason' => null]);

            return [
                'ok' => true, 'sessionId' => $session['id'], 'reply' => $reply, 'state' => $state,
                'picker' => Picker::build($nextState, $catalog, $replyLang, null, $plan),
            ];
        } finally {
            // Released exactly once, AFTER the conversation is saved, so the per-visitor
            // concurrency slot also serialises persistence. Release is idempotent in the DB.
            AiLog::write('[ai] provider calls', ['sessionId' => $session['id']] + $accounting->summary());
            $quota->release($reservation['reservationId'], $accounting->total());
        }
    }
}
