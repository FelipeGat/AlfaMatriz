@props([
    'ranking',              // saída de PainelController::ranking()
    'titulo',
    'nota' => null,
    'rotuloTotal' => 'Total',
    'formato' => 'numero',  // numero | reais
    'compacto' => false,    // sem o bloco de topo (total + líder + faixa)
])

@php
    $formatar = fn (float $v) => $formato === 'reais'
        ? 'R$ '.number_format($v, 2, ',', '.')
        : number_format($v, 0, ',', '.');

    $cor = $ranking['cor'];
@endphp

{{--
    Ranking em três camadas — a gramática que se repete no painel Comercial.

    1. faixa de cabeçalho
    2. bloco de topo: o total, o líder e a faixa segmentada
    3. linhas: posição, nome, barra relativa ao líder, valor e participação

    A barra da linha é proporcional AO LÍDER (não ao total): é o que responde
    "quão longe o segundo está do primeiro". A faixa segmentada, essa sim, é
    proporcional ao total — as duas perguntas convivem sem se confundir.
--}}
<section {{ $attributes->merge(['class' => 'rounded-panel border border-line bg-subtle overflow-hidden']) }}>
    <div class="h-[38px] flex items-center gap-3 px-4 bg-head border-b border-line">
        <h2 class="font-display text-[15px] font-semibold text-ink truncate">{{ $titulo }}</h2>
        @if ($nota)
            <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint truncate">{{ $nota }}</span>
        @endif
    </div>

    @if (empty($ranking['itens']))
        <p class="px-4 py-6 text-[13px] text-ink-mute">Nada para ranquear ainda.</p>
    @else
        @unless ($compacto)
            <div class="px-4 py-3.5 bg-card-grad border-b border-line">
                <div class="flex items-end justify-between gap-4">
                    <div class="min-w-0">
                        <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">{{ $rotuloTotal }}</p>
                        <p class="mt-1 font-display text-[26px] font-semibold leading-none text-ink tabular whitespace-nowrap">
                            {{ $formatar($ranking['total']) }}
                        </p>
                    </div>
                    @if ($ranking['lider'])
                        <div class="min-w-0 text-right">
                            <p class="text-[13px] text-ink truncate">{{ $ranking['lider']['nome'] }}</p>
                            <p class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
                                líder · {{ number_format($ranking['lider']['share'] * 100, 0) }}%
                            </p>
                        </div>
                    @endif
                </div>

                <x-faixa-segmentada class="mt-3" :cor="$cor" :segmentos="collect($ranking['itens'])
                    ->map(fn ($i) => ['rotulo' => $i['nome'], 'texto' => $formatar($i['valor']), 'valor' => $i['valor']])
                    ->all()" />
            </div>
        @endunless

        <div class="px-4 py-2">
            @foreach ($ranking['itens'] as $item)
                <div class="flex items-center gap-3 h-10">
                    <span class="shrink-0 font-mono text-[11px] text-ink-faint">{{ $item['posicao'] }}</span>

                    <span class="min-w-0 truncate text-[13px] {{ $loop->first ? 'font-semibold text-ink' : 'text-ink-dim' }}"
                          style="flex: 0 0 30%">{{ $item['nome'] }}</span>

                    <span class="relative flex-1 min-w-0 h-2.5 rounded-badge bg-bar-track overflow-hidden">
                        <span class="absolute inset-y-0 left-0 rounded-badge"
                              data-barra="{{ $item['nome'] }}"
                              style="width: {{ round($item['largura'] * 100, 3) }}%; background: rgb(var(--{{ $cor }}) / 0.5)"></span>
                        {{-- Cap sólido na ponta: sem ele barras longas parecem
                             terminar num degradê e a comparação fica frouxa. --}}
                        <span class="absolute inset-y-0 w-[2px]"
                              style="left: calc({{ round($item['largura'] * 100, 3) }}% - 2px); background: rgb(var(--{{ $cor }}))"></span>
                    </span>

                    <span class="shrink-0 text-right font-mono text-[12.5px] text-ink whitespace-nowrap" style="width: 80px">
                        {{ $formatar($item['valor']) }}
                    </span>
                    <span class="shrink-0 text-right font-mono text-[11px] text-ink-faint whitespace-nowrap" style="width: 38px">
                        {{ number_format($item['share'] * 100, 0) }}%
                    </span>
                </div>
            @endforeach
        </div>
    @endif
</section>
