<x-app-layout>
    <x-slot name="titulo">Sistemas &amp; preço de atacado</x-slot>
    <x-slot name="contexto">{{ $sistemas->total() }} sistemas</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('produtos.index') }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control border border-btn-line
                  text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition whitespace-nowrap">
            Ver produtos
        </a>
    </x-slot>

    <div class="space-y-4">
        @if (session('status'))
            <x-aviso>{{ session('status') }}</x-aviso>
        @endif

        {{-- O resumo do topo. O controller já o calculava desde sempre e a
             view nunca o desenhou: os números existiam, iam para cá e morriam
             sem chegar a ninguém. --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            <x-kpi-card rotulo="Sistemas ativos" :valor="number_format($sistemasAtivos, 0, ',', '.')"
                        acento="accent" icone="cube-outline" />
            <x-kpi-card rotulo="Clientes ativos" :valor="number_format($clientesAtivos, 0, ',', '.')"
                        acento="brand" icone="users" />
            <x-kpi-card rotulo="MRR de atacado" :valor="'R$ '.number_format($mrrAtacado, 2, ',', '.')"
                        acento="chart-out" icone="repeat" />
            {{-- O preço médio é por LICENÇA, não por cliente: um cliente com
                 dois sistemas paga duas. É o mesmo vocabulário do ranking do
                 Comercial. --}}
            <x-kpi-card rotulo="Preço médio por licença" :valor="'R$ '.number_format($precoMedio, 2, ',', '.')"
                        :delta="$vinculosAtivos.' '.($vinculosAtivos === 1 ? 'licença ativa' : 'licenças ativas')"
                        acento="amber" icone="banknotes" />
        </div>

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
                            {{-- Mesma decisão da lista de Produtos: o nome identifica,
                                 o ícone em 28px só disputa contraste com o dado. --}}
                            <span class="block min-w-0 text-[13.5px] font-medium text-ink truncate">{{ $sistema->nome }}</span>
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
                <span>{{ $sistemas->count() }} de {{ $sistemas->total() }} sistemas</span>
                @if ($sistemas->hasPages())
                    <span>· página {{ $sistemas->currentPage() }} de {{ $sistemas->lastPage() }}</span>
                @endif
                {{-- `$semTier` conta a lista inteira (vem do controller): a
                     pendência não pode sumir do rodapé só por estar na página
                     seguinte. --}}
                @if ($semTier > 0)
                    <span style="color: rgb(var(--warn))">· {{ $semTier }} sem preço de atacado</span>
                @endif
            </x-slot>
        </x-tabela>

        @if ($sistemas->hasPages())
            <div class="mt-3">{{ $sistemas->links() }}</div>
        @endif
    </div>
</x-app-layout>
