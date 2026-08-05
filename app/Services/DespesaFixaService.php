<?php

namespace App\Services;

use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use Carbon\Carbon;

class DespesaFixaService
{
    /**
     * Gera as parcelas de contas a pagar do mês para cada despesa fixa ativa
     * e vigente na competência informada. Idempotente: pula quem já foi gerado.
     */
    public function gerarParaCompetencia(string $competencia): array
    {
        $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $resultado = [];

        $templates = ContaFixaPagar::where('ativo', true)
            ->whereDate('data_inicio', '<=', $mes->copy()->endOfMonth())
            ->where(fn ($q) => $q->whereNull('data_fim')->orWhereDate('data_fim', '>=', $mes->copy()->startOfMonth()))
            ->orderBy('dia_vencimento')
            ->get();

        foreach ($templates as $template) {
            $jaExiste = ContaPagar::where('conta_fixa_pagar_id', $template->id)
                ->where('competencia', $competencia)
                ->exists();

            if ($jaExiste) {
                $resultado[] = ['descricao' => $template->descricao, 'status' => 'ja_gerado'];

                continue;
            }

            $contaPagar = ContaPagar::create([
                'conta_fixa_pagar_id' => $template->id,
                'centro_custo_id' => $template->centro_custo_id,
                'conta_id' => $template->conta_id,
                'fornecedor_id' => $template->fornecedor_id,
                'conta_financeira_id' => $template->conta_financeira_id,
                'descricao' => $template->descricao,
                'valor' => $template->valor,
                'data_vencimento' => $template->dataVencimentoParaCompetencia($competencia),
                'status' => 'em_aberto',
                'tipo' => 'fixa',
                'competencia' => $competencia,
                'forma_pagamento' => $template->forma_pagamento,
            ]);

            $resultado[] = [
                'descricao' => $template->descricao,
                'status' => 'gerado',
                'conta_pagar_id' => $contaPagar->id,
                'valor' => (float) $contaPagar->valor,
            ];
        }

        return $resultado;
    }
}
