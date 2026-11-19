<?php

namespace App\Auth;

/**
 * scrypt (RFC 7914) in plain PHP, for the password format of the original
 * application: "<salt hex>:<key hex>", N=16384, r=16, p=1, 64-byte key, with
 * the hex salt text itself used as the salt bytes.
 *
 * PHP exposes no scrypt with free parameters (sodium fixes r=8), so the mixing
 * function is implemented here on 32-bit words held in PHP integers.
 */
final class Scrypt
{
    public static function derive(string $password, string $salt, int $n, int $r, int $p, int $length): string
    {
        if ($n < 2 || ($n & ($n - 1)) !== 0) {
            throw new \InvalidArgumentException('N must be a power of two');
        }
        $blockBytes = 128 * $r;
        $blocks = hash_pbkdf2('sha256', $password, $salt, 1, $p * $blockBytes, true);
        $mixed = '';
        for ($i = 0; $i < $p; $i++) {
            $mixed .= self::roMix(substr($blocks, $i * $blockBytes, $blockBytes), $n, $r);
        }

        return hash_pbkdf2('sha256', $password, $mixed, 1, $length, true);
    }

    private static function roMix(string $block, int $n, int $r): string
    {
        /** @var list<int> $x 32*r little-endian words, 1-based from unpack */
        $x = array_values(unpack('V*', $block));
        $v = [];
        for ($i = 0; $i < $n; $i++) {
            // Packed: 128*r bytes per entry instead of a PHP array (about 10x smaller).
            $v[$i] = pack('V*', ...$x);
            $x = self::blockMix($x, $r);
        }
        $mask = $n - 1;
        $last = 32 * $r - 16;
        for ($i = 0; $i < $n; $i++) {
            $x = self::blockMix(array_values(unpack('V*', pack('V*', ...$x) ^ $v[$x[$last] & $mask])), $r);
        }

        return pack('V*', ...$x);
    }

