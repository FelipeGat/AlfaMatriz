{{-- Próximos passos, prazo, observações e o parecer do cliente no modelo VixHouse: no fim do Investimento
     quando cabem, ou numa página própria (vixhouse.blade.php decide). A VixHouse assina só como Diretor
     Comercial, sem nome de pessoa (regra das propostas VixCard). Usa as variáveis de quem inclui. --}}
@if ($etapas)
    <div class="passos">@foreach ($etapas as $k => $l)@php [$t, $d] = $partes($semNumero($l)); @endphp<div class="p"><div class="k">{{ $dois($k + 1) }}</div><b>{{ $t }}</b>@if ($d !== '')<span>{{ $d }}</span>@endif</div>@endforeach</div>
@endif
@if ($prazo)<p class="prazo"><b>Prazo.</b> {{ $prazo }}</p>@endif
@if ($o->observacoes)<div class="aviso">{{ $o->observacoes }}</div>@endif
@if ($o->aceite)
    @php $quemAssina = $informado(data_get($dc, 'razao_social')) ? data_get($dc, 'razao_social') : $clienteCurto; @endphp
    <div class="parecer">
        <h4>Parecer do cliente sobre a proposta</h4>
        <div class="op"><span>Aprovo como está</span><span>Aprovo com os ajustes abaixo</span><span>Prefiro conversar antes</span></div>
        @if ($planos)<div class="op"><em>Plano pretendido:</em>@foreach ($planos as $p)<span>{{ $p['nome'] }}</span>@endforeach</div>@endif
        <div style="font-size: 11px; color: var(--cinza-forte); margin-bottom: 2px">Ajustes e observações:</div>
        @for ($l = 0; $l < (count($etapas) > 4 ? 2 : 3); $l++)<div class="ln"></div>@endfor
        <div class="ass">
            <div><b>{{ $quemAssina }}</b>{{ $informado($o->contato) ? $o->contato : 'Representante autorizado' }}</div>
            <div><b>VixHouse</b>Diretor Comercial</div>
            <div><b>Local e data</b>______________ , ___ / ___ / {{ $data->format('Y') }}</div>
        </div>
    </div>
@endif
<div class="contato"><img src="{{ $marca['escura'] }}" alt="VixHouse"><div><b>VixHouse · Soluções em tecnologia</b>@if ($ajustes->linhaDoEmissor())<br>{{ $ajustes->linhaDoEmissor() }}@endif</div></div>
