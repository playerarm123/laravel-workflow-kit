<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title>Structure · {{ config('app.name') }}</title>

        {{-- Follows the system's colour scheme, as the canvas does, so the cards and the canvas agree --}}
        <script>
            if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.documentElement.classList.add('dark');
            }
        </script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/kit/structure.tsx'])
    </head>
    <body class="font-sans antialiased">
        <div id="kit-structure-root"></div>

        <script type="application/json" id="kit-structure">@json(['graph' => $graph, 'endpoints' => $endpoints])</script>
    </body>
</html>
