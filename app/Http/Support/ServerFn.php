<?php

namespace App\Http\Support;

use App\Support\AppError;
use App\Support\Js;
use Symfony\Component\HttpFoundation\Response;

/**
 * Responses for the JSON endpoints that replace the original server
 * functions (/api/fn/<name>). A result is answered as JSON with numbers
 * formatted exactly as the original did. An AppError answers 500 with its
 * message, as a thrown server-function error did; anything else is logged and
 * answered without detail.
 */
final class ServerFn
{
    /** @param  callable(): mixed  $handler */
    public static function respond(callable $handler): Response
    {
        try {
            return self::json($handler());
        } catch (AppError $error) {
            return self::json(['message' => $error->getMessage()], 500);
        } catch (\Throwable $error) {
            error_log('[server-fn] '.$error::class.': '.preg_replace('#//[^/@\s]*@#', '//***@', $error->getMessage()));

            return self::json(['message' => 'Internal Server Error'], 500);
        }
    }

    public static function json(mixed $value, int $status = 200): Response
    {
        return new Response(Js::stringify($value), $status, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }
}
