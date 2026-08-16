<!DOCTYPE html>
{{--
    O DOCUMENTO dos Relatórios — uma peça só com dois destinos, escolhidos por
    `$modo`: 'previa' abre no navegador com a barra de ações (baixar CSV,
    baixar PDF, imprimir) e é exatamente o que a impressão e o PDF produzem;
    'pdf' é a mesma folha renderizada pelo dompdf, sem a barra.

    Documento de papel, não tela: aqui vale a convenção de documento (fundo
    branco, tinta escura, tabelas com fio), e não os tokens do painel — o
    arquivo circula impresso e anexado, fora do tema. Por isso este arquivo
    está na exceção dos guardiões de tokens e de <x-tabela>.

    Tabelas e nada de flex/grid de propósito: é o subconjunto de CSS que o
    dompdf desenha — e o que o navegador imprime igual. DejaVu Sans no PDF
    porque é a fonte embutida do dompdf com acento; a prévia usa a do sistema.

    Espera: $modo, $relatorio (de `relatorioExportavel()`), $recortes,
    $competencia, $secao, $geradoEm.
--}}
@php
    $ehPdf = $modo === 'pdf';

    // A marca embutida como data URI: o dompdf não busca URL por padrão, e
    // assim prévia, PDF e impressão carregam as mesmas imagens sem depender
    // de rede. O par é o padrão da identidade (sidebar, login): ícone +
    // wordmark. O ícone é o PNG rasterizado do `icon-matriz-solid.svg` —
    // o SVG original usa <mask>, que o dompdf não desenha.
    $icone = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('icon-matriz-solid.png')));
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('alfamatriz.png')));

    // Coluna numérica alinha à direita — decidido pelos DADOS, não pela
    // posição: "Vendedor" no meio fica à esquerda, "Dias" e "Valor" no fim
    // vão para a direita, e uma coluna de datas continua à esquerda.
    $colunasNumericas = function (array $bloco): array {
        $numericas = [];
        foreach (array_keys($bloco['colunas']) as $indice) {
            $temNumero = false;
            $todasNumericas = true;
            foreach ($bloco['linhas'] as $linha) {
                $celula = (string) ($linha[$indice] ?? '');
                if ($celula === '—' || $celula === 'sem registro' || $celula === '') {
                    continue;
                }
                if (preg_match('/^(R\$\s?)?[\d.,]+\s?(%|d|h)?$/u', $celula)) {
                    $temNumero = true;
                } else {
                    $todasNumericas = false;
                    break;
                }
            }
            $numericas[$indice] = $temNumero && $todasNumericas;
        }

        return $numericas;
    };
