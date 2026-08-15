@php
    /**
     * O menu é ESTRUTURA, não superfície: faixa de borda a borda, sem raio,
     * marcada por régua e barra. Por isso ele não usa os tokens de card.
     *
     * `pattern` aceita lista para que a tela filha mantenha o pai aceso — a
     * tela de cliente acende "Revendas e clientes", o extrato acende Caixa.
     *
     * `params` deixa o item apontar para um recorte: a revenda cai direto na
     * carteira de clientes dela, que é o trabalho dela. Sem isso, ela abria a
     * aba de revendas com uma linha só — a dela.
     *
     * O estado recolhido vem da classe `rail-fechado` no <html>, posta antes
     * da primeira pintura. Nada aqui depende do Alpine para se posicionar:
     * se dependesse, a marca nasceria num lugar e saltaria para outro.
     */
    // Precisa vir ANTES do array: o item de revendas usa `$escopo` para
    // decidir para qual aba apontar.
    $escopo = Auth::user()?->temEscopoDeRevenda() ?? false;

    $grupos = [
        'Painéis' => [
            ['route' => 'centro-controle', 'recurso' => 'dashboard', 'pattern' => 'centro-controle', 'label' => 'Centro de Controle', 'icon' => 'bolt', 'matriz' => true],
            ['route' => 'dashboard', 'recurso' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Financeiro', 'icon' => 'trending-up', 'matriz' => true],
            // `dashboard_comercial`, como a rota: gateado por `dashboard`, o
            // perfil comercial tinha o painel como casa e nenhuma porta no
            // menu levando até ela.
            ['route' => 'comercial', 'recurso' => 'dashboard_comercial', 'pattern' => 'comercial', 'label' => 'Comercial', 'icon' => 'clipboard', 'matriz' => true],
        ],
        'Comercial' => [
            ['route' => 'leads.index', 'recurso' => 'leads', 'pattern' => 'leads.*', 'label' => 'Funil de Vendas', 'icon' => 'view-grid'],
            ['route' => 'revendas.index', 'recurso' => 'revendas', 'pattern' => ['revendas.*', 'clientes.*'], 'label' => 'Revendas e clientes', 'icon' => 'building', 'params' => $escopo ? ['aba' => 'clientes'] : []],
            ['route' => 'produtos.index', 'recurso' => 'sistemas', 'pattern' => ['produtos.*', 'sistemas.*', 'precos.*'], 'label' => 'Produtos', 'icon' => 'cube-outline', 'matriz' => true],
            ['route' => 'faturamento.index', 'recurso' => 'faturamento', 'pattern' => 'faturamento.*', 'label' => 'Faturamento', 'icon' => 'repeat'],
        ],
        'Financeiro' => [
            ['route' => 'cobrancas.index', 'recurso' => 'cobrancas', 'pattern' => 'cobrancas.*', 'label' => 'Receitas', 'icon' => 'trending-up'],
            ['route' => 'contas-pagar.index', 'recurso' => 'contas_pagar', 'pattern' => ['contas-pagar.*', 'contas-fixas-pagar.*'], 'label' => 'Despesas', 'icon' => 'trending-down', 'matriz' => true],
            ['route' => 'contas-financeiras.index', 'recurso' => 'financeiro', 'pattern' => 'contas-financeiras.*', 'label' => 'Caixa', 'icon' => 'banknotes', 'matriz' => true],
        ],
        'Desenvolvimento' => [
            ['route' => 'tarefas.index', 'recurso' => 'tarefas', 'pattern' => 'tarefas.*', 'label' => 'Tarefas', 'icon' => 'view-grid', 'matriz' => true],
        ],
        'Sistema' => [
            ['route' => 'cadastros-auxiliares.index', 'recurso' => 'financeiro', 'pattern' => ['cadastros-auxiliares.*', 'centros-custo.*', 'fornecedores.*', 'categorias.*', 'subcategorias.*', 'contas.*'], 'label' => 'Cadastros', 'icon' => 'tag', 'matriz' => true],
            ['route' => 'usuarios.index', 'recurso' => 'usuarios', 'pattern' => ['usuarios.*', 'perfis.*'], 'label' => 'Usuários e permissões', 'icon' => 'users', 'matriz' => true],
            ['route' => 'auditoria.index', 'recurso' => 'auditoria', 'pattern' => 'auditoria.*', 'label' => 'Auditoria', 'icon' => 'eye', 'matriz' => true],
        ],
    ];

    // O menu só oferece porta que abre. Dois filtros, por motivos diferentes:
    //
    // `matriz` tira do usuário de revenda o que é gestão da Alfa, mesmo que ele
    // tenha permissão de sobra (revenda com perfil administrativo existe).
    //
    // `recurso` tira o que a permissão dele não alcança — sem isso, o perfil
    // `revenda`, que só enxerga revendas e clientes, via Funil de Vendas,
    // Faturamento e Receitas no menu e tomava 403 nos três. Item que leva a
    // 403 é pior que item ausente: quem clica conclui que o sistema quebrou.
    $usuario = Auth::user();
    $grupos = collect($grupos)
        ->map(fn ($links) => collect($links)
            ->filter(fn ($link) => ! $escopo || empty($link['matriz']))
            ->filter(fn ($link) => empty($link['recurso'])
                || $usuario?->canPermissao($link['recurso'], 'ler'))
            ->values()->all())
        ->filter(fn ($links) => ! empty($links))
        ->all();
@endphp

{{--
    Abaixo de 1024px a sidebar sai do fluxo e vira gaveta — por isso todo o
    comportamento de rail é `lg:`. A gaveta é sempre larga: recolher só faz
    sentido quando há conteúdo ao lado disputando espaço.
--}}
<aside
    class="fixed inset-y-0 left-0 z-40 shrink-0 flex flex-col bg-panel border-r border-line
           w-sidebar rail:lg:w-rail
           transform -translate-x-full transition-transform duration-200 ease-in-out
           lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:transition-[width] lg:duration-rail lg:ease-out
           overflow-hidden"
    :class="gavetaAberta && '!translate-x-0'"
    @keydown.escape.window="gavetaAberta = false"
>
    {{-- Header: ícone sempre; wordmark só com o menu aberto --}}
    <div class="h-topbar shrink-0 flex items-center gap-2.5 border-b border-line px-4
                rail:lg:px-0 rail:lg:justify-center">
        {{-- A marca leva para CASA, e casa não é a mesma para todo mundo: o
             destino era o Centro de Controle para quem não fosse revenda, e
             quem não tem `dashboard` — o perfil comercial — clicava no logo
             para tomar 403. `telaInicial()` é quem sabe a resposta, e já a dava
             ao login e ao desvio de quem entra. --}}
        <a href="{{ Auth::user()->telaInicial() }}" class="flex items-center gap-2.5 min-w-0">
            <img src="/icon-matriz-solid.svg" alt="" class="h-7 w-7 shrink-0">
            <img src="/alfamatriz.png" alt="AlfaMatriz" class="h-[15px] w-auto shrink-0 rail:lg:hidden">
        </a>

        <button type="button" @click="gavetaAberta = false"
                class="ml-auto lg:hidden h-7 w-7 text-ink-mute hover:text-ink transition"
                aria-label="Fechar menu">
            <span class="block h-4 w-4"><x-nav-icon name="x-mark" /></span>
        </button>
    </div>

    <nav id="menu-principal" class="flex-1 overflow-y-auto py-2">
        @foreach ($grupos as $nomeGrupo => $links)
            {{--
                Recolhido, o rótulo do grupo some e uma régua toma o lugar
                dele — menos antes do primeiro grupo, que não separa nada.
                A régua é um div de 1px com fundo: `border-top` em elemento de
                altura zero não pinta.
            --}}
            @unless ($loop->first)
                <div class="hidden rail:lg:block h-px mx-[9px] my-[11px] bg-rule-strong"></div>
            @endunless

            {{-- O rótulo do grupo ganha um respiro maior acima que abaixo: ele
                 pertence ao que vem depois, não ao que veio antes. --}}
            <p class="px-[14px] pt-3 pb-1 first:pt-1.5 font-mono text-[9.5px] font-semibold uppercase tracking-caps-max text-ink-faint
                      rail:lg:hidden">{{ $nomeGrupo }}</p>

            @foreach ($links as $link)
                @php $ativo = request()->routeIs(...(array) $link['pattern']); @endphp
                <a href="{{ route($link['route'], $link['params'] ?? []) }}"
                   @class([
                       // Peso 500 no item inteiro: o menu é lido de relance e
                       // 400 some contra o fundo escuro. O item ativo não
                       // precisa de peso extra — ele já tem fundo, cor de
                       // marca e a barra de 3px o separando dos outros.
                       'group flex items-center gap-3 h-item text-[13.5px] font-medium transition-colors border-l-[3px] px-[13px]',
                       // Expandido, o item respira: com os rótulos ao lado, a
                       // fileira colada vira um bloco de texto e a pessoa
                       // perde a linha ao correr o olho. Recolhido o gap sai —
                       // no rail o que separa os ícones é a régua de grupo, e
                       // espaço extra ali só alonga a coluna à toa.
                       'my-[3px] rail:lg:my-0',
                       // Recolhido, o item centraliza — e cede 3px à direita
                       // para compensar a barra de marca da esquerda, senão o
                       // ícone fica fora do eixo do rail.
                       'rail:lg:justify-center rail:lg:pl-0 rail:lg:pr-[3px]',
                       'bg-nav-active text-brand-text border-brand' => $ativo,
                       'text-ink-dim border-transparent hover:bg-chip hover:text-ink' => ! $ativo,
                   ])
                   @if ($ativo) aria-current="page" @endif
                   title="{{ $link['label'] }}">
                    {{-- O traço acompanha o texto: ícone em 1.5 ao lado de um
                         rótulo em 500 fica visivelmente mais leve que ele. --}}
                    <span @class([
                        'h-[18px] w-[18px] shrink-0',
                        'text-brand-text' => $ativo,
                        'text-ink-mute group-hover:text-ink-dim' => ! $ativo,
                    ])><x-nav-icon :name="$link['icon']" :peso="1.7" /></span>
                    <span class="truncate rail:lg:hidden">{{ $link['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="shrink-0 flex items-center gap-2.5 border-t border-line px-[14px] py-3
                rail:lg:flex-col rail:lg:px-2 rail:lg:gap-2">
        {{--
            O avatar abre um menu em vez de ir direto ao perfil: dali saem
            ações de conta que não merecem lugar fixo no rodapé, e é onde a
            pessoa procura por elas.

            O menu é teletransportado para o <body> porque a sidebar tem
            `overflow-hidden` (a transição do rail depende disso) e o cortaria
            — no rail recolhido, de 60px, ele sumiria quase inteiro.
        --}}
        <div x-data="{ menuConta: false }" class="min-w-0 flex-1 rail:lg:flex-none">
            <button type="button" @click="menuConta = ! menuConta"
                    class="flex items-center gap-2.5 min-w-0 w-full group"
                    :aria-expanded="menuConta.toString()"
                    aria-haspopup="menu"
                    title="Conta de {{ Auth::user()->name }}">
                <span class="h-[30px] w-[30px] shrink-0 rounded-full bg-brand/20 text-brand-text flex items-center justify-center font-mono text-[11.5px] font-semibold">
                    {{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}
                </span>
                <span class="min-w-0 truncate text-[12.5px] text-ink-dim group-hover:text-ink transition rail:lg:hidden">
                    {{ Auth::user()->name }}
                </span>
            </button>

            <template x-teleport="body">
                <div x-show="menuConta" x-cloak
                     @click.outside="menuConta = false"
                     @keydown.escape.window="menuConta = false"
                     x-transition:enter="transition ease-out duration-150"
                     x-transition:enter-start="opacity-0 translate-y-1"
                     x-transition:enter-end="opacity-100 translate-y-0"
                     class="fixed z-50 w-[228px] rounded-panel border border-line bg-panel overflow-hidden"
                     style="left: 12px; bottom: 62px"
                     role="menu">
                    {{-- Quem está logado: no rail recolhido o nome some do
                         rodapé, e esta é a única resposta na tela. --}}
                    <div class="px-3 py-2.5 border-b border-rule">
                        <p class="text-[13px] font-medium text-ink truncate">{{ Auth::user()->name }}</p>
                        <p class="font-mono text-[11px] text-ink-faint truncate">{{ Auth::user()->email }}</p>
                    </div>

                    <a href="{{ route('profile.edit') }}" role="menuitem"
                       class="flex items-center gap-2.5 px-3 py-2.5 text-[13px] text-ink-dim hover:bg-chip hover:text-ink transition">
                        <span class="h-4 w-4 shrink-0"><x-nav-icon name="settings" :peso="1.7" /></span>
                        Configurações
                    </a>

                    <form method="POST" action="{{ route('logout') }}" class="border-t border-rule">
                        @csrf
                        <button type="submit" role="menuitem"
                                class="flex w-full items-center gap-2.5 px-3 py-2.5 text-[13px] text-ink-dim hover:bg-crit-tint hover:text-crit transition"
                                aria-label="Sair da conta">
                            <span class="h-4 w-4 shrink-0"><x-nav-icon name="logout" :peso="1.7" /></span>
                            Sair
                        </button>
                    </form>
                </div>
            </template>
        </div>

        {{--
            O sino. O PAINEL não mora aqui — ele é irmão do <aside>, no
            `app.blade.php`, e o motivo está em duas propriedades deste
            elemento: `overflow-hidden`, que corta qualquer filho passando da
            borda, e o `transform` da gaveta, que faz o aside virar bloco
            contido e impede até `position: fixed` de escapar dele.

            O estado é o `sinoAberto` do `shell`, que é ancestral dos dois.
        --}}
        <button type="button" @click="sinoAberto = ! sinoAberto"
                class="relative h-[30px] w-[30px] shrink-0 rounded-ctl text-ink-mute hover:text-ink hover:bg-chip transition flex items-center justify-center"
                :class="sinoAberto && 'bg-chip text-ink'"
                aria-label="Notificações">
            <span class="h-[18px] w-[18px]"><x-nav-icon name="bell" :peso="1.7" /></span>
            @if (($naoLidas ?? 0) > 0)
                <span class="absolute -top-1 -right-1 min-w-[15px] h-[15px] px-1 rounded-full bg-crit text-white
                             font-mono text-[9px] font-semibold leading-[15px] text-center">{{ $naoLidas }}</span>
            @endif
        </button>

        <button type="button" @click="alternarTema()"
                class="h-[30px] w-[30px] shrink-0 rounded-ctl text-ink-mute hover:text-ink hover:bg-chip transition flex items-center justify-center"
                :aria-label="tema === 'claro' ? 'Usar tema escuro' : 'Usar tema claro'">
            <span class="h-[18px] w-[18px]" x-show="tema === 'escuro'"><x-nav-icon name="sun" :peso="1.7" /></span>
            <span class="h-[18px] w-[18px]" x-show="tema === 'claro'" x-cloak><x-nav-icon name="moon" :peso="1.7" /></span>
        </button>

    </div>
</aside>
