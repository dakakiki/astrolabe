<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Vite;
use Throwable;

/**
 * The client PWA's own files (Phase 9c, docs/spec/12, "PWA za klijente"):
 * the service worker, the web app manifest and the page shown without a
 * connection. None of them carries data or starts a session
 * (routes/portal-pwa.php).
 */
class PwaController extends Controller
{
    /** The service worker's source; served from the root so that it controls the whole portal. */
    public const SOURCE = 'js/portal/service-worker.js';

    /**
     * resources/js/portal/service-worker.js with this build's settings in
     * front: a version that changes with every build (the browser compares
     * the bytes, installs the new worker and the portal offers "Reload"), and
     * the few files kept for offline use — the offline page and its styles.
     * Never cached by the browser's HTTP cache.
     */
    public function serviceWorker(): Response
    {
        $source = (string) file_get_contents(resource_path(self::SOURCE));
        $settings = [
            'version' => self::version($source),
            'precache' => array_values(array_filter(['/offline', self::stylesheet()])),
        ];

        return response('self.__PORTAL_SW__ = '.json_encode($settings, JSON_UNESCAPED_SLASHES).";\n".$source, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * The installable app is AstroLabe's (docs/spec/09: the SaaS icon, no
     * icon per practice yet); the practice's name and logo show inside it
     * after signing in. Colours are the night theme's.
     */
    public function manifest(): JsonResponse
    {
        return response()->json([
            'id' => '/',
            'name' => 'AstroLabe',
            'short_name' => 'AstroLabe',
            'description' => __('portal.manifest.description'),
            'lang' => app()->getLocale(),
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#090d1b',
            'theme_color' => '#090d1b',
            'icons' => [
                ['src' => '/icons/astrolabe-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/astrolabe-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icons/astrolabe-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES);
    }

    /** "You're offline": what the service worker shows when a page cannot be loaded. */
    public function offline(): View
    {
        return view('portal-offline');
    }

    /** Changes when the build or the worker's own code changes. */
    public static function version(string $source): string
    {
        return substr(hash('sha256', (Vite::manifestHash() ?? 'dev').'|'.$source), 0, 16);
    }

    /** The portal stylesheet of this build, as a path, or null without a build (tests, `npm run dev`). */
    private static function stylesheet(): ?string
    {
        if (Vite::isRunningHot()) {
            return null;
        }

        try {
            return parse_url(Vite::asset('resources/css/portal.css'), PHP_URL_PATH) ?: null;
        } catch (Throwable) {
            return null;
        }
    }
}
