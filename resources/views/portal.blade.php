<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="robots" content="noindex, nofollow">

        {{-- The practice's own name replaces this once the portal knows it. --}}
        <title>{{ __('portal.title') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#090d1b">

        {{-- The installable app (Phase 9c): AstroLabe's name and icon; the practice shows inside it. --}}
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="AstroLabe">
        {{-- A solid bar: the page starts below the clock (no safe-area insets needed), in both themes. --}}
        <meta name="apple-mobile-web-app-status-bar-style" content="black">

        {{-- The stored theme before first paint, as in the application (its own storage on this origin). --}}
        <script nonce="{{ Vite::cspNonce() }}">
            try {
                if (localStorage.getItem('astrolabe.theme') === 'day') {
                    document.documentElement.dataset.theme = 'day';
                }
            } catch (e) {}
        </script>

        @fonts
        @vite(['resources/css/portal.css', 'resources/js/portal/main.js'])
    </head>
    <body>
        <div id="portal"></div>
    </body>
</html>
