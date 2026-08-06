@php
    $grupos = [
        'Painéis' => [
            ['route' => 'centro-controle', 'pattern' => 'centro-controle', 'label' => 'Centro de Controle', 'icon' => 'bolt'],
            ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Financeiro', 'icon' => 'trending-up'],
            ['route' => 'comercial', 'pattern' => 'comercial', 'label' => 'Comercial', 'icon' => 'clipboard'],
        ],
        'Comercial' => [
            ['route' => 'leads.index', 'pattern' => 'leads.*', 'label' => 'Funil de Vendas', 'icon' => 'trending-up'],
            ['route' => 'revendas.index', 'pattern' => 'revendas.*', 'label' => 'Revendas', 'icon' => 'building'],
            ['route' => 'clientes.index', 'pattern' => 'clientes.*', 'label' => 'Clientes', 'icon' => 'users'],
            ['route' => 'produtos.index', 'pattern' => ['produtos.*', 'sistemas.*'], 'label' => 'Produtos', 'icon' => 'cube-outline'],
            ['route' => 'faturamento.index', 'pattern' => 'faturamento.*', 'label' => 'Faturamento', 'icon' => 'repeat'],
        ],
        'Financeiro' => [
            ['route' => 'cobrancas.index', 'pattern' => 'cobrancas.*', 'label' => 'Receitas', 'icon' => 'trending-up'],
            ['route' => 'contas-pagar.index', 'pattern' => ['contas-pagar.*', 'contas-fixas-pagar.*'], 'label' => 'Despesas', 'icon' => 'trending-down'],
            ['route' => 'contas-financeiras.index', 'pattern' => 'contas-financeiras.*', 'label' => 'Caixa', 'icon' => 'banknotes'],
        ],
        'Sistema' => [
            ['route' => 'cadastros-auxiliares.index', 'pattern' => 'cadastros-auxiliares.*', 'label' => 'Cadastros', 'icon' => 'tag'],
        ],
    ];
@endphp

<aside
    class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 bg-panel border-r border-white/5 flex flex-col transform transition-transform duration-200 ease-in-out -translate-x-full lg:translate-x-0 lg:static lg:z-auto"
    :class="sidebarOpen && '!translate-x-0'"
    @keydown.escape.window="sidebarOpen = false"
>
    <div class="h-16 flex items-center gap-2 px-6 border-b border-white/5">
        <img src="/logo-tile.svg" alt="AlfaMatriz" class="h-8 w-8 shrink-0">
        <div class="leading-tight">
            <p class="font-display font-semibold text-ink tracking-wide">ALFA</p>
            <p class="text-[10px] uppercase tracking-widest text-ink-mute">Matriz</p>
        </div>
        <button @click="sidebarOpen = false" class="ml-auto lg:hidden text-ink-dim hover:text-ink">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-4">
        @foreach ($grupos as $nomeGrupo => $links)
            <div>
                <p class="px-3 mb-1 text-[10px] font-semibold uppercase tracking-widest text-ink-mute">{{ $nomeGrupo }}</p>
                <div class="space-y-1">
                    @foreach ($links as $link)
                        @php $active = request()->routeIs(...(array) $link['pattern']); @endphp
                        <a href="{{ route($link['route']) }}"
                           class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition
                                  {{ $active ? 'bg-brand/10 text-brand-dim' : 'text-ink-dim hover:bg-white/5 hover:text-ink' }}">
                            <span class="h-5 w-5 shrink-0 {{ $active ? 'text-brand-dim' : 'text-ink-mute group-hover:text-ink-dim' }}">
                                <x-nav-icon :name="$link['icon']" />
                            </span>
                            {{ $link['label'] }}
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="px-3 py-4 border-t border-white/5">
        <div class="flex items-center gap-3 rounded-lg px-3 py-2">
            <span class="h-8 w-8 rounded-full bg-brand/20 text-brand-dim flex items-center justify-center text-xs font-semibold shrink-0">
                {{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}
            </span>
            <div class="min-w-0">
                <p class="text-sm text-ink truncate">{{ Auth::user()->name }}</p>
                <p class="text-xs text-ink-mute truncate">{{ Auth::user()->email }}</p>
            </div>
        </div>
    </div>
</aside>
