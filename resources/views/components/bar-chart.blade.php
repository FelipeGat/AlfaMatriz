@props(['data'])

@php
    $width = 720;
    $height = 260;
    $paddingLeft = 8;
    $paddingRight = 8;
    $paddingTop = 24;
    $paddingBottom = 32;
    $plotWidth = $width - $paddingLeft - $paddingRight;
    $plotHeight = $height - $paddingTop - $paddingBottom;

    $max = collect($data)->flatMap(fn ($d) => [$d['entradas'], $d['saidas']])->max();
    $max = $max > 0 ? $max * 1.15 : 100;

    $groupWidth = $plotWidth / max(count($data), 1);
    $barWidth = min(28, $groupWidth * 0.32);
    $gap = 3;

    $scaleY = fn ($v) => $v <= 0 ? 0 : ($v / $max) * $plotHeight;

    $fmt = fn ($v) => 'R$ ' . number_format($v, 0, ',', '.');
@endphp

<div class="w-full min-w-0 overflow-x-auto">
    <svg viewBox="0 0 {{ $width }} {{ $height }}" class="w-full h-auto min-w-[560px]" role="img" aria-label="Entradas e saídas dos últimos 6 meses">
        {{-- grid lines --}}
        @for ($i = 0; $i <= 3; $i++)
            @php $y = $paddingTop + $plotHeight - ($plotHeight * $i / 3); @endphp
            <line x1="{{ $paddingLeft }}" y1="{{ $y }}" x2="{{ $width - $paddingRight }}" y2="{{ $y }}" stroke="currentColor" style="opacity:.5" class="text-line-soft" stroke-width="1" />
        @endfor

        {{-- baseline --}}
        <line x1="{{ $paddingLeft }}" y1="{{ $paddingTop + $plotHeight }}" x2="{{ $width - $paddingRight }}" y2="{{ $paddingTop + $plotHeight }}" stroke="currentColor" class="text-line" stroke-width="1" />

        @foreach ($data as $i => $d)
            @php
                $groupX = $paddingLeft + $i * $groupWidth;
                $centerX = $groupX + $groupWidth / 2;
                $xEntradas = $centerX - $barWidth - $gap / 2;
                $xSaidas = $centerX + $gap / 2;
                $hEntradas = $scaleY($d['entradas']);
                $hSaidas = $scaleY($d['saidas']);
                $yEntradas = $paddingTop + $plotHeight - $hEntradas;
                $ySaidas = $paddingTop + $plotHeight - $hSaidas;
            @endphp

            {{-- Entrada: série principal --}}
            <rect x="{{ $xEntradas }}" y="{{ $yEntradas }}" width="{{ $barWidth }}" height="{{ max($hEntradas, 1) }}"
                  rx="4" fill="var(--chart)">
                <title>{{ $d['label'] }} · Entradas: {{ $fmt($d['entradas']) }}</title>
            </rect>
            @if ($d['entradas'] > 0)
                <text x="{{ $xEntradas + $barWidth / 2 }}" y="{{ $yEntradas - 6 }}" text-anchor="middle" class="fill-mute" font-size="9">{{ number_format($d['entradas'] / 1000, 1, ',', '.') }}k</text>
            @endif

            {{-- Saída: série secundária, em superfície neutra --}}
            <rect x="{{ $xSaidas }}" y="{{ $ySaidas }}" width="{{ $barWidth }}" height="{{ max($hSaidas, 1) }}"
                  rx="4" fill="var(--track2)">
                <title>{{ $d['label'] }} · Saídas: {{ $fmt($d['saidas']) }}</title>
            </rect>
            @if ($d['saidas'] > 0)
                <text x="{{ $xSaidas + $barWidth / 2 }}" y="{{ $ySaidas - 6 }}" text-anchor="middle" class="fill-mute" font-size="9">{{ number_format($d['saidas'] / 1000, 1, ',', '.') }}k</text>
            @endif

            {{-- month label --}}
            <text x="{{ $centerX }}" y="{{ $height - 10 }}" text-anchor="middle"
                  class="{{ $i === count($data) - 1 ? 'fill-ink' : 'fill-mute' }}" font-size="11">{{ $d['label'] }}</text>
        @endforeach
    </svg>

    <div class="flex items-center gap-4 mt-2 text-xs text-dim">
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background: var(--chart)"></span> Entradas</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm" style="background: var(--track2)"></span> Saídas</span>
    </div>
</div>
