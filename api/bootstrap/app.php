<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stateless API: never redirect unauthenticated requests to a 'login'
        // route (there isn't one). Returning null here makes the auth
        // middleware throw a clean AuthenticationException, which is then
        // rendered as 401 JSON for api/* (see shouldRenderJsonWhen below).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // API is stateless (Sanctum tokens, no login route). Return a 401 JSON
        // for unauthenticated API requests instead of redirecting to a
        // nonexistent 'login' route (which otherwise throws a 500). The
        // dashboard's re-login flow keys off this 401.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return new JsonResponse(['message' => $e->getMessage()], 401);
            }
        });
    })->create();
