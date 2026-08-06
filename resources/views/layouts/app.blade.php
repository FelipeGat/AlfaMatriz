<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full" data-theme="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        {{-- A versão no endereço força o navegador a buscar de novo. Favicon
             fica num cache próprio, bem mais persistente que o das páginas, e
             sobrevive até a janela anônima: sem isto, quem já visitou o site
             continuaria vendo o ícone antigo por tempo indeterminado. --}}
        <link rel="icon" type="image/svg+xml"
              href="{{ asset('favicon.svg') }}?v={{ filemtime(public_path('favicon.svg')) }}">

        {{-- Tema aplicado ANTES da primeira pintura: no <head> e síncrono.
             Se ficasse no app.js, quem usa o tema escuro veria um flash branco
             a cada navegação. --}}
        <script>
            (function () {
                try {
                    var salvo = localStorage.getItem('alfamatriz-tema');
                    if (salvo === 'light' || salvo === 'dark') {
                        document.documentElement.setAttribute('data-theme', salvo);
                    }
                } catch (e) { /* localStorage bloqueado: fica no tema padrão */ }
            })();
        </script>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=geist:400,500,600|geist-mono:400,500" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="h-full font-sans antialiased bg-bg text-ink">
        <div x-data="painel()" class="min-h-screen flex">
            @include('layouts.navigation')

            {{-- Sobreposição do menu em tela estreita --}}
            <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
                 class="fixed inset-0 z-30 bg-black/60 lg:hidden" x-transition.opacity></div>

            <div class="flex-1 flex flex-col min-w-0">
                {{-- Header fino: só breadcrumb e tema. A busca vive na sidebar. --}}
                <header class="sticky top-0 z-10 flex h-12 items-center justify-between gap-4 border-b border-line-soft bg-bg px-5">
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <button @click="sidebarOpen = true" class="lg:hidden text-dim hover:text-ink shrink-0">
                            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            </svg>
                        </button>
                        <div class="min-w-0 flex-1">
                            {{ $header ?? '' }}
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-1">
                        {{-- O ícone mostra o tema para o qual se vai, não o atual. --}}
                        <button type="button" @click="alternarTema()"
                                class="grid h-[30px] w-[30px] place-items-center rounded-control text-dim transition-colors hover:bg-raised hover:text-ink"
                                :aria-label="tema === 'dark' ? 'Mudar para tema claro' : 'Mudar para tema escuro'"
                                :title="tema === 'dark' ? 'Tema claro' : 'Tema escuro'">
                            <svg x-show="tema === 'dark'" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                                <circle cx="12" cy="12" r="4" />
                                <path stroke-linecap="round" d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
                            </svg>
                            <svg x-show="tema === 'light'" x-cloak class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
                            </svg>
                        </button>

                        <x-dropdown align="right" width="48">
                            <x-slot name="trigger">
                                <button class="grid h-[30px] w-[30px] place-items-center rounded-control text-dim transition-colors hover:bg-raised hover:text-ink">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="1" /><circle cx="12" cy="5" r="1" /><circle cx="12" cy="19" r="1" />
                                    </svg>
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

                <main class="flex-1 px-4 pb-10 pt-6 sm:px-6 lg:px-[26px]">
                    {{ $slot }}
                </main>
            </div>
        </div>

        <script>
            // Estado puramente visual do painel. Filtros e seleção continuam no
            // servidor, por query string.
            //
            // O menu não recolhe mais: a direção nova o quer fixo. O que restou
            // do Alpine aqui é o tema e a sobreposição em tela estreita.
            function painel() {
                return {
                    sidebarOpen: false,
                    tema: 'dark',

                    init() {
                        try {
                            this.tema = localStorage.getItem('alfamatriz-tema') || 'dark';
                        } catch (e) { /* localStorage bloqueado: usa o padrão */ }

                        document.documentElement.setAttribute('data-theme', this.tema);

                        // Atalho "/" foca a busca do menu, como no protótipo.
                        window.addEventListener('keydown', (e) => {
                            if (e.key !== '/' || e.metaKey || e.ctrlKey) return;
                            const alvo = e.target;
                            if (alvo && ['INPUT', 'TEXTAREA', 'SELECT'].includes(alvo.tagName)) return;
                            const busca = document.getElementById('busca-menu');
                            if (busca) { e.preventDefault(); busca.focus(); }
                        });
                    },

                    alternarTema() {
                        this.tema = this.tema === 'dark' ? 'light' : 'dark';
                        document.documentElement.setAttribute('data-theme', this.tema);
                        try {
                            localStorage.setItem('alfamatriz-tema', this.tema);
                        } catch (e) { /* sem persistência, vale só nesta aba */ }
                    },
                };
            }
        </script>
    </body>
</html>
