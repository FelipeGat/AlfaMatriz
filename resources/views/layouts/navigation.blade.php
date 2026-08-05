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

{{-- 236px expandida, 68px recolhida. O estado vive no Alpine (layouts/app)
     e é lembrado no localStorage, para não voltar ao tamanho original a cada
     navegação. --}}
<aside
    class="fixed inset-y-0 left-0 z-40 shrink-0 overflow-hidden bg-sidebar border-r border-line flex flex-col
           transform transition-transform duration-200 ease-in-out -translate-x-full
           lg:translate-x-0 lg:static lg:z-auto lg:transition-[width,transform] lg:duration-sidebar"
    :class="[sidebarOpen ? '!translate-x-0' : '', sidebarExpandida ? 'w-[236px]' : 'w-[236px] lg:w-[68px]']"
    @keydown.escape.window="sidebarOpen = false"
>
    {{-- Lockup: ícone da marca + wordmark. Recolhida, o wordmark some por
         opacidade e largura, e o ícone fica centralizado. --}}
    <div class="flex h-[62px] shrink-0 items-center gap-[11px] border-b border-line px-[18px]"
         :class="sidebarExpandida || 'lg:justify-center lg:px-0'">
        <a href="{{ route('dashboard') }}" class="flex items-center gap-[11px] min-w-0">
            <svg class="h-[27px] w-[28px] shrink-0 text-brand" viewBox="2.8 1.8 42.4 43.8" fill="none">
                <path d="M5 4l13 15L5 34" stroke="currentColor" stroke-width="4.4" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
                <path d="M43 4L30 19l13 15" stroke="currentColor" stroke-width="4.4" stroke-linecap="round" stroke-linejoin="round" opacity=".38"/>
                <circle cx="24" cy="39" r="6.6" fill="currentColor"/>
            </svg>
            <img src="{{ asset('brand/alfamatriz-wordmark.png') }}" alt="AlfaMatriz"
                 class="h-[15px] w-auto transition-[opacity,width] duration-sidebar"
                 :class="sidebarExpandida || 'lg:w-0 lg:opacity-0'">
        </a>

        <button @click="sidebarOpen = false" class="ml-auto lg:hidden text-dim hover:text-ink">
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
        </button>
    </div>

    <nav class="flex-1 overflow-y-auto overflow-x-hidden px-3 py-[14px] space-y-4">
        @foreach ($grupos as $nomeGrupo => $links)
            <div>
                <p class="px-3 mb-1 font-mono text-[9.5px] font-medium uppercase tracking-[.16em] text-mute transition-[opacity,height] duration-sidebar"
                   :class="sidebarExpandida || 'lg:h-0 lg:opacity-0 lg:mb-0'">{{ $nomeGrupo }}</p>

                <div class="space-y-1">
                    @foreach ($links as $link)
                        @php $ativo = request()->routeIs($link['pattern']); @endphp
                        <a href="{{ route($link['route']) }}"
                           title="{{ $link['label'] }}"
                           class="group relative flex h-[38px] items-center gap-[11px] rounded-control px-3 text-[13px] transition-colors
                                  {{ $ativo ? 'bg-brand-soft text-brand' : 'text-dim hover:bg-[var(--hover)] hover:text-ink' }}"
                           :class="sidebarExpandida || 'lg:justify-center lg:px-0'">
                            @if ($ativo)
                                {{-- Marcador de 3×18px colado na borda esquerda --}}
                                <span class="absolute -left-3 h-[18px] w-[3px] rounded-r bg-brand" aria-hidden="true"></span>
                            @endif

                            <span class="h-[18px] w-[18px] shrink-0 {{ $ativo ? 'text-brand' : 'text-mute group-hover:text-dim' }}">
                                <x-nav-icon :name="$link['icon']" />
                            </span>

                            <span class="truncate transition-[opacity,width] duration-sidebar"
                                  :class="sidebarExpandida || 'lg:w-0 lg:opacity-0'">{{ $link['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </nav>

    <div class="shrink-0 border-t border-line px-3 py-3">
        <div class="flex items-center gap-[11px] rounded-control px-3 py-2"
             :class="sidebarExpandida || 'lg:justify-center lg:px-0'">
            <span class="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-full bg-brand-soft text-brand text-xs font-semibold">
                {{ Str::of(Auth::user()->name)->substr(0, 1)->upper() }}
            </span>
            <div class="min-w-0 transition-[opacity,width] duration-sidebar"
                 :class="sidebarExpandida || 'lg:w-0 lg:opacity-0'">
                <p class="truncate text-[12.5px] text-ink">{{ Auth::user()->name }}</p>
                <p class="truncate text-[10.5px] text-mute">{{ Auth::user()->email }}</p>
            </div>
        </div>

        {{-- Recolher só faz sentido a partir de lg: abaixo disso a sidebar é
             um drawer sobreposto, que abre e fecha por outro caminho. --}}
        <button type="button" @click="alternarSidebar()"
                class="mt-1 hidden h-[38px] w-full items-center gap-[11px] rounded-control px-3 text-[13px] text-dim transition-colors hover:bg-[var(--hover)] hover:text-ink lg:flex"
                :class="sidebarExpandida || 'lg:justify-center lg:px-0'"
                :title="sidebarExpandida ? 'Recolher menu' : 'Expandir menu'">
            <span class="grid h-[18px] w-[18px] shrink-0 place-items-center text-mute">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round"
                          :d="sidebarExpandida ? 'M15 18l-6-6 6-6' : 'M9 18l6-6-6-6'" />
                </svg>
            </span>
            <span class="transition-[opacity,width] duration-sidebar"
                  :class="sidebarExpandida || 'lg:w-0 lg:opacity-0'">Recolher menu</span>
        </button>
    </div>
</aside>
