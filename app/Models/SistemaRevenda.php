<?php

namespace App\Models;

use App\Models\Concerns\EspelhaSistema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma revenda como ela existe DENTRO de um sistema.
 *
 * Não confundir com `Revenda`, que é a revenda da matriz. A ponte entre as duas
 * é o campo `revenda_id`, preenchido por casamento de documento ou à mão.
 */
class SistemaRevenda extends Model
{
    use EspelhaSistema, HasFactory;

    protected $table = 'sistema_revendas';

    protected $fillable = [
        'sistema_id', 'id_externo', 'revenda_id', 'vinculo_origem',
        'nome', 'cnpj', 'email', 'telefone', 'ativo', 'clientes_ativos',
        'ausente_em_origem_em', 'payload', 'sincronizado_em',
    ];

    protected $attributes = [
        'ativo' => true,
        'clientes_ativos' => 0,
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'clientes_ativos' => 'integer',
            'payload' => 'array',
            'ausente_em_origem_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }

    public function revenda(): BelongsTo
    {
        return $this->belongsTo(Revenda::class);
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(SistemaCliente::class);
    }

    public function vinculada(): bool
    {
        return $this->revenda_id !== null;
    }

    /** Vínculo feito à mão não pode ser desfeito por execução automática. */
    public function vinculoEhManual(): bool
    {
        return $this->vinculo_origem === 'manual';
    }
}
