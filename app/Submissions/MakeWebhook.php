<?php

namespace App\Submissions;

use App\Support\Js;
use Illuminate\Support\Facades\Http;

/**
 * Make.com delivery (port of src/lib/make-webhook.server.ts). The webhook
 * address and shared secret come from server configuration, and the internal
 * pricing payload is only ever sent server to server.
 */
final class MakeWebhook
{
    /** @param  \Closure(string): ?string  $env */
    public function __construct(private readonly \Closure $env) {}

    public function isConfigured(): bool
    {
        return Js::trim((string) (($this->env)('MAKE_WEBHOOK_URL') ?? '')) !== ''
            && Js::trim((string) (($this->env)('MAKE_WEBHOOK_SECRET') ?? '')) !== '';
    }

    /**
     * The error in the result is a summary without addresses or secrets.
     *
     * @param  mixed  $payload  the stored report payload
     * @return array{referenceId: mixed, deliveredAt: string, success: bool, httpStatus: ?int, error: ?string, response: ?array}
     */
    public function send(mixed $payload): array
    {
        $url = ($this->env)('MAKE_WEBHOOK_URL');
        $secret = ($this->env)('MAKE_WEBHOOK_SECRET');
        $base = [
            'referenceId' => is_object($payload) ? ($payload->referenceId ?? null) : ($payload['referenceId'] ?? null),
            'deliveredAt' => Js::isoNow(),
        ];
        if (! $url || ! $secret) {
            return $base + ['success' => false, 'httpStatus' => null, 'error' => 'Make.com is not configured', 'response' => null];
        }

        try {
            // The shared value lets Make.com reject calls that do not come from here.
            $response = Http::withHeaders(['x-make-apikey' => $secret])
                ->timeout(30)
                ->withBody(Js::stringify($payload), 'application/json')
                ->post($url);
            $status = $response->status();
            $ok = $status >= 200 && $status <= 299;

            return $base + [
                'success' => $ok,
                'httpStatus' => $status,
                'error' => $ok ? null : "Webhook responded with status {$status}",
                'response' => self::safeResponseSummary(mb_substr($response->body(), 0, 2000, 'UTF-8')),
            ];
        } catch (\Throwable) {
            return $base + ['success' => false, 'httpStatus' => null, 'error' => 'Webhook request failed', 'response' => null];
        }
    }

    /** The few harmless fields of a Make.com answer worth keeping. */
    private static function safeResponseSummary(string $body): ?array
    {
        $parsed = $body === '' ? null : json_decode($body);
        if (! is_object($parsed)) {
            return null;
        }
        $out = [];
        foreach (['success', 'referenceId', 'executionId', 'status', 'id'] as $key) {
            if (property_exists($parsed, $key)) {
                $out[$key] = $parsed->{$key};
            }
        }

        return $out === [] ? null : $out;
    }
}
