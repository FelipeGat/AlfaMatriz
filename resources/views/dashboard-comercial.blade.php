@php
    // Paleta dos segmentos, na ordem do handoff.
    $cores = ['#029caf', '#2ec9d9', '#0f7c8a', '#7fdce6', '#e8a045', '#8fa4a8'];

    // A rosca: r=47, traço 11, girada -90° para começar no topo. O vão de 2.5
    // unidades entre arcos é o que separa visualmente os sistemas.
    $raio = 47;
    $circunferencia = 2 * M_PI * $raio;
    $vao = 2.5;

    $montarArcos = function ($itens, string $campo) use ($cores, $circunferencia, $vao) {
        $total = collect($itens)->sum($campo);
        $arcos = [];
        $acumulado = 0;

        foreach ($itens as $i => $item) {
            $valor = (float) $item[$campo];
            $fatia = $total > 0 ? $valor / $total : 0;
            $comprimento = max(($fatia * $circunferencia) - $vao, 0);

            $arcos[] = [
                'cor' => $cores[$i % count($cores)],
                'dash' => $comprimento,
                'offset' => -$acumulado,
                'nome' => $item['sistema']->nome,
                'categoria' => $item['sistema']->categoria,
                'valor' => $valor,
                'participacao' => $fatia * 100,
            ];

            $acumulado += $fatia * $circunferencia;
        }

        return ['arcos' => $arcos, 'total' => $total];
    };

    $porClientes = $montarArcos($porQuantidade->take(6), 'clientes_ativos');
    $porValorRanking = $montarArcos($porValor->take(6), 'valor_estimado');
@endphp

