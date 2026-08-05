<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-canvas">
            <a href="/" class="flex items-center gap-2">
                <span class="h-10 w-10 rounded-lg bg-brand/15 text-brand-dim flex items-center justify-center font-display font-bold text-lg">A</span>
                <div class="leading-tight">
                    <p class="font-display font-semibold text-ink tracking-wide">ALFA</p>
                    <p class="text-[10px] uppercase tracking-widest text-ink-mute">Matriz</p>
                </div>
            </a>

            <div class="w-full sm:max-w-md mt-6 px-6 py-4 bg-panel border border-white/5 shadow-panel overflow-hidden sm:rounded-lg">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
