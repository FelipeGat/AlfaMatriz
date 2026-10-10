<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A máquina de uma pessoa, do jeito que o runner dela contou na última batida.
 */
class CentralIaRunner extends Model
{
    protected $table = 'central_ia_runners';

    /** Sem batida há mais que isto, a máquina está fora do ar. */
    public const ONLINE_SEGUNDOS = 90;

    protected $fillable = ['user_id', 'maquina', 'agentes', 'projetos', 'estado', 'consumo', 'sessoes', 'visto_em'];

    protected $casts = [
        'agentes' => 'array',
        'projetos' => 'array',
        'estado' => 'array',
        'consumo' => 'array',
        'sessoes' => 'array',
        'visto_em' => 'datetime',
    ];

    public function online(): bool
    {
        return $this->visto_em->greaterThan(now()->subSeconds(self::ONLINE_SEGUNDOS));
    }
}
