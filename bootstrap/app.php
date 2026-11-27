<?php

use App\Http\Middleware\DenyProjectKey;
use App\Http\Middleware\ResolveProject;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No `throttleApi()`: it would also cover the S3 multipart routes, which sign one request
        // per part (see AGENTS.md).
        $middleware->statefulApi();
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'resolve.project' => ResolveProject::class,
            'no-project-key' => DenyProjectKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    })->create();
