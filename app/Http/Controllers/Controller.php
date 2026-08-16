<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

abstract class Controller
{
    /** Linhas por página em toda listagem do painel. */
    public const POR_PAGINA = 20;

    /**
     * Telas de gestão da matriz (dashboards, catálogo, financeiro, cadastros)
     * mostram números de todas as revendas. Usuário com escopo de revenda não
     * as vê: ele trabalha no próprio portfólio (clientes, cobranças, leads,
     * faturamento).
     */
    protected function bloquearVisaoDaMatriz(): void
    {
        abort_if(auth()->user()->temEscopoDeRevenda(), 403, 'Esta tela é exclusiva da matriz.');
    }

    /**
     * Pagina uma lista JÁ montada em memória.
     *
     * Revendas e Produtos ordenam por número calculado — MRR não é coluna, é
     * conta feita depois de montar a linha inteira. Não dá para pedir ao banco
     * um LIMIT sobre uma ordem que ainda não existe, então o corte acontece
     * aqui.
     *
     * E acontece POR ÚLTIMO, de propósito: quem chama soma os totais e os KPIs
     * da lista inteira ANTES de cortar. Paginar antes de somar faria o rodapé
     * descrever a página em vez do recorte — o total mudaria de valor a cada
     * clique em "próxima", que é o defeito clássico de paginar tela com soma.
     *
     * @param  Collection<int, mixed>  $itens
     * @return LengthAwarePaginator<int, mixed>
     */
    protected function paginarColecao(Collection $itens, int $porPagina = self::POR_PAGINA): LengthAwarePaginator
    {
        $pagina = Paginator::resolveCurrentPage();

        return (new LengthAwarePaginator(
            $itens->forPage($pagina, $porPagina)->values(),
            $itens->count(),
            $porPagina,
            $pagina,
            ['path' => Paginator::resolveCurrentPath()],
        ))->withQueryString();
    }

    /**
     * A competência que a tela mostra — "AAAA-MM" validado, ou o mês corrente
     * quando a URL não pede um em especial ou pede algo malformado.
     *
     * Aqui, e não em cada tela: Painel Financeiro e Relatórios navegam por
     * competência com a mesma URL, e duas validações é como uma delas passa a
     * aceitar o que a outra recusa.
     */
    protected function competenciaSelecionada(Request $request): string
    {
        $valor = $request->query('competencia');

        if (is_string($valor) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $valor)) {
            return $valor;
        }

        return now()->format('Y-m');
    }

    /**
     * Distribui um total em aberto nas quatro faixas de vencimento do
     * desenho: a vencer, 1 a 15 dias de atraso, 16 a 30, e mais de 30.
     *
     * Aqui, e não na tela de Receitas onde nasceu: os Relatórios repartem o
     * mesmo em-aberto, e duas réguas de faixa é como "16 a 30" passa a
     * significar duas coisas. Serve a qualquer coleção com `valor` e
     * `data_vencimento` — Cobranca e ContaPagar inclusas.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $pendentes
     * @return array<string, array{rotulo: string, valor: float}>
     */
    protected function faixasDeAging($pendentes, \Carbon\Carbon $hoje): array
    {
        $faixas = [
            'a_vencer' => ['rotulo' => 'A vencer', 'valor' => 0.0],
            '1_15' => ['rotulo' => '1 a 15 dias', 'valor' => 0.0],
            '16_30' => ['rotulo' => '16 a 30 dias', 'valor' => 0.0],
            'mais_30' => ['rotulo' => '+30 dias', 'valor' => 0.0],
        ];

        foreach ($pendentes as $titulo) {
            $diasParaVencer = $hoje->diffInDays($titulo->data_vencimento, false);
            $diasDeAtraso = $diasParaVencer < 0 ? abs($diasParaVencer) : 0;

            $chave = match (true) {
                $diasDeAtraso === 0 => 'a_vencer',
                $diasDeAtraso <= 15 => '1_15',
                $diasDeAtraso <= 30 => '16_30',
                default => 'mais_30',
            };

            $faixas[$chave]['valor'] += (float) $titulo->valor;
        }

        return $faixas;
    }

    /**
     * Monta um ranking comparável a partir de uma lista de `nome`/`valor` — a
     * gramática de três camadas do `<x-ranking>`, usada pelo Painel Comercial
     * e pelos Relatórios.
     *
     * `share` é a fatia do total (o que a faixa segmentada desenha) e
     * `largura` é o tamanho relativo AO LÍDER (o que a barra da linha
     * desenha). São duas perguntas diferentes — "quanto disso é meu?" e
     * "quão longe estou do primeiro?" — e usar uma no lugar da outra achata
     * o ranking inteiro.
     *
     * @param  Collection<int, array{nome: string, valor: float}>  $itens
     */
    protected function ranking(Collection $itens, string $cor): array
    {
        $itens = $itens->filter(fn ($i) => $i['valor'] > 0)->sortByDesc('valor')->values();

        $total = (float) $itens->sum('valor');
        $lider = $itens->first();
        $maior = (float) ($lider['valor'] ?? 0);

        return [
            'cor' => $cor,
            'total' => $total,
            'lider' => $lider ? [
                'nome' => $lider['nome'],
                'valor' => $lider['valor'],
                'share' => $total > 0 ? $lider['valor'] / $total : 0,
            ] : null,
            'itens' => $itens->map(fn ($item, $i) => $item + [
                'posicao' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'share' => $total > 0 ? $item['valor'] / $total : 0,
                'largura' => $maior > 0 ? $item['valor'] / $maior : 0,
            ])->all(),
        ];
    }
}

