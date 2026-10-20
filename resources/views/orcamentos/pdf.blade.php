@php
    /** @var \App\Models\Orcamento $o */
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $unicos = $o->itens->where('cobranca', 'unico');
    $mensais = $o->itens->where('cobranca', 'mensal');
    $numero = $o->numero ? 'Nº '.$o->codigo(false).' · Rev. '.str_pad((string) $o->revisao, 2, '0', STR_PAD_LEFT) : 'Rascunho';
    $cliente = $o->cliente ?: 'Cliente';
    $produto = \App\Services\Orcamentos\Modelos::nome((string) $o->produto);
    $data = $o->data()->locale('pt_BR');
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
</style>
</head>
<body>
    {{-- 1. Capa --}}
    <section class="pagina capa">
        <div class="marca">Vix<b>SoftHouse</b></div>
        <div class="bloco">
            <div class="sobre">Proposta comercial · {{ $numero }}</div>
            <p class="titulo">{{ $o->titulo ?: 'Proposta' }}@if ($o->destaque)<b>{{ $o->destaque }}</b>@endif</p>
            <div class="traco"></div>
            @if ($o->lead)<p class="lead">{{ $o->lead }}</p>@endif
        </div>
        <div class="rodape-capa">
            <div><span>Cliente</span>{{ $cliente }}@if ($o->contato)<br>{{ $o->contato }}@endif</div>
            <div><span>Produto</span>{{ $produto }}</div>
            <div><span>Data</span>{{ $data->translatedFormat('j \d\e F \d\e Y') }}</div>
            @if ($o->cidade)<div><span>Local</span>{{ $o->cidade }}</div>@endif
        </div>
    </section>

    {{-- 2. Investimento --}}
    <section class="pagina">
        <div class="cab"><b>Proposta comercial</b><span>{{ $cliente }} · <span class="marca">Vix<b>SoftHouse</b></span></span></div>
        <h1>Investimento</h1>
        @if ($unicos->isNotEmpty())
            <table>
                <colgroup><col><col style="width: 24mm"><col style="width: 32mm"><col style="width: 36mm"></colgroup>
                <thead><tr><th>Item</th><th class="num">Qtd</th><th class="num">Unitário</th><th class="num">Valor</th></tr></thead>
                <tbody>
                    @foreach ($unicos as $i)
                        <tr><td>{{ $i->descricao }}</td><td class="num">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td><td class="num">{{ $brl($i->valor_unitario) }}</td><td class="num">{{ $brl($i->subtotal()) }}</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="total"><span>Total do projeto</span><b>{{ $brl($o->total_unico) }}</b></div>
        @endif
        @if ($mensais->isNotEmpty())
            <h2>Mensalidade</h2>
            <table>
                <colgroup><col><col style="width: 24mm"><col style="width: 32mm"><col style="width: 36mm"></colgroup>
                <tbody>
                    @foreach ($mensais as $i)
                        <tr><td>{{ $i->descricao }}</td><td class="num">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td><td class="num">{{ $brl($i->valor_unitario) }}</td><td class="num">{{ $brl($i->subtotal()) }}/mês</td></tr>
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
            @if ($o->total_mensal > 0)
                <div class="cartao"><span>Mensalidade</span><b>{{ $brl($o->total_mensal) }}</b><p>a partir da instalação</p></div>
            @endif
        </div>
        @if ($o->condicoes())<p class="condicoes">{{ $o->condicoes() }}</p>@endif
        <div class="rodape empurra"><span>Proposta válida até {{ $o->validoAte()->format('d/m/Y') }}</span><span>VixSoftHouse</span></div>
    </section>

    {{-- 3. Escopo, próximos passos e aceite --}}
    <section class="pagina">
        <div class="cab"><b>Proposta comercial</b><span>{{ $cliente }} · <span class="marca">Vix<b>SoftHouse</b></span></span></div>
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
        @if ($o->observacoes)<div class="obs">{{ $o->observacoes }}</div>@endif
        @if ($o->aceite)
            <div class="aceite">
                <div>De acordo — {{ $cliente }}<br>Data: ____/____/________</div>
                <div>VixSoftHouse<br>{{ $o->autor?->name ?? 'Responsável comercial' }}</div>
            </div>
        @endif
        <div class="rodape {{ $o->aceite ? '' : 'empurra' }}"><span>VixSoftHouse · Vitória/ES · (27) 3335-4950 · comercial@vixcard.com.br</span><span>VixSoftHouse</span></div>
    </section>
</body>
</html>
