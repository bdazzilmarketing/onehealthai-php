<?php

namespace App\Http\Support;

/**
 * Trusted client IP for rate limiting and AI quota keys (port of
 * src/lib/client-ip.server.ts).
 *
 * A forwarded IP header is only believed when the request provably came
 * through the ingress that sets it. Anything that cannot be verified resolves
 * to null, and callers then share one conservative bucket (fail closed).
 *
 * TRUSTED_PROXY_MODE
 *   none        (default) never trust a forwarded address.
 *   cloudflare  cf-connecting-ip, only when the TCP peer is inside
 *               TRUSTED_PROXY_CIDRS (Cloudflare's published ranges).
 *   vercel      x-real-ip, only when running on Vercel (VERCEL=1).
 *   header      CLIENT_IP_HEADER, only when the TCP peer is inside
 *               TRUSTED_PROXY_CIDRS. In a comma-separated list the right-most
 *               address that is not a trusted proxy is the client.
 * A misconfigured mode is reported once and treated as "none".
 */
final class ClientIp
{
    /** @var array<string, true> */
    private static array $reported = [];

    /**
     * Validates and canonicalises an IP address so equivalent spellings share
     * one network hash: IPv4 without leading zeros; IPv6 fully expanded
     * lower-case; IPv4-mapped IPv6 collapsed to IPv4. Invalid input: null.
     */
    public static function canonicalIp(string $input): ?string
    {
        $v4 = self::v4($input);
        if ($v4 !== null) {
            return implode('.', $v4);
        }
        if (preg_match('/^[0-9a-fA-F:.]+$/D', $input) !== 1 || ! str_contains($input, ':')) {
            return null;
        }
        $head = strtolower($input);
        $tail = [];
        $lastColon = strrpos($head, ':');
        if (str_contains(substr($head, $lastColon + 1), '.')) {
            $embedded = self::v4(substr($head, $lastColon + 1));
            if ($embedded === null) {
                return null;
            }
            $tail = [($embedded[0] << 8) | $embedded[1], ($embedded[2] << 8) | $embedded[3]];
            $head = substr($head, 0, $lastColon + 1);
            if (str_ends_with($head, ':') && ! str_ends_with($head, '::')) {
                $head = substr($head, 0, -1);
            }
        }
        $halves = explode('::', $head);
        if (count($halves) > 2) {
            return null;
        }
        $left = self::groups($halves[0]);
        $right = count($halves) === 2 ? self::groups($halves[1]) : [];
        if ($left === null || $right === null) {
            return null;
        }
        $known = count($left) + count($right) + count($tail);
        if (count($halves) === 2) {
            if ($known > 7) {
                return null;
            }
            $groups = [...$left, ...array_fill(0, 8 - $known, 0), ...$right, ...$tail];
        } else {
            if ($known !== 8) {
                return null;
            }
            $groups = [...$left, ...$tail];
        }
        // IPv4-mapped IPv6 becomes IPv4, so one client cannot hold two network identities.
        if (array_sum(array_slice($groups, 0, 5)) === 0 && $groups[5] === 0xFFFF) {
            return implode('.', [$groups[6] >> 8, $groups[6] & 255, $groups[7] >> 8, $groups[7] & 255]);
        }

        return implode(':', array_map(fn (int $g) => str_pad(dechex($g), 4, '0', STR_PAD_LEFT), $groups));
    }

    /** @return array{v6: bool, base: string, bits: int}|null */
    public static function parseCidr(string $input): ?array
    {
        $parts = explode('/', trim($input));
        if (count($parts) > 2 || $parts[0] === '') {
            return null;
        }
        $canonical = self::canonicalIp($parts[0]);
        if ($canonical === null) {
            return null;
        }
        [$v6, $value] = self::toBytes($canonical);
        $width = $v6 ? 128 : 32;
        if (isset($parts[1]) && preg_match('/^\d{1,3}$/D', $parts[1]) !== 1) {
            return null;
        }
        $bits = isset($parts[1]) ? (int) $parts[1] : $width;
        if ($bits > $width) {
            return null;
        }

        return ['v6' => $v6, 'base' => $value & self::mask($bits, $width), 'bits' => $bits];
    }

    /**
     * @param  array<string, string|null>  $env
     * @return array{mode: string, header: string|null, cidrs: list<array{v6: bool, base: string, bits: int}>, onVercel: bool}
     */
    public static function loadConfig(array $env): array
    {
        $mode = strtolower(trim(($env['TRUSTED_PROXY_MODE'] ?? '') ?: 'none'));
        if (! in_array($mode, ['none', 'cloudflare', 'vercel', 'header'], true)) {
            return self::misconfigured("unknown TRUSTED_PROXY_MODE \"{$mode}\"");
        }
        $cidrs = [];
        foreach (explode(',', $env['TRUSTED_PROXY_CIDRS'] ?? '') as $entry) {
            if (trim($entry) === '') {
                continue;
            }
            $cidr = self::parseCidr($entry);
            if ($cidr === null) {
                return self::misconfigured('invalid TRUSTED_PROXY_CIDRS entry "'.trim($entry).'"');
            }
            $cidrs[] = $cidr;
        }
        $header = strtolower(trim($env['CLIENT_IP_HEADER'] ?? '')) ?: null;
        if ($mode === 'header') {
            if ($header === null || preg_match('/^[a-z0-9-]+$/D', $header) !== 1) {
                return self::misconfigured('header mode needs CLIENT_IP_HEADER');
            }
            if ($cidrs === []) {
                return self::misconfigured('header mode needs TRUSTED_PROXY_CIDRS');
            }
        }

        return ['mode' => $mode, 'header' => $header, 'cidrs' => $cidrs, 'onVercel' => ($env['VERCEL'] ?? null) === '1'];
    }

