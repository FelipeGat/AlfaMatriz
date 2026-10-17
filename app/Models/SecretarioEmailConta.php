<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Caixa IMAP de UMA pessoa. Senha cifrada (APP_KEY) e fora de qualquer serialização (FR-002). */
class SecretarioEmailConta extends Model
{
    protected $table = 'secretario_email_contas';

    protected $fillable = ['user_id', 'rotulo', 'host', 'porta', 'seguranca', 'usuario', 'senha', 'pasta_entrada', 'pasta_enviados', 'posicoes', 'situacao', 'erro', 'lida_em'];

    protected $hidden = ['senha'];

    protected $attributes = ['situacao' => 'ok', 'pasta_entrada' => 'INBOX'];

    protected function casts(): array
    {
        return ['senha' => 'encrypted', 'posicoes' => 'array', 'lida_em' => 'datetime', 'porta' => 'integer'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** O domínio da própria caixa: e-mail vindo dele não é "de fora". */
    public function dominio(): string
    {
        return mb_strtolower((string) substr(strrchr($this->usuario, '@') ?: '', 1));
    }
}
