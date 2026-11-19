<?php

namespace App\Auth;

final class AuthConfig
{
    public const BASE_PATH = '/api/auth';

    public const MIN_PASSWORD_LENGTH = 12;

    public const MAX_PASSWORD_LENGTH = 128;

    public const SESSION_SECONDS = 12 * 60 * 60;

    public const SESSION_UPDATE_AGE = 60 * 60;

    public const TWO_FACTOR_COOKIE_SECONDS = 600;

    public const APP_NAME = 'One Health AI Lab Calculator';

    public const LINK_VALIDITY = [
        'invite' => ['seconds' => 72 * 60 * 60, 'words' => '72 hours'],
        'reset' => ['seconds' => 60 * 60, 'words' => '1 hour'],
    ];

    /** Endpoints the browser may call. Everything else under /api/auth answers 404. */
    public const ALLOWED_ENDPOINTS = [
        'POST /sign-in/email',
        'POST /sign-out',
        'GET /get-session',
        'POST /reset-password',
        'POST /change-password',
        'POST /two-factor/enable',
        'POST /two-factor/get-totp-uri',
        'POST /two-factor/verify-totp',
        'POST /two-factor/verify-backup-code',
    ];

    /**
     * @param  list<string>  $origins
     * @param  list<string>  $mfaRequiredRoles
     */
    public function __construct(
        public readonly string $secret,
        public readonly string $baseUrl,
        public readonly array $origins,
        public readonly array $mfaRequiredRoles,
        public readonly string $passwordAlgorithm = 'argon2id',
    ) {}

    /** @param  array<string, string|null>  $env */
    public static function fromEnv(array $env): self
    {
        $secret = $env['AUTH_SECRET'] ?? '';
        if (strlen($secret) < 32) {
            throw new \RuntimeException('AUTH_SECRET must be at least 32 characters');
        }
        $base = ($env['AUTH_BASE_URL'] ?? '') ?: (($env['ADMIN_SITE_URL'] ?? '') ?: ($env['PUBLIC_SITE_URL'] ?? ''));
        if ($base === '') {
            throw new \RuntimeException('AUTH_BASE_URL (or ADMIN_SITE_URL / PUBLIC_SITE_URL) is not configured');
        }
        $origins = [];
        foreach (['AUTH_BASE_URL', 'ADMIN_SITE_URL', 'PUBLIC_SITE_URL'] as $name) {
            if (! empty($env[$name])) {
                $origins[] = self::origin($env[$name]);
            }
        }
        // Roles that must use two-step sign-in. Default: Super Admin and Admin.
        $roles = array_values(array_filter(array_map('trim', explode(',', $env['AUTH_MFA_REQUIRED_ROLES'] ?? 'super_admin,admin'))));

        return new self($secret, self::origin($base), array_values(array_unique($origins)), $roles, strtolower(($env['AUTH_PASSWORD_ALGORITHM'] ?? '') ?: 'argon2id'));
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Invalid URL in authentication configuration');
        }
        $scheme = strtolower($parts['scheme']);
        $port = $parts['port'] ?? null;
        $default = ['http' => 80, 'https' => 443][$scheme] ?? null;

        return $scheme.'://'.strtolower($parts['host']).($port !== null && $port !== $default ? ':'.$port : '');
    }

    public function secure(): bool
    {
        return str_starts_with($this->baseUrl, 'https://');
    }

    /** "__Secure-onehealth.<name>" over HTTPS, "onehealth.<name>" otherwise. */
    public function cookieName(string $name): string
    {
        return ($this->secure() ? '__Secure-' : '').'onehealth.'.$name;
    }

    /** A complete Set-Cookie value: HttpOnly, SameSite=Lax, Secure over HTTPS. */
    public function cookie(string $name, string $encodedValue, ?int $maxAge): string
    {
        return $this->cookieName($name).'='.$encodedValue
            .($maxAge !== null ? '; Max-Age='.$maxAge : '')
            .'; Path=/; HttpOnly'.($this->secure() ? '; Secure' : '').'; SameSite=Lax';
    }

    public function signedCookie(string $name, string $value, ?int $maxAge): string
    {
        return $this->cookie($name, Crypto::signCookieValue($value, $this->secret), $maxAge);
    }

    public function expiredCookie(string $name): string
    {
        return $this->cookie($name, '', 0);
    }
}
