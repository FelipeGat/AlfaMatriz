@php
    /** @var \App\Models\Orcamento $o */
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $unicos = $o->itens->where('cobranca', 'unico');
    $mensais = $o->itens->where('cobranca', 'mensal');
    $opcoes = $o->itens->where('cobranca', 'opcao');
    $numero = $o->numero ? 'Orçamento '.$o->codigo() : 'Rascunho';
    $cliente = $o->cliente ?: 'Cliente';
    $produto = \App\Services\Orcamentos\Modelos::nome((string) $o->produto);
    $data = $o->data()->locale('pt_BR');
    // Etapa 2 (padrao-e-historico.md): tipo da proposta, 3ª coluna livre da capa e as seções opcionais.
    $tipo = $o->tipo ?: 'Proposta comercial';
    $dadosCliente = collect(\App\Models\Orcamento::DADOS_CLIENTE)->map(fn ($rotulo, $k) => [$rotulo, trim((string) data_get($o->dados_cliente, $k))])->filter(fn ($p) => $p[1] !== '');
    $paginaCliente = $o->temDadosDoCliente() || $o->temSecao('desafio') || $o->temSecao('solucao');
    $paginaDetalhes = $o->temSecao('especificacao') || $o->temSecao('decisoes') || $o->temSecao('extras');
    $cab = fn () => '<div class="cab"><b>'.e($tipo).'</b><span>'.e($cliente).' · <span class="marca">Vix<b>SoftHouse</b></span></span></div>';
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Proposta {{ $numero }} — {{ $cliente }}</title>
{{-- O PDF padrão (docs/specs/orcamentos-vixcard). Um modelo só para todo orçamento:
     muda o conteúdo, nunca o layout. Toda proposta sai como VixSoftHouse, de qualquer
     sistema (Felipe, 19/10/2026), no desenho da proposta do Gestor/Gustavo. --}}
