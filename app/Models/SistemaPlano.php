<?php

namespace App\Models;

use App\Models\Concerns\EspelhaSistema;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Um plano oferecido DENTRO de um sistema.
 *
 * Existe para a matriz poder escolher um plano ao liberar licença, em vez de
 * mandar texto livre e torcer para o sistema reconhecer. Não confundir com
 * `PrecoAtacado`, que é o preço que a Alfa cobra da revenda — coisa diferente.
 */
class SistemaPlano extends Model
{
    use EspelhaSistema, HasFactory;

    protected $table = 'sistema_planos';

    protected $fillable = [
        'sistema_id', 'id_externo', 'nome', 'ativo', 'preco_mensal', 'moeda',
        'limites', 'ausente_em_origem_em', 'payload', 'sincronizado_em',
    ];

    protected $attributes = [
        'ativo' => true,
        'moeda' => 'BRL',
    ];

    protected function casts(): array
    {
        return [
            'ativo' => 'boolean',
            'preco_mensal' => 'decimal:2',
            'limites' => 'array',
            'payload' => 'array',
            'ausente_em_origem_em' => 'datetime',
            'sincronizado_em' => 'datetime',
        ];
    }
}
