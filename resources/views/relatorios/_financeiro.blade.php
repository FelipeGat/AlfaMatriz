{{-- Os mesmos números do Painel Financeiro, da mesma origem
     (`IndicadoresService`) — aqui eles ganham o RESULTADO do mês, que lá não
     existe como card. As curvas são a tendência recente (últimos 6 meses até
     hoje), não a competência navegada — a mesma nota do dashboard. --}}
<div class="grid gap-3" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr))">
    <x-kpi-card rotulo="Receita recorrente" :valor="'R$ '.number_format($mrr, 2, ',', '.')"
                :delta="$mrrContratado ? 'contratado · competência ainda não fechada' : null"
                :serie="$serieMrr"
                acento="accent" icone="trending-up" />
    <x-kpi-card rotulo="Entradas do mês" :valor="'R$ '.number_format($entradasMes, 2, ',', '.')"
                :serie="$serieEntradas"
                acento="good" icone="arrow-down-circle" />
    <x-kpi-card rotulo="Saídas do mês" :valor="'R$ '.number_format($saidasMes, 2, ',', '.')"
                :serie="$serieSaidas"
                acento="chart-out" icone="arrow-up-circle" />
    <x-kpi-card rotulo="Resultado do mês" :valor="'R$ '.number_format($resultadoMes, 2, ',', '.')"
                :acento="$resultadoMes >= 0 ? 'good' : 'crit'"
                :icone="$resultadoMes >= 0 ? 'trending-up' : 'trending-down'" />
    <x-kpi-card rotulo="Saldo ao fim da competência" :valor="'R$ '.number_format($saldoTotal, 2, ',', '.')"
                :serie="$serieSaldo"
                :acento="$saldoTotal >= 0 ? 'brand' : 'crit'" icone="banknotes" />
</div>

{{-- O recorte não alcança o caixa, e a tela precisa dizer isso: "saldo do
     centro de custo X" não existe no livro-caixa, e calar aqui deixaria os
     cards parecerem filtrados — ver `secaoFinanceiro()`. --}}
@if ($recorteNosTitulos)
    <p class="text-[11.5px] text-ink-faint">
        O recorte vale para os painéis de títulos (a receber, a pagar, faixas de vencimento e centro de custo).
        Os cards de caixa e o gráfico são da casa inteira e respondem só à competência.
    </p>
@endif

{{-- O gráfico numa coluna de grade, como no dashboard: o desenho dele é de
     720px (ver o teto no próprio componente), e a receita por tipo ocupa o
     resto da linha em vez de deixar o vão. --}}
<div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    <x-painel titulo="Entradas x saídas" :sub="'ano de '.substr($competencia, 0, 4)">
        <x-bar-chart :data="$historico" />

        @if (collect($historico)->contains('previsto', true))
            <p class="mt-2 text-[11px] text-ink-faint">
                Barra mais clara e tracejada = <strong>previsto</strong> (receita contratada e despesa fixa
                projetada) — meses que ainda não aconteceram não têm caixa movimentado para mostrar.
            </p>
        @endif
    </x-painel>

    <x-ranking :ranking="$rankingTiposReceita"
               titulo="Receita da competência por tipo"
               nota="faturado, pago ou não"
               rotuloTotal="Total faturado"
               formato="reais"
               compacto />
</div>

{{-- O em-aberto é ACUMULADO, não da competência: título pendente de março
     continua pendente em agosto, e um recorte por mês esconderia justamente o
     que envelheceu. --}}
