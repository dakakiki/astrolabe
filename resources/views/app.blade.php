<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'AstroLabe') }}</title>
        <link rel="icon" href="/favicon.ico" sizes="48x48">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#090d1b">

        {{-- Apply the stored theme before first paint to avoid a flash of the wrong one.
             The nonce lets it past the Content Security Policy (SecurityHeaders). --}}
        <script nonce="{{ Vite::cspNonce() }}">
            try {
                if (localStorage.getItem('astrolabe.theme') === 'day') {
                    document.documentElement.dataset.theme = 'day';
                }
            } catch (e) {}
        </script>

        @fonts
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body>
        <div id="app"></div>
    </body>
</html>
