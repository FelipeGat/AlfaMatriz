<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon-16.png" type="image/png" sizes="16x16">
        <link rel="icon" href="/favicon-32.png" type="image/png" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700|space-grotesk:500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-canvas text-ink">
        <div x-data="{ sidebarOpen: false }" class="min-h-screen flex">
            @include('layouts.navigation')

            {{-- Mobile sidebar overlay --}}
            <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
                 class="fixed inset-0 z-30 bg-black/60 lg:hidden" x-transition.opacity></div>

            <div class="flex-1 flex flex-col min-w-0">
                <header class="sticky top-0 z-10 flex items-center justify-between gap-4 border-b border-white/5 bg-canvas/90 backdrop-blur px-4 py-4 sm:px-6 lg:px-8">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <button @click="sidebarOpen = true" class="lg:hidden text-ink-dim hover:text-ink shrink-0">
                            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <div class="min-w-0 flex-1 font-display [&_h2]:font-display">
                            {{ $header ?? '' }}
                        </div>
                    </div>

                    <div class="flex items-center gap-3 shrink-0">
                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button class="flex items-center gap-2 rounded-full bg-panel hover:bg-panel-raised border border-white/5 pl-1 pr-3 py-1 transition">
                                    <span class="h-7 w-7 rounded-full bg-brand/20 text-brand-dim flex items-center justify-center text-xs font-semibold">
                                        {{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}
                                    </span>
                                    <span class="hidden sm:block text-sm text-ink-dim">{{ Auth::user()->name }}</span>
                                </button>
                            </x-slot>
                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile.edit')">Perfil</x-dropdown-link>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <x-dropdown-link :href="route('logout')"
                                            onclick="event.preventDefault(); this.closest('form').submit();">
                                        Sair
                                    </x-dropdown-link>
                                </form>
                            </x-slot>
                        </x-dropdown>
                    </div>
                </header>

                <main class="flex-1 px-4 py-6 sm:px-6 lg:px-8">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
