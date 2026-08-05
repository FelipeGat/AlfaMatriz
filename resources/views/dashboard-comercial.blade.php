<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Painel Comercial</h2>
    </x-slot>

    <div class="space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-stat-card label="Sistemas ativos" :value="$totalSistemasAtivos" icon="cube-outline" accent="brand" />
            <x-stat-card label="Clientes ativos (todos os sistemas)" :value="$totalClientesAtivos" icon="users" accent="good" />
            <x-stat-card label="Revendas ativas" :value="$totalRevendasAtivas" icon="building" accent="ink" />
            <x-stat-card label="MRR estimado (atacado)" :value="'R$ ' . number_format($mrrEstimado, 2, ',', '.')" icon="trending-up" accent="brand" />
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <h3 class="font-display font-semibold text-ink mb-1">Ranking por quantidade de clientes</h3>
                <p class="text-xs text-ink-mute mb-4">Clientes ativos por sistema, hoje.</p>

                <div class="space-y-3">
                    @forelse ($porQuantidade as $i => $item)
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-ink">{{ $i + 1 }}. {{ $item['sistema']->nome }}</span>
                                <span class="text-ink-dim">{{ $item['clientes_ativos'] }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full bg-brand" style="width: {{ $item['clientes_ativos'] > 0 ? max(($item['clientes_ativos'] / $maxQuantidade) * 100, 3) : 0 }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-ink-mute">Nenhum sistema com clientes ainda.</p>
                    @endforelse
                </div>
            </div>

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <h3 class="font-display font-semibold text-ink mb-1">Ranking por valor gerado</h3>
                <p class="text-xs text-ink-mute mb-4">Estimativa de atacado mensal por sistema, hoje.</p>

                <div class="space-y-3">
                    @forelse ($porValor as $i => $item)
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <span class="text-ink">{{ $i + 1 }}. {{ $item['sistema']->nome }}</span>
                                <span class="text-ink-dim">R$ {{ number_format($item['valor_estimado'], 2, ',', '.') }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-white/5 overflow-hidden">
                                <div class="h-full rounded-full bg-amber-signal" style="width: {{ $item['valor_estimado'] > 0 ? max(($item['valor_estimado'] / $maxValor) * 100, 3) : 0 }}%"></div>
                            </div>
                        </div>
                    @empty
                        <p class="text-sm text-ink-mute">Nenhum valor gerado ainda.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <h3 class="font-display font-semibold text-ink mb-4">Clientes por revenda</h3>
                <ul class="divide-y divide-white/5 text-sm">
                    @forelse ($porRevenda as $nome => $qtd)
                        <li class="py-2 flex items-center justify-between">
                            <span class="text-ink">{{ $nome }}</span>
                            <span class="text-ink-dim">{{ $qtd }} {{ Str::plural('cliente', $qtd) }}</span>
                        </li>
                    @empty
                        <li class="py-2 text-ink-mute">Nenhum cliente ativo.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <h3 class="font-display font-semibold text-ink mb-4">Sistemas por categoria</h3>
                <ul class="divide-y divide-white/5 text-sm">
                    @foreach ($porCategoria as $categoria => $qtd)
                        <li class="py-2 flex items-center justify-between">
                            <span class="text-ink uppercase">{{ $categoria }}</span>
                            <span class="text-ink-dim">{{ $qtd }} {{ Str::plural('sistema', $qtd) }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
