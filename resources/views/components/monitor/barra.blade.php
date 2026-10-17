@props([
    'barra' => null,     // {balde_min, pontos: [{inicio, estado}]} da API do monitor
    'altura' => 16,
])

{{--
    A barra de histórico do AlfaMonitor: um traço por checagem, do mais
    antigo (esquerda) ao mais recente, como na tela dele.

    O monitor manda os baldes já prontos de propósito (pedido do Matriz): a
    barra daqui desenha igual à de lá, sem refazer a conta. `parcial` é "no
    ar, mas lento" ou queda descontada por ter caído a rede inteira — por isso
    âmbar, e não vermelho. `sem_dado` fica na superfície de chip: lacuna não é
    queda.

    O título de cada traço é a hora e o estado: passar o mouse responde
    "quando foi aquilo vermelho" sem precisar de legenda.
--}}
@php
    $pontos = collect($barra['pontos'] ?? []);

    $cor = fn (?string $estado) => match ($estado) {
        'up' => 'rgb(var(--good))',
        'parcial' => 'rgb(var(--warn))',
        'down' => 'rgb(var(--crit))',
        default => 'rgb(var(--ink-faint) / 0.25)',
    };

    $rotulo = fn (?string $estado) => match ($estado) {
        'up' => 'no ar',
        'parcial' => 'lento ou descontado',
        'down' => 'fora',
        default => 'sem dado',
    };
@endphp

@if ($pontos->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'flex w-full gap-px overflow-hidden rounded-badge']) }}
         style="height: {{ $altura }}px" role="img"
         aria-label="Histórico: {{ $pontos->where('estado', 'down')->count() }} checagens fora de {{ $pontos->count() }}">
        @foreach ($pontos as $ponto)
            @php $hora = \App\Services\Monitor\FormatoDoMonitor::hora($ponto['inicio'] ?? null); @endphp
            <span class="flex-1 min-w-[2px]" style="background: {{ $cor($ponto['estado'] ?? null) }}"
                  title="{{ $hora?->format('d/m H:i') }} · {{ $rotulo($ponto['estado'] ?? null) }}"></span>
        @endforeach
    </div>
@else
    <span class="font-mono text-[11px] text-ink-faint">sem histórico</span>
@endif
