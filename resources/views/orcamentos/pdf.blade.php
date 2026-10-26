@php
    /**
     * @var \App\Models\Orcamento $o
     * @var array<string, list<array{src: string, legenda: string, retrato: bool}>> $imagens
     */
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $opcoes = $o->itens->where('cobranca', 'opcao');
    $numero = $o->numero ? 'Orçamento '.$o->codigo() : 'Rascunho';
    $cliente = $o->cliente ?: 'Cliente';
    $produto = \App\Services\Orcamentos\Modelos::nome((string) $o->produto);
    $projeto = $o->preCliente?->sistema?->nome ?? ($o->produto !== 'outro' ? $produto : null);
    $data = $o->data()->locale('pt_BR');
    $tipo = $o->tipo ?: 'Proposta comercial';
    $dc = (array) $o->dados_cliente;
    $imagens ??= [];
    $imgs = fn (string $onde) => $imagens[$onde] ?? [];
    $dois = fn (int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);

    // "Remessa enviada × peça devolvida. É difícil saber…": o começo da linha, até o primeiro
    // ponto, dois-pontos ou travessão, sai em negrito — é o que deixa a lista legível de relance.
    $negrito = function (string $t): \Illuminate\Support\HtmlString {
        if (preg_match('/^(.{4,90}?)(\.|:|\s[—–-])\s+(\S.*)$/u', $t, $m) && ! preg_match('/\d$/', $m[1])) {
            $sinal = in_array($m[2], ['.', ':'], true) ? $m[2] : '';

            return new \Illuminate\Support\HtmlString('<b>'.e($m[1].$sinal).'</b> '.e($m[3]));
        }

        return new \Illuminate\Support\HtmlString(e($t));
    };
    // Quanto texto uma página leva, para decidir se a seção seguinte cabe nela ou vai para outra.
    $peso = fn (...$partes) => collect($partes)->flatten()->sum(fn ($t) => mb_strlen((string) $t));

    // Linhas "| a | b |" viram tabela (a primeira é o cabeçalho; "|---|" é só separador);
    // "- x" e "• x" viram lista; o resto, parágrafo. O primeiro parágrafo é a abertura da página.
    $trechos = function (string $texto) {
        return collect(preg_split('/\R/', $texto))
            ->chunkWhile(function ($l, $k, $c) {
                // Linha curta toda em maiúsculas ("O QUE ISSO SIGNIFICA NA PRÁTICA") é um subtítulo, sozinha.
                $tipo = fn ($x) => str_starts_with(trim($x), '|') ? 't' : (preg_match('/^\s*[-•*›]\s+/u', $x) ? 'l' : (trim($x) === '' ? 'v'
                    : (mb_strlen(trim($x)) <= 70 && preg_match('/\p{Lu}{3}/u', $x) && mb_strtoupper(trim($x)) === trim($x) ? 's' : 'p')));

                return $tipo($l) === $tipo($c->last()) && $tipo($l) !== 's';
            })
            ->map(function ($c) {
                $p = trim($c->first());
                if (str_starts_with($p, '|')) {
                    $linhas = $c->map(fn ($l) => array_map('trim', explode('|', trim(trim($l), '|'))))
                        ->reject(fn ($cel) => collect($cel)->every(fn ($x) => preg_match('/^:?-{2,}:?$/', $x) || $x === ''))->values();

                    return ['tipo' => 'tabela', 'linhas' => $linhas];
                }
                if (preg_match('/^[-•*›]\s+/u', $p)) {
                    return ['tipo' => 'lista', 'itens' => $c->map(fn ($l) => preg_replace('/^\s*[-•*›]\s+/u', '', trim($l)))->filter()->values()];
                }
                if ($c->count() === 1 && mb_strlen($p) <= 70 && preg_match('/\p{Lu}{3}/u', $p) && mb_strtoupper($p) === $p) {
                    return ['tipo' => 'subtitulo', 'texto' => $p];
                }

                return ['tipo' => 'texto', 'texto' => trim($c->implode("\n"))];
            })
            ->reject(fn ($t) => $t['tipo'] === 'texto' && $t['texto'] === '')->values();
    };
    // Altura das imagens por linha, conforme quantas linhas de imagem e quanto texto dividem a folha.
    $alturaDas = function (array $lista, int $texto) {
        $retratos = collect($lista)->where('retrato', true)->count();
        $linhas = count($lista) - $retratos + (int) ceil($retratos / 3);
        $util = max(65, 195 - min(125, $texto / 12));

        return round(min(125, $util / max(1, $linhas) - 8));
    };

    $cabecalho = fn () => new \Illuminate\Support\HtmlString('<header class="cab"><b>'.e($tipo).'</b><span>'.e($projeto ? $projeto.' · ' : '').'VixSoftHouse</span></header>');
    $rodape = fn (string $secao) => new \Illuminate\Support\HtmlString('<footer class="rodape"><span>'.e($secao).'</span><span>VixSoftHouse</span></footer>');
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Proposta {{ $numero }} — {{ $cliente }}</title>
{{-- O PDF padrão (docs/specs/orcamentos-vixcard). Um modelo só para todo orçamento: muda o conteúdo,
     nunca o layout. Toda proposta sai como VixSoftHouse (Felipe, 19/10/2026).

     O desenho é o da proposta da Roupas FRID (Igor, São Paulo, 19/10/2026), pedido do Felipe em 20/10:
     "está cheio de texto, cansativo de ler [...] precisamos de páginas bem definidas e com imagens".
     Por isso cada assunto tem a SUA página, com título grande e poucas palavras; as listas abrem em
     negrito; as imagens ocupam a folha; e a seção que não cabe vai para a página seguinte em vez de
     espremer a anterior. --}}
