<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Caixa / Contas financeiras</h2>
            <a href="{{ route('contas-financeiras.create') }}" class="inline-flex items-center px-4 py-2 bg-brand border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-brand-bright">
                + Nova conta
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <div class="mb-6 bg-panel shadow-sm sm:rounded-lg p-6">
                <p class="text-sm text-ink-dim">Saldo total</p>
                <p class="text-3xl font-bold {{ $saldoTotal >= 0 ? 'text-ink' : 'text-status-critical' }}">
                    R$ {{ number_format($saldoTotal, 2, ',', '.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($contasFinanceiras as $conta)
                    <div class="bg-panel shadow-sm sm:rounded-lg p-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="font-semibold text-ink">{{ $conta->nome }}</p>
                                <p class="text-xs text-ink-dim uppercase">{{ $conta->tipo }}</p>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full {{ $conta->ativo ? 'bg-status-good/15 text-status-good' : 'bg-panel-raised text-ink' }}">
                                {{ $conta->ativo ? 'Ativa' : 'Inativa' }}
                            </span>
                        </div>
                        <p class="mt-4 text-2xl font-bold {{ $conta->saldo >= 0 ? 'text-ink' : 'text-status-critical' }}">
                            R$ {{ number_format($conta->saldo, 2, ',', '.') }}
                        </p>
                        <div class="mt-4 flex gap-3 text-sm">
                            <a href="{{ route('contas-financeiras.extrato', $conta) }}" class="text-brand hover:text-brand-bright">Extrato</a>
                            <a href="{{ route('contas-financeiras.edit', $conta) }}" class="text-brand hover:text-brand-bright">Editar</a>
                            <form action="{{ route('contas-financeiras.destroy', $conta) }}" method="POST" onsubmit="return confirm('Remover esta conta?');">
                                @csrf @method('DELETE')
                                <button class="text-status-critical hover:opacity-80">Remover</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-ink-dim">Nenhuma conta financeira cadastrada.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
