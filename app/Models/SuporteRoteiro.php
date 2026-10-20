<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Um roteiro do Pedro: como reconhecer a situação, o que fazer e a mensagem padrão (Felipe, 20/10/2026). */
class SuporteRoteiro extends Model
{
    protected $table = 'suporte_roteiros';

    public const PRIORIDADES = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'critica' => 'Crítica'];

    protected $fillable = ['titulo', 'quando', 'orientacao', 'mensagem', 'prioridade', 'prazo_horas', 'abre_atendimento', 'ativo', 'ordem', 'atualizado_por'];

    public function atualizadoPor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'atualizado_por');
    }

    protected function casts(): array
    {
        return ['abre_atendimento' => 'boolean', 'ativo' => 'boolean', 'prazo_horas' => 'integer', 'ordem' => 'integer'];
    }
}
