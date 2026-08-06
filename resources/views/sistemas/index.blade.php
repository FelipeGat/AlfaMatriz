<x-app-layout>
    <x-slot name="titulo">Sistemas &amp; preço de atacado</x-slot>
    <x-slot name="contexto">{{ $sistemas->count() }} sistemas</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('produtos.index') }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control border border-btn-line
                  text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition whitespace-nowrap">
            Ver produtos
        </a>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <div class="rounded-panel border px-4 py-2.5 text-[13px]"
                 style="background: rgb(var(--good) / var(--tint-alpha)); border-color: rgb(var(--good) / 0.25); color: rgb(var(--good))">
                {{ session('status') }}
            </div>
        @endif

        <x-tabela min="980px">
            <thead>
                <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                    <th class="px-4 py-2.5 font-semibold">Sistema</th>
                    <th class="px-4 py-2.5 font-semibold">Categoria</th>
                    <th class="px-4 py-2.5 font-semibold">Unidade de cobrança</th>
                    <th class="px-4 py-2.5 font-semibold">Clientes vinculados</th>
                    <th class="px-4 py-2.5 font-semibold">Atacado · Alfa → revenda</th>
                    <th class="px-4 py-2.5 font-semibold">Status</th>
                    <th class="px-4 py-2.5 font-semibold text-right">Ações</th>
                </tr>
            </thead>

            <tbody>
                @foreach ($sistemas as $sistema)
                    @php $semPreco = $sistema->precosAtacado->isEmpty(); @endphp

                    <tr class="border-b border-rule hover:bg-chip transition {{ $sistema->ativo ? '' : 'opacity-[0.72]' }}"
                        @if ($semPreco)
                            style="border-left: 2px solid rgb(var(--warn)); background: rgb(var(--warn) / 0.05)"
                        @endif>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <span class="h-7 w-7 shrink-0 rounded-tile bg-chip p-1 flex items-center justify-center">
                                    <x-marca-sistema :sistema="$sistema" />
                                </span>
                                <span class="min-w-0 text-[13.5px] font-medium text-ink truncate">{{ $sistema->nome }}</span>
                            </div>
                        </td>

                        <td class="px-4 py-3">
                            @if ($sistema->categoria)
                                <x-badge :tom="$sistema->categoria === 'crm' ? 'marca' : 'neutro'">{{ $sistema->categoria }}</x-badge>
                            @else
                                <span class="text-[12.5px] text-ink-faint">—</span>
                            @endif
                        </td>

                        <td class="px-4 py-3 font-mono text-[12px] uppercase tracking-caps text-ink-dim truncate">
                            {{ $sistema->unidade_cobranca }}
                        </td>

                        <td class="px-4 py-3 font-mono text-[13.5px] text-ink tabular">
                            {{ number_format($sistema->clientes_count, 0, ',', '.') }}
                        </td>

                        {{-- O preço de atacado é o que decide se o sistema entra
                             no faturamento — sem ele, a linha some do ciclo. --}}
                        <td class="px-4 py-3 text-[13px] text-ink-dim">
                            @if ($semPreco)
                                <span style="color: rgb(var(--warn))">sem preço definido</span>
                            @elseif ($sistema->precosAtacado->count() === 1
                                     && $sistema->precosAtacado->first()->unidades_inclusas == 0
                                     && $sistema->precosAtacado->first()->valor_excedente_unidade)
                                <span class="font-mono whitespace-nowrap">
                                    R$ {{ number_format($sistema->precosAtacado->first()->valor_excedente_unidade, 2, ',', '.') }} / unidade
                                </span>
                            @else
                                <span class="block truncate">{{ $sistema->precosAtacado->pluck('nome')->join(' · ') }}</span>
                                <span class="block font-mono text-[11px] text-ink-faint whitespace-nowrap">
                                    R$ {{ number_format($sistema->precosAtacado->min('preco_base'), 0, ',', '.') }}–{{ number_format($sistema->precosAtacado->max('preco_base'), 0, ',', '.') }} / mês
                                </span>
                            @endif
                        </td>

                        <td class="px-4 py-3">
                            <x-badge :tom="$sistema->ativo ? 'bom' : 'neutro'" ponto>{{ $sistema->ativo ? 'Ativo' : 'Inativo' }}</x-badge>
                        </td>

                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <x-acao-tabela icone="tag" titulo="Configurar tiers de atacado"
                                               :href="route('sistemas.edit', $sistema)" />
                                <x-acao-tabela icone="users" titulo="Ver clientes do sistema"
                                               :href="route('clientes.index', ['sistema' => $sistema->id])" />
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>

            <x-slot name="rodape">
                <span>{{ $sistemas->count() }} sistemas</span>
                @php $semTier = $sistemas->filter(fn ($s) => $s->precosAtacado->isEmpty())->count(); @endphp
                @if ($semTier > 0)
                    <span style="color: rgb(var(--warn))">· {{ $semTier }} sem preço de atacado</span>
                @endif
            </x-slot>
        </x-tabela>
    </div>
</x-app-layout>
