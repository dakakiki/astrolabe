<?php

namespace Tests\Feature\Security;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

/**
 * A new API route must be protected like the others, or be listed here as
 * public on purpose (security review, Phase 8a).
 */
class RouteProtectionTest extends TestCase
{
    /** Reachable without signing in. */
    private const PUBLIC = [
        'api/v1/status',
        'api/v1/health',
    ];

    /** Signed in, but before the email address is verified. */
    private const UNVERIFIED = [
        'api/v1/me',
    ];

    /**
     * @return list<Route>
     */
    private function apiRoutes(): array
    {
        return array_values(array_filter(
            Router::getRoutes()->getRoutes(),
            // Fortify's auth endpoints and session helpers have their own rules (config/fortify.php).
            fn (Route $route) => str_starts_with($route->uri(), 'api/v1/') && ! str_starts_with($route->uri(), 'api/v1/auth/'),
        ));
    }

    /** The operator's admin (Phase 8c): its own guard instead of a practice. */
    private function isAdminRoute(Route $route): bool
    {
        return str_starts_with($route->uri(), 'api/v1/admin/');
    }

    public function test_every_practice_route_needs_a_verified_member_of_the_workspace(): void
    {
        foreach ($this->apiRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            if (in_array($route->uri(), self::PUBLIC, true)) {
                $this->assertNotContains('auth:sanctum', $middleware, $route->uri());

                continue;
            }

            $this->assertContains('auth:sanctum', $middleware, "{$route->uri()} must require sign-in");

            if ($this->isAdminRoute($route)) {
                continue;
            }

            // `/me` alone lets an account without a practice through: the admin's.
            $this->assertContains($route->uri() === 'api/v1/me' ? 'workspace:optional' : 'workspace', $middleware, "{$route->uri()} must resolve the workspace");

            if (! in_array($route->uri(), self::UNVERIFIED, true)) {
                $this->assertContains('verified', $middleware, "{$route->uri()} must require a verified email");
            }
        }
    }

    public function test_every_admin_route_needs_the_admin_and_never_a_practice(): void
    {
        $admin = array_filter($this->apiRoutes(), fn (Route $route) => $this->isAdminRoute($route));
        $this->assertNotEmpty($admin);

        foreach ($admin as $route) {
            $middleware = $route->gatherMiddleware();
            $name = $route->uri().' '.implode('|', $route->methods());

            $this->assertContains('admin', $middleware, "{$name} must require the admin");
            $this->assertContains('verified', $middleware, $name);
            $this->assertEmpty(array_filter($middleware, fn ($entry) => is_string($entry) && str_starts_with($entry, 'workspace')), "{$name} must not resolve a practice");

            // Changing an astrologer's account asks for the password again.
            if (str_contains($route->uri(), '/astrologers/{user}/') && ! in_array('GET', $route->methods(), true)) {
                $this->assertNotEmpty(array_filter($middleware, fn ($entry) => is_string($entry) && str_starts_with($entry, 'password.confirm')), "{$name} must confirm the password");
            }
        }
    }

    /** Open while the practice is scheduled for deletion (Phase 8b): the closing screen needs them. */
    private const OPEN_WHILE_CLOSING = [
        'api/v1/me',
        'api/v1/reference-data',
        'api/v1/workspace GET|HEAD',
        'api/v1/workspace/exports GET|HEAD',
        'api/v1/workspace/exports POST',
        'api/v1/workspace/exports/{export}/download GET|HEAD',
        'api/v1/workspace/deletion POST',
        'api/v1/workspace/deletion DELETE',
    ];

    public function test_a_practice_scheduled_for_deletion_is_closed_everywhere_else(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->uri(), self::PUBLIC, true) || $this->isAdminRoute($route)) {
                continue;
            }

            $name = $route->uri().' '.implode('|', $route->methods());
            $open = in_array($route->uri(), self::OPEN_WHILE_CLOSING, true) || in_array($name, self::OPEN_WHILE_CLOSING, true);

            $this->assertSame(! $open, in_array('practice.active', $route->gatherMiddleware(), true), $name);
        }
    }

    public function test_every_api_route_counts_against_the_general_limit(): void
    {
        $this->assertContains('throttle:api', $this->app['router']->getMiddlewareGroups()['api']);

        foreach ($this->apiRoutes() as $route) {
            $this->assertContains('api', $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_the_engine_routes_and_only_they_have_the_engine_limit(): void
    {
        $limited = [];

        foreach ($this->apiRoutes() as $route) {
            if (in_array('throttle:engine', $route->gatherMiddleware(), true)) {
                $limited[] = $route->uri().' '.implode('|', $route->methods());
            }
        }

        $this->assertEqualsCanonicalizing([
            'api/v1/dashboard GET|HEAD',
            'api/v1/clients/{client}/chart GET|HEAD',
            'api/v1/clients/{client}/transits GET|HEAD',
            'api/v1/clients/{client}/synastry GET|HEAD',
            'api/v1/related-people/{relatedPerson}/chart GET|HEAD',
            'api/v1/related-people/{relatedPerson}/transits GET|HEAD',
            'api/v1/sky GET|HEAD',
            'api/v1/consultations/{consultation}/chart POST',
        ], $limited);
    }
}
