<?php

namespace App\Models;

use App\Concerns\ComOrigemExterna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Revenda extends Model
{
    use ComOrigemExterna, SoftDeletes;

    protected $fillable = [
        'nome', 'cnpj', 'contato_nome', 'contato_email', 'contato_telefone', 'ativo',
    ];

    protected function casts(): array
    {
        return ['ativo' => 'boolean'];
    }

    public function origensExternas(): MorphMany
    {
        return $this->morphMany(OrigemExterna::class, 'entidade');
    }

    public function clientes(): HasMany
    {
        return $this->hasMany(Cliente::class);
    }

    public function precosAtacado(): HasMany
    {
        return $this->hasMany(PrecoAtacado::class);
    }

    public function cobrancas(): HasMany
    {
        return $this->hasMany(Cobranca::class);
    }
}
