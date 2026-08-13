<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subcategoria extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'financeiro';

    protected $fillable = ['categoria_id', 'nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function contas(): HasMany
    {
        return $this->hasMany(Conta::class);
    }
}
