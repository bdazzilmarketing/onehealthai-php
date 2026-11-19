<?php

namespace App\Http\Controllers;

use App\Auth\AuthService;
use App\Http\Support\ClientIp;
use App\Http\Support\RawCookies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Request handler behind /api/auth/* (port of
 * src/lib/auth/route-handler.server.ts). Only the endpoints the admin portal
 * uses are reachable; everything else answers 404. The caller's address comes
 * from the trusted client-IP resolver only, never from a header the client
 * could set itself.
 */
final class AuthController
{
    public function __invoke(Request $request, AuthService $auth, string $path = ''): Response
    {
        try {
            $ip = ClientIp::resolveWithSource(
                $request->server->get('REMOTE_ADDR'),
                fn (string $name) => $request->headers->get($name),
            )['ip'];
            $result = $auth->handle(
                $request->getMethod(),
                '/'.$path,
                $request->headers->get('origin'),
                RawCookies::from($request),
                json_decode($request->getContent(), true),
                $ip,
                $request->headers->get('user-agent'),
            );
        } catch (\Throwable $error) {
            // Configuration and connection failures otherwise surface only as a bare
            // 500. Credentials in a connection string are masked.
            $message = $error::class.': '.$error->getMessage();
            error_log('[auth] request failed: '.preg_replace('#//[^/@\s]*@#', '//***@', $message));
            throw $error;
        }

        $response = $result->text !== null
            ? new Response($result->text, $result->status, ['Content-Type' => 'text/plain;charset=UTF-8'])
            : new Response(json_encode($result->json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $result->status, ['Content-Type' => 'application/json']);
        foreach ($result->headers as $name => $value) {
            $response->headers->set($name, $value);
        }
        foreach ($result->setCookies as $cookie) {
            // Raw: the value is already signed and URL-encoded by App\Auth.
            $response->headers->setCookie(Cookie::fromString($cookie, false));
        }

        return $response;
    }
}