<x-app-layout>
    <x-slot name="header">
        <p class="font-mono text-[9.5px] font-medium uppercase tracking-[.16em] text-mute">Painéis</p>
        <h2 class="font-display text-[17px] font-semibold tracking-[-.02em] text-ink">Comercial</h2>
    </x-slot>

    <div class="space-y-[18px]" x-data="{ metrica: 'clientes' }">

        <div class="grid gap-[14px]" style="grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));">
            <x-kpi-card label="Sistemas ativos" :value="$totalSistemasAtivos" apoio="no catálogo" />
            <x-kpi-card label="Clientes ativos" :value="$totalClientesAtivos" apoio="em todos os sistemas" />
            <x-kpi-card label="Revendas ativas" :value="$totalRevendasAtivas" apoio="parceiras da Alfa" />
            <x-kpi-card label="MRR estimado" :value="'R$ ' . number_format($mrrEstimado, 2, ',', '.')" apoio="preço de atacado" />
        </div>

        <x-painel-card titulo="Ranking de sistemas">
            <x-slot name="acao">
                {{-- Alternador: puramente visual, os dois conjuntos já vêm do servidor --}}
                <div class="inline-flex rounded-control bg-raised p-0.5">
                    <button type="button" @click="metrica = 'clientes'"
                            class="rounded-[7px] px-3 py-1 text-[12px] transition-colors"
                            :class="metrica === 'clientes' ? 'bg-brand-soft text-brand' : 'text-dim hover:text-ink'">
                        Por clientes
                    </button>
                    <button type="button" @click="metrica = 'valor'"
                            class="rounded-[7px] px-3 py-1 text-[12px] transition-colors"
                            :class="metrica === 'valor' ? 'bg-brand-soft text-brand' : 'text-dim hover:text-ink'">
                        Por valor
                    </button>
                </div>
            </x-slot>

            @foreach ([['chave' => 'clientes', 'dados' => $porClientes, 'unidade' => 'clientes', 'moeda' => false],
                       ['chave' => 'valor', 'dados' => $porValorRanking, 'unidade' => 'por mês', 'moeda' => true]] as $painel)
                <div x-show="metrica === '{{ $painel['chave'] }}'" @if ($painel['chave'] === 'valor') x-cloak @endif
                     class="flex flex-wrap items-center gap-6">

                    <div class="relative shrink-0" style="width: 206px; height: 206px;">
                        <svg viewBox="0 0 120 120" class="h-full w-full -rotate-90">
                            <circle cx="60" cy="60" r="{{ $raio }}" fill="none" stroke="var(--track)" stroke-width="11" />
                            @foreach ($painel['dados']['arcos'] as $arco)
                                <circle cx="60" cy="60" r="{{ $raio }}" fill="none"
                                        stroke="{{ $arco['cor'] }}" stroke-width="11"
                                        stroke-dasharray="{{ round($arco['dash'], 2) }} {{ round($circunferencia, 2) }}"
                                        stroke-dashoffset="{{ round($arco['offset'], 2) }}" />
                            @endforeach
                        </svg>

                        <div class="absolute inset-0 grid place-items-center text-center">
                            <div>
                                <p class="valor font-medium tracking-[-.03em] text-[clamp(17px,1.4vw,21px)] text-ink">
                                    @if ($painel['moeda'])
                                        R$ {{ number_format($painel['dados']['total'], 0, ',', '.') }}
                                    @else
                                        {{ (int) $painel['dados']['total'] }}
                                    @endif
                                </p>
                                <p class="mt-0.5 text-[9.5px] font-medium uppercase tracking-[.16em] text-mute">{{ $painel['unidade'] }}</p>
                            </div>
                        </div>
                    </div>

                    <ul class="min-w-0 flex-1 space-y-1.5" style="flex-basis: 320px;">
                        @forelse ($painel['dados']['arcos'] as $i => $arco)
                            <li class="flex items-center gap-3 rounded-control py-1.5 pl-3 pr-2" style="box-shadow: inset 3px 0 0 0 {{ $arco['cor'] }};">
                                <span class="valor w-6 shrink-0 text-[11.5px] text-mute">{{ str_pad($i + 1, 2, '0', STR_PAD_LEFT) }}</span>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-[13px] text-ink">{{ $arco['nome'] }}</p>
                                    <p class="truncate text-[11px] uppercase tracking-[.06em] text-mute">{{ $arco['categoria'] ?? '—' }}</p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p class="valor text-[12.5px] font-medium text-ink">
                                        @if ($painel['moeda'])
                                            R$ {{ number_format($arco['valor'], 2, ',', '.') }}
                                        @else
                                            {{ (int) $arco['valor'] }}
                                        @endif
                                    </p>
                                    <p class="valor text-[11px] text-mute">{{ number_format($arco['participacao'], 1, ',', '.') }}%</p>
                                </div>
                            </li>
                        @empty
                            <li class="py-[34px] text-center text-[13px] text-mute">Nenhum sistema com clientes ainda.</li>
                        @endforelse
                    </ul>
                </div>
            @endforeach
        </x-painel-card>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <x-painel-card titulo="Clientes por revenda">
                <ul class="divide-y divide-line">
                    @forelse ($porRevenda as $nome => $qtd)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <span class="truncate text-[13px] text-ink">{{ $nome }}</span>
                            <span class="valor shrink-0 text-[12.5px] font-medium text-ink">{{ $qtd }}</span>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[13px] text-mute">Nenhum cliente cadastrado.</li>
                    @endforelse
                </ul>
            </x-painel-card>

            <x-painel-card titulo="Sistemas por categoria">
                <ul class="divide-y divide-line">
                    @forelse ($porCategoria as $categoria => $qtd)
                        <li class="flex items-center justify-between gap-3 py-2.5">
                            <span class="truncate text-[13px] text-ink">{{ $categoria ?: 'Sem categoria' }}</span>
                            <span class="valor shrink-0 text-[12.5px] font-medium text-ink">{{ $qtd }}</span>
                        </li>
                    @empty
                        <li class="py-[34px] text-center text-[13px] text-mute">Nenhum sistema cadastrado.</li>
                    @endforelse
                </ul>
            </x-painel-card>
        </div>
    </div>
</x-app-layout>
