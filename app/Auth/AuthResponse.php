<?php

namespace App\Auth;

final class AuthResponse
{
    /**
     * @param  list<string>  $setCookies  complete Set-Cookie header values
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly mixed $json,
        public readonly array $setCookies = [],
        public readonly array $headers = [],
        public readonly ?string $text = null,
    ) {}
}
