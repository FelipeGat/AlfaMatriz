@php
    $grupos = [
        'Painéis' => [
            ['route' => 'dashboard', 'pattern' => 'dashboard', 'label' => 'Financeiro', 'icon' => 'trending-up'],
            ['route' => 'comercial', 'pattern' => 'comercial', 'label' => 'Comercial', 'icon' => 'clipboard'],
        ],
        'Comercial' => [
            ['route' => 'revendas.index', 'pattern' => 'revendas.*', 'label' => 'Revendas', 'icon' => 'building'],
            ['route' => 'clientes.index', 'pattern' => 'clientes.*', 'label' => 'Clientes', 'icon' => 'users'],
            ['route' => 'sistemas.index', 'pattern' => 'sistemas.*', 'label' => 'Sistemas', 'icon' => 'cube'],
            ['route' => 'faturamento.index', 'pattern' => 'faturamento.*', 'label' => 'Faturamento', 'icon' => 'repeat'],
        ],
        'Financeiro' => [
            ['route' => 'cobrancas.index', 'pattern' => 'cobrancas.*', 'label' => 'Receitas', 'icon' => 'trending-up'],
            ['route' => 'contas-pagar.index', 'pattern' => 'contas-pagar.*', 'label' => 'Despesas', 'icon' => 'trending-down'],
            ['route' => 'contas-fixas-pagar.index', 'pattern' => 'contas-fixas-pagar.*', 'label' => 'Despesas Fixas', 'icon' => 'repeat'],
            ['route' => 'contas-financeiras.index', 'pattern' => 'contas-financeiras.*', 'label' => 'Caixa', 'icon' => 'banknotes'],
        ],
        'Sistema' => [
            ['route' => 'cadastros-auxiliares.index', 'pattern' => 'cadastros-auxiliares.*', 'label' => 'Cadastros', 'icon' => 'tag'],
        ],
    ];
@endphp

{{-- Menu fixo de 240px, sem colapso: decisão do cliente na direção
     Vercel/Linear — a navegação fica sempre no mesmo lugar. Abaixo de `lg`
     ele vira sobreposição, comportamento que já existia. --}}
<aside
    class="fixed inset-y-0 left-0 z-40 flex w-60 shrink-0 flex-col border-r border-line bg-sidebar
           -translate-x-full transform transition-transform duration-200 ease-in-out
           lg:translate-x-0 lg:static lg:z-auto"
    :class="sidebarOpen && '!translate-x-0'"
    @keydown.escape.window="sidebarOpen = false"
>
    {{-- Lockup monocromático, sem borda inferior. --}}
    <div class="flex h-12 shrink-0 items-center gap-2.5 px-4">
        <a href="{{ route('dashboard') }}" class="flex min-w-0 items-center gap-2.5">
            <svg class="h-[23px] w-6 shrink-0 text-ink" viewBox="2 1 44 45.6" fill="none">
                <path d="M5 4l13 15L5 34" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
                <path d="M43 4L30 19l13 15" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
                <circle cx="24" cy="39" r="6.6" fill="currentColor"/>
            </svg>
            <img src="{{ asset('brand/alfamatriz-wordmark.png') }}" alt="AlfaMatriz"
                 class="h-3.5 w-auto" style="filter: var(--logo-filter);">
        </a>

        <button @click="sidebarOpen = false" class="ml-auto text-dim hover:text-ink lg:hidden">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    {{-- Busca estilo "Find", com o atalho indicado à direita. --}}
    <div class="shrink-0 px-3 pb-3">
        <div class="relative">
            <svg class="pointer-events-none absolute left-2.5 top-1/2 h-[13px] w-[13px] -translate-y-1/2 text-mute"
                 fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                <circle cx="11" cy="11" r="7" /><path stroke-linecap="round" d="M20 20l-3.5-3.5" />
            </svg>
            <input id="busca-menu" type="search" placeholder="Buscar"
                   class="h-8 w-full rounded-control border-line bg-panel pl-8 pr-8 text-[12.5px] text-ink placeholder:text-mute focus:border-ink focus:ring-0">
            <kbd class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 rounded border border-line px-1 font-mono text-[11px] leading-4 text-mute">/</kbd>
        </div>
    </div>

    <nav class="sem-scrollbar flex-1 overflow-y-auto overflow-x-hidden px-3 pb-3">
        @foreach ($grupos as $nomeGrupo => $links)
            <div class="mb-4">
                <p class="px-2 pb-1 text-[11px] font-medium text-mute">{{ $nomeGrupo }}</p>

                <div class="space-y-0.5">
                    @foreach ($links as $link)
                        @php $ativo = request()->routeIs($link['pattern']); @endphp
                        {{-- Item ativo é neutro: fundo próprio e texto ink, sem
                             cor de marca. A cor viva ficou reservada para o que
                             significa algo (gráfico, situação, indicador). --}}
                        <a href="{{ route($link['route']) }}"
                           class="flex h-[34px] items-center gap-2.5 rounded-control px-2 text-[14px] font-medium transition-colors
                                  {{ $ativo ? 'bg-nav-active text-ink' : 'text-dim hover:bg-nav-hover hover:text-ink' }}">
                            <span class="h-[18px] w-[18px] shrink-0 {{ $ativo ? 'text-ink' : 'text-mute' }}">
                                <x-nav-icon :name="$link['icon']" />
                            </span>
                            <span class="truncate">{{ $link['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="shrink-0 border-t border-line p-3">
        <div class="flex items-center gap-2.5">
            <span class="grid h-7 w-7 shrink-0 place-items-center rounded bg-raised text-[12px] font-medium text-ink">
                {{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}
            </span>
            <div class="min-w-0">
                <p class="truncate text-[14px] font-medium text-ink">{{ Auth::user()->name }}</p>
                <p class="truncate text-[12px] text-mute">{{ Auth::user()->email }}</p>
            </div>
        </div>
    </div>
</aside>
