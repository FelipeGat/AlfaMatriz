<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Faturamento das revendas</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-6xl mx-auto sm:px-6 lg:px-8 space-y-6">
            @if (session('status'))
                <div class="p-4 bg-status-good/10 text-status-good rounded-md text-sm">{{ session('status') }}</div>
            @endif

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <div class="flex flex-wrap items-end justify-between gap-4">
                    <form method="GET" class="flex flex-wrap items-end gap-3">
                        <div>
                            <x-input-label for="competencia" value="Competência" />
                            <input type="month" id="competencia" name="competencia" value="{{ $competencia }}" class="mt-1 border-white/10 rounded-md shadow-sm text-sm" onchange="this.form.submit()">
                        </div>
                        <p class="text-xs text-ink-mute pb-2">
                            Relatório calculado em tempo real com os clientes ativos de hoje.
                        </p>
                    </form>

                    <form action="{{ route('faturamento.gerar') }}" method="POST">
                        @csrf
                        <input type="hidden" name="competencia" value="{{ $competencia }}">
                        <x-primary-button>Gerar cobranças consolidadas ({{ $competencia }})</x-primary-button>
                    </form>
                </div>
            </div>

            <div class="space-y-4">
                @forelse ($preview as $grupo)
                    <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="font-display font-semibold text-ink">{{ $grupo['revenda']->nome }}</h3>
                            <p class="text-lg font-semibold text-brand-dim">R$ {{ number_format($grupo['total'], 2, ',', '.') }}/mês</p>
                        </div>

                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs text-ink-mute uppercase">
                                    <th class="py-1 pr-4">Sistema</th>
                                    <th class="py-1 pr-4">Tier aplicado</th>
                                    <th class="py-1 pr-4 text-right">Clientes ativos</th>
                                    <th class="py-1 pr-4 text-right">Valor</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-white/5">
                                @foreach ($grupo['linhas'] as $linha)
                                    <tr>
                                        <td class="py-2 pr-4 text-ink">{{ $linha['sistema'] }}</td>
                                        <td class="py-2 pr-4 text-ink-dim">
                                            @if ($linha['tier'])
                                                {{ $linha['tier'] }}
                                            @else
                                                <span class="text-status-critical">sem tier compatível — revisar</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-4 text-ink-dim text-right">
                                            <details class="inline">
                                                <summary class="cursor-pointer">{{ $linha['clientes_ativos'] }}</summary>
                                                <div class="text-xs text-ink-mute mt-1 text-left">{{ $linha['clientes']->join(', ') }}</div>
                                            </details>
                                        </td>
                                        <td class="py-2 pr-4 text-ink text-right">{{ $linha['valor'] !== null ? 'R$ '.number_format($linha['valor'], 2, ',', '.') : '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @empty
                    <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6 text-sm text-ink-mute">
                        Nenhuma revenda com clientes ativos em sistemas com tier configurado.
                    </div>
                @endforelse
            </div>

            @if ($cobrancasGeradas->isNotEmpty())
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                    <h3 class="font-display font-semibold text-ink mb-4">Cobranças já geradas para {{ $competencia }}</h3>
                    <ul class="divide-y divide-white/5 text-sm">
                        @foreach ($cobrancasGeradas as $cobranca)
                            <li class="py-2 flex items-center justify-between">
                                <span class="text-ink">{{ $cobranca->revenda->nome }} — {{ $cobranca->descricao }}</span>
                                <div class="flex items-center gap-3">
                                    <span class="text-ink font-medium">R$ {{ number_format($cobranca->valor, 2, ',', '.') }}</span>
                                    <a href="{{ route('cobrancas.show', $cobranca) }}" class="text-brand hover:text-brand-bright">Ver detalhe</a>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
