<?php

namespace Tests\Feature\Portal;

use App\Http\Controllers\Portal\PwaController;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithPortal;
use Tests\TestCase;

/**
 * The client PWA's own files (Phase 9c, docs/spec/12, "PWA za klijente"): the
 * service worker from the portal's root with this build's version, the web app
 * manifest and the offline page — all without data, a session or a cookie —
 * and the VAPID keys command.
 */
class PortalPwaTest extends TestCase
{
    use InteractsWithPortal, RefreshDatabase;

    /** Neither a cookie on the response nor a portal session row behind it. */
    private function assertNoSession(TestResponse $response): void
    {
        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Set-Cookie'));
        $this->assertSame(0, DB::table(config('portal.session.table'))->count());
    }

    public function test_the_service_worker_comes_from_the_root_with_this_builds_settings(): void
    {
        $response = $this->portalGet('sw.js')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/javascript; charset=utf-8');

        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-cache', $cache);
        $this->assertStringContainsString('no-store', $cache);

        $body = $response->getContent();
        $source = file_get_contents(resource_path(PwaController::SOURCE));
        $this->assertStringStartsWith('self.__PORTAL_SW__ = {', $body);
        $this->assertStringEndsWith($source, $body);

        $settings = json_decode(Str::betweenFirst($body, 'self.__PORTAL_SW__ = ', ";\n"), true, flags: JSON_THROW_ON_ERROR);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $settings['version']);
        $this->assertSame(PwaController::version($source), $settings['version']);
        $this->assertContains('/offline', $settings['precache']);

        // Only the offline page and the build's own files are kept on the device.
        foreach ($settings['precache'] as $path) {
            $this->assertMatchesRegularExpression('#^/(offline|build/assets/[\w.-]+\.css)$#', $path);
        }

        $this->assertNoSession($response);
    }

    public function test_the_version_changes_with_the_workers_source(): void
    {
        $this->assertSame(PwaController::version('one'), PwaController::version('one'));
        $this->assertNotSame(PwaController::version('one'), PwaController::version('two'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', PwaController::version('two'));
    }

    public function test_the_manifest_installs_astrolabe_on_the_portals_origin(): void
    {
        $response = $this->portalGet('manifest.webmanifest')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');

        $manifest = $response->json();
        $this->assertSame('/', $manifest['id']);
        $this->assertSame('AstroLabe', $manifest['name']);
        $this->assertSame('AstroLabe', $manifest['short_name']);
        $this->assertSame(__('portal.manifest.description'), $manifest['description']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#090d1b', $manifest['background_color']);
        $this->assertSame('#090d1b', $manifest['theme_color']);

        // Every icon is a real PNG of the size it says.
        $this->assertNotEmpty($manifest['icons']);
        foreach ($manifest['icons'] as $icon) {
            $file = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($file);
            [$width, $height, $type] = getimagesize($file);
            $this->assertSame(IMAGETYPE_PNG, $type, $icon['src']);
            $this->assertSame($icon['sizes'], "{$width}x{$height}", $icon['src']);
        }
        $this->assertContains('maskable', array_column($manifest['icons'], 'purpose'));
        $this->assertContains('192x192', array_column($manifest['icons'], 'sizes'));
        $this->assertContains('512x512', array_column($manifest['icons'], 'sizes'));

        $this->assertNoSession($response);
    }

    public function test_the_offline_page_has_no_data_and_its_script_carries_the_nonce(): void
    {
        $response = $this->portalGet('offline')
            ->assertOk()
            ->assertSee('<title>'.e(__('portal.offline.title')).'</title>', false)
            ->assertSee(__('portal.offline.line'))
            ->assertDontSee('id="portal"', false)
            ->assertDontSee('type="module"', false);

        $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
        $this->assertMatchesRegularExpression('#<a [^>]*href=""[^>]*>\s*'.preg_quote(e(__('portal.offline.retry')), '#').'\s*</a>#', $response->getContent());

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("worker-src 'self'", $policy);
        $this->assertStringContainsString("manifest-src 'self'", $policy);
        $this->assertMatchesRegularExpression("/'nonce-([A-Za-z0-9]+)'/", $policy);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $policy, $nonce);
        $this->assertStringContainsString('<script nonce="'.$nonce[1].'">', $response->getContent());

        $this->assertNoSession($response);
    }

    public function test_the_application_host_serves_no_service_worker_or_manifest(): void
    {
        $worker = $this->get(config('app.url').'/sw.js');
        $this->assertStringNotContainsString('javascript', (string) $worker->headers->get('Content-Type'));
        $this->assertStringNotContainsString('__PORTAL_SW__', (string) $worker->getContent());

        $manifest = $this->get(config('app.url').'/manifest.webmanifest');
        $this->assertStringNotContainsString('manifest+json', (string) $manifest->headers->get('Content-Type'));
    }

    public function test_the_portal_page_links_the_manifest(): void
    {
        $this->portalGet('/')
            ->assertOk()
            ->assertSee('<link rel="manifest" href="/manifest.webmanifest">', false)
            ->assertSee('<meta name="apple-mobile-web-app-title" content="AstroLabe">', false);
    }

    public function test_the_push_keys_command_prints_a_vapid_pair(): void
    {
        // Windows PHP makes EC keys only with OPENSSL_CONF pointing at its openssl.cnf; CI runs on Linux.
        if (openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) === false) {
            $this->markTestSkipped('OpenSSL cannot make EC keys here; set OPENSSL_CONF to PHP\'s openssl.cnf.');
        }

        $this->assertSame(0, Artisan::call('portal:push-keys'));
        $output = Artisan::output();

        // An uncompressed P-256 public key (65 bytes) and its private key (32 bytes), base64url.
        $this->assertMatchesRegularExpression('/^VAPID_PUBLIC_KEY=[A-Za-z0-9_-]{87}\r?$/m', $output);
        $this->assertMatchesRegularExpression('/^VAPID_PRIVATE_KEY=[A-Za-z0-9_-]{43}\r?$/m', $output);
    }
}
