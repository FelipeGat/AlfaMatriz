@props([
    'tom' => 'neutro',   // bom | ambar | atencao | critico | marca | neutro
    'ponto' => false,    // ponto de status antes do texto
])

@php
    // Cada tom é um token; o fundo é o mesmo token com a alpha de tingimento
    // do tema (0,13 no escuro, 0,10 no claro) — por isso ele vem de
    // `var(--tint-alpha)` e não de um número fixo aqui.
    // `ambar` e `atencao` são dois âmbares de propósito, e o sistema já os
    // trazia separados: a escala de prioridade precisa de cinco degraus
    // distinguíveis entre si (AC-126), e o alerta de tarefa parada não pode
    // dividir tom com o grau "Alta", que é gravidade e não alarme.
    $token = match ($tom) {
        'bom' => 'good',
        'ambar' => 'amber',
        'atencao' => 'warn',
        'critico' => 'crit',
        'marca' => 'brand-text',
        default => null,
    };

    $base = 'inline-flex items-center gap-1.5 rounded-badge px-1.5 py-[3px] '
        .'font-mono text-[10px] font-semibold uppercase tracking-[0.08em] whitespace-nowrap';

    // O tom neutro é o único que não tinge: ele usa a superfície de chip.
    $classes = $token ? $base : $base.' bg-chip text-ink-dim';

    $estilo = $token
        ? 'background: rgb(var(--'.$token.') / var(--tint-alpha)); color: rgb(var(--'.$token.'))'
        : null;
@endphp

<span {{ $attributes->merge(array_filter(['class' => $classes, 'style' => $estilo])) }}>
    @if ($ponto)
        <span class="h-[5px] w-[5px] rounded-full shrink-0"
              style="background: {{ $token ? 'rgb(var(--'.$token.'))' : 'rgb(var(--ink-mute))' }}"></span>
    @endif

    {{ $slot }}
</span>
