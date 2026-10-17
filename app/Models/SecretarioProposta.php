<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A interpretação de um pedido, esperando a pessoa confirmar. Nada vira item sem isso. */
class SecretarioProposta extends Model
{
    public const PENDENTE = 'pendente';
    public const CONFIRMADA = 'confirmada';
    public const DESCARTADA = 'descartada';
    public const EXPIRADA = 'expirada';

    public const VALIDADE_MIN = 30;

    protected $table = 'secretario_propostas';

    /** Os padrões da tabela também no objeto recém-criado (sem eles, `status` nulo parecia encerrado). */
    protected $attributes = ['status' => self::PENDENTE];

    protected $fillable = ['user_id', 'origem', 'texto', 'dados', 'falta', 'status', 'expira_em', 'item_id', 'execucao_id', 'telegram_message_id'];

    protected function casts(): array
    {
        return ['dados' => 'array', 'expira_em' => 'datetime'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function scopeAbertas(Builder $query): Builder
    {
        return $query->where('status', self::PENDENTE)->where('expira_em', '>', now());
    }
}