    /** The address of the TCP peer (the last hop). */
    public static function peerAddress(?string $remoteAddress): ?string
    {
        return is_string($remoteAddress) ? self::canonicalIp($remoteAddress) : null;
    }

    /**
     * The verified client address and where it came from, or the shared fallback.
     *
     * @param  callable(string): ?string  $header  lower-case header name to its value
     * @return array{ip: string|null, source: string}
     */
    public static function resolveWithSource(?string $remoteAddress, callable $header, ?array $config = null): array
    {
        $config ??= self::currentConfig();
        $none = ['ip' => null, 'source' => 'untrusted_fallback'];
        switch ($config['mode']) {
            case 'cloudflare':
                $peer = self::peerAddress($remoteAddress);
                if ($peer === null || $config['cidrs'] === [] || ! self::inCidrs($peer, $config['cidrs'])) {
                    return $none;
                }
                $ip = self::single($header('cf-connecting-ip'));

                return $ip !== null ? ['ip' => $ip, 'source' => 'cloudflare'] : $none;
            case 'vercel':
                if (! $config['onVercel']) {
                    return $none;
                }
                $ip = self::single($header('x-real-ip'));

                return $ip !== null ? ['ip' => $ip, 'source' => 'vercel'] : $none;
            case 'header':
                $peer = self::peerAddress($remoteAddress);
                if ($peer === null || $config['header'] === null || ! self::inCidrs($peer, $config['cidrs'])) {
                    return $none;
                }
                $raw = $header($config['header']);
                if ($raw === null || $raw === '' || strlen($raw) > 1024) {
                    return $none;
                }
                // Walk from the right: proxies we trust append; the first address that is
                // not one of them is the client. Anything malformed fails closed.
                $hops = array_map('trim', explode(',', $raw));
                for ($i = count($hops) - 1; $i >= 0; $i--) {
                    $hop = self::canonicalIp($hops[$i]);
                    if ($hop === null) {
                        return $none;
                    }
                    if (! self::inCidrs($hop, $config['cidrs'])) {
                        return ['ip' => $hop, 'source' => 'header'];
                    }
                }

                return $none;
            default:
                return $none;
        }
    }

    public static function currentConfig(): array
    {
        static $cache = null;
        $env = [];
        foreach (['TRUSTED_PROXY_MODE', 'TRUSTED_PROXY_CIDRS', 'CLIENT_IP_HEADER', 'VERCEL'] as $name) {
            $env[$name] = config("onehealth.env.{$name}");
        }
        $key = implode("\n", array_map('strval', $env));
        if ($cache === null || $cache['key'] !== $key) {
            $cache = ['key' => $key, 'config' => self::loadConfig($env)];
        }

        return $cache['config'];
    }

    /** @return list<int>|null */
    private static function v4(string $s): ?array
    {
        $parts = explode('.', $s);
        if (count($parts) !== 4) {
            return null;
        }
        $out = [];
        foreach ($parts as $part) {
            if (preg_match('/^\d{1,3}$/D', $part) !== 1 || (int) $part > 255) {
                return null;
            }
            $out[] = (int) $part;
        }

        return $out;
    }

    /** @return list<int>|null */
    private static function groups(string $s): ?array
    {
        if ($s === '') {
            return [];
        }
        $out = [];
        foreach (explode(':', $s) as $group) {
            if (preg_match('/^[0-9a-f]{1,4}$/D', $group) !== 1) {
                return null;
            }
            $out[] = hexdec($group);
        }

        return $out;
    }

    /** Canonical address to [is IPv6, big-endian bytes]. */
    private static function toBytes(string $canonical): array
    {
        if (str_contains($canonical, ':')) {
            return [true, hex2bin(str_replace(':', '', $canonical))];
        }

        return [false, pack('C4', ...array_map('intval', explode('.', $canonical)))];
    }

    private static function mask(int $bits, int $width): string
    {
        $mask = '';
        for ($i = 0; $i < $width / 8; $i++) {
            $take = max(0, min(8, $bits - $i * 8));
            $mask .= chr((0xFF << (8 - $take)) & 0xFF);
        }

        return $mask;
    }

    private static function inCidrs(string $canonical, array $cidrs): bool
    {
        [$v6, $value] = self::toBytes($canonical);
        $width = $v6 ? 128 : 32;
        foreach ($cidrs as $cidr) {
            if ($cidr['v6'] === $v6 && ($value & self::mask($cidr['bits'], $width)) === $cidr['base']) {
                return true;
            }
        }

        return false;
    }

    private static function single(?string $value): ?string
    {
        if ($value === null || $value === '' || strlen($value) > 64 || str_contains($value, ',')) {
            return null;
        }

        return self::canonicalIp(trim($value));
    }

    private static function misconfigured(string $message): array
    {
        if (! isset(self::$reported[$message])) {
            self::$reported[$message] = true;
            error_log("[client-ip] {$message}; no forwarded address is trusted");
        }

        return ['mode' => 'none', 'header' => null, 'cidrs' => [], 'onVercel' => false];
    }

    /** For tests: which misconfigurations were reported. */
    public static function reported(): array
    {
        return array_keys(self::$reported);
    }
}
