<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** O resumo do dia de UMA conta. Um automático por dia (chave única user_id+data+origem). */
class SecretarioBriefing extends Model
{
    public const AUTOMATICO = 'automatico';
    public const PEDIDO = 'pedido';

    protected $table = 'secretario_briefings';

    protected $fillable = ['user_id', 'data', 'origem', 'texto', 'fontes', 'telegram', 'tentativas', 'proxima_tentativa_em', 'gerado_em'];

    protected $attributes = ['telegram' => 'sem', 'tentativas' => 0];

    protected function casts(): array
    {
        return ['data' => 'date', 'fontes' => 'array', 'proxima_tentativa_em' => 'datetime', 'gerado_em' => 'datetime'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
