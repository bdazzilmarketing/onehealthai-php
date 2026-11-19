<?php

namespace App\Auth;

/**
 * The primitives of the original authentication library, byte-compatible, so
 * cookies, stored TOTP secrets, backup codes and link tokens keep one format.
 */
final class Crypto
{
    private const ALPHANUMERIC = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';

    /** Random string over a-z 0-9 A-Z - _ (the library's default generator). */
    public static function randomString(int $length, string $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-_'): string
    {
        $size = strlen($alphabet);
        $maxValid = intdiv(256, $size) * $size;
        $result = '';
        while (strlen($result) < $length) {
            foreach (unpack('C*', random_bytes($length * 2)) as $byte) {
                if ($byte < $maxValid) {
                    $result .= $alphabet[$byte % $size];
                    if (strlen($result) === $length) {
                        break;
                    }
                }
            }
        }

        return $result;
    }

    /** Session tokens and ids: a-z A-Z 0-9. */
    public static function generateId(int $length = 32): string
    {
        return self::randomString($length, self::ALPHANUMERIC);
    }

    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /** HMAC-SHA256, URL-safe base64 without padding (trusted-device tokens). */
    public static function hmacBase64Url(string $secret, string $value): string
    {
        return self::base64Url(hash_hmac('sha256', $value, $secret, true));
    }

    /** How link and challenge identifiers are stored: SHA-256, never the clear value. */
    public static function hashIdentifier(string $identifier): string
    {
        return self::base64Url(hash('sha256', $identifier, true));
    }

    /** "<value>.<base64 HMAC-SHA256>", URL-encoded as it travels in the Cookie header. */
    public static function signCookieValue(string $value, string $secret): string
    {
        return rawurlencode($value.'.'.base64_encode(hash_hmac('sha256', $value, $secret, true)));
    }

    /** The signed value, or null when the cookie is missing, malformed or forged. */
    public static function verifyCookieValue(?string $cookie, string $secret): ?string
    {
        if ($cookie === null || $cookie === '') {
            return null;
        }
        $cookie = rawurldecode($cookie);
        $at = strrpos($cookie, '.');
        if ($at === false || $at < 1) {
            return null;
        }
        $value = substr($cookie, 0, $at);
        $signature = substr($cookie, $at + 1);
        if (strlen($signature) !== 44 || ! str_ends_with($signature, '=')) {
            return null;
        }
        $given = base64_decode($signature, true);
        if ($given === false || ! hash_equals(hash_hmac('sha256', $value, $secret, true), $given)) {
            return null;
        }

        return $value;
    }

    /** XChaCha20-Poly1305, key SHA-256(secret), hex(nonce || ciphertext || tag). */
    public static function encrypt(string $secret, string $data): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return bin2hex($nonce.sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($data, '', $nonce, hash('sha256', $secret, true)));
    }

    public static function decrypt(string $secret, string $hex): string
    {
        $bytes = @hex2bin($hex);
        $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($bytes === false || strlen($bytes) <= $size) {
            throw new \RuntimeException('Invalid encrypted value');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes, $size), '', substr($bytes, 0, $size), hash('sha256', $secret, true));
        if ($plain === false) {
            throw new \RuntimeException('Invalid encrypted value');
        }

        return $plain;
    }
}
