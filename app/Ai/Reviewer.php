<?php

namespace App\Ai;

use App\Support\Js;

/**
 * Independent final-response reviewer (Findings 4302, 4303, 4307; port of
 * src/lib/ai-reviewer.server.ts).
 *
 * One narrowly scoped model call per admitted turn that ONLY approves or
 * rejects the fully assembled reply. It cannot write replacement text,
 * receives no catalog, state, prices, prompt or configuration, and every
 * failure mode (timeout, HTTP error, malformed JSON, unknown values,
 * uncertainty) is a rejection.
 */
final class Reviewer
{
    public const TIMEOUT_MS = 12_000;

    public const MAX_OUTPUT_TOKENS = 40;

    public const MAX_REPLY_CHARS = 8000;

    public const ISSUES = ['none', 'medical_advice', 'unsupported_language', 'creative', 'other_out_of_scope'];

    /** @param  \Closure(string): mixed  $env */
    public function __construct(private readonly \Closure $env) {}

    public static function instructions(): string
    {
        return Data::get('REVIEWER_INSTRUCTIONS');
    }

    /** @param  list<array{role: string, content: string}>  $history */
    public static function buildPayload(string $model, array $history, string $userMessage, string $reply): array
    {
        $context = Classifier::buildSafetyContext($history);

        return [
            'model' => $model,
            'instructions' => self::instructions(),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => "EARLIER TURNS (context only):\n".($context !== '' ? $context : '(none)')."\n\n"
                        ."VISITOR MESSAGE:\n<<<\n".Js::slice($userMessage, 0, 2000)."\n>>>\n\n"
                        ."CANDIDATE REPLY TO REVIEW:\n<<<\n".Js::slice($reply, 0, self::MAX_REPLY_CHARS)."\n>>>",
                ]],
            ]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'reply_review', 'strict' => true, 'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['verdict', 'issue'],
                'properties' => [
                    'verdict' => ['type' => 'string', 'enum' => ['approve', 'reject']],
                    'issue' => ['type' => 'string', 'enum' => self::ISSUES],
                ],
            ]]],
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * Strict parser: anything other than an exact, consistent approval is a rejection.
     *
     * @return array{approved: true}|array{approved: false, reason: string}
     */
    public static function parseOutput(string $text): array
    {
        $malformed = ['approved' => false, 'reason' => 'reviewer_malformed'];
        $parsed = json_decode($text);
        if (! $parsed instanceof \stdClass) {
            return $malformed;
        }
        $fields = get_object_vars($parsed);
        $keys = array_map('strval', array_keys($fields));
        sort($keys, SORT_STRING);
        if ($keys !== ['issue', 'verdict']) {
            return $malformed;
        }
        $issue = $fields['issue'];
        if (! is_string($issue) || ! in_array($issue, self::ISSUES, true)) {
            return $malformed;
        }
        if ($fields['verdict'] === 'approve' && $issue === 'none') {
            return ['approved' => true];
        }
        if ($fields['verdict'] === 'approve') {
            return ['approved' => false, 'reason' => 'reviewer_inconsistent'];
        }
        if ($fields['verdict'] === 'reject') {
            return ['approved' => false, 'reason' => "reviewer:{$issue}"];
        }

        return $malformed;
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{approved: true, usage: ?array}|array{approved: false, reason: string, usage: ?array}
     */
    public function review(string $model, array $history, string $userMessage, string $reply): array
    {
        $reject = fn (string $reason, ?array $usage = null) => ['approved' => false, 'reason' => $reason, 'usage' => $usage];
        $apiKey = ($this->env)('OPENAI_API_KEY');
        if (! $apiKey) {
            return $reject('reviewer_not_configured');
        }
        // A reply longer than the reviewer can see cannot be approved.
        if (Js::length($reply) > self::MAX_REPLY_CHARS) {
            return $reject('reviewer_too_long');
        }
        $response = ResponsesApi::post($apiKey, self::buildPayload($model, $history, $userMessage, $reply), intdiv(self::TIMEOUT_MS, 1000));
        if ($response['failure'] !== null) {
            return $reject($response['failure'] === 'timeout' ? 'reviewer_timeout' : 'reviewer_network');
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            return $reject("reviewer_http_{$response['status']}");
        }
        $answer = ResponsesApi::read($response['body']);
        if ($answer === null) {
            return $reject('reviewer_malformed');
        }
        $verdict = self::parseOutput($answer['text']);

        return $verdict['approved'] ? ['approved' => true, 'usage' => $answer['usage']] : $reject($verdict['reason'], $answer['usage']);
    }
}