<style>
@font-face { font-family: 'Open Sans'; font-style: normal; font-weight: 300 700; src: url('{{ $fontes }}/open-sans-latin.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
@font-face { font-family: 'Open Sans'; font-style: normal; font-weight: 300 700; src: url('{{ $fontes }}/open-sans-latin-ext.woff2') format('woff2');
  unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }
@page { size: A4; margin: 0 }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact }
html, body { margin: 0; padding: 0 }
body { font-family: 'Open Sans', 'Segoe UI', 'DejaVu Sans', sans-serif; color: #0e1d33; font-size: 10.5pt; line-height: 1.5; background: {{ $previa ? '#d9e0e7' : '#fff' }} }
.pagina { width: 210mm; min-height: 297mm; padding: 18mm 18mm 14mm; display: flex; flex-direction: column; background: #fff; page-break-after: always; break-after: page; position: relative }
.pagina:last-child { page-break-after: auto; break-after: auto }
@if ($previa) .pagina { margin: 0 auto 8mm; box-shadow: 0 2px 10px rgba(10,30,60,.18) } body { padding: 8mm 0 } @endif
/* Capa */
.capa { background: radial-gradient(120% 80% at 20% 62%, #123a7a, #0b2655 70%); color: #fff; padding: 22mm 20mm 18mm }
.marca { font-size: 19pt; font-weight: 300; letter-spacing: .02em; color: #fff }
.marca b { font-weight: 700; color: #8fc0f5 }
.cab .marca { font-size: 8pt; color: #0b2655; letter-spacing: 0 } .cab .marca b { color: #3d7fd1; letter-spacing: 0; text-transform: none }
.bloco { margin-top: auto }
.sobre { font-size: 7.5pt; font-weight: 700; letter-spacing: .38em; color: #8fc0f5; text-transform: uppercase }
.titulo { font-size: 32pt; font-weight: 300; line-height: 1.08; margin: 5mm 0 0 }
.titulo b { display: block; font-weight: 700; color: #8fc0f5 }
.traco { width: 16mm; height: 1mm; background: #8fc0f5; margin: 9mm 0 }
.lead { font-size: 12pt; font-weight: 300; line-height: 1.6; color: #d6e4fa; max-width: 150mm; margin: 0 }
.rodape-capa { margin-top: 26mm; border-top: 1px solid rgba(255,255,255,.25); padding-top: 6mm; display: flex; gap: 16mm; font-size: 10pt }
.rodape-capa span { display: block; font-size: 6.5pt; letter-spacing: .25em; color: #8fc0f5; text-transform: uppercase; margin-bottom: 1.5mm }
/* Capa elaborada: foto, véu, topo, bloco do cliente e faixa de clientes atendidos. */
.capa { overflow: hidden; padding: 16mm 18mm 0 }
.capa-foto { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0 }
.capa-veu { position: absolute; inset: 0; z-index: 0; background: linear-gradient(180deg, rgba(11,38,85,.86) 0%, rgba(11,38,85,.55) 38%, rgba(11,38,85,.82) 70%, rgba(8,26,58,.97) 100%) }
.capa > *:not(.capa-foto):not(.capa-veu) { position: relative; z-index: 1 }
.capa-topo { display: flex; justify-content: space-between; align-items: flex-start }
.capa-num { text-align: right; font-size: 8.5pt; line-height: 1.55; color: #d6e4fa } .capa-num b { color: #fff; font-size: 10pt; letter-spacing: .04em }
.capa .bloco { margin-top: 58mm }
.capa-cliente { margin-top: auto; display: flex; gap: 8mm; padding: 6mm 0; border-top: 1px solid rgba(255,255,255,.28) }
.cc-principal { flex: 0 0 72mm } .cc-principal span, .cc-dados span { display: block; font-size: 6.5pt; letter-spacing: .25em; color: #8fc0f5; text-transform: uppercase; margin-bottom: 1.2mm }
.cc-principal b { display: block; font-size: 14pt; line-height: 1.25 } .cc-principal i { display: block; font-style: normal; font-size: 9pt; color: #d6e4fa; margin-top: 1mm }
.cc-dados { flex: 1; display: grid; grid-template-columns: 1fr 1fr; gap: 3mm 6mm; font-size: 9pt }
.capa-clientes { margin: 0 -18mm; padding: 5mm 18mm 7mm; background: #fff; color: #0e1d33 }
.ccl-titulo { font-size: 7pt; font-weight: 700; letter-spacing: .28em; color: #3d7fd1; text-transform: uppercase; margin-bottom: 2.5mm }
.ccl-grade { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; font-size: 7.6pt; line-height: 1.45; color: #33425a }
.ccl-grade span { display: block; font-weight: 700; color: #0b2655; font-size: 7pt; letter-spacing: .08em; text-transform: uppercase; margin-bottom: .8mm }
/* Páginas internas */
.cab { display: flex; justify-content: space-between; font-size: 7.5pt; padding-bottom: 3mm; border-bottom: 1px solid #e3e8ef }
.cab b { color: #3d7fd1; letter-spacing: .25em; text-transform: uppercase } .cab span { color: #9aa6b6 }
h1 { font-size: 22pt; color: #0b2655; margin: 7mm 0 5mm; padding-bottom: 4mm; border-bottom: .8mm solid #0b2655 }
h2 { font-size: 7.5pt; letter-spacing: .25em; text-transform: uppercase; color: #3d7fd1; margin: 6mm 0 2.5mm }
table { width: 100%; border-collapse: collapse; font-size: 9.5pt }
th { font-size: 6.8pt; font-weight: 700; letter-spacing: .15em; color: #8d99aa; text-align: left; padding: 2.4mm 2mm; border-bottom: 1px solid #e3e8ef; text-transform: uppercase }
td { padding: 2.6mm 2mm; border-bottom: 1px solid #e3e8ef; vertical-align: top }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums }
td small { display: block; color: #5d6b80; font-size: 8pt }
.total { margin-top: 5mm; background: #0b2655; color: #fff; border-radius: 2mm; padding: 5mm 6mm; display: flex; justify-content: space-between; align-items: baseline; break-inside: avoid }
.total span { font-size: 7pt; letter-spacing: .25em; color: #8fc0f5; text-transform: uppercase } .total b { font-size: 20pt }
.cartoes { display: flex; gap: 3.5mm; margin-top: 4mm; break-inside: avoid }
.cartao { flex: 1; border: 1px solid #e3e8ef; border-radius: 2mm; padding: 4mm; background: #f8fafc }
.cartao span { font-size: 6.5pt; letter-spacing: .2em; color: #6b7a8e; text-transform: uppercase; font-weight: 700 } .cartao b { display: block; font-size: 13pt; color: #0b2655; margin: 1mm 0 } .cartao p { margin: 0; font-size: 8pt; color: #5d6b80 }
.condicoes { margin: 4mm 0 0; font-size: 9.5pt; color: #33425a }
.colunas { display: flex; gap: 8mm } .colunas > div { flex: 1 }
ul { margin: 0; padding-left: 5mm } li { margin-bottom: 1.2mm }
.passos { display: grid; gap: 3.5mm; margin-top: 2mm }
.passo { display: flex; gap: 4mm; align-items: flex-start; font-size: 10pt }
.passo i { font-style: normal; flex: 0 0 8mm; height: 8mm; border-radius: 50%; background: #0b2655; color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 9pt }
.passo span { padding-top: 1.2mm }
.obs { margin-top: 5mm; padding: 4mm 5mm; border-left: 1mm solid #8fc0f5; background: #f4f8fd; font-size: 9.5pt; white-space: pre-line }
.aceite { margin-top: auto; padding-top: 14mm; display: flex; gap: 14mm; font-size: 8.5pt; color: #5d6b80; break-inside: avoid }
.aceite div { flex: 1; border-top: 1px solid #9aa6b6; padding-top: 2mm }
.rodape { margin-top: 8mm; padding-top: 3mm; border-top: 1px solid #e3e8ef; display: flex; justify-content: space-between; gap: 6mm; font-size: 7pt; color: #9aa6b6 }
.empurra { margin-top: auto }
.dados td:first-child { width: 42mm; color: #6b7a8e; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .08em }
.citacao { margin: 3mm 0 4mm; padding: 4mm 6mm; border-left: 1.2mm solid #3d7fd1; font-size: 12pt; font-style: italic; color: #0b2655 }
.numeros { display: flex; gap: 3.5mm; margin: 3mm 0 }
.numeros div { flex: 1; border: 1px solid #e3e8ef; border-radius: 2mm; padding: 3.5mm; background: #f8fafc }
.numeros b { display: block; font-size: 16pt; color: #0b2655 } .numeros span { font-size: 8pt; color: #5d6b80 }
.texto { white-space: pre-line; margin: 0 0 3mm }
.qa b { display: block; color: #0b2655; margin-top: 3mm } .qa p { margin: 1mm 0 0; white-space: pre-line }
.contracapa { background: radial-gradient(120% 80% at 80% 30%, #123a7a, #0b2655 70%); color: #fff; padding: 30mm 22mm; justify-content: center }
.contracapa .frase { font-size: 26pt; font-weight: 300; line-height: 1.2 } .contracapa .mensagem { margin-top: 10mm; font-size: 11pt; color: #d6e4fa; white-space: pre-line; max-width: 150mm }
</style>
</head>
<body>
    {{-- 1. Capa (Felipe, 20/10/2026: "bem elaborada, com imagem de fundo, clientes que atendemos e
         informações do cliente"). A foto ocupa a folha; o degradê garante a leitura; o cliente
         fica num bloco próprio; a faixa de baixo mostra quem já atendemos (ajustes do módulo). --}}
    @php
        $dc = (array) $o->dados_cliente;
        $preparado = collect([
            ['A/C', trim($o->contato.(data_get($dc, 'cargo') ? ' · '.data_get($dc, 'cargo') : ''), ' ·')],
            ['CNPJ', data_get($dc, 'cnpj')], ['Cidade', $o->cidade], ['Telefone', data_get($dc, 'telefone')], ['E-mail', data_get($dc, 'email')],
            // A 3ª coluna livre da capa (Sistema, Escopo, Evento…); o A/C já está acima.
            [$o->rotulo_3, ! in_array(mb_strtolower(trim((string) $o->rotulo_3)), ['a/c', 'ac', 'contato'], true) ? $o->valor_3 : null],
        ])->filter(fn ($l) => trim((string) $l[0]) !== '' && trim((string) $l[1]) !== '');
        $clientesAtendidos = $ajustes->clientes();
    @endphp
    <section class="pagina capa {{ $capa ? 'com-foto' : '' }}">
        @if ($capa)<img class="capa-foto" src="{{ $capa }}" alt=""><div class="capa-veu"></div>@endif
        <div class="capa-topo">
            <div class="marca">Vix<b>SoftHouse</b></div>
            <div class="capa-num"><b>{{ $numero }}</b><br>{{ $data->translatedFormat('j \d\e F \d\e Y') }}@if ($o->validoAte())<br>válida até {{ $o->validoAte()->format('d/m/Y') }}@endif</div>
        </div>
        <div class="bloco">
            @php $projetoCapa = $o->preCliente?->sistema?->nome ?? ($o->produto !== 'outro' ? $produto : null); @endphp
            <div class="sobre">{{ $tipo }}@if ($projetoCapa) · {{ $projetoCapa }}@endif</div>
            <p class="titulo">{{ $o->titulo ?: 'Proposta' }}@if ($o->destaque)<b>{{ $o->destaque }}</b>@endif</p>
            <div class="traco"></div>
            @if ($o->lead)<p class="lead">{{ $o->lead }}</p>@endif
        </div>
        <div class="capa-cliente">
            <div class="cc-principal">
                <span>Preparado para</span>
                <b>{{ $cliente }}</b>
                @if (data_get($dc, 'razao_social') && data_get($dc, 'razao_social') !== $cliente)<i>{{ data_get($dc, 'razao_social') }}</i>@endif
            </div>
            <div class="cc-dados">
                @foreach ($preparado as [$rotulo, $valor])<div><span>{{ $rotulo }}</span>{{ $valor }}</div>@endforeach
                <div><span>Consultor</span>{{ $o->autor?->name ?? 'VixSoftHouse' }}</div>
            </div>
        </div>
        @if ($clientesAtendidos)
            <div class="capa-clientes">
                <div class="ccl-titulo">Clientes que atendemos</div>
                <div class="ccl-grade">
                    @foreach ($clientesAtendidos as $g)
                        <div>@if ($g['setor'])<span>{{ $g['setor'] }}</span>@endif{{ implode(' · ', $g['nomes']) }}</div>
                    @endforeach
                </div>
            </div>
        @endif
    </section>

    {{-- Cliente e objeto, o desafio e a solução (só com conteúdo). --}}
    @if ($paginaCliente)
    <section class="pagina">
        {!! $cab() !!}
        @if ($o->temDadosDoCliente())
            <h1>Cliente e objeto</h1>
            @if ($dadosCliente->isNotEmpty() || $o->contato)
                <table class="dados"><tbody>
                    <tr><td>Cliente</td><td>{{ $cliente }}</td></tr>
                    @if ($o->contato)<tr><td>Contato</td><td>{{ $o->contato }}@if (data_get($o->dados_cliente, 'cargo')) · {{ data_get($o->dados_cliente, 'cargo') }}@endif</td></tr>@endif
                    @foreach ($dadosCliente->except('cargo') as [$rotulo, $valor])<tr><td>{{ $rotulo }}</td><td>{{ $valor }}</td></tr>@endforeach
                    @if ($o->cidade)<tr><td>Cidade</td><td>{{ $o->cidade }}</td></tr>@endif
                    @if ($o->natureza)<tr><td>Natureza</td><td>{{ \App\Models\Orcamento::NATUREZAS[$o->natureza] ?? $o->natureza }}</td></tr>@endif
                </tbody></table>
            @endif
            @if ($o->linhas('objeto'))
                <h2>Objeto</h2>
                <div class="passos">@foreach ($o->linhas('objeto') as $n => $l)<div class="passo"><i>{{ str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT) }}</i><span>{{ $l }}</span></div>@endforeach</div>
            @endif
        @endif
        @if ($o->temSecao('desafio'))
            <h1>{{ $o->secao('desafio', 'titulo') ?? 'O desafio' }}</h1>
            @if ($o->secao('desafio', 'citacao'))<div class="citacao">“{{ $o->secao('desafio', 'citacao') }}”</div>@endif
            @if ($o->paresDaSecao('desafio', 'numeros'))<div class="numeros">@foreach ($o->paresDaSecao('desafio', 'numeros') as [$v, $r])<div><b>{{ $v }}</b><span>{{ $r }}</span></div>@endforeach</div>@endif
            @if ($o->secao('desafio', 'texto'))<p class="texto">{{ $o->secao('desafio', 'texto') }}</p>@endif
            @if ($o->linhasDaSecao('desafio', 'itens'))<ul>@foreach ($o->linhasDaSecao('desafio', 'itens') as $l)<li>{{ $l }}</li>@endforeach</ul>@endif
        @endif
        @if ($o->temSecao('solucao'))
            <h1>{{ $o->secao('solucao', 'titulo') ?? 'A solução' }}</h1>
            @if ($o->secao('solucao', 'texto'))<p class="texto">{{ $o->secao('solucao', 'texto') }}</p>@endif
            @if ($o->linhasDaSecao('solucao', 'passos'))<div class="passos">@foreach ($o->linhasDaSecao('solucao', 'passos') as $n => $l)<div class="passo"><i>{{ str_pad((string) ($n + 1), 2, '0', STR_PAD_LEFT) }}</i><span>{{ $l }}</span></div>@endforeach</div>@endif
        @endif
        <div class="rodape empurra"><span>{{ $tipo }} · {{ $numero }}</span><span>VixSoftHouse</span></div>
    </section>
    @endif

    {{-- Especificação, decisões técnicas e seções livres (só com conteúdo). --}}
    @if ($paginaDetalhes)
    <section class="pagina">
        {!! $cab() !!}
        @if ($o->temSecao('especificacao'))
            <h1>{{ $o->secao('especificacao', 'titulo') ?? 'Especificação' }}</h1>
            <table class="dados"><tbody>@foreach ($o->paresDaSecao('especificacao', 'linhas') as [$a, $b])<tr><td>{{ $a }}</td><td>{{ $b }}</td></tr>@endforeach</tbody></table>
        @endif
        @if ($o->temSecao('decisoes'))
            <h1>{{ $o->secao('decisoes', 'titulo') ?? 'Decisões técnicas' }}</h1>
            <table><thead><tr><th>Ponto</th><th>Situação</th></tr></thead><tbody>@foreach ($o->paresDaSecao('decisoes', 'linhas') as [$a, $b])<tr><td>{{ $a }}</td><td>{{ $b }}</td></tr>@endforeach</tbody></table>
        @endif
        @if ($o->temSecao('extras'))
            @foreach ($o->secoesLivres() as $bloco)
                @if ($bloco['titulo'])<h1>{{ $bloco['titulo'] }}</h1>@endif
                {{-- Linhas "| a | b |" viram tabela (a primeira é o cabeçalho; "|---|" é só separador). --}}
                @foreach (collect(preg_split('/\R/', $bloco['texto']))->chunkWhile(fn ($l, $k, $c) => str_starts_with(trim($l), '|') === str_starts_with(trim($c->last()), '|')) as $trecho)
                    @if (str_starts_with(trim($trecho->first()), '|'))
                        @php $linhas = $trecho->map(fn ($l) => array_map('trim', explode('|', trim(trim($l), '|'))))->reject(fn ($c) => collect($c)->every(fn ($x) => preg_match('/^:?-{2,}:?$/', $x) || $x === ''))->values(); @endphp
                        <table>
                            <thead><tr>@foreach ($linhas->first() as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                            <tbody>@foreach ($linhas->slice(1) as $l)<tr>@foreach ($l as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody>
                        </table>
                    @elseif (trim($trecho->implode("\n")) !== '')
                        <p class="texto">{{ trim($trecho->implode("\n")) }}</p>
                    @endif
                @endforeach
            @endforeach
        @endif
        <div class="rodape empurra"><span>{{ $tipo }} · {{ $numero }}</span><span>VixSoftHouse</span></div>
    </section>
    @endif

    {{-- 2. Investimento --}}
    <section class="pagina">
        {!! $cab() !!}
        <h1>Investimento</h1>
        {{-- Material, implantação e recorrente, cada um com o seu subtotal (Felipe, 20/10/2026). --}}
        @foreach (['material' => ['Material e equipamentos', $o->total_material, ''], 'implantacao' => ['Implantação e serviços', $o->total_implantacao, ''],
            'recorrente' => ['Recorrente', $o->total_mensal, '/mês']] as $cat => [$nomeCat, $subtotal, $sufixo])
            @php $daCategoria = $o->itens->filter(fn ($i) => $i->cobranca !== 'opcao' && $i->categoriaEfetiva() === $cat); @endphp
            @if ($daCategoria->isNotEmpty())
                <h2>{{ $nomeCat }}</h2>
                <table>
                    <colgroup><col><col style="width: 24mm"><col style="width: 32mm"><col style="width: 36mm"></colgroup>
                    <tbody>
                        @foreach ($daCategoria as $i)
                            <tr><td>{{ $i->descricao }}</td><td class="num">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td><td class="num">{{ $brl($i->valor_unitario) }}</td><td class="num">{{ $brl($i->subtotal()) }}{{ $sufixo }}</td></tr>
                        @endforeach
                        <tr><td colspan="3" class="num" style="font-weight: 700; color: #0b2655">Subtotal {{ mb_strtolower($nomeCat) }}</td><td class="num" style="font-weight: 700; color: #0b2655">{{ $brl($subtotal) }}{{ $sufixo }}</td></tr>
                    </tbody>
                </table>
            @endif
        @endforeach
        @if ($o->total_unico > 0 || $o->total_mensal > 0)
            <div class="cartoes">
                @if ($o->total_material > 0)<div class="cartao"><span>Material</span><b>{{ $brl($o->total_material) }}</b><p>equipamentos e insumos</p></div>@endif
                @if ($o->total_implantacao > 0)<div class="cartao"><span>Implantação</span><b>{{ $brl($o->total_implantacao) }}</b><p>serviços, uma vez</p></div>@endif
                @if ($o->total_mensal > 0)<div class="cartao"><span>Recorrente</span><b>{{ $brl($o->total_mensal) }}/mês</b><p>a partir da instalação</p></div>@endif
            </div>
            @if ($o->total_unico > 0)<div class="total"><span>Total do projeto (material + implantação)</span><b>{{ $brl($o->total_unico) }}</b></div>@endif
        @endif
        @if ($opcoes->isNotEmpty())
            {{-- Faixas, opcionais e "sob consulta": o cliente escolhe; nada daqui entra no total. --}}
            <h2>Opcionais e faixas (fora do total)</h2>
            <table>
                <colgroup><col><col style="width: 24mm"><col style="width: 32mm"><col style="width: 36mm"></colgroup>
                <tbody>
                    @foreach ($opcoes as $i)
                        <tr><td>{{ $i->descricao }}<small>{{ \App\Models\OrcamentoItem::CATEGORIAS[$i->categoriaEfetiva()] }}{{ $i->categoriaEfetiva() === 'recorrente' ? ' · mensal' : '' }}</small></td><td class="num">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td>
                            <td class="num">{{ $i->valor_unitario > 0 ? $brl($i->valor_unitario) : 'sob consulta' }}</td><td class="num">{{ $i->valor_unitario > 0 ? $brl($i->subtotal()) : '—' }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <div class="cartoes">
            @if ($o->total_unico > 0 && $o->parcelas > 1)
                <div class="cartao"><span>Parcelado</span><b>{{ $o->parcelas }}x de {{ $brl($o->valorParcela()) }}</b><p>{{ $o->entrada > 0 ? 'Entrada de '.$brl($o->entrada).' · ' : '' }}sem juros</p></div>
            @endif
            @if ($o->total_unico > 0 && ($o->desconto_avista > 0 || $o->parcelas <= 1))
                <div class="cartao"><span>À vista</span><b>{{ $brl($o->valorAVista()) }}</b><p>{{ $o->desconto_avista > 0 ? rtrim(rtrim(number_format($o->desconto_avista, 2, ',', ''), '0'), ',').'% de desconto' : 'pagamento único' }}</p></div>
            @endif

        </div>
        @if ($o->planosLista())
            <h2>Planos</h2>
            <table>
                <thead><tr><th>Plano</th><th class="num">Valor</th><th>Período</th><th>Observação</th></tr></thead>
                <tbody>@foreach ($o->planosLista() as $p)<tr><td>{{ $p['nome'] }}</td><td class="num">{{ is_numeric(str_replace(',', '.', $p['valor'])) ? $brl(str_replace(',', '.', $p['valor'])) : $p['valor'] }}</td><td>{{ $p['periodo'] }}</td><td>{{ $p['observacao'] }}</td></tr>@endforeach</tbody>
            </table>
        @endif
        @if ($o->pagamento_texto)<p class="condicoes" style="white-space: pre-line">{{ $o->pagamento_texto }}</p>
        @elseif ($o->condicoes())<p class="condicoes">{{ $o->condicoes() }}</p>@endif
        <div class="rodape empurra"><span>{{ $o->validoAte() ? 'Proposta válida até '.$o->validoAte()->format('d/m/Y') : $tipo.' · '.$numero }}</span><span>VixSoftHouse</span></div>
    </section>

    {{-- 3. Escopo, próximos passos e aceite --}}
    <section class="pagina">
        {!! $cab() !!}
        <h1>Escopo e próximos passos</h1>
        @if ($o->linhas('inclui') || $o->linhas('nao_inclui'))
            <div class="colunas">
                <div><h2>Inclui</h2><ul>@foreach ($o->linhas('inclui') as $l)<li>{{ $l }}</li>@endforeach</ul></div>
                @if ($o->linhas('nao_inclui'))<div><h2>Não inclui</h2><ul>@foreach ($o->linhas('nao_inclui') as $l)<li>{{ $l }}</li>@endforeach</ul></div>@endif
            </div>
        @endif
        @if ($o->linhas('passos'))
            <h2>Próximos passos</h2>
            <div class="passos">@foreach ($o->linhas('passos') as $n => $l)<div class="passo"><i>{{ $n + 1 }}</i><span>{{ $l }}</span></div>@endforeach</div>
        @endif
        @if ($o->temSecao('execucao'))
            <h2>{{ $o->secao('execucao', 'titulo') ?? 'Execução' }}</h2>
            @if ($o->linhasDaSecao('execucao', 'etapas'))<div class="passos">@foreach ($o->linhasDaSecao('execucao', 'etapas') as $n => $l)<div class="passo"><i>{{ $n + 1 }}</i><span>{{ $l }}</span></div>@endforeach</div>@endif
            @if ($o->secao('execucao', 'prazo'))<p class="condicoes"><b>Prazo:</b> {{ $o->secao('execucao', 'prazo') }}</p>@endif
        @endif
        @if ($o->temSecao('perguntas'))
            <h2>Perguntas e respostas</h2>
            <div class="qa">@foreach ($o->paresDaSecao('perguntas', 'linhas') as [$pergunta, $resposta])<b>{{ $pergunta }}</b><p>{{ $resposta }}</p>@endforeach</div>
        @endif
        @if ($o->observacoes)<div class="obs">{{ $o->observacoes }}</div>@endif
        @if ($o->aceite)
            <div class="aceite">
                <div>De acordo — {{ $cliente }}<br>Data: ____/____/________</div>
                <div>VixSoftHouse<br>{{ $o->autor?->name ?? 'Responsável comercial' }}</div>
            </div>
        @endif
        <div class="rodape {{ $o->aceite ? '' : 'empurra' }}"><span>{{ $ajustes->get('emissor_nome', 'VixSoftHouse') }}{{ $ajustes->linhaDoEmissor() ? ' · '.$ajustes->linhaDoEmissor() : '' }}</span><span>VixSoftHouse</span></div>
    </section>

    @if ($o->temSecao('contracapa'))
    <section class="pagina contracapa">
        <div class="marca">Vix<b>SoftHouse</b></div>
        <p class="frase">{{ $o->secao('contracapa', 'frase') }}</p>
        @if ($o->secao('contracapa', 'mensagem'))<p class="mensagem">{{ $o->secao('contracapa', 'mensagem') }}</p>@endif
    </section>
    @endif
</body>
</html>
