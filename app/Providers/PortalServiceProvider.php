<?php

namespace App\Providers;

use App\Support\Portal\PortalContext;
use App\Support\Portal\PortalSessionManager;
use App\Support\Portal\Push\PushSender;
use App\Support\Portal\Push\WebPushSender;
use Illuminate\Auth\SessionGuard;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client portal (docs/spec/12): the same application on its own host,
 * with its own session, guard and routes.
 *
 * - `portal` guard: a session guard over the portal's own session manager
 *   (cookie `astrolabe_portal_session`, table `portal_sessions`). It sends no
 *   auth events: the astrologers' SecurityEventSubscriber listens to those and
 *   would file a client's sign-in as an astrologer's. Portal events are written
 *   to the audit log where they happen.
 * - Routes (routes/portal.php) are bound to the portal host and registered
 *   here, before the application's route files, so the application's
 *   catch-all SPA route never answers on the portal host.
 */
class PortalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PortalSessionManager::class);
        $this->app->scoped(PortalContext::class);

        // Web push in the client PWA (Phase 9c); tests bind FakePushSender.
        $this->app->singleton(PushSender::class, WebPushSender::class);
    }

    public function boot(): void
    {
        Auth::extend('portal-session', function (Application $app, string $name, array $config) {
            $guard = new SessionGuard(
                $name,
                Auth::createUserProvider($config['provider']),
                $app->make(PortalSessionManager::class)->driver(),
                hashKey: $app['config']->get('app.key'),
            );

            $guard->setCookieJar($app['cookie']);
            $guard->setRequest($app->refresh('request', $guard, 'setRequest'));

            return $guard;
        });

        $this->limitRequests();

        if (! $this->app->routesAreCached()) {
            // The PWA's own files (Phase 9c) — service worker, manifest, offline page — carry no
            // data and start no session: a browser fetching them makes no portal session row.
            Route::domain(config('portal.domain'))
                ->group(base_path('routes/portal-pwa.php'));

            Route::domain(config('portal.domain'))
                ->middleware('portal')
                ->group(base_path('routes/portal.php'));
        }
    }

    /**
     * docs/spec/12, "Ograničenja". Signing in is limited per address and per IP
     * in the controller (the address is in the body); here the code, accepting
     * an invitation, the API as a whole, and booking, moving and cancelling
     * appointments (Phase 9b) — per portal account, whatever the outcome, so
     * nobody can probe the calendar by booking in a loop.
     */
    private function limitRequests(): void
    {
        $limit = fn (string $key) => config("portal.limits.{$key}");

        RateLimiter::for('portal', fn (Request $request) => Limit::perMinute($limit('api_per_minute'))
            ->by('portal:'.(Auth::guard('portal')->id() ?? $request->ip())));

        RateLimiter::for('portal-code', fn (Request $request) => Limit::perMinutes($limit('code_per_ip')[1], $limit('code_per_ip')[0])
            ->by('portal-code:'.$request->ip()));

        RateLimiter::for('portal-invitation', fn (Request $request) => Limit::perMinutes($limit('invitation_per_ip')[1], $limit('invitation_per_ip')[0])
            ->by('portal-invitation:'.$request->ip()));

        RateLimiter::for('portal-booking', fn (Request $request) => Limit::perMinutes($limit('booking_per_account')[1], $limit('booking_per_account')[0])
            ->by('portal-booking:'.(Auth::guard('portal')->id() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('portal.errors.too_many')],
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers,
            )));

        // Switching push on and sending a test notice (Phase 9c): each one reaches a push service.
        RateLimiter::for('portal-push', fn (Request $request) => Limit::perMinutes($limit('push_per_account')[1], $limit('push_per_account')[0])
            ->by('portal-push:'.(Auth::guard('portal')->id() ?? $request->ip()))
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => __('portal.errors.too_many')],
                Response::HTTP_TOO_MANY_REQUESTS,
                $headers,
            )));
    }
}
