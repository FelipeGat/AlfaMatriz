<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Clientes</h2>
            <a href="{{ route('clientes.create') }}" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Novo cliente
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <form method="GET" class="mb-4 flex gap-3">
                <input type="text" name="busca" value="{{ request('busca') }}" placeholder="Buscar por nome..." class="border-white/20 rounded-md shadow-sm text-sm flex-1">
                <select name="revenda_id" class="border-white/20 rounded-md shadow-sm text-sm">
                    <option value="">Todas as revendas</option>
                    @foreach ($revendas as $revenda)
                        <option value="{{ $revenda->id }}" {{ (string) request('revenda_id') === (string) $revenda->id ? 'selected' : '' }}>{{ $revenda->nome }}</option>
                    @endforeach
                </select>
                <button type="submit" class="px-4 py-2 bg-panel-raised rounded-md text-sm hover:bg-white/10">Filtrar</button>
            </form>

            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-white/5">
                    <thead class="bg-panel-raised">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Nome</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Revenda</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Sistemas</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Tipo</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-panel divide-y divide-white/5">
                        @forelse ($clientes as $cliente)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-ink">{{ $cliente->nome }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $cliente->revenda->nome ?? 'Venda direta' }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $cliente->sistemas->pluck('nome')->join(', ') ?: '—' }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">
                                    {{ $cliente->tipo_cliente === 'CONTRATO' ? 'Contrato · R$ '.number_format($cliente->valor_mensal, 2, ',', '.') : 'Avulso' }}
                                </td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $cliente->ativo ? 'bg-status-good/15 text-status-good' : 'bg-panel-raised text-ink' }}">
                                        {{ $cliente->ativo ? 'Ativo' : 'Inativo' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    <a href="{{ route('clientes.edit', $cliente) }}" class="text-brand hover:text-brand-bright">Editar</a>
                                    <form action="{{ route('clientes.destroy', $cliente) }}" method="POST" class="inline" onsubmit="return confirm('Remover este cliente?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-status-critical hover:opacity-80">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-ink-dim">Nenhum cliente cadastrado.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $clientes->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
