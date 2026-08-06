<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-2 text-[13px]">
            <span class="text-mute">Painéis</span>
            <span class="text-line">/</span>
            <span class="font-medium text-ink">Financeiro</span>
        </div>
    </x-slot>

    <div class="space-y-[18px]">

        {{-- auto-fit/minmax: os cards reflowam sozinhos e o valor tem largura
             mínima garantida — a combinação que impede a quebra de linha. --}}
        <div class="grid gap-[14px]" style="grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));">
            <x-kpi-card
                label="MRR do mês"
                :value="'R$ ' . number_format($mrr, 2, ',', '.')"
                apoio="competência {{ now()->format('m/Y') }}" />

            <x-kpi-card
                label="Saldo em caixa"
                :value="'R$ ' . number_format($saldoTotal, 2, ',', '.')"
                :tom="$saldoTotal >= 0 ? 'ink' : 'bad'"
                apoio="contas ativas" />

            <x-kpi-card
                label="Entradas do mês"
                :value="'R$ ' . number_format($entradasMes, 2, ',', '.')"
                tom="good"
                :proporcao="$entradasMes + $saidasMes > 0 ? ($entradasMes / ($entradasMes + $saidasMes)) * 100 : 0"
                apoio="recebido em {{ now()->format('m/Y') }}" />

            <x-kpi-card
                label="Saídas do mês"
                :value="'R$ ' . number_format($saidasMes, 2, ',', '.')"
                tom="warn"
                :proporcao="$entradasMes + $saidasMes > 0 ? ($saidasMes / ($entradasMes + $saidasMes)) * 100 : 0"
                apoio="pago em {{ now()->format('m/Y') }}" />
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <x-painel-card titulo="Entradas × Saídas — últimos 6 meses" class="lg:col-span-2 min-w-0">
                <x-bar-chart :data="$historico" />
            </x-painel-card>

            <div class="space-y-4">
                <x-summary-card label="Revendas ativas" :value="$totalRevendas" contexto="parceiras da Alfa" />
                <x-summary-card label="Clientes ativos" :value="$totalClientes" contexto="base total" />
                <x-summary-card label="Clientes diretos" :value="$clientesDiretos" contexto="fora de revenda" />

                {{-- Destaque neutro: na direção nova, chamar atenção é papel
                     da superfície, não da cor. --}}
                <div class="rounded-summary border border-line bg-raised px-[18px] py-4">
                    <p class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">Fechamento do mês</p>
                    <p class="mt-1.5 text-[12.5px] text-dim">Gere as cobranças das revendas da competência {{ now()->format('m/Y') }}.</p>
                    <a href="{{ route('faturamento.index') }}"
                       class="mt-3 inline-flex items-center rounded-control bg-ink px-3 py-1.5 text-[12.5px] font-medium text-bg transition-opacity hover:opacity-90">
                        Ir para Faturamento
                    </a>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-painel-card titulo="Receitas pendentes">
                <x-slot name="acao">
                    <a href="{{ route('cobrancas.index') }}" class="text-dim transition-colors hover:text-ink">Ver todas</a>
                </x-slot>

                <ul class="divide-y divide-line">
                    @forelse ($receitasPendentes as $receita)
                        @php $vencida = $receita->data_vencimento->isPast(); @endphp
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-[13px] text-ink">{{ $receita->descricao }}</p>
                                <p class="text-[11.5px] {{ $vencida ? 'text-bad' : 'text-mute' }}">
                                    {{ $receita->revenda->nome ?? $receita->cliente->nome ?? '—' }} ·
                                    {{ $vencida ? 'venceu' : 'vence' }} {{ $receita->data_vencimento->format('d/m/Y') }}
                                </p>
                            </div>
                            <span class="valor shrink-0 text-[12.5px] font-medium text-ink">
                                R$ {{ number_format($receita->valor, 2, ',', '.') }}
                            </span>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[13px] text-mute">Nenhuma receita pendente.</li>
                    @endforelse
                </ul>
            </x-painel-card>

            <x-painel-card titulo="Despesas em aberto">
                <x-slot name="acao">
                    <a href="{{ route('contas-pagar.index') }}" class="text-dim transition-colors hover:text-ink">Ver todas</a>
                </x-slot>

                <ul class="divide-y divide-line">
                    @forelse ($despesasPendentes as $despesa)
                        @php $vencida = $despesa->data_vencimento->isPast(); @endphp
                        <li class="flex items-center justify-between gap-3 py-3">
                            <div class="min-w-0">
                                <p class="truncate text-[13px] text-ink">{{ $despesa->descricao }}</p>
                                <p class="text-[11.5px] {{ $vencida ? 'text-bad' : 'text-mute' }}">
                                    {{ $despesa->fornecedor->razao_social ?? '—' }} ·
                                    {{ $vencida ? 'venceu' : 'vence' }} {{ $despesa->data_vencimento->format('d/m/Y') }}
                                </p>
                            </div>
                            <span class="valor shrink-0 text-[12.5px] font-medium text-ink">
                                R$ {{ number_format($despesa->valor, 2, ',', '.') }}
                            </span>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[13px] text-mute">Nenhuma despesa em aberto.</li>
                    @endforelse
                </ul>
            </x-painel-card>
        </div>
    </div>
</x-app-layout>
