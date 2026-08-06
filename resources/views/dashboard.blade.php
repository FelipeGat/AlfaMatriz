<x-app-layout>
    <x-slot name="titulo">Painel Financeiro</x-slot>
    <x-slot name="contexto">competência {{ now()->format('m/Y') }}</x-slot>

    <div class="space-y-4">
        {{-- Os cinco números do mês --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            <x-kpi-card rotulo="Receita recorrente" :valor="'R$ '.number_format($mrr, 2, ',', '.')"
                        acento="accent" icone="trending-up" />
            <x-kpi-card rotulo="Projeção anual" :valor="'R$ '.number_format($arr, 2, ',', '.')"
                        acento="brand" icone="trending-up" />
            <x-kpi-card rotulo="Saldo em caixa" :valor="'R$ '.number_format($saldoTotal, 2, ',', '.')"
                        :acento="$saldoTotal >= 0 ? 'brand' : 'crit'" icone="banknotes" />
            <x-kpi-card rotulo="Entradas do mês" :valor="'R$ '.number_format($entradasMes, 2, ',', '.')"
                        acento="good" icone="arrow-down-circle" />
            <x-kpi-card rotulo="Saídas do mês" :valor="'R$ '.number_format($saidasMes, 2, ',', '.')"
                        acento="chart-out" icone="arrow-up-circle" />
        </div>

        {{-- A projeção anual é conta de padeiro, e a tela precisa dizer isso. --}}
        <p class="text-[11.5px] text-ink-faint">
            Projeção anual = receita recorrente × 12 (projeção simples: não considera sazonalidade nem contratos anuais reais).
        </p>

        <div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            <x-painel titulo="Entradas x saídas" sub="últimos 6 meses" style="grid-column: span 1">
                <x-bar-chart :data="$historico" />
            </x-painel>

            <x-painel titulo="Base instalada">
                <dl class="divide-y divide-rule">
                    @foreach ([
                        ['Revendas ativas', $totalRevendas],
                        ['Clientes ativos', $totalClientes],
                        ['Clientes diretos', $clientesDiretos],
                    ] as [$rotulo, $valor])
                        <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                            <dt class="text-[13px] text-ink-dim">{{ $rotulo }}</dt>
                            <dd class="font-mono text-[13.5px] text-ink tabular">{{ number_format($valor, 0, ',', '.') }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-painel>
        </div>

        {{-- O que está em aberto dos dois lados --}}
        <div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            <x-painel titulo="Receitas pendentes" solto>
                <x-slot name="acoes">
                    <a href="{{ route('cobrancas.index', ['status' => 'pendente']) }}"
                       class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Ver todas</a>
                </x-slot>

                @forelse ($receitasPendentes as $receita)
                    <a href="{{ route('cobrancas.index') }}"
                       class="flex items-center gap-3 px-4 py-3 border-b border-rule last:border-0 hover:bg-chip transition">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] text-ink truncate">{{ $receita->descricao }}</span>
                            <span class="block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                {{ $receita->revenda?->nome ?? $receita->cliente?->nome_exibicao ?? 'sem origem' }}
                                · vence {{ \Illuminate\Support\Carbon::parse($receita->data_vencimento)->format('d/m') }}
                            </span>
                        </span>
                        <span class="shrink-0 font-mono text-[13px] text-ink whitespace-nowrap">
                            R$ {{ number_format($receita->valor, 2, ',', '.') }}
                        </span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-[13px] text-ink-mute">Nenhuma receita em aberto.</p>
                @endforelse
            </x-painel>

            <x-painel titulo="Despesas em aberto" solto>
                <x-slot name="acoes">
                    <a href="{{ route('contas-pagar.index', ['status' => 'em_aberto']) }}"
                       class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline">Ver todas</a>
                </x-slot>

                @forelse ($despesasPendentes as $despesa)
                    <a href="{{ route('contas-pagar.index') }}"
                       class="flex items-center gap-3 px-4 py-3 border-b border-rule last:border-0 hover:bg-chip transition">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13px] text-ink truncate">{{ $despesa->descricao }}</span>
                            <span class="block font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">
                                {{ $despesa->fornecedor?->nome ?? 'sem fornecedor' }}
                                · vence {{ \Illuminate\Support\Carbon::parse($despesa->data_vencimento)->format('d/m') }}
                            </span>
                        </span>
                        <span class="shrink-0 font-mono text-[13px] text-ink whitespace-nowrap">
                            R$ {{ number_format($despesa->valor, 2, ',', '.') }}
                        </span>
                    </a>
                @empty
                    <p class="px-4 py-6 text-[13px] text-ink-mute">Nenhuma despesa em aberto.</p>
                @endforelse
            </x-painel>
        </div>
    </div>
</x-app-layout>
