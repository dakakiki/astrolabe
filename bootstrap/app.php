<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureIdempotency;
use App\Http\Middleware\EnsureLegalAccepted;
use App\Http\Middleware\EnsurePracticeIsActive;
use App\Http\Middleware\Portal\AuthenticatePortal;
use App\Http\Middleware\Portal\PreventPortalRequestForgery;
use App\Http\Middleware\Portal\RejectOnPortalHost;
use App\Http\Middleware\Portal\ResolvePortalAccess;
use App\Http\Middleware\Portal\SetPortalLocale;
use App\Http\Middleware\Portal\StartPortalSession;
use App\Http\Middleware\ResolveCurrentWorkspace;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Support\Operations\OperatorAlerts;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
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

        // Every API call counts against the "api" limiter; engine endpoints also
        // against "engine" (AppServiceProvider::limitRequests, routes/api.php).
        $middleware->throttleApi();

        // CSP, HSTS and friends on every response, pages and API alike.
        $middleware->append(SecurityHeaders::class);

        // The astrologers' routes — Fortify's and Sanctum's included — do not exist on
        // the client portal's host (Phase 9a).
        $middleware->web(prepend: [RejectOnPortalHost::class], append: [SetLocale::class]);
        $middleware->api(prepend: [RejectOnPortalHost::class], append: [SetLocale::class]);

        // The client portal (docs/spec/12): its own session cookie and table and its own
        // CSRF cookie, all for the portal host only (PortalServiceProvider, routes/portal.php).
        $middleware->group('portal', [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartPortalSession::class,
            PreventPortalRequestForgery::class,
            SetPortalLocale::class,
        ]);

        $middleware->alias([
            'workspace' => ResolveCurrentWorkspace::class,
            'practice.active' => EnsurePracticeIsActive::class,
            'legal.accepted' => EnsureLegalAccepted::class,
            'admin' => EnsureAdmin::class,
            'idempotent' => EnsureIdempotency::class,
            'portal.auth' => AuthenticatePortal::class,
            'portal.access' => ResolvePortalAccess::class,
        ]);

        // Route model binding must already see the tenant scope, so the workspace is
        // resolved after authentication but before bindings are substituted — in the
        // application and in the portal alike.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveCurrentWorkspace::class,
        );
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolvePortalAccess::class,
        );

        // Signed-in users hitting a guest-only endpoint, and guests hitting a
        // protected page, are sent to the SPA's own screens.
        $middleware->redirectUsersTo('/');
        // The export link from an email comes back after signing in (Phase 8b).
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('exports/*')
            ? '/login?'.http_build_query(['redirect' => $request->getRequestUri()])
            : '/login');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Errors worth reporting (not 404s, validation or sign-in) are also
        // emailed to the operator, once an hour per error and place. The log
        // entry is written as before.
        $exceptions->report(function (Throwable $e) {
            try {
                OperatorAlerts::exception($e);
            } catch (Throwable) {
                // Reporting must never fail because of the alert.
            }
        });
    })->create();
