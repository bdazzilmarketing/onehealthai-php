<?php

namespace App\Mail;

use App\Support\Js;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Microsoft Graph sendMail with an app registration (client credentials). */
final class GraphTransport implements EmailTransport
{
    /** @var array{token: string, expiresAt: float}|null */
    private ?array $cached = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $sender,
    ) {}

    public function name(): string
    {
        return 'graph';
    }

    private function token(): string
    {
        if ($this->cached !== null && $this->cached['expiresAt'] > microtime(true) + 60) {
            return $this->cached['token'];
        }
        $response = Http::asForm()->timeout(30)->post(
            'https://login.microsoftonline.com/'.rawurlencode($this->tenantId).'/oauth2/v2.0/token',
            [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
            ],
        );
        if (! $response->successful()) {
            throw new RuntimeException("Microsoft Graph sign-in failed ({$response->status()})");
        }
        $body = $response->json();
        if (empty($body['access_token']) || ! is_string($body['access_token'])) {
            throw new RuntimeException('Microsoft Graph sign-in returned no token');
        }
        $this->cached = ['token' => $body['access_token'], 'expiresAt' => microtime(true) + (float) ($body['expires_in'] ?? 3600)];

        return $this->cached['token'];
    }

    public function send(array $message): void
    {
        $mail = [
            'subject' => $message['subject'],
            'body' => ['contentType' => 'HTML', 'content' => $message['html']],
            'toRecipients' => [['emailAddress' => ['address' => $message['to']]]],
        ];
        if (! empty($message['replyTo'])) {
            $mail['replyTo'] = [['emailAddress' => ['address' => $message['replyTo']]]];
        }
        $response = Http::withHeaders(['Authorization' => 'Bearer '.$this->token()])
            ->timeout(30)
            ->withBody(Js::stringify(['message' => $mail, 'saveToSentItems' => false]), 'application/json')
            ->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($this->sender).'/sendMail');
        if ($response->status() === 401) {
            $this->cached = null;
        }
        if ($response->status() !== 202) {
            throw new RuntimeException("Microsoft Graph send failed ({$response->status()})");
        }
    }
}
