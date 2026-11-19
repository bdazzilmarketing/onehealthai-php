<?php

namespace App\Auth;

/** An error answered to the browser as {code, message} with an HTTP status. */
final class AuthException extends \RuntimeException
{
    private const CODES = [
        'INVALID_EMAIL' => 'Invalid email',
        'INVALID_PASSWORD' => 'Invalid password',
        'INVALID_EMAIL_OR_PASSWORD' => 'Invalid email or password',
        'INVALID_TOKEN' => 'Invalid token',
        'USER_NOT_FOUND' => 'User not found',
        'PASSWORD_TOO_SHORT' => 'Password too short',
        'PASSWORD_TOO_LONG' => 'Password too long',
        'CREDENTIAL_ACCOUNT_NOT_FOUND' => 'Credential account not found',
        'FAILED_TO_CREATE_SESSION' => 'Failed to create session',
        'FAILED_TO_GET_SESSION' => 'Failed to get session',
        'UNAUTHORIZED' => 'Unauthorized',
        'VALIDATION_ERROR' => 'Invalid body parameters',
        'TOTP_NOT_ENABLED' => 'TOTP not enabled',
        'TOTP_ALREADY_ENABLED' => 'TOTP is already enabled',
        'BACKUP_CODES_NOT_ENABLED' => "Backup codes aren't enabled",
        'INVALID_BACKUP_CODE' => 'Invalid backup code',
        'INVALID_CODE' => 'Invalid code',
        'TOO_MANY_ATTEMPTS_REQUEST_NEW_CODE' => 'Too many attempts. Please request a new code.',
        'ACCOUNT_TEMPORARILY_LOCKED' => 'Too many failed verification attempts. Your account is temporarily locked. Please try again later.',
        'INVALID_TWO_FACTOR_COOKIE' => 'Invalid two factor cookie',
    ];

    public function __construct(public readonly int $status, public readonly ?string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function code(int $status, string $code): self
    {
        return new self($status, $code, self::CODES[$code]);
    }

    public function body(): array
    {
        return array_filter(['message' => $this->getMessage(), 'code' => $this->errorCode], fn ($v) => $v !== null);
    }
}
