{{--
    Moldura do painel — sidebar + topbar + conteúdo.

    Slots:
      $titulo   título da tela (Space Grotesk 17px). Se ausente, cai no $header
                antigo, para as telas ainda não migradas continuarem de pé.
      $contexto linha de contexto em caixa alta ao lado do título
      $acoes    botão primário da tela, à direita da busca
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="icon" href="/icon-matriz.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

        {{--
            Tema E estado do menu ANTES da primeira pintura.

            Os dois são preferência guardada no navegador, e o servidor não tem
            como saber qual é. Se a decisão esperasse o Alpine, ela chegaria
            DEPOIS de a página já ter sido desenhada: quem usa o tema claro
            veria o escuro piscar, e o menu nasceria expandido para só então
            encolher — com a marca deslizando do lugar. Por isso as duas viram
            classe no <html> aqui, e o CSS resolve o resto sem esperar ninguém.
        --}}
        <script>
            (function () {
                try {
                    if (localStorage.getItem('alfamatriz:tema') === 'claro') {
                        document.documentElement.classList.add('theme-light');
                    }
                    if (localStorage.getItem('alfamatriz:rail') === 'fechado') {
                        document.documentElement.classList.add('rail-fechado');
                    }
                } catch (erro) {
                    // sem preferência guardada: tema escuro e menu expandido
                }
            })();
        </script>

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>

    <body class="h-full font-sans antialiased bg-canvas text-ink">
        <div x-data="shell" class="min-h-screen flex">
            @include('layouts.navigation')

            {{-- Véu da gaveta: só existe abaixo de 1024px --}}
            <div x-show="gavetaAberta" x-cloak @click="gavetaAberta = false"
                 class="fixed inset-0 z-30 bg-black/60 lg:hidden" x-transition.opacity></div>

            <div class="flex-1 flex flex-col min-w-0">
                <header class="sticky top-0 z-20 h-topbar shrink-0 flex items-center gap-4 bg-head border-b border-line px-4 sm:px-7">
                    {{--
                        O botão de recolher mora no topbar, e não na sidebar,
                        justamente para não mudar de lugar entre os dois
                        estados. No celular ele abre a gaveta.
                    --}}
                    <button type="button"
                            @click="window.innerWidth < 1024 ? gavetaAberta = true : alternarRail()"
                            class="h-[30px] w-[30px] shrink-0 rounded-ctl border border-btn-line text-ink-mute
                                   flex items-center justify-center transition hover:text-brand hover:border-brand"
                            :aria-expanded="railAberto.toString()"
                            aria-controls="menu-principal"
                            aria-label="Recolher ou expandir o menu">
                        <span class="h-[15px] w-[15px]"><x-nav-icon name="panel-left" /></span>
                    </button>

                    {{--
                        Prioridade de encolhimento: o título nunca cede espaço
                        (flex-none), o contexto cede primeiro (min-w-0) e a
                        busca cede antes dos dois. Sem isso o contexto — que é
                        mais longo — corta o título ao meio.
                    --}}
                    <div class="flex items-baseline gap-3 min-w-0 flex-1">
                        {{--
                            Tela filha manda o caminho inteiro (<x-migalhas>);
                            tela de topo manda só o título. A migalha ocupa o
                            lugar do h1 porque o último degrau dela É o título:
                            os dois juntos diriam a mesma coisa duas vezes.
                        --}}
                        @isset($caminho)
                            <div class="flex-none max-w-full min-w-0">{{ $caminho }}</div>
                        @elseif (isset($titulo))
                            <h1 class="flex-none max-w-full truncate font-display text-[17px] font-semibold text-ink">{{ $titulo }}</h1>
                        @else
                            <div class="flex-none max-w-full truncate font-display [&_h2]:font-display [&_h2]:text-[17px] [&_h2]:font-semibold">{{ $header ?? '' }}</div>
                        @endif

                        @isset($contexto)
                            <p class="flex-1 min-w-0 truncate font-mono text-[11px] uppercase tracking-caps text-ink-faint">{{ $contexto }}</p>
                        @endisset
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0">
                        <label class="hidden md:flex items-center gap-2 h-[34px] flex-1 min-w-[168px] max-w-[300px] px-3
                                      rounded-control bg-input border border-line text-ink-faint
                                      focus-within:border-brand transition">
                            <span class="h-4 w-4 shrink-0"><x-nav-icon name="search" /></span>
                            <input type="search" disabled
                                   placeholder="Buscar cliente, revenda, cobrança…"
                                   class="flex-1 min-w-0 bg-transparent border-0 p-0 text-[13px] text-ink placeholder-ink-faint focus:ring-0">
                            <span class="shrink-0 rounded-badge border border-btn-line px-1.5 py-px font-mono text-[11px] leading-none text-ink-faint">⌘K</span>
                        </label>

                        {{ $acoes ?? '' }}
                    </div>
                </header>

                <main class="flex-1 px-4 pt-6 pb-10 sm:px-7">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
