@php
    /**
     * O menu é ESTRUTURA, não superfície: faixa de borda a borda, sem raio,
     * marcada por régua e barra. Por isso ele não usa os tokens de card.
     *
     * `pattern` aceita lista para que a tela filha mantenha o pai aceso — o
     * formulário de cliente acende Clientes, o extrato acende Caixa.
     */
    $grupos = [
        'Painéis' => [
            ['route' => 'centro-controle', 'pattern' => 'centro-controle', 'label' => 'Centro de Controle', 'icon' => 'bolt'],
            ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Financeiro', 'icon' => 'trending-up'],
            ['route' => 'comercial', 'pattern' => 'comercial', 'label' => 'Comercial', 'icon' => 'clipboard'],
        ],
        'Comercial' => [
            ['route' => 'leads.index', 'pattern' => 'leads.*', 'label' => 'Funil de Vendas', 'icon' => 'view-grid'],
            ['route' => 'revendas.index', 'pattern' => 'revendas.*', 'label' => 'Revendas', 'icon' => 'building'],
            ['route' => 'clientes.index', 'pattern' => 'clientes.*', 'label' => 'Clientes', 'icon' => 'users'],
            ['route' => 'produtos.index', 'pattern' => ['produtos.*', 'sistemas.*', 'precos.*'], 'label' => 'Produtos', 'icon' => 'cube-outline'],
            ['route' => 'faturamento.index', 'pattern' => 'faturamento.*', 'label' => 'Faturamento', 'icon' => 'repeat'],
        ],
        'Financeiro' => [
            ['route' => 'cobrancas.index', 'pattern' => 'cobrancas.*', 'label' => 'Receitas', 'icon' => 'trending-up'],
            ['route' => 'contas-pagar.index', 'pattern' => ['contas-pagar.*', 'contas-fixas-pagar.*'], 'label' => 'Despesas', 'icon' => 'trending-down'],
            ['route' => 'contas-financeiras.index', 'pattern' => 'contas-financeiras.*', 'label' => 'Caixa', 'icon' => 'banknotes'],
        ],
        'Sistema' => [
            ['route' => 'cadastros-auxiliares.index', 'pattern' => ['cadastros-auxiliares.*', 'centros-custo.*', 'fornecedores.*', 'categorias.*', 'subcategorias.*', 'contas.*'], 'label' => 'Cadastros', 'icon' => 'tag'],
        ],
    ];
@endphp

<aside
    class="fixed inset-y-0 left-0 z-40 shrink-0 flex flex-col bg-panel border-r border-line
           transform -translate-x-full transition-transform duration-200 ease-in-out
           lg:sticky lg:top-0 lg:h-screen lg:translate-x-0 lg:transition-[width] lg:duration-rail lg:ease-out
           overflow-hidden"
    :class="gavetaAberta ? 'w-sidebar !translate-x-0' : (railAberto ? 'w-sidebar' : 'lg:w-rail w-sidebar')"
    @keydown.escape.window="gavetaAberta = false"
