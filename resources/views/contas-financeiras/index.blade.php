<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center justify-between">
            <h2 class="font-semibold text-xl text-ink leading-tight">Caixa / Contas financeiras</h2>
            <a href="{{ route('contas-financeiras.create') }}" class="inline-flex h-8 items-center rounded-control bg-ink px-3 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90">
                + Nova conta
            </a>
        </div>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-4 p-4 bg-good/12 text-good rounded-control text-sm">{{ session('status') }}</div>
            @endif

            <div class="mb-6 bg-panel sm:rounded-card p-6">
                <p class="text-sm text-dim">Saldo total</p>
                <p class="text-3xl font-bold {{ $saldoTotal >= 0 ? 'text-ink' : 'text-bad' }}">
                    R$ {{ number_format($saldoTotal, 2, ',', '.') }}
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                @forelse ($contasFinanceiras as $conta)
                    <div class="bg-panel sm:rounded-card p-6">
                        <div class="flex items-start justify-between">
                            <div>
                                <p class="font-semibold text-ink">{{ $conta->nome }}</p>
                                <p class="text-xs text-dim uppercase">{{ $conta->tipo }}</p>
                            </div>
                            <span class="px-2 py-1 text-xs rounded-full {{ $conta->ativo ? 'bg-good/12 text-good' : 'bg-raised text-ink' }}">
                                {{ $conta->ativo ? 'Ativa' : 'Inativa' }}
                            </span>
                        </div>
                        <p class="mt-4 text-2xl font-bold {{ $conta->saldo >= 0 ? 'text-ink' : 'text-bad' }}">
                            R$ {{ number_format($conta->saldo, 2, ',', '.') }}
                        </p>
                        <div class="mt-4 flex gap-3 text-sm">
                            <a href="{{ route('contas-financeiras.extrato', $conta) }}" class="text-brand hover:text-ink">Extrato</a>
                            <a href="{{ route('contas-financeiras.edit', $conta) }}" class="text-brand hover:text-ink">Editar</a>
                            <form action="{{ route('contas-financeiras.destroy', $conta) }}" method="POST" onsubmit="return confirm('Remover esta conta?');">
                                @csrf @method('DELETE')
                                <button class="text-bad hover:opacity-80">Remover</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="text-sm text-dim">Nenhuma conta financeira cadastrada.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-app-layout>
