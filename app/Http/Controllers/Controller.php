<?php

namespace App\Http\Controllers;

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
}

