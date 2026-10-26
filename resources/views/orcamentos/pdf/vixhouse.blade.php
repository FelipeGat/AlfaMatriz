@php
    /**
     * O modelo VixHouse do PDF (docs/specs/orcamentos-vixcard/modelo-vixhouse.md). Felipe, 25/10/2026,
     * depois da proposta conceito do Gestor de Eventos para a Inove: "gere na sua memória sempre usar esse
     * mesmo modelo de proposta para TODOS os clientes que for sistema [...] e deixe ele disponível para
     * geração do pdf quando eu for imprimir um orçamento da VixTrace, ou Vix-ID".
     *
     * O desenho é o daquela proposta (fonte em C:\tmp\gustavo_conceito): A4 em pé, capa marinho com a
     * marca, uma página por assunto com cabeçalho e rodapé fixos, a dor antes da solução, telas com o selo
     * de tela ilustrativa, investimento com a forma de pagamento ao lado de cada valor, e o parecer do
     * cliente no fim, assinado só como Diretor Comercial. O conteúdo vem dos mesmos campos do clássico:
     * trocar de modelo nunca perde nada.
     *
     * @var \App\Models\Orcamento $o
     * @var array<string, list<array{src: string, legenda: string, retrato: bool}>> $imagens
     * @var array{clara: string, escura: string} $marca
     */
    $brl = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $dois = fn (int $n) => str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    $pct = fn (float $v) => rtrim(rtrim(number_format($v, 2, ',', ''), '0'), ',').'%';
    $cliente = $o->cliente ?: 'Cliente';
    $clienteCurto = \Illuminate\Support\Str::before($cliente, ' · ');
    $produto = \App\Services\Orcamentos\Modelos::nome((string) $o->produto);
    // O nome do sistema no cabeçalho e nos títulos: o da 3ª coluna da capa quando ela é "Sistema" ou "Produto"
    // ("Gestor de Eventos"), senão o produto do modelo, senão o projeto do pré-cliente (VixTrace entra como "Outro").
    $rotulo3 = mb_strtolower(trim((string) $o->rotulo_3));
    $projeto = (in_array($rotulo3, ['sistema', 'produto', 'solução', 'solucao'], true) && filled($o->valor_3) ? trim((string) $o->valor_3) : null)
        ?? ($o->produto !== 'outro' ? $produto : null) ?? $o->preCliente?->sistema?->nome;
    $tipo = $o->tipo ?: 'Proposta comercial';
    $numero = $o->numero ? 'Orçamento '.$o->codigo() : 'Rascunho';
    $data = $o->data()->locale('pt_BR');
    $dc = (array) $o->dados_cliente;
    $imagens ??= [];
    $imgs = fn (string $onde) => $imagens[$onde] ?? [];
    $informado = fn ($v) => filled($v) && ! preg_match('/a informar/iu', (string) $v);
    $contatoEmissor = collect([$ajustes->get('emissor_telefone'), $ajustes->get('emissor_email')])->filter()->implode(' · ');
    $rodapePadrao = $o->validoAte() ? $numero.' · proposta válida até '.$o->validoAte()->format('d/m/Y') : $numero;

    // "Título: descrição" (ou "Título. descrição", "Título — descrição") vira negrito + texto.
    $partes = function (string $t): array {
        if (preg_match('/^(.{3,80}?)(:|\.|\s[—–-])\s+(\S.*)$/u', $t, $m) && ! preg_match('/\d$/', $m[1])) {
            return [$m[1], $m[3]];
        }

        return [$t, ''];
    };
    $negrito = function (string $t) use ($partes): \Illuminate\Support\HtmlString {
        [$a, $b] = $partes($t);

        return new \Illuminate\Support\HtmlString($b === '' ? e($a) : '<b>'.e($a).':</b> '.e($b));
    };
    $semNumero = fn (string $l) => preg_replace('/^\d{1,2}[.)]\s+/u', '', $l);
    $peso = fn (...$p) => collect($p)->flatten()->sum(fn ($t) => mb_strlen((string) $t));

    // Texto livre: "| a | b |" vira tabela (1ª linha é o cabeçalho), "- x" vira lista, linha curta em
    // maiúsculas vira subtítulo, o resto é parágrafo — a mesma leitura do modelo clássico.
    $trechos = function (string $texto) {
        $tipoDa = fn ($x) => str_starts_with(trim($x), '|') ? 't' : (preg_match('/^\s*[-•*›]\s+/u', $x) ? 'l' : (trim($x) === '' ? 'v'
            : (mb_strlen(trim($x)) <= 70 && preg_match('/\p{Lu}{3}/u', $x) && mb_strtoupper(trim($x)) === trim($x) ? 's' : 'p')));

        return collect(preg_split('/\R/', $texto))
            ->chunkWhile(fn ($l, $k, $c) => $tipoDa($l) === $tipoDa($c->last()) && $tipoDa($l) !== 's')
            ->map(function ($c) use ($tipoDa) {
                $p = trim($c->first());

                return match ($tipoDa($c->first())) {
                    't' => ['tipo' => 'tabela', 'linhas' => $c->map(fn ($l) => array_map('trim', explode('|', trim(trim($l), '|'))))
                        ->reject(fn ($cel) => collect($cel)->every(fn ($x) => preg_match('/^:?-{2,}:?$/', $x) || $x === ''))->values()],
                    'l' => ['tipo' => 'lista', 'itens' => $c->map(fn ($l) => preg_replace('/^\s*[-•*›]\s+/u', '', trim($l)))->filter()->values()],
                    's' => ['tipo' => 'subtitulo', 'texto' => $p],
                    default => ['tipo' => 'texto', 'texto' => trim($c->implode("\n"))],
                };
            })
            ->reject(fn ($t) => $t['tipo'] === 'texto' && $t['texto'] === '')->values();
    };

    // Imagens em linhas: deitada ocupa a linha; em pé (celular, folheto) vão até três lado a lado.
    $linhasDe = function (array $lista): array {
        $linhas = [];
        foreach ($lista as $i) {
            $ultima = $linhas ? count($linhas) - 1 : null;
            if ($i['retrato'] && $ultima !== null && $linhas[$ultima][0]['retrato'] && count($linhas[$ultima]) < 3) {
                $linhas[$ultima][] = $i;
            } else {
                $linhas[] = [$i];
            }
        }

        return $linhas;
    };

    /* ---------------------------------------------------------------- o que entra, e em que página */
    $dadosCliente = collect([
        ['Razão social', data_get($dc, 'razao_social')], ['CNPJ', data_get($dc, 'cnpj')], ['Inscrição estadual', data_get($dc, 'ie')],
        ['Atividade', data_get($dc, 'atividade')], ['Endereço', data_get($dc, 'endereco')],
        ['Contato', collect([$o->contato, data_get($dc, 'cargo')])->filter()->implode(' · ')],
        ['Telefone e e-mail', collect([data_get($dc, 'telefone'), data_get($dc, 'email')])->filter()->implode(' · ')],
        ['Cidade', $o->cidade], ['Natureza', $o->natureza ? (\App\Models\Orcamento::NATUREZAS[$o->natureza] ?? $o->natureza) : null],
    ])->filter(fn ($l) => trim((string) $l[1]) !== '');
    $temDesafio = $o->temSecao('desafio');
    $temSolucao = $o->temSecao('solucao');

    // Os pontos do desafio: "a | b | c | d" viram a tabela da dor (como no diagnóstico da Inove); uma
    // primeira linha "# Ponto | O que vimos | …" troca os títulos das colunas.
    $linhasDor = $o->linhasDaSecao('desafio', 'itens');
    $cabDor = null;
    if ($linhasDor && str_starts_with($linhasDor[0], '#')) {
        $cabDor = array_map('trim', explode('|', ltrim(array_shift($linhasDor), '# ')));
    }
    $colunasDor = $linhasDor ? collect($linhasDor)->map(fn ($l) => substr_count($l, '|') + 1)->max() : 1;
    $tabelaDor = $colunasDor >= 2 && collect($linhasDor)->every(fn ($l) => str_contains($l, '|'));
    $resolve = $projeto ? 'Como o '.$projeto.' resolve' : 'Como resolvemos';
    $cabDor ??= match (true) {
        $colunasDor >= 4 => ['Ponto', 'O que vimos', 'O que isso pode causar', $resolve],
        $colunasDor === 3 => ['Ponto', 'Como é hoje', $resolve],
        default => ['Como é hoje', $resolve],
    };

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

    // Telas (com selo) e fotos: duas linhas por página, para a folha não sobrar em branco. A imagem do escopo
    // (e a do desafio, quando ele tem a tabela da dor) vai para as fotos: junto do texto, empurrava a página
    // para uma folha a mais.
    $fotosDoDesafio = $tabelaDor && $linhasDor ? $imgs('desafio') : [];
    $paginasTelas = array_chunk($linhasDe($imgs('telas')), 2);
    $paginasFotos = array_chunk($linhasDe([...$imgs('galeria'), ...$fotosDoDesafio, ...$imgs('especificacao')]), 2);

    $temEspec = $o->temSecao('especificacao');
    $temDecisoes = $o->temSecao('decisoes');
    $temEscopo = $o->linhas('inclui') || $o->linhas('nao_inclui') || $temEspec;
    // Altura aproximada, em px, de cada bloco do escopo — para as decisões abrirem página própria e o lembrete
    // do investimento só entrar quando couber. A folha útil tem uns 940 px.
    $linhasTabela = fn (array $pares, int $porLinha) => collect($pares)->sum(fn ($p) => 22 + 15 * max(1, (int) ceil(mb_strlen((string) $p[1]) / $porLinha)));
    $alturaEscopo = 90 + ($o->linhas('inclui') ? 50 + (int) ceil(count($o->linhas('inclui')) / 2) * 46 : 0)
        + ($temEspec ? 44 + $linhasTabela($o->paresDaSecao('especificacao', 'linhas'), 80) : 0)
        + ($o->linhas('nao_inclui') ? 64 + (int) ceil(count($o->linhas('nao_inclui')) / 2) * 42 : 0);
    $alturaDecisoes = $temDecisoes ? 70 + $linhasTabela($o->paresDaSecao('decisoes', 'linhas'), 70) : 0;
    $decisoesJunto = $temDecisoes && $temEscopo && $alturaEscopo + $alturaDecisoes <= 900;
    $temEscopo = $temEscopo || $decisoesJunto;
    $decisoesSozinhas = $temDecisoes && ! $decisoesJunto;

    $opcoes = $o->itens->where('cobranca', 'opcao');
    $grupos = collect(['material' => ['Material e equipamentos', $o->total_material, ''], 'implantacao' => ['Implantação e serviços', $o->total_implantacao, ''],
        'recorrente' => ['Recorrente', $o->total_mensal, '/mês']])
        ->map(fn ($g, $cat) => [...$g, $o->itens->filter(fn ($i) => $i->cobranca !== 'opcao' && $i->categoriaEfetiva() === $cat)])
        ->filter(fn ($g) => $g[3]->isNotEmpty());
    $planos = $o->planosLista();
    $condicao = $o->pagamento_texto ?: $o->condicoes();
    $temInvest = $grupos->isNotEmpty() || $opcoes->isNotEmpty() || $planos || $condicao;

    $etapas = $o->temSecao('execucao') && $o->linhasDaSecao('execucao', 'etapas') ? $o->linhasDaSecao('execucao', 'etapas') : $o->linhas('passos');
    $prazo = $o->temSecao('execucao') ? $o->secao('execucao', 'prazo') : null;
    $temPassos = $etapas || $prazo || $o->observacoes || $o->aceite;
    // Investimento curto e sem parecer: os passos cabem embaixo dele, sem abrir uma página quase vazia.
    $passosJunto = $temPassos && $temInvest && ! $o->aceite && ! $planos && $o->itens->count() <= 5 && count($etapas) <= 4;

    // A solução divide a folha com o cliente e o desafio quando as duas cabem (altura aproximada em px; a folha
    // útil tem uns 900): sem isso, uma página de quatro cartões ficava com metade em branco.
    $temCliente = $dadosCliente->isNotEmpty() || $o->linhas('objeto') || $temDesafio;
    $linhasDe2 = fn (string $t, int $porLinha) => (int) ceil(mb_strlen($t) / $porLinha);
    $alturaCliente = 110 + 19 * $linhasDe2((string) $o->secao('desafio', 'texto'), 115)
        + ($dadosCliente->isNotEmpty() ? 34 + (int) ceil($dadosCliente->count() / 2) * 46 : 0)
        + ($o->linhas('objeto') ? 46 + count($o->linhas('objeto')) * 34 : 0)
        + ($o->secao('desafio', 'citacao') ? 60 : 0) + ($o->paresDaSecao('desafio', 'numeros') ? 100 : 0)
        + ($linhasDor ? ($tabelaDor ? 50 + collect($linhasDor)->sum(fn ($l) => 34 + 17 * max(array_map(fn ($c) => $linhasDe2($c, 26), explode('|', $l))))
            : 14 + collect($linhasDor)->sum(fn ($l) => 16 + 18 * $linhasDe2($l, 105))) : 0)
        + ($imgs('desafio') && ! ($tabelaDor && $linhasDor) ? 340 : 0);
    $passosSolucao = $fluxo = $o->linhasDaSecao('solucao', 'passos');
    $colunas = in_array(count($fluxo), [2, 4], true) ? 2 : 3;
    $alturaSolucao = 70 + 19 * $linhasDe2((string) $o->secao('solucao', 'texto'), 115)
        + (int) ceil(count($passosSolucao) / (in_array(count($passosSolucao), [2, 4], true) ? 2 : 3)) * 134;
    $solucaoJunto = $temSolucao && $temCliente && ! $imgs('solucao') && count($imgs('telas')) < 3 && $alturaCliente + $alturaSolucao <= 880;

    $ordem = array_merge(['capa'],
        $temCliente ? ['cliente'] : [],
        $temSolucao && ! $solucaoJunto ? ['solucao'] : [],
        array_map(fn ($k) => 'livre:'.$k, array_keys($paginasLivres)),
        array_map(fn ($k) => 'telas:'.$k, array_keys($paginasTelas)),
        array_map(fn ($k) => 'fotos:'.$k, array_keys($paginasFotos)),
        $temEscopo ? ['escopo'] : [],
        $decisoesSozinhas ? ['decisoes'] : [],
        $o->temSecao('perguntas') ? ['perguntas'] : [],
        $temInvest ? ['investimento'] : [],
        $temPassos && ! $passosJunto ? ['passos'] : [],
        $o->temSecao('contracapa') ? ['contracapa'] : []);
    $total = count($ordem);
    $pag = fn (string $chave) => array_search($chave, $ordem, true) + 1;

    // O índice das telas (como "As telas desta prévia" da Inove): o título de cada uma e a página onde está.
    $indiceTelas = [];
    foreach ($paginasTelas as $k => $linhas) {
        foreach ($linhas as $linha) {
            foreach ($linha as $img) {
                $indiceTelas[] = ['titulo' => $partes($img['legenda'])[0] ?: 'Tela '.(count($indiceTelas) + 1), 'pag' => $pag('telas:'.$k)];
            }
        }
    }

    /* ---------------------------------------------------------------- moldura das páginas */
    $topo = fn (string $secao) => new \Illuminate\Support\HtmlString('<div class="faixa"></div><div class="topo"><img src="'.e($marca['escura']).'" alt="VixHouse"><div class="r">'
        .e($tipo).($projeto ? ' · '.e($projeto) : '').' · <b>'.e($secao).'</b></div></div>');
    $curta = fn (string $t) => \Illuminate\Support\Str::limit(\Illuminate\Support\Str::before($t, ' — '), 34);
    $rod = fn (string $chave, ?string $txt = null) => new \Illuminate\Support\HtmlString('<div class="rod"><span>VixHouse · Soluções em tecnologia · '.e($txt ?? $rodapePadrao)
        .'</span><span class="n">'.$dois($pag($chave)).' / '.$dois($total).'</span></div>');
    $cab = fn (string $eyebrow, string $titulo, ?string $lead = null, bool $selo = false) => new \Illuminate\Support\HtmlString('<div class="cab"><div><div class="eyebrow">'.e($eyebrow)
        .'</div><h2>'.e($titulo).'</h2>'.($lead ? '<p class="lead">'.nl2br(e($lead)).'</p>' : '').'</div>'
        .($selo ? '<span class="selo"><b>●</b> Tela ilustrativa · dados fictícios</span>' : '').'</div>');

    // A forma de pagamento ao lado de cada valor (jeito de fazer das propostas VixCard).
    $comoPaga = function ($i) use ($o, $brl, $pct) {
        if ($i->cobranca === 'opcao') {
            return $i->valor_unitario > 0 ? 'Opcional' : 'Sob consulta';
        }
        if ($i->categoriaEfetiva() === 'recorrente') {
            return 'Mensal';
        }
        if ($o->parcelas <= 1) {
            // A condição escrita só no texto livre ("entrada de 30% e saldo em 6x"): "à vista" seria falso.
            if ($o->pagamento_texto) {
                return 'Conforme a condição abaixo';
            }


            return 'À vista'.($o->desconto_avista > 0 ? ', com '.$pct($o->desconto_avista).' de desconto' : '');
        }
        if ($o->entrada > 0 && $o->total_unico > 0) {
            return 'Entrada de '.$pct(round($o->entrada / $o->total_unico * 100, 1)).' e saldo em '.$o->parcelas.'x sem juros';
        }

        return 'Em até '.$o->parcelas.'x de '.$brl($i->subtotal() / $o->parcelas).' sem juros';
    };
    $valorPlano = fn (string $v) => is_numeric(str_replace(',', '.', $v)) ? $brl(str_replace(',', '.', $v)) : $v;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>{{ $tipo }} {{ $numero }} — {{ $cliente }} · VixHouse</title>
