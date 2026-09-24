<?php

use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // First-party SPA authenticates through Sanctum's cookie session.
        $middleware->statefulApi();

        $middleware->web(append: [SetLocale::class]);
        $middleware->api(append: [SetLocale::class]);

        $middleware->alias([
            'workspace' => ResolveCurrentWorkspace::class,
            'idempotent' => EnsureIdempotency::class,
        ]);

        // Route model binding must already see the tenant scope, so the workspace is
        // resolved after authentication but before bindings are substituted.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCurrentWorkspace::class,
        );

        // Signed-in users hitting a guest-only endpoint, and guests hitting a
        // protected page, are sent to the SPA's own screens.
        $middleware->redirectUsersTo('/');
        $middleware->redirectGuestsTo('/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
