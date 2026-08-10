<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;

/**
 * Origem única dos indicadores que aparecem em mais de uma tela.
 *
 * Antes, cada painel contava por conta própria. As contas coincidiam, mas
 * duplicar a regra é justamente como os números começam a divergir: basta
 * alguém ajustar o critério de "ativo" num lugar e esquecer o outro. Quando
 * isso acontece, quem usa não sabe qual tela está certa.
 */
class IndicadoresService
{
    public function clientesAtivos(): int
    {
        return Cliente::where('ativo', true)->count();
    }

    public function clientesDiretos(): int
    {
        return Cliente::where('ativo', true)->whereNull('revenda_id')->count();
    }

    public function revendasAtivas(): int
    {
        return Revenda::where('ativo', true)->count();
    }

    public function sistemasAtivos(): int
    {
        return Sistema::where('ativo', true)->count();
    }

    /**
     * Receita recorrente da competência: o que foi cobrado, sem os cancelados.
     */
    public function mrr(?string $competencia = null): float
    {
        return (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia ?? now()->format('Y-m'))
            ->where('status', '!=', 'cancelado')
            ->sum('valor');
    }

    /**
     * Valor de atacado por sistema, com o tier aplicado por revenda.
     *
     * Vive aqui porque aparece no painel Comercial e na tela de Sistemas: se
     * cada uma calculasse por conta própria, bastaria alguém ajustar a regra
     * de um lado para os dois números passarem a discordar.
     *
     * @return \Illuminate\Support\Collection<int, array{sistema: Sistema, clientes_ativos: int, valor_estimado: float}>
     */
    public function rankingSistemas()
    {
        return Sistema::withCount(['clientes' => fn ($q) => $q->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)])
            ->get()
            ->map(function (Sistema $sistema) {
                $porRevenda = $sistema->clientes()
                    ->where('clientes.ativo', true)
                    ->where('cliente_sistema.ativo', true)
                    ->get(['clientes.id', 'clientes.revenda_id'])
                    ->groupBy('revenda_id');

                $valorTotal = 0.0;
                foreach ($porRevenda as $revendaId => $clientes) {
                    $qtd = $clientes->count();
                    // Cliente sem revenda vira chave vazia no groupBy — `chaveDeRevenda`
                    // a normaliza para null (o tier padrão). Sem isso, um único
                    // cliente de venda direta derruba a tela inteira com TypeError.
                    $tier = $sistema->tierParaVolume($qtd, $sistema->chaveDeRevenda($revendaId));
                    $valorTotal += $tier?->calcularMensalidade($qtd) ?? 0;
                }

                return [
                    'sistema' => $sistema,
                    'clientes_ativos' => $sistema->clientes_count,
                    'valor_estimado' => $valorTotal,
                ];
            });
    }

    /** Soma do atacado de todos os sistemas. */
    public function mrrAtacado(): float
    {
        return (float) $this->rankingSistemas()->sum('valor_estimado');
    }

    public function saldoEmCaixa(): float
    {
        return (float) ContaFinanceira::where('ativo', true)->sum('saldo');
    }

    public function entradasDoMes(): float
    {
        return (float) Cobranca::where('status', 'pago')
            ->whereBetween('data_pagamento', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('valor_pago');
    }

    public function saidasDoMes(): float
    {
        return (float) ContaPagar::where('status', 'pago')
            ->whereBetween('data_pagamento', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('valor_pago');
    }
}
