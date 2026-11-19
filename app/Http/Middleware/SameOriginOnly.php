<?php

namespace App\Http\Middleware;

use App\Auth\AuthConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses cross-site calls to the /api/fn/ endpoints, as the original
 * server functions did: the browser's Sec-Fetch-Site must say same-origin;
 * without it the Origin, and without that the Referer, must be this site.
 * A request that carries none of the three is refused.
 */
final class SameOriginOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->allowed($request) ? $next($request) : new Response('Forbidden', 403, ['Content-Type' => 'text/plain;charset=UTF-8']);
    }

    private function allowed(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null) {
            return $fetchSite === 'same-origin';
        }
        $own = $this->ownOrigins($request);
        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            return in_array($origin, $own, true);
        }
        $referer = $request->headers->get('Referer');
        if ($referer === null) {
            return false;
        }
        foreach ($own as $candidate) {
            if ($referer === $candidate || (str_starts_with($referer, $candidate) && in_array($referer[strlen($candidate)], ['/', '?', '#'], true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The origin this request was addressed to, plus the configured site
     * addresses (behind a proxy the request itself may look like plain HTTP).
     *
     * @return list<string>
     */
    private function ownOrigins(Request $request): array
    {
        $origins = [$request->getSchemeAndHttpHost()];
        foreach (['AUTH_BASE_URL', 'ADMIN_SITE_URL', 'PUBLIC_SITE_URL'] as $name) {
            $url = config("onehealth.env.{$name}");
            if (is_string($url) && $url !== '') {
                try {
                    $origins[] = AuthConfig::origin($url);
                } catch (\Throwable) {
                    // an unusable setting grants nothing
                }
            }
        }

        return $origins;
    }
}
