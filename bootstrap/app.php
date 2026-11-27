<?php

use App\Http\Controllers\PanelController;
use App\Http\Middleware\DenyProjectKey;
use App\Http\Middleware\ResolveProject;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // Last, so it only answers what no route above matched (see PanelController).
        then: fn () => Route::fallback(PanelController::class),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // No `throttleApi()`: it would also cover the S3 multipart routes, which sign one request
        // per part (see AGENTS.md).
        $middleware->statefulApi();
        // Nowhere to send a guest: the API answers 401 (see withExceptions) and the panel has a
        // login page of its own. Laravel's default resolves route('login'), which this app does not
        // have, before the 401 is even raised.
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->trustProxies(at: '*');
        $middleware->alias([
            'resolve.project' => ResolveProject::class,
            'no-project-key' => DenyProjectKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The API is JSON whatever the client asks for. A browser opening an API URL sends
        // `Accept: text/html`, and Laravel then redirected a guest to a `login` route this API does
        // not have (a 500 where a 401 belongs) and drew HTML error pages for everything else.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api', 'api/*') || $request->expectsJson(),
        );

        $exceptions->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    })->create();
