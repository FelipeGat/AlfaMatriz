<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conta extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'financeiro';

    protected $fillable = ['subcategoria_id', 'nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function subcategoria(): BelongsTo
    {
        return $this->belongsTo(Subcategoria::class);
    }

    public function contasPagar(): HasMany
    {
        return $this->hasMany(ContaPagar::class);
    }
}
