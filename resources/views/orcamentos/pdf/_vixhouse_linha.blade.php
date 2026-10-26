{{-- Uma linha de imagens do modelo VixHouse: a deitada ocupa a largura; em pé, até três lado a lado.
     A legenda "Título: descrição" sai com o título em negrito; nas telas, numerada. Usa as variáveis
     de quem inclui ($partes, $dois). --}}
@php $largura = (int) floor((714 - 18 * (count($linha) - 1)) / count($linha)); @endphp
<div class="linha">
    @foreach ($linha as $j => $img)
        @php [$t, $d] = $partes($img['legenda']); @endphp
        <figure class="fig" style="margin: 0; max-width: {{ $largura }}px">
            <div class="vp"><img src="{{ $img['src'] }}" alt="" style="max-width: {{ $largura }}px; max-height: {{ $altura }}px"></div>
            @if ($img['legenda'] !== '' || $numerar)
                <figcaption class="cap">@if ($numerar)<span class="n">{{ $dois(($inicio ?? 0) + $j + 1) }}</span>@endif<div>@if ($d !== '')<b>{{ $t }}.</b> {{ $d }}@else{{ $t }}@endif</div></figcaption>
            @endif
        </figure>
    @endforeach
</div>
