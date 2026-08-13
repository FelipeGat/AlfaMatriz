<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Categoria extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'financeiro';

    protected $fillable = ['nome', 'tipo', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function subcategorias(): HasMany
    {
        return $this->hasMany(Subcategoria::class);
    }
}
