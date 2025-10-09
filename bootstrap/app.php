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
    ->withMiddleware(function (Middleware $middleware) {
        // Register your custom middleware
        $middleware->alias([
            'auth.barangay' => \App\Http\Middleware\AuthTokenValid::class,
            'auth.admin' => \App\Http\Middleware\AdminAuthTokenValid::class,
        ]);

        // If using Sanctum, you can also add:
        $middleware->statefulApi();
        //
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
