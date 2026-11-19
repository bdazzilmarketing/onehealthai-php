<?php

namespace App\Auth;

/** One call to an authentication endpoint, with the cookies and headers it will answer with. */
final class AuthRequest
{
    /** @var list<string> */
    public array $setCookies = [];

    /** @var array<string, string> */
    public array $headers = [];

    /**
     * @param  array<string, string>  $cookies
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public readonly array $cookies,
        public readonly array $body,
        public readonly ?string $clientIp,
        public readonly ?string $userAgent,
    ) {}

    public function string(string $key, bool $required = true): string
    {
        $value = $this->body[$key] ?? null;
        if (is_string($value)) {
            return $value;
        }
        if ($required || $value !== null) {
            throw AuthException::code(400, 'VALIDATION_ERROR');
        }

        return '';
    }
}
