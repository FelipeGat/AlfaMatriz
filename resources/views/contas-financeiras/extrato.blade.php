<x-app-layout>
    <x-slot name="caminho">
        <x-migalhas :caminho="[['rotulo' => 'Caixa', 'rota' => route('contas-financeiras.index')]]"
                    :atual="$contaFinanceira->nome" />
    </x-slot>
    <x-slot name="contexto">saldo atual R$ {{ number_format($contaFinanceira->saldo, 2, ',', '.') }}</x-slot>
    <x-slot name="acoes">
        <a href="{{ route('contas-financeiras.index') }}"
           class="h-[34px] px-3 inline-flex items-center rounded-control border border-btn-line
                  text-[12.5px] font-semibold text-ink-dim hover:text-brand hover:border-brand transition whitespace-nowrap">
            Voltar ao caixa
        </a>
    </x-slot>

    <x-tabela min="820px">
        <thead>
            <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                <th class="px-4 py-2.5 font-semibold">Data</th>
                <th class="px-4 py-2.5 font-semibold">Descrição</th>
                <th class="px-4 py-2.5 font-semibold">Tipo</th>
                <th class="px-4 py-2.5 font-semibold text-right">Valor</th>
                <th class="px-4 py-2.5 font-semibold text-right">Saldo resultante</th>
            </tr>
        </thead>

        <tbody>
            @forelse ($movimentacoes as $movimentacao)
                @php $entrada = $movimentacao->tipo === 'entrada'; @endphp

                <tr class="border-b border-rule hover:bg-chip transition">
                    <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($movimentacao->data)->format('d/m/Y') }}
                    </td>

                    <td class="px-4 py-3 text-[13.5px] text-ink">{{ $movimentacao->descricao }}</td>

                    <td class="px-4 py-3">
                        <x-badge :tom="match ($movimentacao->tipo) {
                            'entrada' => 'bom',
                            'saida' => 'atencao',
                            default => 'neutro',
                        }">{{ ucfirst($movimentacao->tipo) }}</x-badge>
                    </td>

                    {{-- O valor traz o sinal: sem ele, entrada e saída viram a
                         mesma coisa numa varredura rápida da coluna. --}}
                    <td class="px-4 py-3 text-right font-mono text-[13.5px] whitespace-nowrap"
                        style="color: rgb(var(--{{ $entrada ? 'good' : 'chart-out' }}))">
                        {{ $entrada ? '+' : '−' }}R$ {{ number_format($movimentacao->valor, 2, ',', '.') }}
                    </td>

                    {{-- O saldo depois do lançamento é o que permite conferir a
                         conta linha a linha, sem somar de cabeça. --}}
                    <td class="px-4 py-3 text-right font-mono text-[13.5px] whitespace-nowrap
                               {{ $movimentacao->saldo_resultante < 0 ? 'text-crit' : 'text-ink' }}">
                        R$ {{ number_format($movimentacao->saldo_resultante, 2, ',', '.') }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-8 text-center text-[13px] text-ink-mute">
                        Nenhuma movimentação nesta conta.
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot name="rodape">
            <span>{{ $movimentacoes->count() }} de {{ $movimentacoes->total() }} movimentações</span>
            <span>· página {{ $movimentacoes->currentPage() }} de {{ $movimentacoes->lastPage() }}</span>
            {{ $movimentacoes->links() }}
        </x-slot>
    </x-tabela>
</x-app-layout>
