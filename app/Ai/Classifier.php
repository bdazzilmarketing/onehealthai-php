<?php

namespace App\Ai;

use App\Support\Js;

/**
 * Constrained semantic request classifier (Findings 4302–4308; port of
 * src/lib/ai-classifier.server.ts). A separate, minimal model call that ONLY
 * labels the request. It receives no catalog, no state, no configuration and
 * cannot produce visitor-visible text.
 */
final class Classifier
{
    public const TIMEOUT_MS = 15_000;

    public const MAX_OUTPUT_TOKENS = 80;

    public const SAFETY_CONTEXT_MAX_CHARS = 6000;

    /** @param  \Closure(string): mixed  $env */
    public function __construct(private readonly \Closure $env) {}

    public static function instructions(): string
    {
        return Data::get('CLASSIFIER_INSTRUCTIONS');
    }

    /**
     * Safety context shared by the classifier and the output reviewer (4302).
     * The generation model sees up to 24 stored messages, so the safety layers
     * must also see the visitor's earlier turns: every prior visitor message
     * (most recent last, bounded), plus the last two assistant replies. A
     * follow-up such as "which one?" is then classified with its antecedent.
     * Rejected exchanges never enter history, so this contains only accepted turns.
     *
     * @param  list<array{role: string, content: string}>  $history
     */
    public static function buildSafetyContext(array $history): string
    {
        $recent = array_slice($history, -24);
        $assistant = [];
        foreach ($recent as $i => $m) {
            if ($m['role'] === 'assistant') {
                $assistant[] = $i;
            }
        }
        $lastAssistant = array_slice($assistant, -2);
        $lines = [];
        foreach ($recent as $i => $m) {
            if ($m['role'] === 'user') {
                $lines[] = 'USER: '.Js::slice($m['content'], 0, 400);
            } elseif (in_array($i, $lastAssistant, true)) {
                $lines[] = 'ASSISTANT: '.Js::slice($m['content'], 0, 500);
            }
        }
        $out = implode("\n", $lines);
        // Keep the most recent context when the bound is reached.
        $length = Js::length($out);
        if ($length > self::SAFETY_CONTEXT_MAX_CHARS) {
            $out = Js::slice($out, $length - self::SAFETY_CONTEXT_MAX_CHARS);
        }

        return $out;
    }

    /** @param  list<array{role: string, content: string}>  $history */
    public static function buildPayload(string $model, array $history, string $message): array
    {
        $context = self::buildSafetyContext($history);

        return [
            'model' => $model,
            'instructions' => self::instructions(),
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => "EARLIER TURNS (context only):\n".($context !== '' ? $context : '(none)')."\n\nFINAL USER MESSAGE TO CLASSIFY:\n<<<\n{$message}\n>>>",
                ]],
            ]],
            'text' => ['format' => ['type' => 'json_schema', 'name' => 'request_category', 'strict' => true, 'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['category'],
                'properties' => ['category' => ['type' => 'string', 'enum' => Data::get('CLASSIFIER_CATEGORIES')]],
            ]]],
            'store' => false,
            'max_output_tokens' => self::MAX_OUTPUT_TOKENS,
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $history
     * @return array{category: string, usage: ?array{input: mixed, output: mixed}}
     *
     * @throws ClassifierError
     */
    public function classify(string $model, array $history, string $message): array
    {
        $apiKey = ($this->env)('OPENAI_API_KEY');
        if (! $apiKey) {
            throw new ClassifierError('not_configured');
        }
        $response = ResponsesApi::post($apiKey, self::buildPayload($model, $history, $message), intdiv(self::TIMEOUT_MS, 1000));
        if ($response['failure'] !== null) {
            throw new ClassifierError($response['failure']);
        }
        if ($response['status'] < 200 || $response['status'] > 299) {
            throw new ClassifierError("http_{$response['status']}");
        }
        $answer = ResponsesApi::read($response['body']);
        if ($answer === null) {
            throw new ClassifierError('invalid_response');
        }
        $parsed = json_decode($answer['text']);
        $category = $parsed instanceof \stdClass ? ($parsed->category ?? null) : null;
        if (! is_string($category) || ! in_array($category, Data::get('CLASSIFIER_CATEGORIES'), true)) {
            throw new ClassifierError('invalid_output', $answer['usage']);
        }

        return ['category' => $category, 'usage' => $answer['usage']];
    }
}