<style>
@font-face { font-family: Inter; src: url('{{ $fontes }}/inter-latin.woff2') format('woff2'); font-weight: 100 900;
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD }
@font-face { font-family: Inter; src: url('{{ $fontes }}/inter-latin-ext.woff2') format('woff2'); font-weight: 100 900;
  unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+1E00-1E9F, U+20A0-20AB, U+20AD-20C4, U+2113 }
@font-face { font-family: Jakarta; src: url('{{ $fontes }}/jakarta-latin.woff2') format('woff2'); font-weight: 200 800;
  unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD }
@font-face { font-family: Jakarta; src: url('{{ $fontes }}/jakarta-latin-ext.woff2') format('woff2'); font-weight: 200 800;
  unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+1E00-1E9F, U+20A0-20AB, U+20AD-20C4, U+2113 }
:root { --marinho: #061326; --tinta: #101d35; --azul: #0866f5; --vibrante: #008cff; --ciano: #00c8f8; --nevoa: #f4f7fc; --linha: #e2e8f2;
  --cinza: #66758d; --cinza-forte: #5a687f; --claro: #a9b6cc; --grad: linear-gradient(90deg, #00c8f8 0%, #0866f5 55%, #0652c6 100%) }
@page { size: 210mm 297mm; margin: 0 }
* { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact }
html, body { margin: 0; padding: 0 }
body { font-family: Inter, 'Segoe UI', sans-serif; color: var(--tinta); text-rendering: geometricPrecision; background: {{ $previa ? '#d9e0e7' : '#fff' }} }
h1, h2, h3, h4 { font-family: Jakarta, Inter, sans-serif }
b, strong { color: var(--marinho) }
/* A página tem a altura da folha; o rodapé vem no fluxo, no fim, para nada se perder se o conteúdo passar. */
.pg { width: 210mm; min-height: 297mm; position: relative; background: #fff; padding: 0 40px 12px; display: flex; flex-direction: column;
  page-break-after: always; break-after: page }
.pg:last-child { page-break-after: auto; break-after: auto }
@if ($previa) .pg { margin: 0 auto 8mm; box-shadow: 0 2px 10px rgba(10, 30, 60, .18) } body { padding: 8mm 0 } @endif
.faixa { position: absolute; left: 0; right: 0; top: 0; height: 4px; background: var(--grad) }
.topo { height: 54px; flex: none; display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--linha) }
.topo img { height: 25px; display: block }
.topo .r { font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; color: var(--cinza); font-weight: 600; text-align: right; max-width: 470px }
.topo .r b { color: var(--azul) }
.rod { margin-top: auto; padding-top: 6px; border-top: 1px solid var(--linha); display: flex; justify-content: space-between; align-items: center; font-size: 9px; color: #8a97ad; gap: 16px }
.rod .n { font-family: Jakarta; font-weight: 700; color: var(--azul); font-size: 10px; white-space: nowrap }
.corpo { flex: none; padding-bottom: 22px }
.cab { padding-top: 18px; position: relative }
.cab > div { padding-right: 0 }
.eyebrow { font-size: 10px; font-weight: 700; letter-spacing: .17em; text-transform: uppercase; color: var(--azul) }
h2 { font-size: 25px; font-weight: 700; margin: 3px 0 4px; color: var(--marinho); letter-spacing: -.015em; line-height: 1.15 }
.lead { font-size: 12.5px; color: var(--cinza-forte); margin: 0; line-height: 1.5; max-width: 690px }
.selo { position: absolute; right: 0; top: 13px; font-size: 9.5px; color: var(--cinza); border: 1px solid var(--linha); border-radius: 999px; padding: 4px 11px; background: var(--nevoa); white-space: nowrap }
.selo b { color: var(--azul) }
.h3 { font-size: 19px; font-weight: 700; color: var(--marinho); margin: 4px 0 4px; letter-spacing: -.01em }
.sub { font-family: Jakarta; font-size: 10px; letter-spacing: .16em; text-transform: uppercase; color: var(--cinza); font-weight: 700; margin: 24px 0 0 }
.isec { font-family: Jakarta; font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: var(--azul); font-weight: 700; margin-top: 26px; padding-top: 14px; border-top: 2px solid var(--marinho) }
p { margin: 0 0 8px }

/* Cliente e desafio */
.cli { margin-top: 16px; display: grid; grid-template-columns: 1fr 1fr; gap: 0 26px; background: var(--nevoa); border: 1px solid var(--linha); border-radius: 12px; padding: 12px 18px }
.cli div { padding: 7px 0; border-bottom: 1px dashed #dfe6f0; font-size: 11.5px; line-height: 1.4; color: var(--tinta) }
.cli div span { display: block; font-size: 8.8px; letter-spacing: .14em; text-transform: uppercase; color: var(--cinza); font-weight: 700; margin-bottom: 1px }
.cli div.l { grid-column: 1 / 3 }
.citacao { margin: 16px 0 0; padding: 4px 0 4px 16px; border-left: 4px solid var(--azul); font-size: 14px; font-style: italic; color: var(--marinho); line-height: 1.5 }
.numeros { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-top: 16px }
.numeros div { border: 1px solid var(--linha); border-radius: 12px; padding: 14px 16px; background: #fff; position: relative; overflow: hidden }
.numeros div:before { content: ""; position: absolute; left: 0; top: 0; right: 0; height: 3px; background: var(--azul) }
.numeros b { display: block; font-family: Jakarta; font-size: 24px; font-weight: 800; color: var(--marinho) }
.numeros span { font-size: 11px; color: var(--cinza-forte); line-height: 1.4 }
table.dor { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 18px; font-size: 11.6px; line-height: 1.5 }
table.dor th { font-family: Jakarta; font-size: 8.8px; letter-spacing: .12em; text-transform: uppercase; color: #fff; background: var(--marinho); padding: 10px; line-height: 1.35; vertical-align: bottom; text-align: left; font-weight: 700 }
table.dor th:first-child { border-radius: 9px 0 0 0 }
table.dor th:last-child { border-radius: 0 9px 0 0; background: var(--azul) }
table.dor td { padding: 16px 11px; border-bottom: 1px solid var(--linha); vertical-align: top; color: #33425a }
table.dor td:first-child { font-family: Jakarta; font-weight: 700; color: var(--marinho); font-size: 12.4px; width: 150px }
table.dor td:last-child { background: #f5f9ff; color: #13306a; font-weight: 500 }
ul.pontos { list-style: none; margin: 14px 0 0; padding: 0 }
ul.pontos li { font-size: 12px; line-height: 1.5; color: #3c4a62; padding: 7px 0 7px 18px; position: relative; border-bottom: 1px dashed #e7ecf4 }
ul.pontos li:before { content: ""; position: absolute; left: 0; top: 13px; width: 7px; height: 7px; border-radius: 2px; background: var(--azul) }
.objeto { margin-top: 16px; border: 1px solid var(--linha); border-radius: 12px; padding: 12px 18px }
.objeto h4, .box h4 { font-family: Jakarta; font-size: 11px; letter-spacing: .14em; text-transform: uppercase; margin: 0 0 6px; color: var(--azul) }
.etapa { display: flex; gap: 14px; padding: 7px 0; border-bottom: 1px solid var(--linha); font-size: 12px; line-height: 1.45; color: #3c4a62 }
.etapa:last-child { border-bottom: 0 }
.etapa i { font-style: normal; flex: 0 0 22px; font-family: Jakarta; font-weight: 800; color: var(--vibrante) }

/* A solução: o fluxo em cartões numerados */
.fluxo { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px 0; margin-top: 22px }
.fluxo.c2 { grid-template-columns: repeat(2, 1fr) }
.fluxo .p { position: relative; padding: 14px 18px 14px 16px; background: var(--nevoa); border-top: 3px solid var(--azul); min-height: 118px; margin-right: 12px; border-radius: 0 0 10px 10px }
.fluxo .p .k { font-family: Jakarta; font-weight: 800; font-size: 22px; color: var(--vibrante) }
.fluxo .p b { display: block; font-family: Jakarta; font-size: 13px; color: var(--marinho); margin: 2px 0 4px }
.fluxo .p span { font-size: 11px; color: var(--cinza-forte); line-height: 1.42; display: block }
.fluxo .p:after { content: ""; position: absolute; right: -12px; top: 42px; border: 7px solid transparent; border-left: 8px solid var(--azul); z-index: 2 }
.fluxo.c3 .p:nth-child(3n), .fluxo.c2 .p:nth-child(2n) { margin-right: 0 }
.fluxo.c3 .p:nth-child(3n):after, .fluxo.c2 .p:nth-child(2n):after, .fluxo .p:last-child:after { display: none }

.mapa { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 10px }
.mapa .m { display: flex; gap: 10px; align-items: center; border: 1px solid var(--linha); border-radius: 10px; padding: 10px 12px; background: #fff; min-height: 52px }
.mapa .m .ic { width: 30px; height: 30px; border-radius: 8px; background: #0c1e36; color: #fff; display: grid; place-items: center; flex: none; font: 700 11px/1 Jakarta, sans-serif }
.mapa .m b { font-family: Jakarta; font-size: 11.5px; color: var(--marinho); line-height: 1.3 }
.mapa .m em { margin-left: auto; font-style: normal; font-family: Jakarta; font-weight: 700; font-size: 10px; color: var(--azul); white-space: nowrap }

/* Texto livre */
.txt { margin-top: 14px; font-size: 12.2px; line-height: 1.6; color: #33425a }
.txt .bl { margin-top: 18px }
.txt h3 { font-size: 15px; color: var(--marinho); margin: 0 0 6px }
.txt p { white-space: pre-line }
.txt ul { list-style: none; margin: 4px 0 10px; padding: 0 }
.txt li { padding: 4px 0 4px 17px; position: relative }
.txt li:before { content: ""; position: absolute; left: 0; top: 11px; width: 7px; height: 7px; border-radius: 2px; background: var(--azul) }
table.res { width: 100%; border-collapse: separate; border-spacing: 0; margin-top: 10px; font-size: 11.6px }
table.res th { font-family: Jakarta; font-size: 9px; letter-spacing: .12em; text-transform: uppercase; color: #fff; background: var(--marinho); padding: 10px 12px; text-align: left; font-weight: 700 }
table.res th:first-child { border-radius: 9px 0 0 0 }
table.res th:last-child { border-radius: 0 9px 0 0 }
table.res td { padding: 11px 12px; border-bottom: 1px solid var(--linha); color: #33425a; vertical-align: top }
table.res td:first-child { font-family: Jakarta; font-weight: 700; color: var(--marinho) }
table.res .v { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums }
table.res td.v { font-family: Jakarta; font-weight: 700; color: var(--marinho) }
table.res tr.g td { padding: 14px 12px 6px; font-family: Jakarta; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; color: var(--azul); font-weight: 700; background: #fff; border-bottom: 1px solid #c9d6e6 }
table.res tr.st td { background: var(--nevoa); font-family: Jakarta; font-weight: 700; color: var(--marinho) }
table.res tr.op td, table.res tr.op td:first-child { color: #6b7a8e; font-weight: 400; font-family: Inter }
table.res.compacta td { padding-top: 7px; padding-bottom: 7px }
table.res.compacta tr.g td { padding-top: 10px; padding-bottom: 4px }
table.res td small { display: block; font-family: Inter; font-weight: 400; color: var(--cinza); font-size: 10px }

/* Telas e fotos */
.figs { display: flex; flex-direction: column; gap: 18px; margin-top: 18px }
.linha { display: flex; gap: 18px; justify-content: center; align-items: flex-start }
.fig { display: flex; flex-direction: column; align-items: center; min-width: 0 }
.fig .vp { display: inline-block; line-height: 0; border-radius: 12px; overflow: hidden; box-shadow: 0 12px 30px rgba(6, 19, 38, .13), 0 0 0 1px var(--linha); background: #f3f6fb }
.fig img { display: block; max-width: 714px; width: auto; height: auto }
.cap { display: flex; gap: 9px; margin-top: 9px; font-size: 10.6px; line-height: 1.4; color: #3c4a62; max-width: 714px; align-self: stretch }
.cap .n { flex: none; width: 20px; height: 20px; border-radius: 50%; background: #e8f0ff; color: var(--azul); font: 700 10.5px/20px Jakarta, sans-serif; text-align: center }
.cap b { color: var(--tinta); font-family: Jakarta }

/* Escopo */
.box { border: 1px solid var(--linha); border-radius: 12px; padding: 18px 22px; background: #fff; margin-top: 16px }
.box.cinza { background: var(--nevoa) }
.box.cinza h4 { color: var(--cinza-forte) }
.lst { display: grid; grid-template-columns: 1fr 1fr; gap: 0 22px }
.lst div { font-size: 11.6px; line-height: 1.5; color: #3c4a62; padding: 10px 0 10px 24px; position: relative; border-bottom: 1px dashed #e7ecf4 }
.lst div:before { content: ""; position: absolute; left: 0; top: 12px; width: 13px; height: 13px; border-radius: 50%;
  background: #e3f7ea url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%2315803d' stroke-width='4' stroke-linecap='round' stroke-linejoin='round'><path d='M5 12l5 5L20 7'/></svg>") center/9px no-repeat }
.lst2 { display: grid; grid-template-columns: 1fr 1fr; gap: 0 26px }
.lst2 div { font-size: 11.6px; line-height: 1.45; color: #3c4a62; padding: 9px 0 9px 22px; position: relative; border-bottom: 1px dashed #dfe6f0 }
.lst2 div:before { content: ""; position: absolute; left: 1px; top: 11px; width: 9px; height: 9px; border-radius: 50%; border: 2px solid var(--claro) }
.escuro { margin-top: 18px; border-radius: 12px; padding: 18px 22px; background: var(--marinho); color: #dfe8f6; font-size: 12px; line-height: 1.55 }
.escuro b { display: block; font-family: Jakarta; font-size: 13px; color: #fff; margin-bottom: 3px }
.escuro p { margin: 0; white-space: pre-line }

/* Investimento */
.planos { display: grid; grid-template-columns: repeat(3, 1fr); gap: 14px; margin-top: 12px }
.planos.dois { grid-template-columns: 1fr 1fr }
.pc { border: 1px solid var(--linha); border-radius: 12px; padding: 20px 20px 18px; background: var(--nevoa); position: relative; overflow: hidden }
.pc:before { content: ""; position: absolute; left: 0; top: 0; right: 0; height: 3px; background: var(--azul) }
.pc span { display: block; font-family: Jakarta; font-size: 10px; letter-spacing: .15em; text-transform: uppercase; color: var(--cinza); font-weight: 700 }
.pc b { display: block; font-family: Jakarta; font-size: 25px; font-weight: 800; color: var(--marinho); margin: 6px 0 6px; letter-spacing: -.01em }
.pc i { display: block; font-style: normal; font-family: Jakarta; font-size: 11.5px; font-weight: 600; color: var(--azul); margin: -2px 0 8px }
.pc em { display: block; font-style: normal; font-size: 11px; color: var(--cinza-forte); line-height: 1.45 }
.totais { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 16px }
.tt { border-radius: 12px; padding: 16px 20px; background: var(--marinho); color: #c4d0e4; font-size: 11px; line-height: 1.45 }
.tt span { display: block; font-family: Jakarta; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; color: var(--ciano); font-weight: 700 }
.tt b { display: block; font-family: Jakarta; font-size: 24px; font-weight: 800; color: #fff; margin: 4px 0 2px }
.tt.claro { background: var(--nevoa); border: 1px solid var(--linha); color: var(--cinza-forte) }
.tt.claro span { color: var(--azul) } .tt.claro b { color: var(--marinho) }
.validade { margin-top: 12px; font-size: 11px; color: var(--cinza-forte) }

/* Próximos passos e parecer */
.passos { display: grid; grid-template-columns: repeat(2, 1fr); gap: 14px; margin-top: 20px }
.passos .p { border: 1px solid var(--linha); border-radius: 12px; padding: 16px 18px; position: relative; overflow: hidden; min-height: 92px }
.passos .p:before { content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; background: var(--grad) }
.passos .p .k { font-family: Jakarta; font-weight: 800; font-size: 12px; color: var(--azul); letter-spacing: .1em }
.passos .p b { display: block; font-family: Jakarta; font-size: 13.5px; color: var(--marinho); margin: 3px 0 4px }
.passos .p span { font-size: 11.6px; color: var(--cinza-forte); line-height: 1.45; display: block }
.aviso { margin-top: 16px; font-size: 11px; line-height: 1.5; color: #7a4b00; background: #fff6e5; border: 1px solid #ffe2a8; border-radius: 9px; padding: 12px 14px; white-space: pre-line }
.prazo { margin-top: 14px; font-size: 12px; color: #3c4a62 }
.parecer { margin-top: 24px; border: 1.5px solid var(--azul); border-radius: 14px; padding: 18px 22px; break-inside: avoid }
.parecer h4 { font-size: 11px; letter-spacing: .16em; text-transform: uppercase; color: var(--azul); margin: 0 0 10px }
.parecer .op { display: flex; flex-wrap: wrap; gap: 12px 26px; font-size: 12px; color: var(--tinta); margin-bottom: 12px }
.parecer .op span:before { content: ""; display: inline-block; width: 13px; height: 13px; border: 1.5px solid var(--cinza); border-radius: 3px; margin-right: 8px; vertical-align: -2px }
.parecer .op em { font-style: normal; font-weight: 600; color: var(--tinta); margin-right: -6px }
.parecer .ln { border-bottom: 1px solid #c9d3e2; height: 30px }
.ass { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 24px; margin-top: 64px; align-items: end }
.ass div { border-top: 1px solid #9aa8bd; padding-top: 6px; font-size: 10.5px; color: var(--cinza-forte); text-align: center; line-height: 1.4 }
.ass div b { display: block; color: var(--tinta); font-family: Jakarta; font-size: 11.5px }
.contato { margin: 22px 0 18px; display: flex; align-items: center; justify-content: space-between; background: var(--nevoa); border: 1px solid var(--linha); border-radius: 12px; padding: 12px 18px; break-inside: avoid }
.contato img { height: 30px }
.contato div { font-size: 11px; color: var(--cinza-forte); text-align: right; line-height: 1.5 }

/* Capa e contracapa */
.capa { height: 297mm; min-height: 0; background: var(--marinho); color: #fff; padding: 58px 56px 0; overflow: hidden }
.capa .gl { position: absolute; inset: 0; background: radial-gradient(circle at 88% 12%, rgba(0, 200, 248, .28), transparent 42%), radial-gradient(circle at 62% 105%, rgba(8, 102, 245, .45), transparent 52%), radial-gradient(circle at 0% 0%, rgba(8, 102, 245, .18), transparent 40%) }
.capa .grade { position: absolute; inset: 0; background-image: linear-gradient(rgba(255, 255, 255, .035) 1px, transparent 1px), linear-gradient(90deg, rgba(255, 255, 255, .035) 1px, transparent 1px); background-size: 44px 44px }
.capa > *:not(.gl):not(.grade):not(.base) { position: relative; z-index: 1 }
.capa .alto { display: flex; justify-content: space-between; align-items: flex-start }
.capa .logo { height: 54px; display: block }
.capa .num { font-size: 10.5px; color: #9fb1cc; letter-spacing: .06em; text-align: right; padding-top: 6px }
.capa .txt { margin-top: 64px; max-width: 650px }
.capa .eyebrow { color: var(--ciano); font-size: 11px }
.capa h1 { font-size: 42px; line-height: 1.08; margin: 12px 0 16px; font-weight: 800; letter-spacing: -.02em; color: #fff; text-wrap: balance }
.capa h1 .g { color: var(--vibrante) } /* cor sólida: degradê com background-clip:text deixa contorno em alguns leitores de PDF */
.capa p.l { font-size: 14.5px; line-height: 1.55; color: #c4d0e4; margin: 0 }
.capa .vis { flex: 1 1 auto; min-height: 0; display: flex; align-items: center; justify-content: center; padding: 30px 0 24px }
.capa .tela { line-height: 0; border-radius: 14px; overflow: hidden; box-shadow: 0 30px 80px rgba(0, 0, 0, .55), 0 0 0 1px rgba(255, 255, 255, .12) }
.capa .tela img { display: block; max-width: 640px; max-height: 390px; width: auto; height: auto }
.capa .ccl { width: 100% }
.capa .ccl .t { font-family: Jakarta; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; color: #7f93b3; font-weight: 700; margin-bottom: 10px }
.capa .ccl .gr { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px 26px }
.capa .ccl .gr div { font-size: 11px; line-height: 1.5; color: #c4d0e4; border-top: 1px solid rgba(255, 255, 255, .1); padding-top: 8px }
.capa .ccl .gr span { display: block; font-family: Jakarta; font-weight: 700; color: #fff; font-size: 10px; letter-spacing: .12em; text-transform: uppercase; margin-bottom: 2px }
.capa .meta { display: flex; gap: 40px; padding-bottom: 76px }
.capa .meta div { font-size: 12px; color: #e8eef8; line-height: 1.45 }
.capa .meta span { display: block; font-size: 9.5px; letter-spacing: .16em; text-transform: uppercase; color: #7f93b3; font-weight: 700; margin-bottom: 3px }
.capa .base { position: absolute; left: 0; right: 0; bottom: 0; height: 46px; display: flex; align-items: center; justify-content: space-between; padding: 0 56px; font-size: 10px; color: #9fb1cc; border-top: 1px solid rgba(255, 255, 255, .08); z-index: 1 }
.capa .base:before { content: ""; position: absolute; left: 0; right: 0; top: -1px; height: 3px; background: var(--grad) }
.contracapa .vis { flex-direction: column; align-items: flex-start; justify-content: center }
.contracapa .frase { font-family: Jakarta; font-size: 30px; font-weight: 700; line-height: 1.2; color: #fff; margin: 0 0 18px; max-width: 620px }
.contracapa .msg { font-size: 13px; line-height: 1.6; color: #c4d0e4; white-space: pre-line; max-width: 600px }
</style>
</head>
<body>
    {{-- 1. Capa: a marca, o título com o destaque em azul, a frase e, no meio, a foto da capa ou a
         primeira tela do sistema; sem nenhuma das duas, os clientes que atendemos (Ajustes). --}}
    @php
        $clienteCapa = str_contains($cliente, ' · ') ? $cliente : collect([$cliente, $informado($o->contato) ? 'A/C '.$o->contato : null])->filter()->implode(' · ');
        $visual = $capa ?: ($imgs('telas')[0]['src'] ?? null);
        $atendidos = $visual ? [] : $ajustes->clientes();
        // "Gestor de Eventos sob medida para a" + "Inove Audiovisual" é uma frase só; "Comanda digital" + "NexBeer", duas linhas.
        $continua = (bool) preg_match('/\b(a|o|as|os|de|da|do|das|dos|para|com|por|pela|pelo|na|no|em|e)$/iu', trim((string) $o->titulo));
        $terceira = $o->rotulo_3 && $o->valor_3 && ! in_array(mb_strtolower(trim((string) $o->rotulo_3)), ['a/c', 'ac', 'contato', 'cliente', 'data', 'proponente'], true);
    @endphp
    <section class="pg capa">
        <div class="gl"></div><div class="grade"></div>
        <div class="alto"><img class="logo" src="{{ $marca['clara'] }}" alt="VixHouse"><div class="num">{{ $numero }}</div></div>
        <div class="txt">
            <div class="eyebrow">{{ $tipo }} · Rev. {{ $dois($o->revisao ?: 1) }}</div>
            <h1>{{ $o->titulo ?: 'Proposta' }}@if ($o->destaque){!! $continua ? ' ' : '<br>' !!}<span class="g">{{ $o->destaque }}</span>@endif</h1>
            @if ($o->lead)<p class="l">{{ $o->lead }}</p>@endif
        </div>
        <div class="vis">
            @if ($visual)
                <div class="tela"><img src="{{ $visual }}" alt=""></div>
            @elseif ($atendidos)
                <div class="ccl"><div class="t">Clientes que atendemos</div>
                    <div class="gr">@foreach ($atendidos as $g)<div>@if ($g['setor'])<span>{{ $g['setor'] }}</span>@endif{{ implode(' · ', $g['nomes']) }}</div>@endforeach</div></div>
            @endif
        </div>
        <div class="meta">
            <div><span>Cliente</span>{{ $clienteCapa }}@if ($informado(data_get($dc, 'razao_social')) && data_get($dc, 'razao_social') !== $cliente)<br>{{ data_get($dc, 'razao_social') }}@endif</div>
            @if ($terceira)<div><span>{{ $o->rotulo_3 }}</span>{{ $o->valor_3 }}</div>@endif
            <div><span>Documento</span>{{ $tipo }}<br>{{ $total }} páginas</div>
            <div><span>Data</span>{{ $data->translatedFormat('j \d\e F') }}<br>de {{ $data->format('Y') }}@if ($o->validoAte())<br>válida até {{ $o->validoAte()->format('d/m/Y') }}@endif</div>
        </div>
        <div class="base"><span>VixHouse · Soluções em tecnologia</span><span>{{ $contatoEmissor }}</span></div>
    </section>

    {{-- 2. Cliente e desafio: os dados do cadastro, o objeto e a dor — antes da solução. --}}
    @if ($temCliente)
        <section class="pg">
            {{ $topo($temDesafio ? 'Diagnóstico' : 'Cliente') }}
            <div class="corpo">
                {{ $cab($temDesafio ? 'Cliente e diagnóstico' : 'Cliente e objeto', $o->secao('desafio', 'titulo') ?? ($temDesafio ? 'O desafio de hoje' : $clienteCurto), $temDesafio ? $o->secao('desafio', 'texto') : null) }}
                @if ($dadosCliente->isNotEmpty())
                    <div class="cli">@foreach ($dadosCliente as [$r, $v])<div @class(['l' => in_array($r, ['Endereço', 'Razão social'], true) && mb_strlen($v) > 44])><span>{{ $r }}</span>{{ $v }}</div>@endforeach</div>
                @endif
                @if ($o->linhas('objeto'))
                    <div class="objeto"><h4>Objeto da proposta</h4>@foreach ($o->linhas('objeto') as $k => $l)<div class="etapa"><i>{{ $dois($k + 1) }}</i><span>{{ $negrito($l) }}</span></div>@endforeach</div>
                @endif
                @if ($temDesafio)
                    @if ($o->secao('desafio', 'citacao'))<p class="citacao">“{{ $o->secao('desafio', 'citacao') }}”</p>@endif
                    @if ($o->paresDaSecao('desafio', 'numeros'))
                        <div class="numeros">@foreach ($o->paresDaSecao('desafio', 'numeros') as [$v, $r])<div><b>{{ $v }}</b><span>{{ $r }}</span></div>@endforeach</div>
                    @endif
                    @if ($linhasDor && $tabelaDor)
                        <table class="dor">
                            <tr>@foreach (array_slice(array_pad($cabDor, $colunasDor, ''), 0, $colunasDor) as $h)<th>{{ $h }}</th>@endforeach</tr>
                            @foreach ($linhasDor as $l)<tr>@foreach (array_pad(array_map('trim', explode('|', $l, $colunasDor)), $colunasDor, '') as $cel)<td>{{ $cel }}</td>@endforeach</tr>@endforeach
                        </table>
                    @elseif ($linhasDor)
                        <ul class="pontos">@foreach ($linhasDor as $l)<li>{{ $negrito($l) }}</li>@endforeach</ul>
                    @endif
                    @if ($imgs('desafio') && ! $fotosDoDesafio)
                        <div class="figs">@foreach ($linhasDe($imgs('desafio')) as $linha)@include('orcamentos.pdf._vixhouse_linha', ['linha' => $linha, 'altura' => 300, 'numerar' => false])@endforeach</div>
                    @endif
                @endif
                @if ($solucaoJunto)
                    <div class="isec">A proposta</div>
                    <h3 class="h3">{{ $o->secao('solucao', 'titulo') ?? ($projeto ? 'Como o '.$projeto.' resolve' : 'A solução') }}</h3>
                    @if ($o->secao('solucao', 'texto'))<p class="lead">{{ $o->secao('solucao', 'texto') }}</p>@endif
                    @if ($fluxo)
                        <div class="fluxo c{{ $colunas }}" style="margin-top: 14px">@foreach ($fluxo as $k => $l)@php [$t, $d] = $partes($semNumero($l)); @endphp<div class="p"><div class="k">{{ $dois($k + 1) }}</div><b>{{ $t }}</b>@if ($d !== '')<span>{{ $d }}</span>@endif</div>@endforeach</div>
                    @endif
                @endif
            </div>
            {{ $rod('cliente', $temDesafio ? 'Diagnóstico a partir das informações e documentos do cliente' : null) }}
        </section>
    @endif

    {{-- 3. A solução: o caminho em cartões numerados (como o fluxo da Inove, do orçamento ao financeiro). --}}
    @if ($temSolucao && ! $solucaoJunto)
        <section class="pg">
            {{ $topo('A proposta') }}
            <div class="corpo">
                {{ $cab('A proposta', $o->secao('solucao', 'titulo') ?? ($projeto ? 'Como o '.$projeto.' resolve' : 'A solução'), $o->secao('solucao', 'texto')) }}
                @if ($fluxo)
                    <div class="fluxo c{{ $colunas }}">@foreach ($fluxo as $k => $l)@php [$t, $d] = $partes($semNumero($l)); @endphp<div class="p"><div class="k">{{ $dois($k + 1) }}</div><b>{{ $t }}</b>@if ($d !== '')<span>{{ $d }}</span>@endif</div>@endforeach</div>
                @endif
                @if ($imgs('solucao'))
                    <div class="figs">@foreach ($linhasDe($imgs('solucao')) as $linha)@include('orcamentos.pdf._vixhouse_linha', ['linha' => $linha, 'altura' => count($fluxo) > 3 ? 300 : 420, 'numerar' => false])@endforeach</div>
                @endif
                @if (count($indiceTelas) >= 3 && count($indiceTelas) <= 18)
                    <div class="sub">As telas desta proposta</div>
                    <div class="mapa">@foreach ($indiceTelas as $k => $t)<div class="m"><span class="ic">{{ $dois($k + 1) }}</span><b>{{ $t['titulo'] }}</b><em>pág. {{ $t['pag'] }}</em></div>@endforeach</div>
                @endif
            </div>
            {{ $rod('solucao') }}
        </section>
    @endif

    {{-- 4. Seções livres: a que tem imagem ou muito texto abre a sua página; as curtas dividem a folha. --}}
    @foreach ($paginasLivres as $k => $pl)
        @php $primeira = $pl['blocos'][0]; @endphp
        <section class="pg">
            {{ $topo($curta($primeira['titulo'] ?: $tipo)) }}
            <div class="corpo">
                {{ $cab($tipo, $primeira['titulo'] ?: $tipo) }}
                <div class="txt">
                    @foreach ($pl['blocos'] as $b => $bloco)
                        <div class="bl">
                            @if ($b > 0 && $bloco['titulo'])<h3>{{ $bloco['titulo'] }}</h3>@endif
                            @foreach ($trechos($bloco['texto']) as $t)
                                @if ($t['tipo'] === 'tabela')
                                    <table class="res"><tr>@foreach ($t['linhas'][0] as $h)<th>{{ $h }}</th>@endforeach</tr>
                                        @foreach ($t['linhas']->slice(1) as $cel)<tr>@foreach ($cel as $x)<td>{{ $x }}</td>@endforeach</tr>@endforeach</table>
                                @elseif ($t['tipo'] === 'lista')
                                    <ul>@foreach ($t['itens'] as $i)<li>{{ $negrito($i) }}</li>@endforeach</ul>
                                @elseif ($t['tipo'] === 'subtitulo')
                                    <div class="sub">{{ $t['texto'] }}</div>
                                @else
                                    <p>{{ $t['texto'] }}</p>
                                @endif
                            @endforeach
                            @if ($imgs($bloco['chave']))
                                <div class="figs">@foreach ($linhasDe($imgs($bloco['chave'])) as $linha)@include('orcamentos.pdf._vixhouse_linha', ['linha' => $linha, 'altura' => $bloco['peso'] > 700 ? 280 : 400, 'numerar' => false])@endforeach</div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
            {{ $rod('livre:'.$k) }}
        </section>
    @endforeach

    {{-- 5. Telas do sistema (sempre com o selo de tela ilustrativa) e fotos, duas linhas por página. --}}
    @php $nTela = 0; @endphp
    @foreach ($paginasTelas as $k => $linhas)
        <section class="pg">
            {{ $topo('Telas') }}
            <div class="corpo">
                {{ $cab('Telas · '.$dois($k + 1).' de '.$dois(count($paginasTelas)), $projeto ? $projeto.' na prática' : 'O sistema na prática', $k === 0 ? 'Prévia das telas, com nomes e valores de exemplo.' : null, true) }}
                <div class="figs">
                    @foreach ($linhas as $linha)
                        @include('orcamentos.pdf._vixhouse_linha', ['linha' => $linha, 'altura' => count($linhas) > 1 ? ($k === 0 ? 378 : 392) : 600, 'numerar' => true, 'inicio' => $nTela])
                        @php $nTela += count($linha); @endphp
                    @endforeach
                </div>
            </div>
            {{ $rod('telas:'.$k, 'Telas ilustrativas · nomes, valores e quantidades das telas são fictícios') }}
        </section>
    @endforeach
    @foreach ($paginasFotos as $k => $linhas)
        <section class="pg">
            {{ $topo('Na prática') }}
            <div class="corpo">
                {{ $cab('Na prática', $projeto ? $projeto.' na prática' : 'Na prática') }}
                <div class="figs">@foreach ($linhas as $linha)@include('orcamentos.pdf._vixhouse_linha', ['linha' => $linha, 'altura' => count($linhas) > 1 ? 392 : 620, 'numerar' => false])@endforeach</div>
            </div>
            {{ $rod('fotos:'.$k) }}
        </section>
    @endforeach

    {{-- 6. Escopo: o que entra, a especificação, as decisões e o que fica de fora. --}}
    @if ($temEscopo)
        <section class="pg">
            {{ $topo('Escopo') }}
            <div class="corpo">
                {{ $cab('Escopo', $o->secao('especificacao', 'titulo') ?? 'O que entra no fornecimento') }}
                @if ($o->linhas('inclui'))
                    <div class="box"><h4>O que está incluso</h4><div class="lst">@foreach ($o->linhas('inclui') as $l)<div>{{ $negrito($l) }}</div>@endforeach</div></div>
                @endif
                @if ($temEspec)
                    @php $pares = $o->paresDaSecao('especificacao', 'linhas'); $soUma = collect($pares)->every(fn ($p) => trim((string) $p[1]) === ''); @endphp
                    <table class="res" style="margin-top: 16px"><tr><th style="width: 34%">Item</th>@unless ($soUma)<th>Especificação</th>@endunless</tr>
                        @foreach ($pares as [$a, $b])<tr><td>{{ $a }}</td>@unless ($soUma)<td>{{ $b }}</td>@endunless</tr>@endforeach</table>
                @endif
                @if ($decisoesJunto)
                    <div class="sub">{{ $o->secao('decisoes', 'titulo') ?? 'Decisões técnicas' }}</div>
                    <table class="res"><tr><th style="width: 34%">Ponto</th><th>Decisão</th></tr>
                        @foreach ($o->paresDaSecao('decisoes', 'linhas') as [$a, $b])<tr><td>{{ $a }}</td><td>{{ $b }}</td></tr>@endforeach</table>
                @endif
                @if ($o->linhas('nao_inclui'))
                    <div class="box cinza"><h4>Não incluso · pode ser orçado à parte</h4><div class="lst2">@foreach ($o->linhas('nao_inclui') as $l)<div>{{ $negrito($l) }}</div>@endforeach</div></div>
                @endif
                @if ($temInvest && $alturaEscopo + ($decisoesJunto ? $alturaDecisoes : 0) + 90 <= 900)
                    <div class="escuro"><b>Investimento</b>Na página {{ $pag('investimento') }}, com a forma de pagamento ao lado de cada valor.</div>
                @endif
            </div>
            {{ $rod('escopo') }}
        </section>
    @endif

    @if ($decisoesSozinhas)
        <section class="pg">
            {{ $topo('Decisões técnicas') }}
            <div class="corpo">
                {{ $cab('Escopo', $o->secao('decisoes', 'titulo') ?? 'Decisões técnicas') }}
                <table class="res" style="margin-top: 16px"><tr><th style="width: 34%">Ponto</th><th>Decisão</th></tr>
                    @foreach ($o->paresDaSecao('decisoes', 'linhas') as [$a, $b])<tr><td>{{ $a }}</td><td>{{ $b }}</td></tr>@endforeach</table>
            </div>
            {{ $rod('decisoes') }}
        </section>
    @endif

    {{-- 7. Perguntas e respostas. --}}
    @if ($o->temSecao('perguntas'))
        <section class="pg">
            {{ $topo('Perguntas') }}
            <div class="corpo">
                {{ $cab('Perguntas e respostas', 'O que nos perguntaram') }}
                <div class="objeto">@foreach ($o->paresDaSecao('perguntas', 'linhas') as $k => [$pergunta, $resposta])<div class="etapa"><i>{{ $dois($k + 1) }}</i><span><b>{{ $pergunta }}</b><br>{{ $resposta }}</span></div>@endforeach</div>
            </div>
            {{ $rod('perguntas') }}
        </section>
    @endif

    {{-- 8. Investimento: os planos, os valores por grupo com a forma de pagamento de cada um, os totais
         e as condições. Os opcionais aparecem, mas ficam fora do total. --}}
    @if ($temInvest)
        <section class="pg">
            {{ $topo('Investimento') }}
            <div class="corpo">
                {{ $cab('Investimento', $passosJunto ? 'Investimento e próximos passos' : 'Valores e condições', 'Os valores com a forma de pagamento ao lado de cada um.') }}
                @if ($planos)
                    <div class="isec">Planos</div>
                    <div @class(['planos', 'dois' => count($planos) % 3 !== 0 && count($planos) % 2 === 0])>
                        @foreach ($planos as $p)
                            <div class="pc"><span>{{ $p['nome'] }}</span><b>{{ $valorPlano($p['valor']) }}</b>@if ($p['periodo'])<i>por {{ preg_replace('/^(por|\/)\s*/iu', '', $p['periodo']) }}</i>@endif @if ($p['observacao'])<em>{{ $p['observacao'] }}</em>@endif</div>
                        @endforeach
                    </div>
                @endif
                @if ($grupos->isNotEmpty() || $opcoes->isNotEmpty())
                    <div class="isec">{{ $planos ? 'Demais valores' : 'Valores' }}</div>
                    <table @class(['res', 'valores', 'compacta' => $o->itens->count() + $grupos->count() > 8])>
                        <colgroup><col><col style="width: 13%"><col style="width: 25%"><col style="width: 17%"></colgroup>
                        <tr><th>Descrição</th><th class="v">Qtd</th><th>Forma de pagamento</th><th class="v">Valor</th></tr>
                        @foreach ($grupos as [$nomeGrupo, $subtotal, $sufixo, $itens])
                            @if ($grupos->count() > 1 || $opcoes->isNotEmpty())<tr class="g"><td colspan="4">{{ $nomeGrupo }}</td></tr>@endif
                            @foreach ($itens as $i)
                                <tr><td>{{ $i->descricao }}@if ($i->quantidade != 1 && $i->valor_unitario > 0)<small>{{ $brl($i->valor_unitario) }} por {{ $i->unidade }}</small>@endif</td>
                                    <td class="v">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td><td>{{ $comoPaga($i) }}</td><td class="v">{{ $brl($i->subtotal()) }}{{ $sufixo }}</td></tr>
                            @endforeach
                            @if ($itens->count() > 1)<tr class="st"><td colspan="3">Subtotal · {{ mb_strtolower($nomeGrupo) }}</td><td class="v">{{ $brl($subtotal) }}{{ $sufixo }}</td></tr>@endif
                        @endforeach
                        @if ($opcoes->isNotEmpty())
                            <tr class="g"><td colspan="4">Opcionais · fora do total</td></tr>
                            @foreach ($opcoes as $i)
                                <tr class="op"><td>{{ $i->descricao }}@if ($i->categoriaEfetiva() === 'recorrente')<small>mensal</small>@endif</td><td class="v">{{ $i->quantidadeLegivel() }} {{ $i->unidade }}</td>
                                    <td>{{ $comoPaga($i) }}</td><td class="v">{{ $i->valor_unitario > 0 ? $brl($i->subtotal()) : '—' }}</td></tr>
                            @endforeach
                        @endif
                    </table>
                @endif
                @if ($o->total_unico > 0 || $o->total_mensal > 0)
                    @php $partesTotal = collect([$o->total_material > 0 ? 'Material '.$brl($o->total_material) : null, $o->total_implantacao > 0 ? 'Implantação '.$brl($o->total_implantacao) : null])->filter(); @endphp
                    <div class="totais">
                        @if ($o->total_unico > 0)
                            <div class="tt"><span>Total do projeto</span><b>{{ $brl($o->total_unico) }}</b>{{ $partesTotal->count() > 1 ? $partesTotal->implode(' + ') : 'Valor único' }}</div>
                        @endif
                        @if ($o->total_mensal > 0)
                            <div class="tt claro"><span>Recorrente</span><b>{{ $brl($o->total_mensal) }}/mês</b>A partir da implantação</div>
                        @endif
                    </div>
                @endif
                @if ($condicao)
                    <div class="escuro"><b>Condição de pagamento</b><p>{{ $condicao }}</p></div>
                @endif
                @if ($o->validoAte())<p class="validade">Proposta válida até {{ $o->validoAte()->format('d/m/Y') }}.</p>@endif
                @if ($passosJunto)
                    @include('orcamentos.pdf._vixhouse_passos')
                @endif
            </div>
            {{ $rod('investimento') }}
        </section>
    @endif

    {{-- 9. Próximos passos e parecer do cliente (assinatura só como Diretor Comercial). --}}
    @if (in_array('passos', $ordem, true))
        <section class="pg">
            {{ $topo('Próximos passos') }}
            <div class="corpo">
                {{ $cab('Próximos passos', $o->aceite ? 'Da aprovação ao sistema em uso' : 'Próximos passos', $o->aceite ? 'A aprovação desta proposta confirma o escopo e os valores. O trabalho começa depois do aceite formal.' : null) }}
                @include('orcamentos.pdf._vixhouse_passos')
            </div>
            {{ $rod('passos') }}
        </section>
    @endif

    {{-- 10. Contracapa, quando pedida. --}}
    @if ($o->temSecao('contracapa'))
        <section class="pg capa contracapa">
            <div class="gl"></div><div class="grade"></div>
            <div class="alto"><img class="logo" src="{{ $marca['clara'] }}" alt="VixHouse"></div>
            <div class="vis">
                @if ($o->secao('contracapa', 'frase'))<p class="frase">{{ $o->secao('contracapa', 'frase') }}</p>@endif
                @if ($o->secao('contracapa', 'mensagem'))<p class="msg">{{ $o->secao('contracapa', 'mensagem') }}</p>@endif
            </div>
            <div class="base"><span>VixHouse · Soluções em tecnologia</span><span>{{ $contatoEmissor }}</span></div>
        </section>
    @endif
</body>
</html>
