<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Ações que a IA propôs enviar ao VixOrganize, validadas contra a fila e
 * esperando a pessoa responder "sim" no mesmo chat
 * (docs/specs/secretario-vixorganize). Nada vai sem isso.
 */
class SecretarioVixOrganizePedido extends Model
{
    public const PENDENTE = 'pendente';
    public const ENVIADO = 'enviado';
    public const DESCARTADO = 'descartado';

    public const VALIDADE_MIN = 60;

    protected $table = 'secretario_vixorganize_pedidos';

    protected $attributes = ['status' => self::PENDENTE];

    protected $fillable = ['user_id', 'tarefa_id', 'projeto', 'execucao_id', 'acoes', 'status', 'resultado', 'expira_em'];

    protected function casts(): array
    {
        return ['acoes' => 'array', 'resultado' => 'array', 'expira_em' => 'datetime'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeAbertos(Builder $query): Builder
    {
        return $query->where('status', self::PENDENTE)->where('expira_em', '>', now());
    }
}
