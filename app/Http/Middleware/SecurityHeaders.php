<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side protection on every response (Phase 8a, security review).
 *
 * The SPA page gets a Content Security Policy: scripts only from this origin or
 * with the per-response nonce (the inline theme script in app.blade.php), no
 * plugins, no foreign forms, and no framing by another site (clickjacking; the
 * application's own pages may frame each other). Styles may be inline: the self-hosted
 * fonts come as an inline @font-face block and Vue sets style attributes; that
 * is not where script injection happens. A response that already has a policy
 * (file downloads, with their own sandbox) keeps it.
 *
 * HSTS only over HTTPS: the local host is plain HTTP. With the Vite dev server
 * running (`npm run dev`) the policy is left out, since its scripts come from
 * another origin; the built assets (`npm run build`) are what it is written for.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);
        $headers = $response->headers;

        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'same-origin');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        // PHP adds this itself unless expose_php is off (docs/deployment.md turns it off too).
        $headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $isPage = str_starts_with((string) $headers->get('Content-Type'), 'text/html');

        if ($isPage && ! $headers->has('Content-Security-Policy') && ! Vite::isRunningHot()) {
            $headers->set('Content-Security-Policy', self::policy($nonce));
        }

        return $response;
    }

    public static function policy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self'",
            "connect-src 'self'",
            "media-src 'self' blob:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
