<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">Painel Financeiro</h2>
    </x-slot>

    <div class="space-y-6">

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <x-stat-card label="MRR (mês atual)" :value="'R$ ' . number_format($mrr, 2, ',', '.')" icon="trending-up" accent="brand" />
            <x-stat-card label="ARR (projetado)" :value="'R$ ' . number_format($arr, 2, ',', '.')" icon="trending-up" accent="brand" />
            <x-stat-card label="Saldo em caixa" :value="'R$ ' . number_format($saldoTotal, 2, ',', '.')" icon="banknotes" :accent="$saldoTotal >= 0 ? 'ink' : 'critical'" />
            <x-stat-card label="Entradas do mês" :value="'R$ ' . number_format($entradasMes, 2, ',', '.')" icon="arrow-down-circle" accent="good" />
            <x-stat-card label="Saídas do mês" :value="'R$ ' . number_format($saidasMes, 2, ',', '.')" icon="arrow-up-circle" accent="warning" />
        </div>
        <p class="text-xs text-ink-mute -mt-2">ARR = MRR × 12 (projeção simples, não considera sazonalidade nem contratos anuais reais).</p>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <div class="lg:col-span-2 min-w-0 bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <h3 class="font-display font-semibold text-ink mb-4">Entradas x Saídas — últimos 6 meses</h3>
                <x-bar-chart :data="$historico" />
            </div>

            <div class="space-y-4">
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                    <p class="text-xs text-ink-mute uppercase tracking-wide">Revendas ativas</p>
                    <p class="mt-1 text-2xl font-display font-semibold text-ink">{{ $totalRevendas }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                    <p class="text-xs text-ink-mute uppercase tracking-wide">Clientes ativos</p>
                    <p class="mt-1 text-2xl font-display font-semibold text-ink">{{ $totalClientes }}</p>
                </div>
                <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                    <p class="text-xs text-ink-mute uppercase tracking-wide">Clientes diretos (fora de revenda)</p>
                    <p class="mt-1 text-2xl font-display font-semibold text-ink">{{ $clientesDiretos }}</p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-display font-semibold text-ink">Receitas pendentes</h3>
                    <a href="{{ route('cobrancas.index') }}" class="text-xs text-brand hover:text-brand-bright">Ver todas</a>
                </div>
                <ul class="divide-y divide-white/5">
                    @forelse ($receitasPendentes as $receita)
                        <li class="py-3 flex items-center justify-between text-sm">
                            <div class="min-w-0">
                                <p class="text-ink truncate">{{ $receita->descricao }}</p>
                                <p class="text-xs text-ink-mute">{{ $receita->revenda->nome ?? $receita->cliente->nome ?? '—' }} · vence {{ $receita->data_vencimento->format('d/m/Y') }}</p>
                            </div>
                            <span class="font-medium text-ink shrink-0 ml-3">R$ {{ number_format($receita->valor, 2, ',', '.') }}</span>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-ink-mute">Nenhuma receita pendente.</li>
                    @endforelse
                </ul>
            </div>

            <div class="bg-panel border border-white/5 shadow-panel rounded-xl p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="font-display font-semibold text-ink">Despesas em aberto</h3>
                    <a href="{{ route('contas-pagar.index') }}" class="text-xs text-brand hover:text-brand-bright">Ver todas</a>
                </div>
                <ul class="divide-y divide-white/5">
                    @forelse ($despesasPendentes as $despesa)
                        <li class="py-3 flex items-center justify-between text-sm">
                            <div class="min-w-0">
                                <p class="text-ink truncate">{{ $despesa->descricao }}</p>
                                <p class="text-xs text-ink-mute">{{ $despesa->fornecedor->razao_social ?? '—' }} · vence {{ $despesa->data_vencimento->format('d/m/Y') }}</p>
                            </div>
                            <span class="font-medium text-ink shrink-0 ml-3">R$ {{ number_format($despesa->valor, 2, ',', '.') }}</span>
                        </li>
                    @empty
                        <li class="py-3 text-sm text-ink-mute">Nenhuma despesa em aberto.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</x-app-layout>
