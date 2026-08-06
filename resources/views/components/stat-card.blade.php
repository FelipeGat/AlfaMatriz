@props([
    'label',
    'value',
    'icon' => 'trending-up',
    'accent' => 'brand',
    'delta' => null,
    'serie' => [],
])

@php
    // A API antiga fala em `accent` com nomes do tema anterior; o card novo
    // fala em token. Este mapa é a ponte, para as telas ainda não migradas
    // continuarem de pé com o visual novo.
    $token = match ($accent) {
        'good' => 'good',
        'warning' => 'warn',
        'critical' => 'crit',
        'amber' => 'amber',
        'ink' => 'ink-mute',
        default => 'accent',
    };
@endphp

<x-kpi-card :rotulo="$label" :valor="$value" :icone="$icon" :acento="$token"
            :delta="$delta" :serie="$serie" {{ $attributes }} />
