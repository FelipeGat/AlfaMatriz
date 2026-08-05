<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContaPagar extends Model
{
    protected $table = 'contas_pagar';

    protected $fillable = [
        'conta_fixa_pagar_id', 'centro_custo_id', 'conta_id', 'fornecedor_id', 'conta_financeira_id',
        'descricao', 'valor', 'data_vencimento', 'data_pagamento', 'valor_pago',
        'status', 'tipo', 'competencia', 'forma_pagamento',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'valor_pago' => 'decimal:2',
            'data_vencimento' => 'date',
            'data_pagamento' => 'date',
        ];
    }

    public function centroCusto(): BelongsTo
    {
        return $this->belongsTo(CentroCusto::class);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(Conta::class);
    }

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class);
    }

    public function contaFixaPagar(): BelongsTo
    {
        return $this->belongsTo(ContaFixaPagar::class);
    }

    public function baixar(?float $valorPago = null, ?string $dataPagamento = null): void
    {
        if ($this->status === 'pago' || ! $this->conta_financeira_id) {
            return;
        }

        $valorPago ??= (float) $this->valor;
        $dataPagamento ??= now()->toDateString();

        $this->update([
            'status' => 'pago',
            'valor_pago' => $valorPago,
            'data_pagamento' => $dataPagamento,
        ]);

        $this->contaFinanceira->movimentacoes()->create([
            'conta_financeira_id' => $this->conta_financeira_id,
            'tipo' => 'saida',
            'descricao' => $this->descricao,
            'valor' => $valorPago,
            'saldo_resultante' => 0,
            'data' => $dataPagamento,
            'origem_type' => self::class,
            'origem_id' => $this->id,
        ]);

        $this->contaFinanceira->reprocessarSaldo();
    }
}
