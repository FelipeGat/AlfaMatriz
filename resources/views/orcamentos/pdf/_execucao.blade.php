{{-- Etapas depois da aprovação, observações e aceite: no fim do Investimento quando cabem, ou numa
     página própria (pdf.blade.php decide). Usa as variáveis de quem inclui. --}}
@if ($etapas)
    <div class="bloco">
        <p class="rotulo">{{ $o->temSecao('execucao') ? ($o->secao('execucao', 'titulo') ?? 'Etapas após a aprovação') : 'Etapas após a aprovação' }}</p>
        <div class="etapas">@foreach ($etapas as $n => $l)<div class="etapa"><i>{{ $dois($n + 1) }}</i><span>{{ $negrito($l) }}</span></div>@endforeach</div>
        @if ($prazo)<p style="margin-top: 3mm"><b>Prazo.</b> {{ $prazo }}</p>@endif
    </div>
@elseif ($prazo)
    <div class="bloco"><p class="rotulo">Prazo</p><p>{{ $prazo }}</p></div>
@endif
@if ($o->observacoes)
    <div class="chamada"><p class="rotulo">Observações</p><p class="texto">{{ $o->observacoes }}</p></div>
@endif
@if ($o->aceite)
    <div class="bloco" style="break-inside: avoid">
        <p class="rotulo">Aceite</p>
        <p class="intro">De acordo com o escopo, os valores e as condições de pagamento descritos nesta proposta.</p>
        <p>Local e data: ______________________________, ____ / ____ / ________</p>
        <div class="assinaturas">
            <div><b>Contratante</b><br>{{ \Illuminate\Support\Str::before($cliente, ' · ') }} · representante autorizado<br>CNPJ: ______________________</div>
            <div><b>VixSoftHouse</b><br>{{ $o->autor?->name ?? 'Responsável comercial' }}</div>
        </div>
    </div>
@endif
@if ($ajustes->linhaDoEmissor())
    <div class="contato"><b>{{ $ajustes->get('emissor_nome', 'VixSoftHouse') }}</b> · {{ $ajustes->linhaDoEmissor() }}</div>
@endif
