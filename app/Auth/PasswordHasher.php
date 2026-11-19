<?php

namespace App\Auth;

/**
 * Staff passwords.
 *
 * - argon2id (default for passwords set here): PHP's built-in implementation.
 * - scrypt: the original application's format "<salt hex>:<key hex>"
 *   (N=16384, r=16, p=1, 64 bytes). Always accepted at sign-in, so accounts
 *   moved from the original application keep their passwords.
 *
 * Passwords are NFKC-normalised first, as the original did.
 */
final class PasswordHasher
{
    private const N = 16384;

    private const R = 16;

    private const P = 1;

    private const KEY_BYTES = 64;

    public function __construct(private readonly string $algorithm = 'argon2id')
    {
        if (! in_array($algorithm, ['argon2id', 'scrypt'], true)) {
            throw new \InvalidArgumentException('AUTH_PASSWORD_ALGORITHM must be argon2id or scrypt');
        }
    }

    public function hash(string $password): string
    {
        $password = self::normalize($password);
        if ($this->algorithm === 'scrypt') {
            $salt = bin2hex(random_bytes(16));

            return $salt.':'.bin2hex(self::scrypt($password, $salt));
        }

        return defined('PASSWORD_ARGON2ID')
            ? password_hash($password, PASSWORD_ARGON2ID)
            : sodium_crypto_pwhash_str($password, SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE);
    }

    public function verify(string $hash, string $password): bool
    {
        $password = self::normalize($password);
        if (str_starts_with($hash, '$argon2id$')) {
            return defined('PASSWORD_ARGON2ID') ? password_verify($password, $hash) : sodium_crypto_pwhash_str_verify($hash, $password);
        }
        $parts = explode(':', $hash);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '' || ! ctype_xdigit($parts[1])) {
            return false;
        }

        return hash_equals(strtolower($parts[1]), bin2hex(self::scrypt($password, $parts[0])));
    }

    /** True when a verified password should be stored again in the configured format. */
    public function needsRehash(string $hash): bool
    {
        return ($this->algorithm === 'argon2id') !== str_starts_with($hash, '$argon2id$');
    }

    private static function scrypt(string $password, string $salt): string
    {
        // The PECL scrypt extension, where installed, is about 50x faster.
        if (function_exists('scrypt')) {
            return hex2bin(\scrypt($password, $salt, self::N, self::R, self::P, self::KEY_BYTES));
        }

        return Scrypt::derive($password, $salt, self::N, self::R, self::P, self::KEY_BYTES);
    }

    private static function normalize(string $password): string
    {
        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($password, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        return $password;
    }
}
