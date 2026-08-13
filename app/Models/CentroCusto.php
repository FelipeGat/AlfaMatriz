<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CentroCusto extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'financeiro';

    protected $table = 'centros_custo';

    protected $fillable = ['nome', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function contasPagar(): HasMany
    {
        return $this->hasMany(ContaPagar::class);
    }
}
