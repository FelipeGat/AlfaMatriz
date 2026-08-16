<x-app-layout>
    <x-slot name="titulo">Relatórios</x-slot>
    <x-slot name="contexto">competência {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y') }}</x-slot>

    <div class="space-y-4">
        {{-- Seção e competência na mesma linha: são os DOIS recortes da tela,
             e separá-los em linhas diferentes sugeriria que a competência só
             vale para uma parte. Quebra livre em telas estreitas. --}}
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2">
            <x-abas>
                <x-abas.item href="{{ route('relatorios.index', array_merge(request()->query(), ['secao' => 'comercial'])) }}"
                             :ativo="$secao === 'comercial'" icone="clipboard">Comercial</x-abas.item>
                <x-abas.item href="{{ route('relatorios.index', array_merge(request()->query(), ['secao' => 'financeiro'])) }}"
                             :ativo="$secao === 'financeiro'" icone="banknotes">Financeiro</x-abas.item>
                <x-abas.item href="{{ route('relatorios.index', array_merge(request()->query(), ['secao' => 'desenvolvimento'])) }}"
                             :ativo="$secao === 'desenvolvimento'" icone="view-grid">Desenvolvimento</x-abas.item>
                <x-abas.item href="{{ route('relatorios.index', array_merge(request()->query(), ['secao' => 'sistema'])) }}"
                             :ativo="$secao === 'sistema'" icone="settings">Sistema</x-abas.item>
            </x-abas>

            {{-- A mesma navegação de competência do Painel Financeiro — os
                 links preservam a seção aberta, como lá preservam o filtro do
                 gráfico. --}}
            <div class="flex items-center gap-2">
                <a href="{{ route('relatorios.index', array_merge(request()->except('competencia'), ['competencia' => $competenciaAnterior])) }}"
                   class="flex h-8 w-8 items-center justify-center rounded-control border border-line text-ink-mute hover:border-brand hover:text-brand transition"
                   aria-label="Competência anterior">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="chevron-left" :peso="1.8" /></span>
                </a>

                <form method="GET" class="flex items-center">
                    @foreach (request()->except(['competencia']) as $chave => $valor)
                        <input type="hidden" name="{{ $chave }}" value="{{ $valor }}">
                    @endforeach
                    <input type="month" name="competencia" value="{{ $competencia }}" onchange="this.form.submit()"
                           class="h-8 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                </form>

                <a href="{{ route('relatorios.index', array_merge(request()->except('competencia'), ['competencia' => $competenciaProxima])) }}"
                   class="flex h-8 w-8 items-center justify-center rounded-control border border-line text-ink-mute hover:border-brand hover:text-brand transition"
                   aria-label="Próxima competência">
                    <span class="h-3.5 w-3.5"><x-nav-icon name="chevron-right" :peso="1.8" /></span>
                </a>

                @unless ($competenciaEhAtual)
                    <a href="{{ route('relatorios.index', request()->except('competencia')) }}"
                       class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline ml-1">Hoje</a>
                @endunless
            </div>
        </div>

        @include('relatorios._filtros')

        @include('relatorios._'.$secao)
    </div>
</x-app-layout>
