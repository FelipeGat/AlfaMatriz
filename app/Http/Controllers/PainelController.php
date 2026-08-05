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
