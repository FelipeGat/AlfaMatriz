<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecretarioPreferencia extends Model
{
    public const PADROES = ['auto_confirmar' => false, 'antecedencia_padrao' => 30, 'telegram' => true];

    protected $table = 'secretario_preferencias';

    protected $fillable = ['user_id', 'auto_confirmar', 'antecedencia_padrao', 'telegram'];

    protected function casts(): array
    {
        return ['auto_confirmar' => 'boolean', 'telegram' => 'boolean'];
    }

    /** Sem linha valem os padrões: abrir a tela não grava nada. */
    public static function de(User $user): self
    {
        return self::where('user_id', $user->id)->first() ?? new self(['user_id' => $user->id] + self::PADROES);
    }
}
