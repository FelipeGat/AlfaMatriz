<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Receitas / Contas a receber</h2>
            <a href="{{ route('cobrancas.create') }}" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Nova receita
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif
            @if ($errors->any())
                <div class="mb-4 p-4 bg-status-critical/10 text-status-critical rounded-md text-sm">{{ $errors->first() }}</div>
            @endif

            <form method="GET" class="mb-4 flex gap-3">
                <select name="status" class="border-white/20 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                    <option value="">Todos os status</option>
                    <option value="pendente" {{ request('status') === 'pendente' ? 'selected' : '' }}>Pendente</option>
                    <option value="pago" {{ request('status') === 'pago' ? 'selected' : '' }}>Pago</option>
                    <option value="cancelado" {{ request('status') === 'cancelado' ? 'selected' : '' }}>Cancelado</option>
                </select>
            </form>

            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-white/5">
                    <thead class="bg-panel-raised">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Descrição</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Revenda/Cliente</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Vencimento</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-ink-dim uppercase">Valor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-panel divide-y divide-white/5">
                        @forelse ($cobrancas as $cobranca)
                            <tr>
                                <td class="px-6 py-4 text-sm text-ink">{{ $cobranca->descricao }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $cobranca->revenda->nome ?? $cobranca->cliente->nome ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $cobranca->data_vencimento->format('d/m/Y') }}</td>
                                <td class="px-6 py-4 text-sm text-right font-medium">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ ['pago' => 'bg-status-good/15 text-status-good', 'cancelado' => 'bg-panel-raised text-ink'][$cobranca->status] ?? 'bg-status-warning/15 text-status-warning' }}">
                                        {{ ucfirst($cobranca->status) }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    @if ($cobranca->status === 'pendente')
                                        <form action="{{ route('cobrancas.baixar', $cobranca) }}" method="POST" class="inline" onsubmit="return confirm('Confirmar recebimento?');">
                                            @csrf
                                            <button type="submit" class="text-status-good hover:opacity-80">Baixar</button>
                                        </form>
                                    @endif
                                    <a href="{{ route('cobrancas.show', $cobranca) }}" class="text-brand hover:text-brand-bright">Ver</a>
                                    <a href="{{ route('cobrancas.edit', $cobranca) }}" class="text-brand hover:text-brand-bright">Editar</a>
                                    <form action="{{ route('cobrancas.destroy', $cobranca) }}" method="POST" class="inline" onsubmit="return confirm('Remover esta receita?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-status-critical hover:opacity-80">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-ink-dim">Nenhuma receita cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $cobrancas->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
