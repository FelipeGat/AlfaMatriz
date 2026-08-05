<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaturamentoSnapshot extends Model
{
    protected $table = 'faturamento_snapshot';

    protected $fillable = [
        'competencia', 'sistema_id', 'revenda_id', 'clientes_ativos',
        'valor_unitario', 'total', 'cobranca_id',
    ];

    protected function casts(): array
    {
        return [
            'valor_unitario' => 'decimal:2',
            'total' => 'decimal:2',
        ];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function cobranca(): BelongsTo
    {
        return $this->belongsTo(Cobranca::class);
    }
}