<style>
@font-face { font-family: 'Open Sans'; font-style: normal; font-weight: 300 700; src: url('{{ $fontes }}/open-sans-latin.woff2') format('woff2');
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD; }
@font-face { font-family: 'Open Sans'; font-style: normal; font-weight: 300 700; src: url('{{ $fontes }}/open-sans-latin-ext.woff2') format('woff2');
  unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF; }
@page { size: A4; margin: 0 }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact }
html, body { margin: 0; padding: 0 }
body { font-family: 'Open Sans', 'Segoe UI', 'DejaVu Sans', sans-serif; color: #33425a; font-size: 9.6pt; line-height: 1.6; background: {{ $previa ? '#d9e0e7' : '#fff' }} }
b, strong { color: #0b2655; font-weight: 700 }
.pagina { width: 210mm; min-height: 297mm; padding: 13mm 17mm 20mm; display: flex; flex-direction: column; background: #fff; page-break-after: always; break-after: page; position: relative }
.pagina:last-child { page-break-after: auto; break-after: auto }
@if ($previa) .pagina { margin: 0 auto 8mm; box-shadow: 0 2px 10px rgba(10,30,60,.18) } body { padding: 8mm 0 } @endif

/* Cabeçalho e rodapé de toda página interna */
.cab { display: flex; justify-content: space-between; align-items: baseline; padding-bottom: 3.2mm; border-bottom: 1px solid #e6ebf1; font-size: 7.2pt }
.cab b { color: #3d7fd1; letter-spacing: .32em; text-transform: uppercase }
.cab span { color: #a3adbb }
.rodape { position: absolute; left: 17mm; right: 17mm; bottom: 9mm; padding-top: 3mm; border-top: 1px solid #e6ebf1; display: flex; justify-content: space-between; font-size: 7pt; color: #a3adbb }
.conteudo { padding-bottom: 6mm }

/* Títulos: um grande por página; os blocos dentro dela abrem com um traço azul-escuro e um rótulo espaçado */
h1 { font-size: 21pt; font-weight: 600; color: #0b2655; line-height: 1.2; margin: 7mm 0 3.5mm }
.intro { font-size: 10.6pt; line-height: 1.75; color: #4a5870; margin: 0 0 4mm }
.bloco { border-top: .5mm solid #0b2655; margin-top: 5mm; padding-top: 3.2mm }
.bloco:first-of-type { margin-top: 2mm }
.rotulo { font-size: 7pt; font-weight: 700; letter-spacing: .3em; color: #3d7fd1; text-transform: uppercase; margin: 0 0 2.6mm }
p { margin: 0 0 2.5mm } .texto { white-space: pre-line }

/* Tabelas: rótulo em negrito à esquerda, linhas finas */
table { width: 100%; border-collapse: collapse; font-size: 9pt }
th { font-size: 6.6pt; font-weight: 700; letter-spacing: .16em; color: #9aa6b6; text-align: left; padding: 2mm 2mm 2.2mm; border-bottom: 1px solid #e6ebf1; text-transform: uppercase }
td { padding: 2.1mm 2mm; border-bottom: 1px solid #e6ebf1; vertical-align: top }
td:first-child { font-weight: 700; color: #0b2655 }
.dados td:first-child { width: 46mm }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums }
td small { display: block; font-weight: 400; color: #8492a6; font-size: 7.8pt }

/* Listas com o começo em negrito e etapas numeradas 01, 02… */
ul.pontos { list-style: none; margin: 0; padding: 0 }
ul.pontos li { position: relative; padding-left: 6mm; margin-bottom: 2.4mm }
ul.pontos li::before { content: ''; position: absolute; left: .6mm; top: 1.9mm; width: 1.8mm; height: 1.8mm; border-radius: 50%; background: #3d7fd1 }
.etapas { display: grid }
.etapa { display: flex; gap: 6mm; padding: 2.3mm 0; border-bottom: 1px solid #e6ebf1 }
.etapa i { font-style: normal; flex: 0 0 8mm; font-weight: 700; color: #3d7fd1 }

/* Imagens */
.figuras { display: flex; gap: 5mm; justify-content: center; margin: 4mm 0 2mm; break-inside: avoid }
.figuras figure { margin: 0; flex: 0 1 auto; min-width: 0; display: flex; flex-direction: column; align-items: center }
.figuras img { display: block; max-width: 100%; width: auto; height: auto; border-radius: 2.4mm }
.figuras figcaption { margin-top: 2mm; font-size: 7.6pt; line-height: 1.45; color: #8492a6; text-align: center; max-width: 165mm }
.lado { display: flex; gap: 7mm; margin-top: 6mm; align-items: flex-start }
.lado > .figuras { flex: 0 0 70mm; margin: 0 }
.lado > div { flex: 1; min-width: 0 }
.lado .bloco:first-child { margin-top: 0 }

/* Destaques */
.chamada { margin-top: 4mm; padding: 4mm 5mm; border-left: 1mm solid #3d7fd1; background: #f2f6fc; border-radius: 0 2mm 2mm 0; break-inside: avoid }
.chamada p:last-child { margin: 0 }
.numeros { display: flex; gap: 3.5mm; margin: 1mm 0 4mm }
.numeros div { flex: 1; border-radius: 2mm; padding: 3.5mm 4mm; background: #f2f6fc }
.numeros b { display: block; font-size: 17pt; line-height: 1.2 } .numeros span { font-size: 8pt; color: #5d6b80 }
.citacao { margin: 0 0 4mm; padding: 1mm 0 1mm 5mm; border-left: 1mm solid #3d7fd1; font-size: 11.5pt; font-style: italic; color: #0b2655 }

/* Investimento */
.grupo td { padding-top: 4mm; font-size: 6.8pt; font-weight: 700; letter-spacing: .26em; color: #3d7fd1; text-transform: uppercase; border-bottom: 1px solid #c9d6e6 }
.subtotal td { font-weight: 700; color: #0b2655; background: #f7f9fc }
.opcional td, .opcional td:first-child { color: #6b7a8e; font-weight: 400 }
.resumo { display: flex; gap: 3.5mm; margin-top: 5mm; break-inside: avoid }
.resumo div { flex: 1; border: 1px solid #e6ebf1; border-radius: 2mm; padding: 3.2mm 4mm }
.resumo span { display: block; font-size: 6.6pt; font-weight: 700; letter-spacing: .2em; color: #8492a6; text-transform: uppercase }
.resumo b { display: block; font-size: 12.5pt; margin-top: .6mm }
.total { margin-top: 3mm; background: #0b2655; color: #fff; border-radius: 2mm; padding: 4.5mm 6mm; display: flex; justify-content: space-between; align-items: baseline; break-inside: avoid }
.total span { display: block; font-size: 7pt; letter-spacing: .25em; color: #8fc0f5; text-transform: uppercase } .total b { font-size: 18pt; color: #fff }
.total small { display: block; margin-top: .8mm; font-size: 8pt; color: #c6d7f0 }
.total.mensal { background: #f2f6fc; color: #0b2655; margin-top: 2mm; padding: 3.5mm 6mm } .total.mensal span { color: #3d7fd1 } .total.mensal small { color: #6b7a8e } .total.mensal b { color: #0b2655; font-size: 14pt }

/* Aceite */
.assinaturas { display: flex; gap: 12mm; margin-top: 15mm; text-align: center; font-size: 8.6pt; color: #5d6b80; break-inside: avoid }
.assinaturas div { flex: 1; border-top: .4mm solid #0b2655; padding-top: 2.5mm; line-height: 1.5 }
.contato { margin-top: 7mm; padding-top: 3mm; border-top: 1px solid #e6ebf1; text-align: center; font-size: 8pt; color: #6b7a8e }

/* Capa: azul profundo (ou a foto com véu), a marca no alto, o título no meio, o cliente embaixo */
.capa { background: linear-gradient(160deg, #123a7a 0%, #0b2655 55%, #081d44 100%); color: #fff; padding: 22mm 20mm 0; overflow: hidden }
.capa-foto { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0 }
.capa-veu { position: absolute; inset: 0; z-index: 0; background: linear-gradient(180deg, rgba(11,38,85,.9) 0%, rgba(11,38,85,.62) 40%, rgba(11,38,85,.86) 72%, rgba(8,29,68,.98) 100%) }
.capa > *:not(.capa-foto):not(.capa-veu) { position: relative; z-index: 1 }
.capa-topo { display: flex; justify-content: space-between; align-items: flex-start }
.marca { font-size: 21pt; font-weight: 300; letter-spacing: .01em; color: #fff; line-height: 1 }
.marca b { font-weight: 700; color: #8fc0f5 }
.marca small { display: block; margin-top: 2mm; font-size: 6pt; font-weight: 600; letter-spacing: .42em; color: #8fc0f5; text-transform: uppercase }
.capa-num { text-align: right; font-size: 8pt; color: #c6d7f0; letter-spacing: .06em }
.capa-meio { margin-top: 74mm }
.sobre { font-size: 7.4pt; font-weight: 700; letter-spacing: .4em; color: #8fc0f5; text-transform: uppercase }
.titulo { font-size: 30pt; font-weight: 300; line-height: 1.12; margin: 6mm 0 0; color: #fff }
.titulo b { display: block; font-weight: 700; color: #8fc0f5 }
.traco { width: 15mm; height: .9mm; background: #8fc0f5; margin: 9mm 0 }
.lead { font-size: 11pt; font-weight: 300; line-height: 1.7; color: #d6e4fa; max-width: 150mm; margin: 0 }
.capa-cliente { margin-top: auto; border-top: 1px solid rgba(255,255,255,.22); padding: 6mm 0 7mm; display: flex; gap: 12mm; font-size: 9.4pt; color: #fff }
.capa-cliente > div { min-width: 0 } .capa-cliente > div:first-child { flex: 1 }
.capa-cliente span { display: block; font-size: 6.4pt; letter-spacing: .3em; color: #8fc0f5; text-transform: uppercase; margin-bottom: 1.6mm }
.capa-cliente i { display: block; font-style: normal; font-size: 8pt; color: #c6d7f0; margin-top: .6mm }
.capa-clientes { margin: 0 -20mm; padding: 5mm 20mm 8mm; background: rgba(4,16,40,.55); border-top: 1px solid rgba(255,255,255,.08) }
.ccl-titulo { font-size: 6.4pt; font-weight: 700; letter-spacing: .32em; color: #8fc0f5; text-transform: uppercase; margin-bottom: 2.4mm }
.ccl-grade { display: grid; grid-template-columns: repeat(4, 1fr); gap: 3mm; font-size: 7.3pt; line-height: 1.5; color: #c6d7f0 }
.ccl-grade span { display: block; font-weight: 700; color: #fff; font-size: 6.6pt; letter-spacing: .1em; text-transform: uppercase; margin-bottom: .6mm }

.contracapa { background: linear-gradient(160deg, #123a7a 0%, #0b2655 55%, #081d44 100%); color: #fff; padding: 30mm 22mm; justify-content: center }
.contracapa .frase { font-size: 24pt; font-weight: 300; line-height: 1.25; color: #fff; margin-top: 12mm }
.contracapa .mensagem { margin-top: 8mm; font-size: 10.5pt; color: #d6e4fa; white-space: pre-line; max-width: 150mm }
.contracapa .contato { border-top-color: rgba(255,255,255,.2); color: #c6d7f0; text-align: left; margin-top: 16mm }
</style>
</head>
<body>
    {{-- 1. Capa. A foto é opcional (a do orçamento ou a padrão dos ajustes); sem ela, o azul da FRID. --}}
    @php
        // O nome do histórico às vezes já traz A/C e cidade ("Roupas FRID · A/C Igor · São Paulo/SP"):
        // nada se repete, e o que ainda está "a informar" não vai para a capa.
        $informado = fn ($v) => filled($v) && ! preg_match('/a informar/iu', (string) $v);
        $clienteCapa = str_contains($cliente, ' · ') ? $cliente
            : collect([$cliente, $informado($o->contato) ? 'A/C '.$o->contato : null, $o->cidade])->filter()->implode(' · ');
        $terceira = $o->rotulo_3 && $o->valor_3 && ! in_array(mb_strtolower(trim((string) $o->rotulo_3)), ['a/c', 'ac', 'contato', 'cliente', 'data', 'proponente'], true);
        $clientesAtendidos = $ajustes->clientes();
    @endphp
    <section class="pagina capa">
        @if ($capa)<img class="capa-foto" src="{{ $capa }}" alt=""><div class="capa-veu"></div>@endif
        <div class="capa-topo">
            <div class="marca">Vix<b>SoftHouse</b><small>Software e identificação</small></div>
            <div class="capa-num">{{ $numero }}</div>
        </div>
        <div class="capa-meio">
            <div class="sobre">{{ $tipo }} · Rev. {{ $dois($o->revisao ?: 1) }}</div>
            <p class="titulo">{{ $o->titulo ?: 'Proposta' }}@if ($o->destaque)<b>{{ $o->destaque }}</b>@endif</p>
            <div class="traco"></div>
            @if ($o->lead)<p class="lead">{{ $o->lead }}</p>@endif
        </div>
        <div class="capa-cliente">
            <div><span>Cliente</span>{{ $clienteCapa }}@if ($informado(data_get($dc, 'razao_social')) && data_get($dc, 'razao_social') !== $cliente)<i>{{ data_get($dc, 'razao_social') }}</i>@endif</div>
            @if ($terceira)<div><span>{{ $o->rotulo_3 }}</span>{{ $o->valor_3 }}</div>@endif
            <div><span>Proponente</span>VixSoftHouse @if ($ajustes->get('emissor_cnpj'))<i>CNPJ {{ $ajustes->get('emissor_cnpj') }}</i>@endif @if ($o->autor)<i>{{ $o->autor->name }}</i>@endif</div>
            <div><span>Data</span>{{ $data->translatedFormat('j \d\e F \d\e Y') }}@if ($o->validoAte())<i>válida até {{ $o->validoAte()->format('d/m/Y') }}</i>@endif</div>
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

    {{-- 2. Cliente e desafio: os dados, o problema e como resolvemos. A solução só divide a página
         com o desafio se couber; senão, abre a sua. --}}
    @php
        $dadosCliente = collect([
            ['Razão social', data_get($dc, 'razao_social')], ['CNPJ', data_get($dc, 'cnpj')], ['Inscrição estadual', data_get($dc, 'ie')],
            ['Atividade', data_get($dc, 'atividade')], ['Endereço', data_get($dc, 'endereco')],
            ['Contato', collect([$o->contato, data_get($dc, 'cargo'), data_get($dc, 'telefone'), data_get($dc, 'email')])->filter()->implode(' · ')],
            ['Cidade', $o->cidade], ['Natureza', $o->natureza ? (\App\Models\Orcamento::NATUREZAS[$o->natureza] ?? $o->natureza) : null],
        ])->filter(fn ($l) => trim((string) $l[1]) !== '');
        $temDesafio = $o->temSecao('desafio');
        $temSolucao = $o->temSecao('solucao');
        $pesoDesafio = $peso($dadosCliente->pluck(1), $o->linhas('objeto'), $o->secao('desafio', 'texto'), $o->linhasDaSecao('desafio', 'itens'), $o->secao('desafio', 'citacao'))
            + ($o->paresDaSecao('desafio', 'numeros') ? 300 : 0);
        $pesoSolucao = $peso($o->secao('solucao', 'texto'), $o->linhasDaSecao('solucao', 'passos'));
        $solucaoJunto = $temSolucao && ! $imgs('desafio') && ! $imgs('solucao') && $pesoDesafio + $pesoSolucao + $dadosCliente->count() * 60 < 1900;
        $solucaoTitulo = $o->secao('solucao', 'titulo') ?? ($projeto ? 'Como o '.$projeto.' resolve' : 'A solução');
    @endphp
    @if ($dadosCliente->isNotEmpty() || $o->linhas('objeto') || $temDesafio || $solucaoJunto)
        @php $tituloPagina = $temDesafio ? 'Cliente e desafio' : 'Cliente e objeto'; @endphp
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                <h1>{{ $tituloPagina }}</h1>
                @if ($dadosCliente->isNotEmpty())
                    <div class="bloco">
                        <p class="rotulo">Dados do cliente</p>
                        <table class="dados"><tbody>@foreach ($dadosCliente as [$r, $v])<tr><td>{{ $r }}</td><td>{{ $v }}</td></tr>@endforeach</tbody></table>
                    </div>
                @endif
                @if ($o->linhas('objeto'))
                    <div class="bloco">
                        <p class="rotulo">Objeto</p>
                        <div class="etapas">@foreach ($o->linhas('objeto') as $n => $l)<div class="etapa"><i>{{ $dois($n + 1) }}</i><span>{{ $negrito($l) }}</span></div>@endforeach</div>
                    </div>
                @endif
                @if ($temDesafio)
                    <div class="bloco">
                        <p class="rotulo">{{ $o->secao('desafio', 'titulo') ?? 'O desafio de hoje' }}</p>
                        @if ($o->secao('desafio', 'citacao'))<p class="citacao">“{{ $o->secao('desafio', 'citacao') }}”</p>@endif
                        @if ($o->paresDaSecao('desafio', 'numeros'))<div class="numeros">@foreach ($o->paresDaSecao('desafio', 'numeros') as [$v, $r])<div><b>{{ $v }}</b><span>{{ $r }}</span></div>@endforeach</div>@endif
                        @if ($o->secao('desafio', 'texto'))<p class="intro texto">{{ $o->secao('desafio', 'texto') }}</p>@endif
                        @if ($o->linhasDaSecao('desafio', 'itens'))<ul class="pontos">@foreach ($o->linhasDaSecao('desafio', 'itens') as $l)<li>{{ $negrito($l) }}</li>@endforeach</ul>@endif
                    </div>
                    @if ($imgs('desafio'))
                        @include('orcamentos.pdf._figuras', ['imgs' => $imgs('desafio'), 'altura' => $alturaDas($imgs('desafio'), $pesoDesafio)])
                    @endif
                @endif
                @if ($solucaoJunto)
                    <div class="bloco">
                        <p class="rotulo">{{ $solucaoTitulo }}</p>
                        @if ($o->secao('solucao', 'texto'))<p class="texto">{{ $o->secao('solucao', 'texto') }}</p>@endif
                        @if ($o->linhasDaSecao('solucao', 'passos'))<div class="etapas">@foreach ($o->linhasDaSecao('solucao', 'passos') as $n => $l)<div class="etapa"><i>{{ $dois($n + 1) }}</i><span>{{ $negrito($l) }}</span></div>@endforeach</div>@endif
                    </div>
                @endif
            </div>
            {{ $rodape($tituloPagina) }}
        </section>
    @endif

    @if ($temSolucao && ! $solucaoJunto)
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                <h1>{{ $solucaoTitulo }}</h1>
                @if ($o->secao('solucao', 'texto'))<p class="intro texto">{{ $o->secao('solucao', 'texto') }}</p>@endif
                @if ($o->linhasDaSecao('solucao', 'passos'))<div class="etapas">@foreach ($o->linhasDaSecao('solucao', 'passos') as $n => $l)<div class="etapa"><i>{{ $dois($n + 1) }}</i><span>{{ $negrito($l) }}</span></div>@endforeach</div>@endif
                @if ($imgs('solucao'))
                    @include('orcamentos.pdf._figuras', ['imgs' => $imgs('solucao'), 'altura' => $alturaDas($imgs('solucao'), $pesoSolucao)])
                @endif
            </div>
            {{ $rodape($solucaoTitulo) }}
        </section>
    @endif

    {{-- 3. Seções livres. A que tem imagem ou muito texto abre a sua página; as curtas se juntam
         na mesma folha, cada uma com o seu rótulo, em vez de uma página quase vazia para cada.
         A seção "Aceite" do histórico sai: o aceite do padrão já vem no fim. --}}
    @php
        $paginasLivres = [];
        if ($o->temSecao('extras')) {
            $atual = null;
            foreach ($o->secoesLivres() as $bloco) {
                if ($o->aceite && mb_strtolower(trim($bloco['titulo'])) === 'aceite') {
                    continue;
                }
                $bloco['chave'] = 'livre:'.\Illuminate\Support\Str::slug($bloco['titulo']);
                $bloco['peso'] = $peso($bloco['texto']);
                $comImagem = (bool) $imgs($bloco['chave']);
                // Uma seção pequena sem imagem ainda cabe embaixo da página anterior, mesmo com imagem.
                $miuda = ! $comImagem && $bloco['peso'] < 400 && $atual !== null && $atual['peso'] + $bloco['peso'] < 900;
                if (! $miuda && ($atual === null || $comImagem || $atual['imagem'] || $atual['peso'] + $bloco['peso'] > 1500)) {
                    if ($atual !== null) {
                        $paginasLivres[] = $atual;
                    }
                    $atual = ['blocos' => [], 'peso' => 0, 'imagem' => $comImagem];
                }
                $atual['blocos'][] = $bloco;
                $atual['peso'] += $bloco['peso'];
            }
            if ($atual !== null) {
                $paginasLivres[] = $atual;
            }
        }
    @endphp
    @foreach ($paginasLivres as $pag)
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                @foreach ($pag['blocos'] as $b => $bloco)
                    @php
                        $partes = $trechos($bloco['texto']);
                        $primeiro = $b === 0 ? $partes->search(fn ($t) => $t['tipo'] === 'texto') : -1;
                    @endphp
                    @if ($b > 0)<div class="bloco">@endif
                    @if ($bloco['titulo'])
                        @if ($b === 0)<h1>{{ $bloco['titulo'] }}</h1>@else<p class="rotulo">{{ $bloco['titulo'] }}</p>@endif
                    @endif
                    @foreach ($partes as $n => $t)
                        @if ($t['tipo'] === 'tabela')
                            @php $cols = count($t['linhas']->first() ?? []); @endphp
                            <table>
                                <thead><tr>@foreach ($t['linhas']->first() ?? [] as $c)<th>{{ $c }}</th>@endforeach</tr></thead>
                                <tbody>@foreach ($t['linhas']->slice(1) as $l)<tr>@foreach (array_pad($l, $cols, '') as $c)<td>{{ $c }}</td>@endforeach</tr>@endforeach</tbody>
                            </table>
                        @elseif ($t['tipo'] === 'subtitulo')
                            <p class="rotulo" style="margin-top: 4mm">{{ $t['texto'] }}</p>
                        @elseif ($t['tipo'] === 'lista')
                            <ul class="pontos" style="margin-top: 2mm">@foreach ($t['itens'] as $l)<li>{{ $negrito($l) }}</li>@endforeach</ul>
                        @else
                            <p class="{{ $n === $primeiro ? 'intro' : '' }} texto">{{ $t['texto'] }}</p>
                        @endif
                    @endforeach
                    @if ($imgs($bloco['chave']))
                        @include('orcamentos.pdf._figuras', ['imgs' => $imgs($bloco['chave']), 'altura' => $alturaDas($imgs($bloco['chave']), $pag['peso'] + (count($pag['blocos']) - 1) * 150)])
                    @endif
                    @if ($b > 0)</div>@endif
                @endforeach
            </div>
            {{ $rodape($pag['blocos'][0]['titulo'] ?: $tipo) }}
        </section>
    @endforeach

    {{-- 4. Na prática: as imagens soltas, duas por página, cada uma com a sua legenda. As "telas do
         sistema" (lugar do modelo VixHouse) entram aqui também, para nada sumir ao trocar de modelo. --}}
    @foreach (array_chunk([...$imgs('galeria'), ...$imgs('telas')], 2) as $par)
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                <h1>{{ $projeto ? $projeto.' na prática' : 'Na prática' }}</h1>
                @include('orcamentos.pdf._figuras', ['imgs' => $par, 'altura' => $alturaDas($par, 0)])
            </div>
            {{ $rodape('Na prática') }}
        </section>
    @endforeach

    {{-- 5. Escopo do fornecimento: a tabela do que vai, as decisões, e embaixo a imagem ao lado do que
         está e do que não está incluso. --}}
    @php
        $temEspec = $o->temSecao('especificacao');
        $temDecisoes = $o->temSecao('decisoes');
        $temEscopo = $o->linhas('inclui') || $o->linhas('nao_inclui');
        $tituloEscopo = $o->secao('especificacao', 'titulo') ?? 'Escopo do fornecimento';
        $imgsEscopo = $imgs('especificacao');
    @endphp
    @if ($temEspec || $temDecisoes || $temEscopo)
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                <h1>{{ $temEspec || $temEscopo ? $tituloEscopo : ($o->secao('decisoes', 'titulo') ?? 'Decisões técnicas') }}</h1>
                @if ($temEspec)
                    @php $pares = $o->paresDaSecao('especificacao', 'linhas'); $soUma = collect($pares)->every(fn ($p) => trim((string) $p[1]) === ''); @endphp
                    <table>
                        <thead><tr><th style="width: 46mm">Item</th>@unless ($soUma)<th>Especificação</th>@endunless</tr></thead>
                        <tbody>@foreach ($pares as [$a, $b])<tr><td>{{ $a }}</td>@unless ($soUma)<td>{{ $b }}</td>@endunless</tr>@endforeach</tbody>
                    </table>
                @endif
                @if ($temDecisoes)
                    <div class="bloco">
                        @if ($temEspec || $temEscopo)<p class="rotulo">{{ $o->secao('decisoes', 'titulo') ?? 'Decisões técnicas' }}</p>@endif
                        <table class="dados"><tbody>@foreach ($o->paresDaSecao('decisoes', 'linhas') as [$a, $b])<tr><td>{{ $a }}</td><td>{{ $b }}</td></tr>@endforeach</tbody></table>
                    </div>
                @endif
                @if ($temEscopo || $imgsEscopo)
                    <div class="{{ $imgsEscopo && $temEscopo ? 'lado' : '' }}">
                        @if ($imgsEscopo && $temEscopo)
                            @include('orcamentos.pdf._figuras', ['imgs' => [$imgsEscopo[0]], 'altura' => 100])
                        @endif
                        <div>
                            @if ($o->linhas('inclui'))
                                <div class="bloco"><p class="rotulo">O que está incluso</p><ul class="pontos">@foreach ($o->linhas('inclui') as $l)<li>{{ $negrito($l) }}</li>@endforeach</ul></div>
                            @endif
                            @if ($o->linhas('nao_inclui'))
                                <div class="bloco"><p class="rotulo">Não incluso</p><p class="intro" style="margin-bottom: 2.5mm">Qualquer item abaixo pode ser orçado à parte.</p>
                                    <ul class="pontos">@foreach ($o->linhas('nao_inclui') as $l)<li>{{ $negrito($l) }}</li>@endforeach</ul></div>
                            @endif
                        </div>
                    </div>
                    @php $restantes = $temEscopo ? array_slice($imgsEscopo, 1) : $imgsEscopo; @endphp
                    @if ($restantes)
                        @include('orcamentos.pdf._figuras', ['imgs' => $restantes, 'altura' => 90])
                    @endif
                @endif
            </div>
            {{ $rodape($tituloEscopo) }}
        </section>
    @endif

    {{-- 6. Perguntas e respostas, numa página própria. --}}
    @if ($o->temSecao('perguntas'))
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                <h1>Perguntas e respostas</h1>
                <div class="etapas">@foreach ($o->paresDaSecao('perguntas', 'linhas') as $n => [$pergunta, $resposta])
                    <div class="etapa"><i>{{ $dois($n + 1) }}</i><span><b>{{ $pergunta }}</b><br>{{ $resposta }}</span></div>
                @endforeach</div>
            </div>
            {{ $rodape('Perguntas e respostas') }}
        </section>
    @endif

    {{-- 7. Investimento e execução. Material, implantação e recorrente em grupos, cada um com o seu
         subtotal (Felipe, 20/10/2026: "o calcanhar de Aquiles"); os opcionais à parte, fora do total.
         Execução e aceite dividem a página quando cabem; senão, abrem a seguinte. --}}
    @php
        $grupos = collect(['material' => ['Material e equipamentos', $o->total_material, ''], 'implantacao' => ['Implantação e serviços', $o->total_implantacao, ''],
            'recorrente' => ['Recorrente', $o->total_mensal, ' / mês']])
            ->map(fn ($g, $cat) => [...$g, $o->itens->filter(fn ($i) => $i->cobranca !== 'opcao' && $i->categoriaEfetiva() === $cat)])
            ->filter(fn ($g) => $g[3]->isNotEmpty());
        $etapas = $o->temSecao('execucao') && $o->linhasDaSecao('execucao', 'etapas') ? $o->linhasDaSecao('execucao', 'etapas') : $o->linhas('passos');
        $condicao = $o->pagamento_texto ?: $o->condicoes();
        $prazo = $o->temSecao('execucao') ? $o->secao('execucao', 'prazo') : null;
        $linhasTabela = $o->itens->count() + $grupos->count() * 2 + ($opcoes->isNotEmpty() ? 1 : 0);
        $execucaoJunto = $linhasTabela <= 10 && ! $o->planosLista() && count($etapas) <= 4 && $peso($etapas, $condicao, $o->observacoes) < 1100;
        $temExecucao = $etapas || $prazo || $o->observacoes || $o->aceite;
        $execucaoJunto = $execucaoJunto || ! $temExecucao;
        $tituloInvest = $execucaoJunto && $temExecucao ? 'Investimento e execução' : 'Investimento';
    @endphp
    @php $temInvestimento = $grupos->isNotEmpty() || $opcoes->isNotEmpty() || $o->planosLista() || $o->pagamento_texto; @endphp
    @if (! $temInvestimento)
        @php $execucaoJunto = ! $temExecucao; @endphp
    @else
    <section class="pagina">
        {{ $cabecalho() }}
        <div class="conteudo">
            <h1>{{ $tituloInvest }}</h1>
            @if ($grupos->isNotEmpty() || $opcoes->isNotEmpty())
                <table>
                    <colgroup><col><col style="width: 22mm"><col style="width: 30mm"><col style="width: 34mm"></colgroup>
                    <thead><tr><th>Descrição</th><th class="num">Qtd</th><th class="num">Unitário</th><th class="num">Valor</th></tr></thead>
                    <tbody>
                        @foreach ($grupos as [$nomeGrupo, $subtotal, $sufixo, $itens])
                            <tr class="grupo"><td colspan="4">{{ $nomeGrupo }}</td></tr>
                            @foreach ($itens as $i)
                                <tr><td>{{ $i->descricao }}</td><td class="num">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td><td class="num">{{ $brl($i->valor_unitario) }}</td><td class="num">{{ $brl($i->subtotal()) }}{{ $sufixo }}</td></tr>
                            @endforeach
                            @if ($itens->count() > 1)<tr class="subtotal"><td colspan="3">Subtotal · {{ mb_strtolower($nomeGrupo) }}</td><td class="num">{{ $brl($subtotal) }}{{ $sufixo }}</td></tr>@endif
                        @endforeach
                        @if ($opcoes->isNotEmpty())
                            <tr class="grupo"><td colspan="4">Opcionais e faixas · fora do total</td></tr>
                            @foreach ($opcoes as $i)
                                <tr class="opcional"><td>{{ $i->descricao }}@if ($i->categoriaEfetiva() === 'recorrente')<small>mensal</small>@endif</td>
                                    <td class="num">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td><td class="num">{{ $i->valor_unitario > 0 ? $brl($i->valor_unitario) : 'sob consulta' }}</td><td class="num">{{ $i->valor_unitario > 0 ? $brl($i->subtotal()) : '—' }}</td></tr>
                            @endforeach
                        @endif
                    </tbody>
                </table>
            @endif
            @if ($o->total_unico > 0 || $o->total_mensal > 0)
                @php
                    $partesTotal = collect([$o->total_material > 0 ? 'Material '.$brl($o->total_material) : null, $o->total_implantacao > 0 ? 'Implantação '.$brl($o->total_implantacao) : null])->filter();
                @endphp
                @if ($o->total_unico > 0)
                    <div class="total"><div><span>Total do projeto</span>@if ($partesTotal->count() > 1)<small>{{ $partesTotal->implode(' + ') }}</small>@endif</div><b>{{ $brl($o->total_unico) }}</b></div>
                @endif
                @if ($o->total_mensal > 0)
                    <div class="total mensal"><div><span>Recorrente</span><small>a partir da implantação</small></div><b>{{ $brl($o->total_mensal) }} / mês</b></div>
                @endif
            @endif
            @if ($o->planosLista())
                <div class="bloco">
                    <p class="rotulo">Planos</p>
                    <table>
                        <thead><tr><th>Plano</th><th class="num">Valor</th><th>Período</th><th>Observação</th></tr></thead>
                        <tbody>@foreach ($o->planosLista() as $p)<tr><td>{{ $p['nome'] }}</td><td class="num">{{ is_numeric(str_replace(',', '.', $p['valor'])) ? $brl(str_replace(',', '.', $p['valor'])) : $p['valor'] }}</td><td>{{ $p['periodo'] }}</td><td>{{ $p['observacao'] }}</td></tr>@endforeach</tbody>
                    </table>
                </div>
            @endif
            @if ($condicao)
                <div class="chamada"><p class="rotulo">Condição de pagamento</p><p class="texto">{{ $condicao }}</p></div>
            @endif
            @if ($execucaoJunto)
                @include('orcamentos.pdf._execucao')
            @endif
        </div>
        {{ $rodape($o->validoAte() ? 'Proposta válida até '.$o->validoAte()->format('d/m/Y') : $tituloInvest) }}
    </section>
    @endif

    @unless ($execucaoJunto)
        <section class="pagina">
            {{ $cabecalho() }}
            <div class="conteudo">
                <h1>{{ $o->aceite ? 'Execução e aceite' : 'Execução' }}</h1>
                @include('orcamentos.pdf._execucao')
            </div>
            {{ $rodape($o->aceite ? 'Execução e aceite' : 'Execução') }}
        </section>
    @endunless

    @if ($o->temSecao('contracapa'))
        <section class="pagina contracapa">
            <div class="marca">Vix<b>SoftHouse</b><small>Software e identificação</small></div>
            <p class="frase">{{ $o->secao('contracapa', 'frase') }}</p>
            @if ($o->secao('contracapa', 'mensagem'))<p class="mensagem">{{ $o->secao('contracapa', 'mensagem') }}</p>@endif
            @if ($ajustes->linhaDoEmissor())<div class="contato">{{ $ajustes->get('emissor_nome', 'VixSoftHouse') }} · {{ $ajustes->linhaDoEmissor() }}</div>@endif
        </section>
    @endif
</body>
</html>
