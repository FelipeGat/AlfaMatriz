@props([
    'label',
    'value',
    'contexto' => null,   // linha de apoio: "de 12 na base"
    'tom' => 'ink',       // ink | good | warn | bad
])

@php
    $tons = [
        'ink' => 'text-ink',
        'good' => 'text-good',
        'warn' => 'text-warn',
        'bad' => 'text-bad',
    ];
@endphp

{{-- Card de resumo das telas de lista: menor que o KPI dos painéis, mesma
     regra para o valor (mono, sem quebra, tamanho fluido). --}}
<div class="rounded-summary border border-line bg-panel px-[18px] py-4">
    <p class="font-mono text-[10px] font-medium uppercase tracking-[.08em] text-mute">{{ $label }}</p>

    <p class="valor mt-1.5 font-medium tracking-[-.03em] text-[clamp(17px,1.6vw,21px)] leading-tight {{ $tons[$tom] ?? $tons['ink'] }}">
        {{ $value }}
    </p>

    @if ($contexto)
        <p class="mt-1 text-[11.5px] text-mute">{{ $contexto }}</p>
    @endif
</div>
