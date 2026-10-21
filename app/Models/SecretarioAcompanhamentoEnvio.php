<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Um resumo de acompanhamentos, num canal: o registro que a Fase 8.2 pede. Só ids, nunca o texto. */
class SecretarioAcompanhamentoEnvio extends Model
{
    public const PENDENTE = 'pendente';
    public const ENVIANDO = 'enviando';
    public const ENTREGUE = 'entregue';
    public const FALHOU = 'falhou';

    protected $table = 'secretario_acompanhamento_envios';

    protected $attributes = ['status' => self::PENDENTE, 'tentativas' => 0];

    protected $fillable = ['user_id', 'canal', 'status', 'tentativas', 'proxima_tentativa_em', 'acompanhamentos', 'quantos',
        'telegram_message_id', 'erro', 'entregue_em'];

    protected function casts(): array
    {
        return ['acompanhamentos' => 'array', 'proxima_tentativa_em' => 'datetime', 'entregue_em' => 'datetime'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