>
    {{-- Header: ícone sempre; wordmark só com o menu aberto --}}
    <div class="h-topbar shrink-0 flex items-center gap-2.5 border-b border-line transition-[padding] duration-rail"
         :class="(railAberto || gavetaAberta) ? 'px-4' : 'lg:px-0 lg:justify-center px-4'">
        <a href="{{ route('centro-controle') }}" class="flex items-center gap-2.5 min-w-0">
            <img src="/icon-matriz.svg" alt="" class="h-7 w-7 shrink-0">
            <img src="/alfamatriz.png" alt="AlfaMatriz" class="h-[15px] w-auto shrink-0 transition-opacity duration-150"
                 x-show="railAberto || gavetaAberta" x-cloak>
        </a>

        <button type="button" @click="gavetaAberta = false"
                class="ml-auto lg:hidden h-7 w-7 text-ink-mute hover:text-ink transition"
                aria-label="Fechar menu">
            <span class="block h-4 w-4"><x-nav-icon name="x-mark" /></span>
        </button>
    </div>

    <nav id="menu-principal" class="flex-1 overflow-y-auto py-2"
         :data-rail="(railAberto || gavetaAberta) ? 'open' : 'closed'">
        @foreach ($grupos as $nomeGrupo => $links)
            {{--
                Recolhido, o rótulo do grupo some e uma régua toma o lugar
                dele — menos antes do primeiro grupo, que não separa nada.
                A régua é um div de 1px com fundo: `border-top` em elemento de
                altura zero não pinta.
            --}}
            @unless ($loop->first)
                <div class="h-px mx-[9px] my-[11px] bg-rule-strong" x-show="! railAberto && ! gavetaAberta" x-cloak></div>
            @endunless

            <p class="px-[14px] py-1.5 font-mono text-[9.5px] font-semibold uppercase tracking-caps-max text-ink-faint"
               x-show="railAberto || gavetaAberta" x-cloak>{{ $nomeGrupo }}</p>

            @foreach ($links as $link)
                @php $ativo = request()->routeIs(...(array) $link['pattern']); @endphp
                <a href="{{ route($link['route']) }}"
                   @class([
                       'group flex items-center gap-3 h-item text-[13.5px] transition-colors border-l-[3px]',
                       'bg-nav-active text-brand-text font-semibold border-brand' => $ativo,
                       'text-ink-dim border-transparent hover:bg-chip hover:text-ink' => ! $ativo,
                   ])
                   :class="(railAberto || gavetaAberta) ? 'px-[13px]' : 'lg:justify-center lg:pl-0 lg:pr-[3px] px-[13px]'"
                   @if ($ativo) aria-current="page" @endif
                   title="{{ $link['label'] }}">
                    <span @class([
                        'h-[18px] w-[18px] shrink-0',
                        'text-brand-text' => $ativo,
                        'text-ink-mute group-hover:text-ink-dim' => ! $ativo,
                    ])><x-nav-icon :name="$link['icon']" /></span>
                    <span class="truncate" x-show="railAberto || gavetaAberta" x-cloak>{{ $link['label'] }}</span>
                </a>
            @endforeach
        @endforeach
    </nav>

    <div class="shrink-0 flex items-center gap-2.5 border-t border-line px-[14px] py-3"
         :class="(railAberto || gavetaAberta) ? '' : 'lg:flex-col lg:px-2 lg:gap-2'">
        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2.5 min-w-0 flex-1 group"
           :class="(railAberto || gavetaAberta) ? '' : 'lg:flex-none'">
            <span class="h-7 w-7 shrink-0 rounded-full bg-brand/20 text-brand-text flex items-center justify-center font-mono text-[11px] font-semibold">
                {{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}
            </span>
            <span class="min-w-0 truncate text-[12.5px] text-ink-dim group-hover:text-ink transition"
                  x-show="railAberto || gavetaAberta" x-cloak>{{ Auth::user()->name }}</span>
        </a>

        <button type="button"
                class="relative h-7 w-7 shrink-0 rounded-ctl text-ink-mute hover:text-ink hover:bg-chip transition flex items-center justify-center"
                aria-label="Notificações">
            <span class="h-4 w-4"><x-nav-icon name="bell" /></span>
            @if (($naoLidas ?? 0) > 0)
                <span class="absolute -top-1 -right-1 min-w-[15px] h-[15px] px-1 rounded-full bg-crit text-white
                             font-mono text-[9px] font-semibold leading-[15px] text-center">{{ $naoLidas }}</span>
            @endif
        </button>

        <button type="button" @click="alternarTema()"
                class="h-7 w-7 shrink-0 rounded-ctl text-ink-mute hover:text-ink hover:bg-chip transition flex items-center justify-center"
                :aria-label="tema === 'claro' ? 'Usar tema escuro' : 'Usar tema claro'">
            <span class="h-4 w-4" x-show="tema === 'escuro'"><x-nav-icon name="sun" /></span>
            <span class="h-4 w-4" x-show="tema === 'claro'" x-cloak><x-nav-icon name="moon" /></span>
        </button>
    </div>
</aside>
