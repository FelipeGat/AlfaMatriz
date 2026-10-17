@props([
    'pontos' => [],      // [['t' => ISO, 'cpu' => n, 'mem' => n], …], do mais antigo ao mais novo
    'series' => [],      // [['chave' => 'cpu', 'rotulo' => 'CPU', 'cor' => 'accent'], …]
    'teto' => 100,       // em %: a escala é fixa, para 30% parecer 30% e não o pico
])

{{--
    Gráfico de linha do servidor (#394): CPU e memória da última hora.

    Escala FIXA de 0 a 100%: escala que se ajusta ao maior valor faz um
    servidor ocioso parecer no limite — o pico de 8% encostaria no teto.

    Mesmas medidas do `x-bar-chart` (viewBox de 720 de largura, grade de
    quatro linhas, rótulo em Geist Mono 10/11) para os gráficos do sistema
    lerem como uma família. O teto de 720px é o próprio viewBox: deixar o SVG
    esticar multiplicaria a fonte junto.
--}}
@php
    $largura = 720;
    $altura = 200;
    $recuoEsq = 36;      // os rótulos "100%" do eixo
    $recuoDir = 8;
    $recuoTopo = 10;
    $recuoBase = 24;     // a hora

    $areaL = $largura - $recuoEsq - $recuoDir;
    $areaA = $altura - $recuoTopo - $recuoBase;

    $pontos = array_values($pontos);
    $n = count($pontos);

    $x = fn (int $i) => $recuoEsq + ($n > 1 ? $areaL * $i / ($n - 1) : $areaL / 2);
    $y = fn ($v) => $recuoTopo + $areaA - (min(max((float) $v, 0), $teto) / $teto) * $areaA;

    $hora = fn (int $i) => \App\Services\Monitor\FormatoDoMonitor::hora($pontos[$i]['t'] ?? null)?->format('H:i');

    // Cinco marcas de hora no eixo: o começo, o fim e três no meio.
    $marcas = $n > 1 ? array_unique(array_map(fn ($k) => (int) round(($n - 1) * $k / 4), [0, 1, 2, 3, 4])) : [];
@endphp

<div class="w-full min-w-0 overflow-x-auto">
    @if ($n === 0)
        <p class="py-6 text-center text-[12.5px] text-ink-mute">Sem amostras na última hora.</p>
    @else
        <svg viewBox="0 0 {{ $largura }} {{ $altura }}" class="w-full h-auto min-w-[560px] max-w-[720px]"
             role="img" aria-label="Uso na última hora">
            @for ($i = 0; $i <= 4; $i++)
                @php $yy = $recuoTopo + $areaA - ($areaA * $i / 4); @endphp
                <line x1="{{ $recuoEsq }}" y1="{{ $yy }}" x2="{{ $largura - $recuoDir }}" y2="{{ $yy }}"
                      stroke="rgb(var(--ink-faint) / {{ $i === 0 ? 0.32 : 0.18 }})" stroke-width="1" />
                <text x="{{ $recuoEsq - 6 }}" y="{{ $yy + 3 }}" text-anchor="end"
                      fill="rgb(var(--ink-faint))" font-size="10" font-family="Geist Mono, monospace">{{ (int) ($teto * $i / 4) }}%</text>
            @endfor

            @foreach ($series as $serie)
                @php
                    $caminho = collect($pontos)
                        ->map(fn ($p, $i) => (is_numeric($p[$serie['chave']] ?? null) ? round($x($i), 1).','.round($y($p[$serie['chave']]), 1) : null))
                        ->filter()
                        ->implode(' ');
                @endphp
                <polyline points="{{ $caminho }}" fill="none" stroke="rgb(var(--{{ $serie['cor'] }}))"
                          stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round" />
            @endforeach

            @foreach ($marcas as $i)
                <text x="{{ $x($i) }}" y="{{ $altura - 6 }}" text-anchor="{{ $i === 0 ? 'start' : ($i === $n - 1 ? 'end' : 'middle') }}"
                      fill="rgb(var(--ink-mute))" font-size="11" font-family="Geist Mono, monospace">{{ $hora($i) }}</text>
            @endforeach
        </svg>

        <div class="flex items-center gap-4 mt-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
            @foreach ($series as $serie)
                <span class="inline-flex items-center gap-1.5">
                    <span class="h-2 w-2 rounded-badge" style="background: rgb(var(--{{ $serie['cor'] }}))"></span> {{ $serie['rotulo'] }}
                </span>
            @endforeach
        </div>
    @endif
</div>
