<?php

namespace App\Ai;

use App\Support\Js;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One request to the OpenAI Responses API: a single attempt, no automatic
 * retries, bounded by a hard timeout. Shared by the generation, classifier
 * and reviewer calls, which each decide what a failure means.
 */
final class ResponsesApi
{
    public const URL = 'https://api.openai.com/v1/responses';

    /**
     * @return array{failure: 'timeout'|'network'}|array{failure: null, status: int, body: string}
     */
    public static function post(string $apiKey, mixed $payload, int $timeoutSeconds): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => "Bearer {$apiKey}"])
                ->timeout($timeoutSeconds)
                ->connectTimeout($timeoutSeconds)
                ->withBody(Js::stringify($payload), 'application/json')
                ->post(self::URL);
        } catch (ConnectionException $error) {
            // cURL reports an exceeded time limit as error 28.
            $timedOut = str_contains($error->getMessage(), 'cURL error 28') || stripos($error->getMessage(), 'timed out') !== false;

            return ['failure' => $timedOut ? 'timeout' : 'network'];
        } catch (\Throwable) {
            return ['failure' => 'network'];
        }

        return ['failure' => null, 'status' => $response->status(), 'body' => $response->body()];
    }

    /**
     * Decodes a provider answer. Null when the body is not JSON.
     *
     * @return array{usage: ?array{input: mixed, output: mixed}, text: string, id: ?string}|null
     */
    public static function read(string $body): ?array
    {
        try {
            $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($json)) {
            throw new \UnexpectedValueException('AI_RESPONSE_SHAPE');
        }
        $usage = empty($json['usage']) || ! is_array($json['usage'])
            ? null
            : ['input' => $json['usage']['input_tokens'] ?? null, 'output' => $json['usage']['output_tokens'] ?? null];

        if (isset($json['output_text'])) {
            $text = Js::string($json['output_text']);
        } else {
            $text = '';
            foreach (is_array($json['output'] ?? null) ? $json['output'] : [] as $item) {
                foreach (is_array($item['content'] ?? null) ? $item['content'] : [] as $content) {
                    if (($content['type'] ?? null) === 'output_text') {
                        $text .= Js::string($content['text'] ?? '');
                    }
                }
            }
        }

        return ['usage' => $usage, 'text' => $text, 'id' => is_string($json['id'] ?? null) ? $json['id'] : null];
    }
}
