<?php

namespace App\Models;

use App\Concerns\Auditavel;
use App\Concerns\ComOrigemExterna;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Revenda extends Model
{
    use Auditavel, ComOrigemExterna, SoftDeletes;

    protected string $recursoAuditoria = 'revendas';

    protected $fillable = [
        'nome', 'cnpj', 'contato_nome', 'contato_email', 'contato_telefone', 'ativo',
        'data_cadastro',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'data_cadastro' => 'date',
        ];
    }

    /**
     * O dia em que a revenda entrou na base — ver `Cliente::data_entrada`, que
     * segue a mesma regra e existe pelo mesmo motivo: `created_at` é o dia da
     * importação para todo mundo de uma vez.
     */
    public function getDataEntradaAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->data_cadastro ?? $this->created_at;
    }

    /** A mesma regra em SQL, para filtro e ordenação. */
    public static function expressaoDeEntrada(): string
    {
        return 'COALESCE(revendas.data_cadastro, DATE(revendas.created_at))';
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
