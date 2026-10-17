@props([
    'rotulo',
    'pct' => null,       // 0–100
    'detalhe' => null,   // "2,6 GB de 7,8 GB", "média 24h 11,8% · pico 1h 37,1%"
])

{{--
    Medidor de recurso do servidor (CPU, memória, disco, banda).

    O tom é só o da BARRA: até 70% bom, até 90% atenção, acima disso
    crítico. Quem diz se o servidor está mal é o `sev` que o monitor manda, e
    é ele que vai no selo do cartão — a barra não julga o servidor, só mostra
    quanto está cheio.
--}}
@php
    $valor = is_numeric($pct) ? max(0, min(100, (float) $pct)) : null;
    $token = match (true) {
        $valor === null => 'ink-faint',
        $valor >= 90 => 'crit',
        $valor >= 70 => 'warn',
        default => 'good',
    };
@endphp

<div {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <div class="flex items-baseline justify-between gap-2">
        <span class="font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">{{ $rotulo }}</span>
        <span class="font-sans tabular text-[13px] font-semibold text-ink">{{ \App\Services\Monitor\FormatoDoMonitor::pct($pct) }}</span>
    </div>
    <div class="mt-1 h-[6px] w-full overflow-hidden rounded-badge" style="background: rgb(var(--ink-faint) / 0.18)">
        <div class="h-full rounded-badge" style="width: {{ $valor ?? 0 }}%; background: rgb(var(--{{ $token }}))"></div>
    </div>
    @if ($detalhe)
        <p class="mt-1 truncate text-[11px] text-ink-faint" title="{{ $detalhe }}">{{ $detalhe }}</p>
    @endif
</div>
