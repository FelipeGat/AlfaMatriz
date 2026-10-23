<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma mudança confirmada na agenda dos técnicos, à espera da máquina que grava no Gestor (pedro-dia-do-tecnico). */
class SuporteGestorAgenda extends Model
{
    protected $table = 'suporte_gestor_agenda';

    protected $fillable = ['conversa_id', 'chave', 'lote', 'tipo', 'visita_id', 'atendimento', 'tecnico_id', 'inicio', 'fim', 'esperado',
        'motivo', 'observacao', 'pedido_por', 'situacao', 'resultado', 'erro', 'reservado_em'];

    protected function casts(): array
    {
        return ['esperado' => 'array', 'resultado' => 'array', 'reservado_em' => 'datetime'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }
}