@endphp
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <title>{{ $relatorio['titulo'] }} — {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y') }}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            {{-- Sem aspas nos nomes de fonte, de propósito: o Blade escapa
                 `{{ }}` e a aspa viraria `&#039;` dentro do CSS — o dompdf
                 caía no fallback serifado. Nome composto sem aspas é CSS
                 válido (sequência de identificadores). --}}
            font-family: {{ $ehPdf ? 'DejaVu Sans, sans-serif' : '-apple-system, Segoe UI, Roboto, Arial, sans-serif' }};
            font-size: {{ $ehPdf ? '10px' : '13.5px' }};
            color: #14232a;
            background: {{ $ehPdf ? '#ffffff' : '#e8edee' }};
        }

        /* A folha: no PDF ela É a página; na prévia, uma folha sobre a mesa. */
        .folha {
            background: #ffffff;
            @if (! $ehPdf)
            max-width: 880px;
            margin: 70px auto 40px;
            padding: 48px 56px 40px;
            box-shadow: 0 1px 3px rgba(11, 26, 30, 0.12), 0 8px 32px rgba(11, 26, 30, 0.10);
            @endif
        }

        /* ---- Cabeçalho do documento ---- */
        .cabecalho { width: 100%; border-collapse: collapse; }
        .cabecalho td { vertical-align: bottom; }
        {{-- As mesmas proporções da sidebar: o ícone quase dobra a altura do
             wordmark (28px × 15px lá; 2.8em × 1.5em aqui). --}}
        .icone { height: 2.8em; width: auto; vertical-align: middle; }
        .logo { height: 1.5em; width: auto; vertical-align: middle; margin-left: 0.55em; }
        .titulo { font-size: 2em; font-weight: bold; margin-top: 0.35em; }
        .meta { text-align: right; font-size: 0.82em; color: #5d6e75; line-height: 1.7; }
        .meta b { color: #14232a; }
        .filete { height: 3px; background: #029caf; margin: 1.1em 0 1.6em; }

        /* ---- Cards de indicadores ---- */
        .kpis { width: 100%; border-collapse: separate; border-spacing: 0.45em; margin: 0 -0.45em; }
        .kpi {
            width: 25%;
            border: 1px solid #d5dfe1;
            border-top: 2px solid #029caf;
            border-radius: 4px;
            padding: 0.7em 0.85em 0.6em;
            vertical-align: top;
        }
        .kpi .rotulo {
            font-size: 0.68em; text-transform: uppercase; letter-spacing: 0.09em;
            color: #5d6e75; margin-bottom: 0.5em;
        }
        .kpi .valor { font-size: 1.45em; font-weight: bold; white-space: nowrap; }
        .kpi-vazio { border: 0; }

        /* ---- Blocos ---- */
        h2 {
            font-size: 1.05em; margin: 1.7em 0 0.6em;
            padding-left: 0.55em; border-left: 3px solid #029caf;
            page-break-after: avoid;
        }
        table.bloco { width: 100%; border-collapse: collapse; }
        .bloco th {
            text-align: left; font-size: 0.72em; text-transform: uppercase; letter-spacing: 0.08em;
            color: #5d6e75; padding: 0.55em 0.8em; border-bottom: 1.5px solid #9db0b6;
        }
        .bloco td { padding: 0.55em 0.8em; border-bottom: 0.5px solid #dde5e7; }
        .bloco tr.zebra td { background: #f4f8f9; }
        .bloco .numero { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        .bloco .vazio { color: #8798a0; }

        /* ---- Rodapé ---- */
        .rodape {
            margin-top: 2.2em; padding-top: 0.8em; border-top: 1px solid #d5dfe1;
            font-size: 0.78em; color: #8798a0;
        }

        @if ($ehPdf)
        /* Número de página no pé de cada folha do PDF. */
        .paginacao { position: fixed; bottom: -22px; right: 0; font-size: 8px; color: #8798a0; }
        .paginacao .n:after { content: counter(page); }
        @else
        /* ---- Barra de ações da prévia — some na impressão ---- */
        .barra {
            position: fixed; top: 0; left: 0; right: 0; z-index: 10;
            background: #0a1215; color: #e6eef0;
            padding: 10px 24px;
        }
        .barra table { width: 100%; border-collapse: collapse; }
        .barra .dica { font-size: 12px; color: #9db0b6; }
        .barra a, .barra button {
            display: inline-block; margin-left: 8px; padding: 7px 14px;
            border: 1px solid rgba(255, 255, 255, 0.18); border-radius: 6px;
            background: transparent; color: #e6eef0;
            font: inherit; font-size: 12.5px; font-weight: 600;
            text-decoration: none; cursor: pointer;
        }
        .barra a:hover, .barra button:hover { border-color: #26d4e6; color: #5be3ef; }
        .barra .primario { background: #029caf; border-color: #029caf; color: #04181b; }
        .barra .primario:hover { background: #26d4e6; color: #04181b; }

        @media print {
            body { background: #ffffff; font-size: 10.5pt; }
            .barra { display: none; }
            .folha { max-width: none; margin: 0; padding: 0; box-shadow: none; }
        }
        @endif
    </style>
</head>
<body>
    @unless ($ehPdf)
        <div class="barra">
            <table>
                <tr>
                    <td class="dica">Prévia do arquivo — o PDF e a impressão saem exatamente assim; o CSV leva os mesmos dados em planilha.</td>
                    <td style="text-align: right; white-space: nowrap">
                        <a href="{{ route('relatorios.index', array_filter(request()->except(['imprimir', 'formato']))) }}">Voltar ao painel</a>
                        <a href="{{ route('relatorios.exportar', array_merge(request()->except('imprimir'), ['secao' => $secao, 'formato' => 'csv'])) }}">Baixar CSV</a>
                        <a href="{{ route('relatorios.exportar', array_merge(request()->except('imprimir'), ['secao' => $secao, 'formato' => 'pdf'])) }}">Baixar PDF</a>
                        <button type="button" class="primario" onclick="window.print()">Imprimir</button>
                    </td>
                </tr>
            </table>
        </div>
    @endunless

    <div class="folha">
        <table class="cabecalho">
            <tr>
                <td>
                    <div><img class="icone" src="{{ $icone }}" alt=""><img class="logo" src="{{ $logo }}" alt="AlfaMatriz"></div>
                    <div class="titulo">{{ $relatorio['titulo'] }}</div>
                </td>
                <td class="meta">
                    Competência <b>{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y') }}</b><br>
                    @if ($recortes)
                        Recorte: <b>{{ collect($recortes)->pluck('rotulo')->implode(' · ') }}</b><br>
                    @endif
                    Gerado em {{ $geradoEm->format('d/m/Y H:i') }}
                </td>
            </tr>
        </table>
        <div class="filete"></div>

        {{-- Os indicadores como cards, quatro por linha — o resumo executivo
             antes das tabelas, como na tela. --}}
        <table class="kpis">
            @foreach (collect($relatorio['kpis'])->chunk(4) as $linhaDeKpis)
                <tr>
                    @foreach ($linhaDeKpis as $kpi)
                        <td class="kpi">
                            <div class="rotulo">{{ $kpi[0] }}</div>
                            <div class="valor">{{ $kpi[1] }}</div>
                        </td>
                    @endforeach
                    @for ($vaga = $linhaDeKpis->count(); $vaga < 4; $vaga++)
                        <td class="kpi-vazio"></td>
                    @endfor
                </tr>
            @endforeach
        </table>

        @foreach ($relatorio['blocos'] as $bloco)
            @php $numericas = $colunasNumericas($bloco); @endphp
            <h2>{{ $bloco['titulo'] }}</h2>
            <table class="bloco">
                <thead>
                    <tr>
                        @foreach ($bloco['colunas'] as $indice => $coluna)
                            <th @class(['numero' => $numericas[$indice]])>{{ $coluna }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($bloco['linhas'] as $linha)
                        <tr @class(['zebra' => $loop->even])>
                            @foreach ($linha as $indice => $celula)
                                <td @class(['numero' => $numericas[$indice] ?? false])>{{ $celula }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($bloco['colunas']) }}" class="vazio">Nada neste recorte.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach

        <p class="rodape">Gerado pelo AlfaMatriz em {{ $geradoEm->format('d/m/Y H:i') }} · {{ $relatorio['titulo'] }} · competência {{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $competencia)->format('m/Y') }}</p>
    </div>

    @if ($ehPdf)
        <div class="paginacao">Página <span class="n"></span></div>
    @else
        @if (request()->boolean('imprimir'))
            {{-- O botão "Imprimir" do painel abre esta prévia já imprimindo:
                 imprimir a tela escura direto sairia um borrão de toner. --}}
            <script>window.addEventListener('load', () => window.print());</script>
        @endif
    @endif
</body>
</html>
