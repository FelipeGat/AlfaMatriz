@props([
    'rotulo',
    'valor',
    'delta' => null,          // variação, já formatada ("+7,2% vs jul")
    'sinal' => 'neutro',      // bom | ruim | neutro — colore o delta
    'acento' => 'accent',     // token que pinta o fio de luz e a sparkline
    'icone' => null,          // tile de ícone no canto superior direito
    'serie' => [],            // série da sparkline
])

@php
    $corDoDelta = match ($sinal) {
        'bom' => 'text-good',
        'ruim' => 'text-crit',
        default => 'text-ink-faint',
    };
@endphp

{{--
    Card de destaque (KPI).

    O fio de luz de 1px no topo é o que separa o card do fundo sem precisar de
    sombra — ele nasce e morre transparente, recuado 16px de cada lado, para
    não virar uma borda.

    O valor é Space Grotesk com números tabulares: sem `tabular-nums`, cards
    lado a lado dançam de largura a cada atualização.
--}}
<div {{ $attributes->merge(['class' => 'relative overflow-hidden rounded-panel border border-line bg-card-grad px-4 pt-[17px] pb-[15px]']) }}>
    <div class="absolute top-0 left-4 right-4 h-px pointer-events-none"
         style="background: linear-gradient(90deg, transparent, rgb(var(--{{ $acento }})), transparent)"></div>

    <div class="flex items-start justify-between gap-3">
        <p class="text-[11px] uppercase tracking-[0.10em] text-ink-mute">{{ $rotulo }}</p>

        @if ($icone)
            <span class="h-[26px] w-[26px] shrink-0 rounded-tile flex items-center justify-center"
                  style="background: rgb(var(--{{ $acento }}) / var(--tint-alpha)); color: rgb(var(--{{ $acento }}))">
                <span class="h-[14px] w-[14px]"><x-nav-icon :name="$icone" /></span>
            </span>
        @endif
    </div>

    <p class="mt-2 font-display text-[27px] font-semibold leading-none tracking-[-0.02em] text-ink tabular whitespace-nowrap">{{ $valor }}</p>

    @if ($delta !== null || count($serie) > 1)
        <div class="mt-3 flex items-end justify-between gap-3">
            <p class="font-sans tabular text-[11px] {{ $corDoDelta }} truncate">{{ $delta }}</p>
            <x-sparkline :pontos="$serie" :cor="$acento" />
        </div>
    @endif
</div>
