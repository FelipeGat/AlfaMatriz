@props([
    'pontos' => [],       // série de valores, do mais antigo ao mais recente
    'cor' => 'accent',    // nome do token de cor (accent, good, crit, warn…)
    'titulo' => null,
])

@php
    $serie = array_values(array_map('floatval', $pontos));

    $largura = 88;
    $altura = 26;
    $margem = 2; // o traço tem 1,6px: sem margem ele encosta na borda do SVG

    // Série achatada (todos iguais, ou um ponto só) vira uma linha no meio —
    // normalizar por amplitude zero daria divisão por zero.
    $min = $serie ? min($serie) : 0;
    $max = $serie ? max($serie) : 0;
    $amplitude = $max - $min;

    $caminho = '';
    foreach ($serie as $i => $valor) {
        $x = count($serie) > 1
            ? $margem + ($i / (count($serie) - 1)) * ($largura - 2 * $margem)
            : $largura / 2;

        $normalizado = $amplitude > 0 ? ($valor - $min) / $amplitude : 0.5;
        $y = $altura - $margem - $normalizado * ($altura - 2 * $margem);

        $caminho .= ($i === 0 ? 'M' : 'L').round($x, 2).' '.round($y, 2).' ';
    }
@endphp

@if (count($serie) > 1)
    <svg viewBox="0 0 {{ $largura }} {{ $altura }}" width="{{ $largura }}" height="{{ $altura }}"
         fill="none" class="shrink-0 overflow-visible"
         role="img" aria-label="{{ $titulo ?? 'Tendência dos últimos '.count($serie).' períodos' }}"
         {{ $attributes }}>
        <path d="{{ trim($caminho) }}"
              stroke="rgb(var(--{{ $cor }}))" stroke-width="1.6"
              stroke-linecap="round" stroke-linejoin="round" />
    </svg>
@endif
