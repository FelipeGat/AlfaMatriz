<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um atendimento que o Pedro pediu para abrir no Gestor; quem grava é a máquina, pela ponte. */
class SuporteGestorAbertura extends Model
{
    protected $table = 'suporte_gestor_aberturas';

    protected $fillable = ['conversa_id', 'gestor_cliente_id', 'assunto_id', 'modo', 'atendimento_numero', 'pedido_por', 'funcionario_id', 'agendar_data', 'agendar_periodo', 'agendamento', 'titulo', 'novo_pre', 'hora_inicio', 'hora_fim', 'empresa_id', 'descricao', 'solicitante', 'telefone', 'prioridade', 'situacao', 'numero', 'erro', 'reservado_em'];

    protected function casts(): array
    {
        return ['reservado_em' => 'datetime', 'agendar_data' => 'date', 'novo_pre' => 'array'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }
}
