<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Fornecedor extends Model
{
    use Auditavel, SoftDeletes;

    protected string $recursoAuditoria = 'financeiro';

    protected $table = 'fornecedores';

    protected $fillable = [
        'razao_social', 'nome_fantasia', 'cpf_cnpj', 'email', 'telefone', 'ativo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function contasPagar(): HasMany
    {
        return $this->hasMany(ContaPagar::class);
    }
}
