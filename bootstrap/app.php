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
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
        ]);

        // Security middleware disabled for now - enable later when app is complete
        // Uncomment below when ready for production:

        // $middleware->api(prepend: [
        //     \App\Http\Middleware\SecurityHeaders::class,
        //     \App\Http\Middleware\SanitizeInput::class,
        //     \App\Http\Middleware\LogApiRequests::class,
        // ]);

        // Rate Limiting (basic - can enable later)
        // $middleware->throttleApi();

        // Trust Proxies (for production)
        // $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Redirect any 404 (unknown URL) to the admin login page
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, \Illuminate\Http\Request $request) {
            if (! $request->expectsJson()) {
                return redirect()->route('admin.login');
            }
        });
    })->create();
