<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MovimentacaoFinanceira extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'financeiro';

    protected $table = 'movimentacoes_financeiras';

    protected $fillable = [
        'conta_financeira_id', 'tipo', 'descricao', 'valor', 'saldo_resultante', 'data',
    ];

    protected function casts(): array
    {
        return [
            'valor' => 'decimal:2',
            'saldo_resultante' => 'decimal:2',
            'data' => 'date',
        ];
    }

    public function contaFinanceira(): BelongsTo
    {
        return $this->belongsTo(ContaFinanceira::class);
    }

    public function origem(): MorphTo
    {
        return $this->morphTo();
    }
}
