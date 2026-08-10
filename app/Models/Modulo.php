<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um módulo contratável de um sistema — adicional cobrado à parte da licença.
 *
 * O catálogo é por sistema: FINANCEIRO do AlfaControl não é o FINANCEIRO de
 * outro produto, mesmo que o código coincida.
 */
class Modulo extends Model
{
    use HasFactory;

    protected $table = 'modulos';

    protected $fillable = ['sistema_id', 'codigo', 'nome', 'descricao', 'ativo'];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    public function contratacoes(): HasMany
    {
        return $this->hasMany(ClienteModulo::class);
    }
}
