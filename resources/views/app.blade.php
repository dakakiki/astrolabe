<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>{{ config('app.name', 'AstroLabe') }}</title>

        {{-- Apply the stored theme before first paint to avoid a flash of the wrong one. --}}
        <script>
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
