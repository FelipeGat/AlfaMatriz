<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\IndicadoresService;

class PainelController extends Controller
{
    public function __construct(private readonly IndicadoresService $indicadores) {}

    public function index()
    {
        // Todos os números que também aparecem em outra tela saem daqui —
        // ver IndicadoresService.
        $mrr = $this->indicadores->mrr();
        $saldoTotal = $this->indicadores->saldoEmCaixa();
        $entradasMes = $this->indicadores->entradasDoMes();
        $saidasMes = $this->indicadores->saidasDoMes();

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

        $totalRevendas = $this->indicadores->revendasAtivas();
        $totalClientes = $this->indicadores->clientesAtivos();
        $clientesDiretos = $this->indicadores->clientesDiretos();

        $historico = $this->historicoSeisMeses();

        return view('dashboard', compact(
            'mrr', 'saldoTotal', 'entradasMes', 'saidasMes',
            'receitasPendentes', 'despesasPendentes',
            'totalRevendas', 'totalClientes', 'clientesDiretos', 'historico'
        ));
    }

    public function comercial()
    {
        // Mesma origem da tela de Sistemas: o valor de atacado precisa bater
        // entre as duas.
        $ranking = $this->indicadores->rankingSistemas();
        $sistemas = $ranking->pluck('sistema');

        $porQuantidade = $ranking->sortByDesc('clientes_ativos')->values();
        $porValor = $ranking->sortByDesc('valor_estimado')->values();

        $maxQuantidade = max($porQuantidade->max('clientes_ativos'), 1);
        $maxValor = max($porValor->max('valor_estimado'), 1);

        // Mesma origem do painel financeiro: é o que garante que o mesmo
        // indicador não mostre dois números em telas diferentes.
        $totalClientesAtivos = $this->indicadores->clientesAtivos();
        $totalSistemasAtivos = $this->indicadores->sistemasAtivos();
        $totalRevendasAtivas = $this->indicadores->revendasAtivas();
        $mrrEstimado = $this->indicadores->mrrAtacado();

        $porRevenda = Cliente::where('ativo', true)
            ->with('revenda')
            ->get()
            ->groupBy(fn ($c) => $c->revenda?->nome ?? 'Venda direta')
            ->map->count()
            ->sortByDesc(fn ($qtd) => $qtd);

        $porCategoria = $sistemas->groupBy('categoria')->map->count();

        return view('dashboard-comercial', compact(
            'porQuantidade', 'porValor', 'maxQuantidade', 'maxValor',
            'totalClientesAtivos', 'totalSistemasAtivos', 'totalRevendasAtivas',
            'mrrEstimado', 'porRevenda', 'porCategoria'
        ));
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
