<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrecoAtacado extends Model
{
    use HasFactory;

    protected $table = 'precos_atacado';

    protected $fillable = [
        'sistema_id', 'revenda_id', 'nome', 'preco_base', 'unidades_inclusas',
        'valor_excedente_unidade', 'limite_unidades', 'ordem', 'vigencia_inicio', 'vigencia_fim',
    ];

    protected function casts(): array
    {
        return [
            'preco_base' => 'decimal:2',
            'valor_excedente_unidade' => 'decimal:2',
            'unidades_inclusas' => 'integer',
            'limite_unidades' => 'integer',
            'ordem' => 'integer',
            'vigencia_inicio' => 'date',
            'vigencia_fim' => 'date',
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

    public function ehMetrado(): bool
    {
        return (int) $this->unidades_inclusas === 0 && $this->valor_excedente_unidade !== null;
    }

    public function ehTierFechado(): bool
    {
        return $this->valor_excedente_unidade === null;
    }

    public function comportaUnidades(int $unidades): bool
    {
        return $this->limite_unidades === null || $unidades <= $this->limite_unidades;
    }

    /**
     * Calcula a mensalidade para uma quantidade de unidades ativas.
     * Retorna null se o tier não comporta esse volume (precisa fazer upgrade).
     */
    public function calcularMensalidade(int $unidadesAtivas): ?float
    {
        if (! $this->comportaUnidades($unidadesAtivas)) {
            return null;
        }

        $incluidas = $this->unidades_inclusas ?? 0;

        if ($unidadesAtivas <= $incluidas) {
            return (float) $this->preco_base;
        }

        if ($this->valor_excedente_unidade === null) {
            return (float) $this->preco_base;
        }

        $excedente = $unidadesAtivas - $incluidas;

        return (float) $this->preco_base + ($excedente * (float) $this->valor_excedente_unidade);
    }
}
