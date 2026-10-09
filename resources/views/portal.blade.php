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