<div class="grid gap-4 items-start" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr))">
    <x-painel titulo="A receber em aberto" sub="acumulado">
        <dl class="divide-y divide-rule">
            <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                <dt class="text-[13px] text-ink-dim">Total em aberto</dt>
                <dd class="font-mono text-[13.5px] text-ink tabular">R$ {{ number_format($aReceber->total, 2, ',', '.') }}</dd>
            </div>
            <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                <dt class="text-[13px] text-ink-dim">Vencido</dt>
                <dd class="font-mono text-[13.5px] tabular {{ $aReceber->vencido > 0 ? 'text-crit' : 'text-ink' }}">
                    R$ {{ number_format($aReceber->vencido, 2, ',', '.') }}
                </dd>
            </div>
            <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                <dt class="text-[13px] text-ink-dim">Títulos</dt>
                <dd class="font-mono text-[13.5px] text-ink tabular">{{ number_format($aReceber->qtd, 0, ',', '.') }}</dd>
            </div>
        </dl>
    </x-painel>

    {{-- As MESMAS quatro faixas da tela de Receitas (`faixasDeAging()`, no
         Controller base): duas réguas de faixa é como "16 a 30" passa a
         significar duas coisas. --}}
    <x-painel titulo="A receber por faixa de vencimento" sub="aging do acumulado">
        @php $alphasFaixa = [0.9, 0.72, 0.58, 0.46]; @endphp
        <div class="flex flex-wrap gap-x-6 gap-y-2 mb-3">
            @foreach (array_values($faixasAReceber) as $i => $faixa)
                <div class="flex items-center gap-2">
                    <span class="h-2.5 w-2.5 rounded-badge shrink-0"
                          style="background: rgb(var(--warn) / {{ $alphasFaixa[$i] ?? end($alphasFaixa) }})"></span>
                    <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-mute">{{ $faixa['rotulo'] }}</span>
                    <span class="font-mono text-[12px] text-ink tabular">R$ {{ number_format($faixa['valor'], 2, ',', '.') }}</span>
                </div>
            @endforeach
        </div>

        <x-faixa-segmentada cor="warn"
                            :segmentos="collect($faixasAReceber)->map(fn ($f) => ['rotulo' => $f['rotulo'], 'valor' => $f['valor']])->values()->all()" />
    </x-painel>

    <x-painel titulo="A pagar em aberto" sub="acumulado">
        <dl class="divide-y divide-rule">
            <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                <dt class="text-[13px] text-ink-dim">Total em aberto</dt>
                <dd class="font-mono text-[13.5px] text-ink tabular">R$ {{ number_format($aPagar->total, 2, ',', '.') }}</dd>
            </div>
            <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                <dt class="text-[13px] text-ink-dim">Vencido</dt>
                <dd class="font-mono text-[13.5px] tabular {{ $aPagar->vencido > 0 ? 'text-crit' : 'text-ink' }}">
                    R$ {{ number_format($aPagar->vencido, 2, ',', '.') }}
                </dd>
            </div>
            <div class="flex items-center justify-between py-2.5 first:pt-0 last:pb-0">
                <dt class="text-[13px] text-ink-dim">Títulos</dt>
                <dd class="font-mono text-[13.5px] text-ink tabular">{{ number_format($aPagar->qtd, 0, ',', '.') }}</dd>
            </div>
        </dl>
    </x-painel>

    {{-- "Lançado", e não "pago": conta em aberto já é destino decidido do
         dinheiro do mês — ver `secaoFinanceiro()`. --}}
    <x-ranking :ranking="$rankingCentrosDeCusto"
               titulo="Despesa por centro de custo"
               nota="lançado na competência"
               formato="reais"
               compacto />
</div>

{{-- A mesa de cobrança: os dez maiores valores vencidos, com a origem de
     cada um — é por onde a régua de cobrança começa. --}}
<x-tabela min="820px" titulo="Maiores títulos vencidos" sub="a receber · top 10 por valor">
    <thead>
        <tr class="bg-head border-b border-line font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <th class="px-4 py-2.5 font-semibold">Título</th>
            <th class="px-4 py-2.5 font-semibold">Origem</th>
            <th class="px-4 py-2.5 font-semibold">Venceu em</th>
            <th class="px-4 py-2.5 font-semibold text-right">Dias de atraso</th>
            <th class="px-4 py-2.5 font-semibold text-right">Valor</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($maioresVencidos as $cobranca)
            <tr class="border-b border-rule hover:bg-chip transition">
                <td class="px-4 py-3 text-[13.5px] text-ink">{{ $cobranca->descricao }}</td>
                <td class="px-4 py-3 text-[13px] text-ink-dim">{{ $cobranca->revenda?->nome ?? $cobranca->cliente?->nome_exibicao ?? 'Sem origem' }}</td>
                <td class="px-4 py-3 font-mono text-[13px] text-ink-dim whitespace-nowrap">
                    {{ \Illuminate\Support\Carbon::parse($cobranca->data_vencimento)->format('d/m/Y') }}
                </td>
                <td class="px-4 py-3 font-mono text-[13px] text-crit tabular text-right">
                    {{ number_format(abs(now()->startOfDay()->diffInDays(\Illuminate\Support\Carbon::parse($cobranca->data_vencimento))), 0, ',', '.') }}
                </td>
                <td class="px-4 py-3 font-mono text-[13px] text-ink tabular text-right whitespace-nowrap">
                    R$ {{ number_format($cobranca->valor, 2, ',', '.') }}
                </td>
            </tr>
        @empty
            <tr><td colspan="5" class="px-4 py-6 text-[13px] text-ink-mute">Nenhum título vencido neste recorte.</td></tr>
        @endforelse
    </tbody>
</x-tabela>
