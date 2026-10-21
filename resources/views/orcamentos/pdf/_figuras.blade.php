@php
    /**
     * As imagens de um lugar da proposta, em linhas: a deitada ocupa a largura da folha; as em pé
     * (tela de celular, folheto) vão lado a lado, até três. A altura máxima vem de quem inclui,
     * que sabe quanto texto divide a página com elas.
     *
     * @var list<array{src: string, legenda: string, retrato: bool}> $imgs
     * @var float $altura em mm, por linha
     */
    $linhas = [];
    foreach ($imgs as $img) {
        $ultima = count($linhas) - 1;
        if ($img['retrato'] && $ultima >= 0 && $linhas[$ultima][0]['retrato'] && count($linhas[$ultima]) < 3) {
            $linhas[$ultima][] = $img;
        } else {
            $linhas[] = [$img];
        }
    }
@endphp
@foreach ($linhas as $linha)
    <div class="figuras">
        @foreach ($linha as $img)
            <figure>
                <img src="{{ $img['src'] }}" alt="" style="max-height: {{ $altura }}mm">
                @if ($img['legenda'] !== '')<figcaption>{{ $img['legenda'] }}</figcaption>@endif
            </figure>
        @endforeach
    </div>
@endforeach
