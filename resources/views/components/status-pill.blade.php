@props([
    'tom' => 'neutro',   // good | bad | warn | brand | neutro
])

@php
    // Pílulas do handoff: Ativo/Baixada em good, Vencida em bad,
    // Aberta/Inativo em superfície neutra.
    $tons = [
        'good' => 'bg-good/12 text-good',
        'bad' => 'bg-bad/12 text-bad',
        'warn' => 'bg-warn/12 text-warn',
        'brand' => 'bg-raised text-ink',
        'neutro' => 'bg-raised text-dim',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center whitespace-nowrap rounded-pill px-1.5 py-[2px] font-mono text-[10px] font-medium uppercase tracking-[.04em] '.($tons[$tom] ?? $tons['neutro'])]) }}>
    {{ $slot }}
</span>
