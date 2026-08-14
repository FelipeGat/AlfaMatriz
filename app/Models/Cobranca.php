<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Cobranca extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'cobrancas';

    protected $fillable = [
        'revenda_id', 'cliente_id', 'sistema_id', 'conta_financeira_id',
        'descricao', 'valor', 'data_vencimento', 'data_pagamento', 'valor_pago',
        'status', 'tipo', 'competencia', 'forma_pagamento', 'detalhamento',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'valor_pago' => 'decimal:2',
            'data_vencimento' => 'date',
            'data_pagamento' => 'date',
            'detalhamento' => 'array',
        ];
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class);
    }

    public function anexos(): HasMany
    {
        return $this->hasMany(CobrancaAnexo::class);
    }

    /**
     * Ver `Tarefa::booted()` — mesma armadilha: o cascade do banco leva as
     * linhas dos anexos e deixa os arquivos no disco, porque arquivo não tem
     * chave estrangeira.
     *
     * `deleting` e não `forceDeleting`: a receita não tem exclusão reversível,
     * então excluir aqui já é definitivo.
     */
    protected static function booted(): void
    {
        static::deleting(function (Cobranca $cobranca): void {
            Storage::disk('public')->delete($cobranca->anexos()->pluck('caminho')->all());
        });
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
            'tipo' => 'entrada',
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
