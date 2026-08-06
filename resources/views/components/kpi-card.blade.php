@props([
    'label',
    'value',
    'apoio' => null,      // texto de contexto abaixo do valor
    'variacao' => null,   // número: percentual de variação (positivo/negativo)
    'tom' => 'ink',       // ink | good | warn | bad — cor do valor
    'proporcao' => null,  // 0..100: desenha barra em vez de texto de apoio
])

@php
    $tons = [
        'ink' => 'text-ink',
        'good' => 'text-good',
        'warn' => 'text-warn',
        'bad' => 'text-bad',
    ];
@endphp

{{-- O valor usa `valor` (mono + nowrap) e tamanho fluido: em card estreito
     ele encolhe em vez de quebrar em duas linhas, que era o defeito mais
     recorrente do protótipo. --}}
<div class="rounded-card border border-line bg-panel px-6 py-[22px]">
    <p class="text-[10.5px] font-medium uppercase tracking-[.06em] text-mute">{{ $label }}</p>

    <p class="valor mt-2 font-medium tracking-[-.03em] text-[clamp(19px,2.1vw,26px)] leading-tight {{ $tons[$tom] ?? $tons['ink'] }}">
        {{ $value }}
    </p>

    @if (! is_null($proporcao))
        <div class="mt-3 h-1.5 w-full overflow-hidden rounded-[3px] bg-track">
            <div class="h-full rounded-[3px] bg-brand" style="width: {{ max(0, min(100, $proporcao)) }}%"></div>
        </div>
        @if ($apoio)
            <p class="mt-2 text-[11.5px] text-mute">{{ $apoio }}</p>
        @endif
    @elseif (! is_null($variacao) || $apoio)
        <p class="mt-2 flex items-center gap-1.5 text-[11.5px] text-mute">
            @if (! is_null($variacao))
                <span class="valor font-medium {{ $variacao >= 0 ? 'text-good' : 'text-bad' }}">
                    {{ $variacao >= 0 ? '+' : '' }}{{ number_format($variacao, 1, ',', '.') }}%
                </span>
            @endif
            @if ($apoio)
                <span>{{ $apoio }}</span>
            @endif
        </p>
    @endif
</div>
