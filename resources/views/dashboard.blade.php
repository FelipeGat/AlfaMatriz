<x-app-layout>
    <x-slot name="titulo">Painel Financeiro</x-slot>
    <x-slot name="contexto">competência {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y') }}</x-slot>

    <div class="space-y-4">
        {{-- Navegação de competência: os cinco cards do topo são desta
             competência, não sempre "hoje" — sem isto não havia como olhar um
             mês fechado sem trocar de tela. Anterior/próximo e o seletor
             preservam o filtro do gráfico logo abaixo, que é independente
             deste. --}}
        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard', array_merge(request()->except('competencia'), ['competencia' => $competenciaAnterior])) }}"
               class="flex h-8 w-8 items-center justify-center rounded-control border border-line text-ink-mute hover:border-brand hover:text-brand transition"
               aria-label="Competência anterior">
                <span class="h-3.5 w-3.5"><x-nav-icon name="chevron-left" :peso="1.8" /></span>
            </a>

            <form method="GET" class="flex items-center">
                @foreach (request()->except(['competencia']) as $chave => $valor)
                    <input type="hidden" name="{{ $chave }}" value="{{ $valor }}">
                @endforeach
                <input type="month" name="competencia" value="{{ $competencia }}" onchange="this.form.submit()"
                       class="h-8 py-0 text-[13px] rounded-control bg-input border-line text-ink">
            </form>

            <a href="{{ route('dashboard', array_merge(request()->except('competencia'), ['competencia' => $competenciaProxima])) }}"
               class="flex h-8 w-8 items-center justify-center rounded-control border border-line text-ink-mute hover:border-brand hover:text-brand transition"
               aria-label="Próxima competência">
                <span class="h-3.5 w-3.5"><x-nav-icon name="chevron-right" :peso="1.8" /></span>
            </a>

            @unless ($competenciaEhAtual)
                <a href="{{ route('dashboard', request()->except('competencia')) }}"
                   class="font-mono text-[10.5px] uppercase tracking-caps text-brand-text hover:underline ml-1">Hoje</a>
            @endunless
        </div>

        {{-- Os cinco números do mês --}}
        <div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
            {{-- O mesmo número, a mesma marca e a mesma curva do Centro de
                 Controle: as duas telas perguntam ao serviço, e é isso que as
                 impede de mostrar valores diferentes sob este rótulo. --}}
            <x-kpi-card rotulo="Receita recorrente" :valor="'R$ '.number_format($mrr, 2, ',', '.')"
                        :delta="$mrrContratado ? 'contratado · competência ainda não fechada' : null"
                        :serie="$serieMrr"
                        acento="accent" icone="trending-up" />
            <x-kpi-card rotulo="Projeção anual" :valor="'R$ '.number_format($arr, 2, ',', '.')"
                        acento="brand" icone="trending-up" />
            <x-kpi-card rotulo="Saldo em caixa" :valor="'R$ '.number_format($saldoTotal, 2, ',', '.')"
                        :serie="$serieSaldo"
                        :acento="$saldoTotal >= 0 ? 'brand' : 'crit'" icone="banknotes" />
            <x-kpi-card rotulo="Entradas do mês" :valor="'R$ '.number_format($entradasMes, 2, ',', '.')"
                        :serie="$serieEntradas"
                        acento="good" icone="arrow-down-circle" />
            <x-kpi-card rotulo="Saídas do mês" :valor="'R$ '.number_format($saidasMes, 2, ',', '.')"
                        :serie="$serieSaidas"
                        acento="chart-out" icone="arrow-up-circle" />
        </div>

        {{-- A projeção anual é conta de padeiro, e a tela precisa dizer isso. --}}
        <p class="text-[11.5px] text-ink-faint">
            Projeção anual = receita recorrente × 12 (projeção simples: não considera sazonalidade nem contratos anuais reais).
        </p>

        <div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
            <x-painel titulo="Entradas x saídas" style="grid-column: span 1">
                {{-- Linha própria para o filtro, fora da faixa de 38px do
                     cabeçalho: com dois inputs de mês e o rótulo do período
                     ela não cabia ali sem cortar texto (AC pedido em
                     15/08/2026). Quebra livre em telas estreitas. --}}
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 mb-3">
                    <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotuloGrafico }}</span>

                    {{-- Mesma altura/tipografia do seletor de competência do
                         Faturamento (`h-9`, `text-[13px]`, sem uppercase): a
                         combinação anterior (h-7 + 10.5px + uppercase +
                         tracking-caps) espremia demais o `<select>` nativo e
                         corrompia a renderização do texto/seta. --}}
                    <form method="GET" class="flex flex-wrap items-center gap-1.5">
                        @foreach (request()->except(['grafico', 'grafico_de', 'grafico_ate']) as $chave => $valor)
                            <input type="hidden" name="{{ $chave }}" value="{{ $valor }}">
                        @endforeach
                        <select name="grafico" onchange="this.form.submit()"
                                class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink">
                            <option value="ano_atual" {{ $filtroGrafico === 'ano_atual' ? 'selected' : '' }}>Ano vigente</option>
                            <option value="mes_anterior" {{ $filtroGrafico === 'mes_anterior' ? 'selected' : '' }}>Mês anterior</option>
                            <option value="mes_atual" {{ $filtroGrafico === 'mes_atual' ? 'selected' : '' }}>Mês atual</option>
                            <option value="proximo_mes" {{ $filtroGrafico === 'proximo_mes' ? 'selected' : '' }}>Próximo mês</option>
                            <option value="personalizado" {{ $filtroGrafico === 'personalizado' ? 'selected' : '' }}>Período personalizado</option>
                        </select>

                        @if ($filtroGrafico === 'personalizado')
                            <input type="month" name="grafico_de" value="{{ $graficoDe }}" onchange="this.form.submit()"
                                   class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink w-[128px]">
                            <span class="font-mono text-[10.5px] text-ink-faint">até</span>
                            <input type="month" name="grafico_ate" value="{{ $graficoAte }}" onchange="this.form.submit()"
                                   class="h-9 py-0 text-[13px] rounded-control bg-input border-line text-ink w-[128px]">
                        @endif
                    </form>
                </div>

                <x-bar-chart :data="$historico" />

                @if (collect($historico)->contains('previsto', true))
                    <p class="mt-2 text-[11px] text-ink-faint">
                        Barra mais clara e tracejada = <strong>previsto</strong> (receita contratada e despesa fixa
                        projetada) — meses que ainda não aconteceram não têm caixa movimentado para mostrar.
                    </p>
                @endif
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
