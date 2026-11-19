<?php

namespace App\Http\Support;

use Illuminate\Http\Request;

/**
 * Cookie values exactly as the browser sent them (still URL-encoded, not
 * decrypted). The staff session cookies are signed by App\Auth, not by
 * Laravel's cookie encryption.
 */
final class RawCookies
{
    /** @return array<string, string> */
    public static function from(Request $request): array
    {
        $cookies = [];
        foreach (explode(';', (string) $request->headers->get('cookie', '')) as $pair) {
            $at = strpos($pair, '=');
            if ($at === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $at));
            if ($name !== '' && ! isset($cookies[$name])) {
                $cookies[$name] = trim(substr($pair, $at + 1));
            }
        }

        return $cookies;
    }
}
