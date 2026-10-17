<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SecretarioPreferencia extends Model
{
    public const PADROES = ['auto_confirmar' => false, 'antecedencia_padrao' => 30, 'telegram' => true,
        'briefing' => true, 'briefing_hora' => '07:00', 'briefing_dias' => [1, 2, 3, 4, 5]];

    protected $table = 'secretario_preferencias';

    protected $fillable = ['user_id', 'auto_confirmar', 'antecedencia_padrao', 'telegram', 'briefing', 'briefing_hora', 'briefing_dias'];

    protected function casts(): array
    {
        return ['auto_confirmar' => 'boolean', 'telegram' => 'boolean', 'briefing' => 'boolean', 'briefing_dias' => 'array'];
    }

    /** Sem linha valem os padrões: abrir a tela não grava nada. */
    public static function de(User $user): self
    {
        $p = self::where('user_id', $user->id)->first() ?? new self(['user_id' => $user->id] + self::PADROES);
        // Linha gravada antes da Etapa 3 vem com `briefing_dias` nulo: vale o padrão.
        $p->briefing_dias ??= self::PADROES['briefing_dias'];

        return $p;
    }
}
