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
        {{-- `viewport-fit=cover` deixa a tela ir até as bordas do iPhone (o
             entalhe e a barra de baixo são devolvidos por `safe-area-inset`
             no CSS); `resizes-content` faz o teclado encolher a página em vez
             de cobrir o campo. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'AlfaMatriz') }}</title>

        <link rel="icon" href="/icon-matriz-solid.svg" type="image/svg+xml">
        <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        @include('layouts._cabeca-celular')


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
                    // Sem escolha guardada, o celular segue o claro/escuro do
                    // aparelho, como os apps do iPhone; no computador o padrão
                    // continua o escuro.
                    var tema = localStorage.getItem('alfamatriz:tema');
                    if (tema === 'claro' || (tema === null
                        && matchMedia('(max-width: 767px)').matches
                        && matchMedia('(prefers-color-scheme: light)').matches)) {
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

    @php
        // As raízes das abas do celular (layouts/abas-celular) e a tela inicial
        // da conta não ganham o "voltar": não há de onde ter vindo.
        $raizDeAba = request()->routeIs('secretario.meu-dia', 'tarefas.index', 'agenda.index', 'central-ia.index')
            || request()->path() === ltrim(parse_url(auth()->user()->telaInicial(), PHP_URL_PATH) ?? '', '/');
    @endphp
    <body class="h-full font-sans antialiased bg-canvas text-ink">
        <div x-data="shell" class="min-h-screen flex">
            @include('layouts.navigation')

            {{-- Véu da gaveta: só existe abaixo de 1024px --}}
            <div x-show="gavetaAberta" x-cloak @click="gavetaAberta = false"
                 class="fixed inset-0 z-30 bg-black/60 lg:hidden" x-transition.opacity></div>

            @include('layouts.notificacoes')

            @include('layouts.abas-celular')

            <div class="flex-1 flex flex-col min-w-0">
                {{--
                    No celular (abaixo de 768px) a topbar vira a barra de
                    navegação do iPhone: a primeira linha leva o voltar, o sino
                    e as ações; o título desce para a linha de baixo, grande,
                    como nos apps do sistema. O fundo vira vidro para o
                    conteúdo passar por baixo ao rolar, e o topo devolve a
                    altura do entalhe (`safe-area-inset-top`).
                --}}
                <header class="sticky top-0 z-20 h-topbar shrink-0 flex items-center gap-4 bg-head border-b border-line px-4 sm:px-7
                               max-md:h-auto max-md:flex-wrap max-md:gap-x-2 max-md:gap-y-1.5 max-md:pb-2.5
                               max-md:pt-[calc(8px+env(safe-area-inset-top))] max-md:bg-head/85 max-md:backdrop-blur-xl">
                    {{--
                        O botão de recolher mora no topbar, e não na sidebar,
                        justamente para não mudar de lugar entre os dois
                        estados. No celular ele abre a gaveta.
                    --}}
                    <button type="button"
                            @click="window.innerWidth < 1024 ? gavetaAberta = true : alternarRail()"
                            class="max-md:hidden h-[30px] w-[30px] shrink-0 rounded-ctl border border-btn-line text-ink-mute
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
                    {{--
                        O voltar do celular. Tela que é item do menu volta ao
                        "Mais", de onde se chegou a ela; tela de dentro volta
                        à anterior. As quatro telas das abas não têm voltar,
                        como as raízes de aba do iPhone.
                    --}}
                    @unless ($raizDeAba)
                        <button type="button" @click="voltar()"
                                class="md:hidden -ml-1.5 flex h-[34px] min-w-0 items-center gap-0.5 pr-2 text-[16px] text-brand-text">
                            <span class="h-[22px] w-[22px] shrink-0"><x-nav-icon name="chevron-left" :peso="2" /></span>
                            <span x-text="telaDoMenu ? 'Mais' : 'Voltar'">Voltar</span>
                        </button>
                    @endunless

                    <div class="flex items-baseline gap-3 min-w-0 flex-1 max-md:order-last max-md:basis-full">
                        {{--
                            Tela filha manda o caminho inteiro (<x-migalhas>);
                            tela de topo manda só o título. A migalha ocupa o
                            lugar do h1 porque o último degrau dela É o título:
                            os dois juntos diriam a mesma coisa duas vezes.
                        --}}
                        @isset($caminho)
                            <div class="flex-none max-w-full min-w-0">{{ $caminho }}</div>
                        @elseif (isset($titulo))
                            <h1 data-titulo-celular class="flex-none max-w-full truncate font-display text-[17px] font-semibold text-ink max-md:text-[28px] max-md:leading-tight max-md:whitespace-normal max-md:line-clamp-2">{{ $titulo }}</h1>
                        @else
                            <div data-titulo-celular class="flex-none max-w-full truncate font-display [&_h2]:font-display [&_h2]:text-[17px] [&_h2]:font-semibold max-md:[&_h2]:text-[28px] max-md:[&_h2]:leading-tight max-md:whitespace-normal max-md:line-clamp-2">{{ $header ?? '' }}</div>
                        @endif

                        @isset($contexto)
                            <p class="flex-1 min-w-0 truncate font-mono text-[11px] uppercase tracking-caps text-ink-faint max-md:hidden">{{ $contexto }}</p>
                        @endisset
                    </div>

                    <div class="flex items-center gap-2.5 shrink-0 max-md:ml-auto max-md:min-w-0 max-md:shrink max-md:flex-wrap max-md:justify-end max-md:gap-2">
                        {{-- O sino do celular: o do computador mora no rodapé
                             do menu, que no celular é a aba "Mais". Mesmo
                             estado e mesmo painel do `shell`. --}}
                        @unless (auth()->user()->ehContaDeExibicao())
                            <button type="button" @click="sinoAberto ? (sinoAberto = false) : abrirSino()"
                                    class="md:hidden relative h-[34px] w-[34px] shrink-0 rounded-full text-ink-dim flex items-center justify-center"
                                    :class="(sinoAberto || sinoAviso) && 'bg-chip text-ink'"
                                    aria-label="Notificações">
                                <span class="relative h-[22px] w-[22px]" :class="sinoAviso && 'text-crit'"><x-nav-icon name="bell" :peso="1.7" /></span>
                                <span x-show="naoLidas > 0" x-cloak x-text="naoLidas"
                                      class="absolute top-0 right-0 min-w-[16px] h-[16px] px-1 rounded-full bg-crit text-white
                                             font-sans tabular text-[10px] font-semibold leading-[16px] text-center"></span>
                            </button>
                        @endunless

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

                {{-- No celular o fim da página sobe acima da barra de abas,
                     que flutua por cima do conteúdo. --}}
                <main class="flex-1 px-4 pt-6 pb-10 sm:px-7 max-md:pt-4 max-md:pb-[calc(96px+env(safe-area-inset-bottom))]">
                    {{ $slot }}
                </main>
            </div>
        </div>

        {{--
            Onde os avisos pousam.

            A pilha é fixa e fica FORA do fluxo de propósito: enquanto o aviso
            nascia no meio do conteúdo, aparecer um custava à página inteira a
            altura dele — o mesmo layout tinha duas alturas, e quem estava
            lendo (ou mirando um botão) perdia o lugar no instante em que a
            confirmação chegava. Aqui ele passa por cima, sem mover nada.

            Quem entrega os avisos aqui é o componente aviso, de qualquer
            canto da tela. A faixa em si não recebe clique: ela cobre o topo
            inteiro e roubaria o que está embaixo — só os avisos voltam a
            receber ponteiro.
        --}}
        <div id="pilha-avisos"
             class="fixed top-topbar left-1/2 -translate-x-1/2 z-[60] pt-3 px-4 w-full
                    flex flex-col items-center gap-2 pointer-events-none"></div>

        {{-- O relógio da sessão: o aviso antes de derrubar e a volta ao login.
             Só na moldura de dentro — do lado de fora não há sessão a perder,
             e o login já tem o próprio renovador de token. --}}
        @include('layouts.sessao')

        {{--
            O aviso "O que espera você" (#300), buscado depois da página.

            A pergunta de entrada é só a coluna da própria conta — silenciou?
            —, que não custa consulta. As listas são calculadas no servidor só
            quando o aviso pode aparecer, e ele responde 204 quando não há nada.
            O Alpine inicializa sozinho o modal que entra no DOM.
        --}}
        @if (app(\App\Services\OQueEsperaVoce::class)->avisaAoEntrar(auth()->user()))
            <div data-aviso-espera></div>
            <script>
                document.addEventListener('DOMContentLoaded', async () => {
                    try {
                        const resposta = await fetch('{{ route('espera.aviso') }}', { headers: { 'Accept': 'text/html' } });

                        if (resposta.status === 200) {
                            document.querySelector('[data-aviso-espera]').innerHTML = await resposta.text();
                        }
                    } catch (erro) {
                        // sem aviso desta vez: o painel continua mostrando tudo
                    }
                });
            </script>
        @endif
    </body>
</html>
