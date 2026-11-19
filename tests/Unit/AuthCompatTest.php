<?php

namespace Tests\Unit;

use App\Auth\Crypto;
use App\Auth\PasswordHasher;
use App\Auth\Totp;
use PHPUnit\Framework\TestCase;

/**
 * Values produced by the original application's authentication library
 * (Better Auth 1.7) for fixed inputs. They pin the stored and on-the-wire
 * formats, so data and cookies written by one implementation are readable by
 * the other.
 */
final class AuthCompatTest extends TestCase
{
    private const SECRET = 'test-secret-xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

    public function test_signs_and_verifies_cookies_like_the_original(): void
    {
        $cookie = 'tok_abc123.fEhkA%2B0yYxLsBsh4bKt80gR2nOs5LEUdrr4tBTvek10%3D';
        $this->assertSame($cookie, Crypto::signCookieValue('tok_abc123', self::SECRET));
        $this->assertSame('tok_abc123', Crypto::verifyCookieValue($cookie, self::SECRET));
        $this->assertNull(Crypto::verifyCookieValue(str_replace('tok_abc123', 'tok_abc124', $cookie), self::SECRET));
        $this->assertNull(Crypto::verifyCookieValue($cookie, self::SECRET.'y'));
        $this->assertNull(Crypto::verifyCookieValue(null, self::SECRET));
    }

    public function test_decrypts_two_step_secrets_stored_by_the_original(): void
    {
        $stored = '0a526e955b038c3524adc0699d60ef818d2de643210acf9112f4718e22a66107d376ad1d743d801189964e6133251cf487d47e4c28f228ee8fd5d59701b401';
        $this->assertSame('JBSWY3DPEHPK3PXP-secret', Crypto::decrypt(self::SECRET, $stored));
        $this->assertSame('round trip', Crypto::decrypt(self::SECRET, Crypto::encrypt(self::SECRET, 'round trip')));
    }

    public function test_hashes_link_tokens_like_the_original(): void
    {
        $this->assertSame('IMUqJ3lXuvod79AbI4EdsWmdAkZeIP5syfKs7-d5fQQ', Crypto::hashIdentifier('reset-password:abc'));
    }

    public function test_computes_the_same_authenticator_codes(): void
    {
        $secret = '0123456789abcdefghijklmnopqrstuv';
        $this->assertSame('046920', Totp::code($secret, intdiv(1700000000, 30)));
        $this->assertTrue(Totp::verify($secret, '046920', 1700000000.0));
        // One period of clock drift either way, no more.
        $this->assertTrue(Totp::verify($secret, '046920', 1700000000.0 + 30));
        $this->assertFalse(Totp::verify($secret, '046920', 1700000000.0 + 90));
    }

    public function test_verifies_a_password_hash_stored_by_the_original_and_upgrades_it(): void
    {
        $stored = 'fb01bfbad4f2f1c247e7a5f31bd8cdbb:31a5bf9a9548aa8d6adcb3b98ce6775582aa8df89bb49f2cd4ec95a73a97eaf39f9d01809436e9b2f4b5b6d9ef52968a730e74ead338de49b72698961801d6ed';
        $hasher = new PasswordHasher;
        $this->assertTrue($hasher->verify($stored, 'Correct horse battery staple 1!'));
        $this->assertFalse($hasher->verify($stored, 'Correct horse battery staple 2!'));
        $this->assertTrue($hasher->needsRehash($stored));

        $new = $hasher->hash('Correct horse battery staple 1!');
        $this->assertStringStartsWith('$argon2id$', $new);
        $this->assertTrue($hasher->verify($new, 'Correct horse battery staple 1!'));
        $this->assertFalse($hasher->needsRehash($new));
    }
}
