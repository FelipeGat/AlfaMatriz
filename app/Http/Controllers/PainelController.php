<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;

class PainelController extends Controller
{
    public function index()
    {
        $competenciaAtual = now()->format('Y-m');
        $inicioMes = now()->startOfMonth();
        $fimMes = now()->endOfMonth();

        $mrr = Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competenciaAtual)
            ->where('status', '!=', 'cancelado')
            ->sum('valor');

        $arr = $mrr * 12;

        $saldoTotal = ContaFinanceira::where('ativo', true)->sum('saldo');

        $entradasMes = Cobranca::where('status', 'pago')
            ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
            ->sum('valor_pago');

        $saidasMes = ContaPagar::where('status', 'pago')
            ->whereBetween('data_pagamento', [$inicioMes, $fimMes])
            ->sum('valor_pago');

        $receitasPendentes = Cobranca::where('status', 'pendente')
            ->orderBy('data_vencimento')
            ->with(['revenda', 'cliente'])
            ->limit(5)
            ->get();

        $despesasPendentes = ContaPagar::where('status', 'em_aberto')
            ->orderBy('data_vencimento')
            ->with('fornecedor')
            ->limit(5)
            ->get();

        $totalRevendas = Revenda::where('ativo', true)->count();
        $totalClientes = Cliente::where('ativo', true)->count();
        $clientesDiretos = Cliente::where('ativo', true)->whereNull('revenda_id')->count();

        $historico = $this->historicoSeisMeses();

        return view('dashboard', compact(
            'mrr', 'arr', 'saldoTotal', 'entradasMes', 'saidasMes',
            'receitasPendentes', 'despesasPendentes',
            'totalRevendas', 'totalClientes', 'clientesDiretos', 'historico'
        ));
    }

    public function comercial()
    {
        $sistemas = Sistema::withCount(['clientes' => fn ($q) => $q->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)])
            ->get();

        $ranking = $sistemas->map(fn (Sistema $sistema) => [
            'sistema' => $sistema,
            'clientes_ativos' => $sistema->clientes_count,
            'valor_estimado' => $sistema->mrrEstimado(),
        ]);

        $porQuantidade = $ranking->sortByDesc('clientes_ativos')->values();
        $porValor = $ranking->sortByDesc('valor_estimado')->values();

        $maxQuantidade = max($porQuantidade->max('clientes_ativos'), 1);
        $maxValor = max($porValor->max('valor_estimado'), 1);

        // Os dois rankings da tela, já em três camadas: o total e o líder no
        // topo, a faixa segmentada no meio e as linhas embaixo. Cada linha
        // conhece a própria participação e o próprio tamanho relativo ao
        // líder — comparar é o motivo desta tela existir.
        $rankingClientes = $this->ranking(
            $porQuantidade->map(fn ($l) => ['nome' => $l['sistema']->nome, 'valor' => (float) $l['clientes_ativos']]),
            'accent'
        );
        $rankingValor = $this->ranking(
            $porValor->map(fn ($l) => ['nome' => $l['sistema']->nome, 'valor' => (float) $l['valor_estimado']]),
            'chart-out'
        );

        $totalClientesAtivos = Cliente::where('ativo', true)->count();
        $totalSistemasAtivos = Sistema::where('ativo', true)->count();
        $totalRevendasAtivas = Revenda::where('ativo', true)->count();
        $mrrEstimado = $ranking->sum('valor_estimado');

        $porRevenda = Cliente::where('ativo', true)
            ->with('revenda')
            ->get()
            ->groupBy(fn ($c) => $c->revenda?->nome ?? 'Venda direta')
            ->map->count()
            ->sortByDesc(fn ($qtd) => $qtd);

        $porCategoria = $sistemas->groupBy('categoria')->map->count();

        // Os dois painéis de apoio usam a mesma gramática das linhas do
        // ranking, para a tela inteira se ler do mesmo jeito.
        $rankingRevendas = $this->ranking(
            $porRevenda->map(fn ($qtd, $nome) => ['nome' => $nome, 'valor' => (float) $qtd])->values(),
            'accent'
        );
        $rankingCategorias = $this->ranking(
            $porCategoria->map(fn ($qtd, $nome) => ['nome' => $nome ?: 'Sem categoria', 'valor' => (float) $qtd])
                ->sortByDesc('valor')->values(),
            'brand'
        );

        return view('dashboard-comercial', compact(
            'porQuantidade', 'porValor', 'maxQuantidade', 'maxValor',
            'totalClientesAtivos', 'totalSistemasAtivos', 'totalRevendasAtivas',
            'mrrEstimado', 'porRevenda', 'porCategoria',
            'rankingClientes', 'rankingValor', 'rankingRevendas', 'rankingCategorias'
        ));
    }

    /**
     * Monta um ranking comparável a partir de uma lista de `nome`/`valor`.
     *
     * `share` é a fatia do total (o que a faixa segmentada desenha) e
     * `largura` é o tamanho relativo AO LÍDER (o que a barra da linha
     * desenha). São duas perguntas diferentes — "quanto disso é meu?" e
     * "quão longe estou do primeiro?" — e usar uma no lugar da outra achata
     * o ranking inteiro.
     *
     * @param  \Illuminate\Support\Collection<int, array{nome: string, valor: float}>  $itens
     */
    private function ranking(\Illuminate\Support\Collection $itens, string $cor): array
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

    private function historicoSeisMeses(): array
    {
        $meses = collect(range(5, 0))->map(fn ($i) => now()->subMonths($i)->startOfMonth());

        return $meses->map(function ($mes) {
            $inicio = $mes->copy()->startOfMonth();
            $fim = $mes->copy()->endOfMonth();

            return [
                'label' => ucfirst($mes->translatedFormat('M/y')),
                'entradas' => (float) Cobranca::where('status', 'pago')
                    ->whereBetween('data_pagamento', [$inicio, $fim])
                    ->sum('valor_pago'),
                'saidas' => (float) ContaPagar::where('status', 'pago')
                    ->whereBetween('data_pagamento', [$inicio, $fim])
                    ->sum('valor_pago'),
            ];
        })->all();
    }
}
