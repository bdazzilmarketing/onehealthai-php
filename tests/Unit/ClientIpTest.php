<?php

namespace Tests\Unit;

use App\Http\Support\ClientIp;
use PHPUnit\Framework\TestCase;

/** Port of src/lib/client-ip.server.test.ts. */
final class ClientIpTest extends TestCase
{
    private const SPOOF = [
        'x-forwarded-for' => '1.1.1.1',
        'x-real-ip' => '2.2.2.2',
        'cf-connecting-ip' => '3.3.3.3',
        'true-client-ip' => '4.4.4.4',
        'x-client-ip' => '5.5.5.5',
        'forwarded' => 'for=6.6.6.6',
        'x-waf-client-ip' => '7.7.7.7',
        'cf-ray' => 'abc',
    ];

    private function resolve(?string $peer, array $headers, array $config): array
    {
        return ClientIp::resolveWithSource($peer, fn (string $name) => $headers[$name] ?? null, $config);
    }

    private function header(string $cidrs = '10.0.0.0/8,fd00::/8'): array
    {
        return ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'header', 'CLIENT_IP_HEADER' => 'X-WAF-Client-IP', 'TRUSTED_PROXY_CIDRS' => $cidrs]);
    }

    public function test_default_trusts_no_forwarded_header_from_anyone(): void
    {
        $config = ClientIp::loadConfig([]);
        $this->assertSame('none', $config['mode']);
        foreach (['10.0.0.1', '127.0.0.1', null] as $peer) {
            $this->assertSame(['ip' => null, 'source' => 'untrusted_fallback'], $this->resolve($peer, self::SPOOF, $config));
        }
    }

    public function test_header_mode_uses_the_configured_header_only_when_the_peer_is_a_trusted_proxy(): void
    {
        $config = $this->header();
        $this->assertSame(['ip' => '7.7.7.7', 'source' => 'header'], $this->resolve('10.1.2.3', self::SPOOF, $config));
        // Direct to origin from the internet: the same header is ignored.
        $this->assertNull($this->resolve('203.0.113.9', self::SPOOF, $config)['ip']);
        // A runtime that does not expose the peer cannot be verified.
        $this->assertNull($this->resolve(null, self::SPOOF, $config)['ip']);
        // Other spoofable headers are never read, even from a trusted peer.
        $others = self::SPOOF;
        unset($others['x-waf-client-ip']);
        $this->assertNull($this->resolve('10.1.2.3', $others, $config)['ip']);
    }

    public function test_header_mode_takes_the_rightmost_address_that_is_not_a_trusted_proxy(): void
    {
        $at = fn (string $value) => $this->resolve('10.0.0.2', ['x-waf-client-ip' => $value], $this->header())['ip'];
        $this->assertSame('198.51.100.7', $at('9.9.9.9, 198.51.100.7, 10.0.0.9'));
        $this->assertSame('198.51.100.7', $at('198.51.100.7'));
        $this->assertNull($at('10.0.0.3, 10.0.0.4'));
        $this->assertNull($at('198.51.100.7, not-an-ip, 10.0.0.9'));
        $this->assertNull($at('198.51.100.7, , 10.0.0.9'));
        $this->assertNull($at(str_repeat('1.1.1.1, ', 200).'1.1.1.1'));
    }

    public function test_collapses_ipv6_spellings_and_ipv4_mapped_addresses_to_one_identity(): void
    {
        $at = fn (string $peer, string $value) => $this->resolve($peer, ['x-waf-client-ip' => $value], $this->header())['ip'];
        $this->assertSame('198.51.100.7', $at('::ffff:10.0.0.2', '::ffff:198.51.100.7'));
        $this->assertSame($at('10.0.0.2', '2001:0db8:0:0:0:0:0:1'), $at('fd00::1', '2001:DB8::1'));
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0001', $at('fd00::1', '2001:db8::1'));
        $this->assertSame('10.0.0.2', ClientIp::peerAddress('::ffff:10.0.0.2'));
    }

    public function test_falls_back_to_the_shared_bucket_when_misconfigured_and_says_so_once(): void
    {
        $log = ini_set('error_log', '/dev/null');
        try {
            $this->assertSame('none', ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'header', 'CLIENT_IP_HEADER' => 'x-a'])['mode']);
            $this->assertSame('none', ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'header', 'TRUSTED_PROXY_CIDRS' => '10.0.0.0/8'])['mode']);
            $this->assertSame('none', ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'header', 'CLIENT_IP_HEADER' => 'x-a', 'TRUSTED_PROXY_CIDRS' => '10.0.0.0/33'])['mode']);
            $this->assertSame('none', ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'trust-everyone'])['mode']);
            $this->assertSame('none', ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'trust-everyone'])['mode']);
            $this->assertCount(1, array_filter(ClientIp::reported(), fn ($m) => str_contains($m, 'trust-everyone')));
        } finally {
            ini_set('error_log', (string) $log);
        }
    }

    public function test_cloudflare_mode_reads_cf_connecting_ip_only_from_a_cloudflare_peer(): void
    {
        $config = ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'cloudflare', 'TRUSTED_PROXY_CIDRS' => '173.245.48.0/20,2400:cb00::/32']);
        $this->assertSame(['ip' => '3.3.3.3', 'source' => 'cloudflare'], $this->resolve('173.245.48.10', self::SPOOF, $config));
        $this->assertSame('3.3.3.3', $this->resolve('2400:cb00::5', self::SPOOF, $config)['ip']);
        // cf-ray and the right Host do not make an arbitrary peer Cloudflare.
        $this->assertNull($this->resolve('198.51.100.1', self::SPOOF, $config)['ip']);
        // Without ranges nothing can be verified.
        $this->assertNull($this->resolve('173.245.48.10', self::SPOOF, ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'cloudflare']))['ip']);
    }

    public function test_cloudflare_mode_refuses_a_list_or_garbage(): void
    {
        $config = ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'cloudflare', 'TRUSTED_PROXY_CIDRS' => '173.245.48.0/20']);
        foreach (['1.1.1.1, 2.2.2.2', 'example.com', ''] as $value) {
            $this->assertNull($this->resolve('173.245.48.10', ['cf-connecting-ip' => $value], $config)['ip']);
        }
    }

    public function test_vercel_mode_reads_x_real_ip_only_when_running_on_vercel(): void
    {
        $off = ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'vercel']);
        $on = ClientIp::loadConfig(['TRUSTED_PROXY_MODE' => 'vercel', 'VERCEL' => '1']);
        $this->assertNull($this->resolve(null, self::SPOOF, $off)['ip']);
        $this->assertSame(['ip' => '2.2.2.2', 'source' => 'vercel'], $this->resolve(null, self::SPOOF, $on));
        $this->assertNull($this->resolve(null, ['x-forwarded-for' => '1.1.1.1'], $on)['ip']);
    }

    public function test_cidr_parsing(): void
    {
        $this->assertSame([false, 8], [ClientIp::parseCidr('10.0.0.0/8')['v6'], ClientIp::parseCidr('10.0.0.0/8')['bits']]);
        $this->assertSame(32, ClientIp::parseCidr('10.1.2.3')['bits']);
        $this->assertSame([true, 0], [ClientIp::parseCidr('::/0')['v6'], ClientIp::parseCidr('::/0')['bits']]);
        $this->assertSame([true, 32], [ClientIp::parseCidr('2400:cb00::/32')['v6'], ClientIp::parseCidr('2400:cb00::/32')['bits']]);
        foreach (['10.0.0.0/33', '::/129', '10.0.0/8', '10.0.0.0/8/1', '10.0.0.0/-1', 'x/8', ''] as $bad) {
            $this->assertNull(ClientIp::parseCidr($bad), $bad);
        }
        $this->assertSame('10.0.0.1', ClientIp::canonicalIp('010.000.000.001'));
    }
}
