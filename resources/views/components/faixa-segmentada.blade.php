@props([
    'segmentos' => [],    // [['rotulo' => 'AlfaGym', 'valor' => 14940], …]
    'cor' => 'brand',     // token que tinge a faixa inteira
    'altura' => 8,
])

@php
    $itens = array_values($segmentos);
    $total = array_sum(array_map(fn ($s) => (float) ($s['valor'] ?? 0), $itens));

    // Alpha decrescente: a ordem de leitura vira gradação de peso, sem
    // precisar de seis cores diferentes brigando entre si.
    $alphas = [0.9, 0.72, 0.58, 0.46, 0.36, 0.28];
@endphp

@if ($total > 0)
    <div {{ $attributes->merge(['class' => 'flex w-full gap-[2px] overflow-hidden rounded-badge']) }}
         style="height: {{ $altura }}px">
        @foreach ($itens as $i => $segmento)
            @php
                $valor = (float) ($segmento['valor'] ?? 0);
                $share = $valor / $total;
                $alpha = $alphas[$i] ?? end($alphas);
            @endphp

            {{-- `min-width` de 3px para que um segmento pequeno ainda exista:
                 sem isso ele some e a faixa mente sobre quantos itens há. --}}
            <span class="block h-full shrink-0"
                  style="width: {{ round($share * 100, 3) }}%; min-width: 3px;
                         background: rgb(var(--{{ $cor }}) / {{ $alpha }})"
                  title="{{ $segmento['rotulo'] ?? '' }}{{ isset($segmento['texto']) ? ' · '.$segmento['texto'] : '' }} · {{ number_format($share * 100, 1, ',', '.') }}%"></span>
        @endforeach
    </div>
@endif
