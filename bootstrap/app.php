<?php

use App\Http\Controllers\WebController;
use App\Http\Middleware\RequireStaff;
use App\Http\Middleware\SameOriginOnly;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The staff session is checked against the database on every request.
        $middleware->alias(['staff' => RequireStaff::class, 'same-origin' => SameOriginOnly::class]);
        // Pages keep no server-side state: no framework session or cookies.
        $middleware->group('web', []);
        // Outermost, so error answers carry the headers too.
        $middleware->prepend(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // A page that cannot be served shows the plain error page, never framework detail.
        $exceptions->respond(function (Response $response, Throwable $error, Request $request) {
            if ($response->getStatusCode() >= 500 && ! config('app.debug') && ! $request->is('api/*') && ! $request->expectsJson()) {
                return new Response(WebController::errorPage(), 500, ['Content-Type' => 'text/html; charset=utf-8']);
            }

            return $response;
        });
    })->create();
