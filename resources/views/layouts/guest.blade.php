<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="dark">
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

        {{-- O tema salvo vale também na entrada: sem isto, quem usa o escuro
             levava um flash branco antes mesmo de logar. --}}
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

        {{-- Mesma tipografia do painel: sem isto, a tela de entrada usaria
             outra fonte e destoaria do sistema já no primeiro contato. --}}
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=geist:400,500,600|geist-mono:400,500" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-ink antialiased">
        <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-bg">
            <a href="/" class="flex items-center gap-[11px]">
                <svg class="h-[33px] w-[34px] text-ink" viewBox="2 1 44 45.6" fill="none">
                    <path d="M5 4l13 15L5 34" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
                    <path d="M43 4L30 19l13 15" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
                    <circle cx="24" cy="39" r="6.6" fill="currentColor"/>
                </svg>
                <img src="{{ asset('brand/alfamatriz-wordmark.png') }}" alt="AlfaMatriz"
                     class="h-[19px] w-auto" style="filter: var(--logo-filter);">
            </a>

            <div class="w-full sm:max-w-md mt-6 px-6 py-5 bg-panel border border-line overflow-hidden rounded-card">
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
