<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        // Do not redirect unauthenticated API requests to a login page.
        $middleware->redirectGuestsTo(
            fn (Request $request) => null
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Always return JSON errors for API requests.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $exception) =>
                $request->is('api/*') || $request->expectsJson()
        );
    })->create();
