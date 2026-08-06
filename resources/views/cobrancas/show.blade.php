<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Receita — {{ $cobranca->descricao }}</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-panel border border-line rounded-card p-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <p class="text-xs text-mute uppercase">Revenda/Cliente</p>
                        <p class="text-ink">{{ $cobranca->revenda->nome ?? $cobranca->cliente->nome ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-mute uppercase">Competência</p>
                        <p class="text-ink">{{ $cobranca->competencia ?? '—' }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-mute uppercase">Vencimento</p>
                        <p class="text-ink">{{ $cobranca->data_vencimento->format('d/m/Y') }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-mute uppercase">Status</p>
                        <span class="px-2 py-1 text-xs rounded-full {{ ['pago' => 'bg-good/12 text-good', 'cancelado' => 'bg-raised text-mute'][$cobranca->status] ?? 'bg-warn/12 text-warn' }}">
                            {{ ucfirst($cobranca->status) }}
                        </span>
                    </div>
                </div>
                <div class="mt-4 pt-4 border-t border-line">
                    <p class="text-xs text-mute uppercase">Valor total</p>
                    <p class="text-2xl font-display font-semibold text-ink">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</p>
                </div>
            </div>

            @if ($cobranca->detalhamento)
                <div class="bg-panel border border-line rounded-card p-6">
                    <h3 class="font-semibold text-ink mb-4">Detalhamento por sistema</h3>
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-mute uppercase">
                                <th class="py-1 pr-4">Sistema</th>
                                <th class="py-1 pr-4">Tier</th>
                                <th class="py-1 pr-4 text-right">Clientes ativos</th>
                                <th class="py-1 pr-4 text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            @foreach ($cobranca->detalhamento as $linha)
                                <tr>
                                    <td class="py-2 pr-4 text-ink">{{ $linha['sistema'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-dim">{{ $linha['tier'] ?? '—' }}</td>
                                    <td class="py-2 pr-4 text-dim text-right">
                                        <details class="inline">
                                            <summary class="cursor-pointer">{{ $linha['clientes_ativos'] ?? '—' }}</summary>
                                            <div class="text-xs text-mute mt-1 text-left">{{ collect($linha['clientes'] ?? [])->join(', ') }}</div>
                                        </details>
                                    </td>
                                    <td class="py-2 pr-4 text-ink text-right">R$ {{ number_format($linha['valor'] ?? 0, 2, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div class="flex items-center gap-4">
                <a href="{{ route('cobrancas.index') }}" class="text-sm text-dim hover:text-ink">&larr; Voltar</a>
                @if ($cobranca->status === 'pendente')
                    <form action="{{ route('cobrancas.baixar', $cobranca) }}" method="POST" onsubmit="return confirm('Confirmar recebimento?');">
                        @csrf
                        <button class="text-good hover:opacity-80 text-sm">Baixar</button>
                    </form>
                @endif
                <a href="{{ route('cobrancas.edit', $cobranca) }}" class="text-brand hover:text-ink text-sm">Editar</a>
            </div>
        </div>
    </div>
</x-app-layout>
