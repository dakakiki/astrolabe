<?php

namespace Tests\Concerns;

use App\Enums\PortalAccessStatus;
use App\Models\Client;
use App\Models\PortalAccess;
use App\Models\PortalLoginToken;
use App\Models\PortalUser;
use App\Support\Portal\PortalSessionManager;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Requests to the client portal as a browser makes them (docs/spec/12): on the
 * portal host, carrying only the portal's session cookie from the previous
 * response — guards, session stores and scoped instances are rebuilt for
 * each request, so nothing carries over in memory the way it would not in
 * production.
 */
trait InteractsWithPortal
{
    private ?string $portalCookie = null;

    protected function portalUrl(string $path = ''): string
    {
        return 'http://'.config('portal.domain').'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function portal(string $method, string $path, array $data = []): TestResponse
    {
        $this->freshPortalRequest();

        $response = $this->json($method, $this->portalUrl('api/portal/v1/'.ltrim($path, '/')), $data);
        $this->rememberPortalCookie($response);

        return $response;
    }

    /** A plain GET (a page, a file) on the portal host with the portal's cookie. */
    protected function portalGet(string $path): TestResponse
    {
        $this->freshPortalRequest();

        $response = $this->get($this->portalUrl($path));
        $this->rememberPortalCookie($response);

        return $response;
    }

    /** As if the browser were closed and its cookies cleared. */
    protected function forgetPortalCookie(): void
    {
        $this->portalCookie = null;
    }

    protected function portalCookie(): ?string
    {
        return $this->portalCookie;
    }

    /** Continue as another browser that kept this session cookie. */
    protected function usePortalCookie(?string $cookie): void
    {
        $this->portalCookie = $cookie;
    }

    /** An account with an accepted link to the client, without going through the invitation. */
    protected function portalUserFor(Client $client, ?PortalUser $user = null): PortalUser
    {
        $user ??= PortalUser::factory()->create(['email' => mb_strtolower($client->email ?? fake()->unique()->safeEmail())]);

        $access = new PortalAccess([
            'client_id' => $client->id,
            'portal_user_id' => $user->id,
            'email' => $user->email,
            'status' => PortalAccessStatus::Active,
            'invited_at' => now()->subDay(),
            'accepted_at' => now()->subDay(),
        ]);
        $access->workspace_id = $client->workspace_id;
        $access->save();

        return $user;
    }

    /** Signs in through an emailed link, the way the portal does. */
    protected function signInToPortal(PortalUser $user): TestResponse
    {
        [, $token] = PortalLoginToken::issue($user, Request::create('/'));

        return $this->portal('POST', 'sign-in/link', ['token' => $token])->assertOk();
    }

    private function freshPortalRequest(): void
    {
        $this->app->make(PortalSessionManager::class)->forgetDrivers();
        $this->app['auth']->forgetGuards();
        $this->app->forgetScopedInstances();

        // JSON requests in tests carry cookies only "with credentials".
        $this->withCredentials();
        $this->unencryptedCookies = [];

        if ($this->portalCookie !== null) {
            $this->withUnencryptedCookie(config('portal.session.cookie'), $this->portalCookie);
        }
    }

    private function rememberPortalCookie(TestResponse $response): void
    {
        $cookie = collect($response->headers->getCookies())
            ->first(fn (Cookie $cookie) => $cookie->getName() === config('portal.session.cookie'));

        if ($cookie !== null) {
            $this->portalCookie = $cookie->isCleared() ? null : $cookie->getValue();
        }

        // Relative URLs in later requests are built from the last request; the
        // astrologers' requests must go to the application's host again.
        $this->app['url']->setRequest(Request::create(config('app.url')));
        $this->withCredentials = false;
        $this->unencryptedCookies = [];
    }
}
