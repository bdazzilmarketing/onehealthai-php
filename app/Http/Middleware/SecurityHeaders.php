<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser security headers on every response (port of
 * src/lib/security-headers.ts).
 *
 * script-src keeps 'unsafe-inline' because the page shell carries inline
 * start-up scripts. Everything else is same-origin only, apart from the
 * Google Fonts stylesheet and font files. A header the route already set (for
 * example a stricter one on file downloads) is left as it is.
 */
final class SecurityHeaders
{
    public const CONTENT_SECURITY_POLICY = "default-src 'self'; script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; "
        ."img-src 'self' data: blob:; connect-src 'self'; worker-src 'self' blob:; object-src 'none'; "
        ."base-uri 'self'; form-action 'self'; frame-ancestors 'none'";

    public const HEADERS = [
        'content-security-policy' => self::CONTENT_SECURITY_POLICY,
        'strict-transport-security' => 'max-age=31536000; includeSubDomains',
        'x-content-type-options' => 'nosniff',
        'x-frame-options' => 'DENY',
        'referrer-policy' => 'strict-origin-when-cross-origin',
        'permissions-policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
        'cross-origin-opener-policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        foreach (self::HEADERS as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
