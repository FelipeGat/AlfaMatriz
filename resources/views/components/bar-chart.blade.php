@props([
    'data',
    'legenda' => true,   // fora quando a legenda vive na faixa de cabeçalho
])

@php
    $largura = 720;
    $altura = 260;
    $recuoLateral = 8;
    $recuoTopo = 24;     // espaço para o rótulo do valor acima da barra mais alta
    $recuoBase = 32;     // espaço para o nome do mês

    $areaLargura = $largura - 2 * $recuoLateral;
    $areaAltura = $altura - $recuoTopo - $recuoBase;

    $series = collect($data);

    // A escala sobe 15% acima do maior valor: encostar o topo da barra no
    // teto do gráfico faz o maior mês parecer um limite, não um dado.
    $maior = $series->flatMap(fn ($d) => [$d['entradas'], $d['saidas']])->max();
    $escala = $maior > 0 ? $maior * 1.15 : 100;

    $larguraGrupo = $areaLargura / max($series->count(), 1);
    $larguraBarra = min(28, $larguraGrupo * 0.32);
    $vao = 4;

    $paraAltura = fn ($valor) => $valor <= 0 ? 0 : ($valor / $escala) * $areaAltura;
    $emReais = fn ($valor) => 'R$ '.number_format($valor, 0, ',', '.');
    $emMilhares = fn ($valor) => number_format($valor / 1000, 1, ',', '.').'k';
@endphp

<div class="w-full min-w-0 overflow-x-auto">
    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" class="w-full h-auto min-w-[560px]"
         role="img" aria-label="Entradas e saídas dos últimos {{ $series->count() }} meses">
        {{-- Quatro linhas de grade: o suficiente para estimar altura sem virar papel milimetrado. --}}
        @for ($i = 0; $i <= 3; $i++)
            @php $y = $recuoTopo + $areaAltura - ($areaAltura * $i / 3); @endphp
            <line x1="{{ $recuoLateral }}" y1="{{ $y }}" x2="{{ $largura - $recuoLateral }}" y2="{{ $y }}"
                  stroke="rgb(var(--ink-faint) / 0.18)" stroke-width="1" />
        @endfor

        <line x1="{{ $recuoLateral }}" y1="{{ $recuoTopo + $areaAltura }}"
              x2="{{ $largura - $recuoLateral }}" y2="{{ $recuoTopo + $areaAltura }}"
              stroke="rgb(var(--ink-faint) / 0.32)" stroke-width="1" />

        @foreach ($series as $i => $mes)
            @php
                $centro = $recuoLateral + $i * $larguraGrupo + $larguraGrupo / 2;
                $xEntradas = $centro - $larguraBarra - $vao / 2;
                $xSaidas = $centro + $vao / 2;
                $hEntradas = $paraAltura($mes['entradas']);
                $hSaidas = $paraAltura($mes['saidas']);
                $yEntradas = $recuoTopo + $areaAltura - $hEntradas;
                $ySaidas = $recuoTopo + $areaAltura - $hSaidas;
            @endphp

            <rect x="{{ $xEntradas }}" y="{{ $yEntradas }}" width="{{ $larguraBarra }}"
                  height="{{ max($hEntradas, 1) }}" fill="rgb(var(--brand))">
                <title>{{ $mes['label'] }} · Entradas: {{ $emReais($mes['entradas']) }}</title>
            </rect>
            @if ($mes['entradas'] > 0)
                <text x="{{ $xEntradas + $larguraBarra / 2 }}" y="{{ $yEntradas - 6 }}" text-anchor="middle"
                      fill="rgb(var(--ink-dim))" font-size="10" font-family="Geist Mono, monospace">{{ $emMilhares($mes['entradas']) }}</text>
            @endif

            <rect x="{{ $xSaidas }}" y="{{ $ySaidas }}" width="{{ $larguraBarra }}"
                  height="{{ max($hSaidas, 1) }}" fill="rgb(var(--chart-out))">
                <title>{{ $mes['label'] }} · Saídas: {{ $emReais($mes['saidas']) }}</title>
            </rect>
            @if ($mes['saidas'] > 0)
                <text x="{{ $xSaidas + $larguraBarra / 2 }}" y="{{ $ySaidas - 6 }}" text-anchor="middle"
                      fill="rgb(var(--ink-dim))" font-size="10" font-family="Geist Mono, monospace">{{ $emMilhares($mes['saidas']) }}</text>
            @endif

            <text x="{{ $centro }}" y="{{ $altura - 10 }}" text-anchor="middle"
                  fill="rgb(var(--ink-mute))" font-size="11" font-family="Geist Mono, monospace">{{ $mes['label'] }}</text>
        @endforeach
    </svg>

    @if ($legenda)
        <div class="flex items-center gap-4 mt-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-badge" style="background: rgb(var(--brand))"></span> Entradas
            </span>
            <span class="inline-flex items-center gap-1.5">
                <span class="h-2 w-2 rounded-badge" style="background: rgb(var(--chart-out))"></span> Saídas
            </span>
        </div>
    @endif
</div>
