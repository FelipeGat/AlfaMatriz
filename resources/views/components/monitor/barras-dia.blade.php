@props([
    'dias' => [],    // [['dia' => 'AAAA-MM-DD', 'liberados' => n, 'negados' => n], …]
    'quantos' => 30, // a janela inteira, com os dias sem dado no lugar deles
    'ate' => null,   // o último dia (Carbon): a data do retrato; sem ela, hoje
])

{{--
    Acessos por dia do cliente (#394): liberados e, empilhados em cima,
    negados.

    O monitor NÃO manda o dia sem acesso (combinado com ele). Aqui a janela é
    remontada com os 30 dias, e o dia vazio fica vazio no lugar dele —
    desenhar só os dias que vieram juntaria um feriado com o dia seguinte e
    o gráfico mentiria sobre o ritmo.

    Medidas do `x-bar-chart` (viewBox de 720, grade de quatro linhas, escala
    15% acima do maior) para os gráficos do sistema lerem como uma família.
--}}
@php
    $largura = 720;
    $altura = 220;
    $recuoLat = 8;
    $recuoTopo = 12;
    $recuoBase = 24;

    $areaL = $largura - 2 * $recuoLat;
    $areaA = $altura - $recuoTopo - $recuoBase;

    $porDia = collect($dias)->keyBy('dia');
    // Termina no dia do retrato, e não no último dia que veio: os dias finais
    // sem acesso também são dado.
    $fim = ($ate ?? now())->copy()->startOfDay();
    $janela = collect(range($quantos - 1, 0))->map(fn ($k) => $fim->copy()->subDays($k)->format('Y-m-d'));

    $maior = $janela->map(fn ($d) => (int) ($porDia[$d]['liberados'] ?? 0) + (int) ($porDia[$d]['negados'] ?? 0))->max();
    $escala = $maior > 0 ? $maior * 1.15 : 1;

    $passo = $areaL / max($janela->count(), 1);
    $larguraBarra = max(2, $passo * 0.62);
    $h = fn ($v) => ((int) $v / $escala) * $areaA;
    $num = fn ($v) => number_format((int) $v, 0, ',', '.');
@endphp

<div class="w-full min-w-0 overflow-x-auto">
    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" class="w-full h-auto min-w-[560px] max-w-[720px]"
         role="img" aria-label="Acessos por dia nos últimos {{ $quantos }} dias">
        @for ($i = 0; $i <= 3; $i++)
            @php $y = $recuoTopo + $areaA - ($areaA * $i / 3); @endphp
            <line x1="{{ $recuoLat }}" y1="{{ $y }}" x2="{{ $largura - $recuoLat }}" y2="{{ $y }}"
                  stroke="rgb(var(--ink-faint) / {{ $i === 0 ? 0.32 : 0.18 }})" stroke-width="1" />
        @endfor

        @foreach ($janela as $i => $dia)
            @php
                $lib = (int) ($porDia[$dia]['liberados'] ?? 0);
                $neg = (int) ($porDia[$dia]['negados'] ?? 0);
                $x = $recuoLat + $i * $passo + ($passo - $larguraBarra) / 2;
                $base = $recuoTopo + $areaA;
                $data = \Illuminate\Support\Carbon::parse($dia);
            @endphp
            <g>
                <title>{{ $data->format('d/m') }} · {{ $porDia->has($dia) ? $num($lib).' liberados, '.$num($neg).' negados' : 'sem dado' }}</title>
                @if ($lib > 0)
                    <rect x="{{ $x }}" y="{{ $base - $h($lib) }}" width="{{ $larguraBarra }}" height="{{ max($h($lib), 1) }}" fill="rgb(var(--good))" />
                @endif
                @if ($neg > 0)
                    <rect x="{{ $x }}" y="{{ $base - $h($lib) - $h($neg) }}" width="{{ $larguraBarra }}" height="{{ max($h($neg), 1) }}" fill="rgb(var(--crit))" />
                @endif
            </g>
            @if ($i % 5 === 0 || $i === $janela->count() - 1)
                <text x="{{ $x + $larguraBarra / 2 }}" y="{{ $altura - 6 }}" text-anchor="middle"
                      fill="rgb(var(--ink-mute))" font-size="11" font-family="Geist Mono, monospace">{{ $data->format('d/m') }}</text>
            @endif
        @endforeach
    </svg>

    <div class="flex items-center gap-4 mt-2 font-mono text-[10.5px] uppercase tracking-caps text-ink-faint">
        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-badge" style="background: rgb(var(--good))"></span> Liberados</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-badge" style="background: rgb(var(--crit))"></span> Negados</span>
    </div>
</div>
