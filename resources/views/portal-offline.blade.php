<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">
        <title>{{ __('portal.offline.title') }}</title>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <meta name="theme-color" content="#090d1b">

        {{-- Kept by the service worker (Phase 9c): no data, no app script; the theme as in portal.blade.php. --}}
        <script nonce="{{ Vite::cspNonce() }}">
            try {
                if (localStorage.getItem('astrolabe.theme') === 'day') {
                    document.documentElement.dataset.theme = 'day';
                }
            } catch (e) {}
        </script>

        @vite(['resources/css/portal.css'])
    </head>
    <body>
        <div class="portal-auth">
            <div class="portal-auth-top">
                <svg class="size-7" viewBox="0 0 64 64" aria-hidden="true" focusable="false">
                    <defs>
                        <radialGradient id="offline-sky" cx=".38" cy=".3" r=".8">
                            <stop offset="0" stop-color="var(--logo-sky-1)" />
                            <stop offset="1" stop-color="var(--logo-sky-2)" />
                        </radialGradient>
                    </defs>
                    <circle cx="32" cy="7.6" r="3.7" fill="none" stroke="var(--logo-limb)" stroke-width="2.2" />
                    <circle cx="32" cy="35" r="22.2" fill="url(#offline-sky)" />
                    <circle cx="32" cy="35" r="23.5" fill="none" stroke="var(--logo-limb)" stroke-width="2.6" />
                    <circle cx="32" cy="31" r="13" fill="none" stroke="var(--logo-brass)" stroke-width="1.7" />
                    <path d="M43.1 15.77L20.9 54.23" stroke="var(--logo-brass)" stroke-width="1.5" />
                    <circle cx="32" cy="35" r="2.3" fill="var(--logo-brass)" />
                    <path d="M40.15 13.88Q41.6 19.43 47.15 20.88Q41.6 22.33 40.15 27.88Q38.7 22.33 33.15 20.88Q38.7 19.43 40.15 13.88Z" fill="var(--logo-star)" />
                </svg>
                <span>{{ __('portal.title') }}</span>
            </div>
            <main class="card portal-auth-card">
                <h1>{{ __('portal.offline.title') }}</h1>
                <p class="sub">{{ __('portal.offline.line') }}</p>
                {{-- An empty link opens the address the person asked for again. --}}
                <a class="btn btn-primary" href="">{{ __('portal.offline.retry') }}</a>
            </main>
        </div>
    </body>
</html>
