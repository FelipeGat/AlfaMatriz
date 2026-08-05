<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Revendas</h2>
            <a href="{{ route('revendas.create') }}" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Nova revenda
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <div class="bg-panel overflow-hidden shadow-sm sm:rounded-lg">
                <table class="min-w-full divide-y divide-white/5">
                    <thead class="bg-panel-raised">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Nome</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">CNPJ</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Contato</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Clientes</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-ink-dim uppercase">Status</th>
                            <th class="px-6 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="bg-panel divide-y divide-white/5">
                        @forelse ($revendas as $revenda)
                            <tr>
                                <td class="px-6 py-4 text-sm font-medium text-ink">{{ $revenda->nome }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $revenda->cnpj ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $revenda->contato_nome ?? '—' }}</td>
                                <td class="px-6 py-4 text-sm text-ink-dim">{{ $revenda->clientes_count }}</td>
                                <td class="px-6 py-4 text-sm">
                                    <span class="px-2 py-1 text-xs rounded-full {{ $revenda->ativo ? 'bg-status-good/15 text-status-good' : 'bg-panel-raised text-ink' }}">
                                        {{ $revenda->ativo ? 'Ativa' : 'Inativa' }}
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-right text-sm space-x-3">
                                    <a href="{{ route('revendas.edit', $revenda) }}" class="text-brand hover:text-brand-bright">Editar</a>
                                    <form action="{{ route('revendas.destroy', $revenda) }}" method="POST" class="inline" onsubmit="return confirm('Remover esta revenda?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="text-status-critical hover:opacity-80">Remover</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-6 py-8 text-center text-sm text-ink-dim">Nenhuma revenda cadastrada.</td></tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4">{{ $revendas->links() }}</div>
            </div>
        </div>
    </div>
</x-app-layout>
