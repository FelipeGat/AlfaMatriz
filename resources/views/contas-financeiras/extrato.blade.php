<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Extrato — {{ $contaFinanceira->nome }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="mb-4">
                <a href="{{ route('contas-financeiras.index') }}" class="text-sm text-brand hover:text-ink">&larr; Voltar para contas</a>
            </div>

            <div class="bg-panel overflow-hidden sm:rounded-card">
                <table class="min-w-full divide-y divide-line">
                    <thead class="bg-raised">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-dim uppercase">Data</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-dim uppercase">Descrição</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-dim uppercase">Tipo</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-dim uppercase">Valor</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-dim uppercase">Saldo</th>
                        </tr>
                    </thead>
                    <tbody class="bg-panel divide-y divide-line">
                        @forelse ($movimentacoes as $mov)
                            <tr>
                                <td class="px-6 py-4 text-sm text-dim">{{ $mov->data->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm text-ink">{{ $mov->descricao }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $mov->tipo === 'entrada' ? 'bg-good/12 text-good' : 'bg-bad/15 text-bad' }}">
                                        {{ ucfirst($mov->tipo) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-right {{ $mov->tipo === 'saida' ? 'text-bad' : 'text-good' }}">
                                    {{ $mov->tipo === 'saida' ? '-' : '+' }} R$ {{ number_format($mov->valor, 2, ',', '.') }}
                                </td>
                                <td class="px-6 py-4 text-sm text-right font-medium">R$ {{ number_format($mov->saldo_resultante, 2, ',', '.') }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="px-6 py-8 text-center text-sm text-dim">Nenhuma movimentação registrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $movimentacoes->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