    /**
     * @param  list<int>  $b
     * @return list<int>
     */
    private static function blockMix(array $b, int $r): array
    {
        $count = 2 * $r;
        $offset = ($count - 1) * 16;
        [$x0, $x1, $x2, $x3, $x4, $x5, $x6, $x7, $x8, $x9, $x10, $x11, $x12, $x13, $x14, $x15] = array_slice($b, $offset, 16);
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $o = $i * 16;
            $x0 ^= $b[$o];
            $x1 ^= $b[$o + 1];
            $x2 ^= $b[$o + 2];
            $x3 ^= $b[$o + 3];
            $x4 ^= $b[$o + 4];
            $x5 ^= $b[$o + 5];
            $x6 ^= $b[$o + 6];
            $x7 ^= $b[$o + 7];
            $x8 ^= $b[$o + 8];
            $x9 ^= $b[$o + 9];
            $x10 ^= $b[$o + 10];
            $x11 ^= $b[$o + 11];
            $x12 ^= $b[$o + 12];
            $x13 ^= $b[$o + 13];
            $x14 ^= $b[$o + 14];
            $x15 ^= $b[$o + 15];
            $j0 = $x0;
            $j1 = $x1;
            $j2 = $x2;
            $j3 = $x3;
            $j4 = $x4;
            $j5 = $x5;
            $j6 = $x6;
            $j7 = $x7;
            $j8 = $x8;
            $j9 = $x9;
            $j10 = $x10;
            $j11 = $x11;
            $j12 = $x12;
            $j13 = $x13;
            $j14 = $x14;
            $j15 = $x15;
            for ($round = 0; $round < 4; $round++) {
                $t = ($x0 + $x12) & 0xFFFFFFFF;
                $x4 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x4 + $x0) & 0xFFFFFFFF;
                $x8 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x8 + $x4) & 0xFFFFFFFF;
                $x12 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x12 + $x8) & 0xFFFFFFFF;
                $x0 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x5 + $x1) & 0xFFFFFFFF;
                $x9 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x9 + $x5) & 0xFFFFFFFF;
                $x13 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x13 + $x9) & 0xFFFFFFFF;
                $x1 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x1 + $x13) & 0xFFFFFFFF;
                $x5 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x10 + $x6) & 0xFFFFFFFF;
                $x14 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x14 + $x10) & 0xFFFFFFFF;
                $x2 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x2 + $x14) & 0xFFFFFFFF;
                $x6 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x6 + $x2) & 0xFFFFFFFF;
                $x10 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x15 + $x11) & 0xFFFFFFFF;
                $x3 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x3 + $x15) & 0xFFFFFFFF;
                $x7 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x7 + $x3) & 0xFFFFFFFF;
                $x11 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x11 + $x7) & 0xFFFFFFFF;
                $x15 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x0 + $x3) & 0xFFFFFFFF;
                $x1 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x1 + $x0) & 0xFFFFFFFF;
                $x2 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x2 + $x1) & 0xFFFFFFFF;
                $x3 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x3 + $x2) & 0xFFFFFFFF;
                $x0 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x5 + $x4) & 0xFFFFFFFF;
                $x6 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x6 + $x5) & 0xFFFFFFFF;
                $x7 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x7 + $x6) & 0xFFFFFFFF;
                $x4 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x4 + $x7) & 0xFFFFFFFF;
                $x5 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x10 + $x9) & 0xFFFFFFFF;
                $x11 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x11 + $x10) & 0xFFFFFFFF;
                $x8 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x8 + $x11) & 0xFFFFFFFF;
                $x9 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x9 + $x8) & 0xFFFFFFFF;
                $x10 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
                $t = ($x15 + $x14) & 0xFFFFFFFF;
                $x12 ^= (($t << 7) | ($t >> 25)) & 0xFFFFFFFF;
                $t = ($x12 + $x15) & 0xFFFFFFFF;
                $x13 ^= (($t << 9) | ($t >> 23)) & 0xFFFFFFFF;
                $t = ($x13 + $x12) & 0xFFFFFFFF;
                $x14 ^= (($t << 13) | ($t >> 19)) & 0xFFFFFFFF;
                $t = ($x14 + $x13) & 0xFFFFFFFF;
                $x15 ^= (($t << 18) | ($t >> 14)) & 0xFFFFFFFF;
            }
            $x0 = ($x0 + $j0) & 0xFFFFFFFF;
            $x1 = ($x1 + $j1) & 0xFFFFFFFF;
            $x2 = ($x2 + $j2) & 0xFFFFFFFF;
            $x3 = ($x3 + $j3) & 0xFFFFFFFF;
            $x4 = ($x4 + $j4) & 0xFFFFFFFF;
            $x5 = ($x5 + $j5) & 0xFFFFFFFF;
            $x6 = ($x6 + $j6) & 0xFFFFFFFF;
            $x7 = ($x7 + $j7) & 0xFFFFFFFF;
            $x8 = ($x8 + $j8) & 0xFFFFFFFF;
            $x9 = ($x9 + $j9) & 0xFFFFFFFF;
            $x10 = ($x10 + $j10) & 0xFFFFFFFF;
            $x11 = ($x11 + $j11) & 0xFFFFFFFF;
            $x12 = ($x12 + $j12) & 0xFFFFFFFF;
            $x13 = ($x13 + $j13) & 0xFFFFFFFF;
            $x14 = ($x14 + $j14) & 0xFFFFFFFF;
            $x15 = ($x15 + $j15) & 0xFFFFFFFF;
            // Even results fill the first half, odd results the second.
            $to = (($i >> 1) + ($i & 1) * $r) * 16;
            $out[$to] = $x0;
            $out[$to + 1] = $x1;
            $out[$to + 2] = $x2;
            $out[$to + 3] = $x3;
            $out[$to + 4] = $x4;
            $out[$to + 5] = $x5;
            $out[$to + 6] = $x6;
            $out[$to + 7] = $x7;
            $out[$to + 8] = $x8;
            $out[$to + 9] = $x9;
            $out[$to + 10] = $x10;
            $out[$to + 11] = $x11;
            $out[$to + 12] = $x12;
            $out[$to + 13] = $x13;
            $out[$to + 14] = $x14;
            $out[$to + 15] = $x15;
        }
        ksort($out);

        return $out;
    }
}
