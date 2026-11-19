<?php

namespace App\Auth;

/** RFC 6238 codes as the original library produces them (SHA-1, 6 digits, 30 s, one step either side). */
final class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    public static function code(string $secret, int $counter): string
    {
        $mac = hash_hmac('sha1', pack('J', $counter), $secret, true);
        $offset = ord($mac[19]) & 0x0F;
        $number = unpack('N', substr($mac, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, ?float $now = null): bool
    {
        $counter = (int) floor(($now ?? microtime(true)) / self::PERIOD);
        $matched = false;
        for ($i = -1; $i <= 1; $i++) {
            // No early exit: every candidate is compared.
            $matched = hash_equals(self::code($secret, $counter + $i), $code) || $matched;
        }

        return $matched;
    }

    public static function uri(string $secret, string $issuer, string $account): string
    {
        $query = http_build_query(['secret' => self::base32($secret), 'issuer' => $issuer, 'digits' => self::DIGITS, 'period' => self::PERIOD], '', '&', PHP_QUERY_RFC1738);

        return 'otpauth://totp/'.rawurlencode($issuer).':'.rawurlencode($account).'?'.$query;
    }

    public static function base32(string $bytes): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }
}
