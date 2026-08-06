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
        'brand' => 'bg-brand-soft text-brand',
        'neutro' => 'bg-raised text-dim',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center whitespace-nowrap rounded-pill px-2.5 py-[3px] text-[11px] font-medium '.($tons[$tom] ?? $tons['neutro'])]) }}>
    {{ $slot }}
</span>
