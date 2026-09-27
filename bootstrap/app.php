<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'ensure.society.scope' => \App\Http\Middleware\EnsureSocietyScope::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
        ]);

        // Pure API — there is no login PAGE, so never attempt a redirect
        // for an unauthenticated request. This stops the crash at its
        // actual source (Authenticate middleware calling route('login')
        // internally), rather than trying to catch the fallout afterward.
        $middleware->redirectGuestsTo(fn () => null);
    })
    
    ->withExceptions(function (Exceptions $exceptions): void {
        // This is a pure API — there is no login PAGE to redirect an
        // unauthenticated request to. Without this, any failed/missing auth
        // token crashes with "Route [login] not defined" instead of a clean
        // 401 JSON response.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
        });
    })->create();