<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O atendimento que o Pedro abriu (docs/specs/suporte-pedro-whatsapp/conhecimento.md):
 * prioridade e prazo do roteiro, e o nº do Gestor que quem atende é obrigado a
 * informar quando o destino é o Gestor.
 */
class SuporteAtendimento extends Model
{
    protected $table = 'suporte_atendimentos';

    protected $fillable = ['tarefa_id', 'conversa_id', 'roteiro_id', 'prioridade', 'prazo_em', 'destino', 'gestor_cliente_id',
        'gestor_numero', 'gestor_registrado_por', 'gestor_registrado_em', 'ultima_cobranca_em', 'cobrancas'];

    protected function casts(): array
    {
        return ['prazo_em' => 'datetime', 'gestor_registrado_em' => 'datetime', 'ultima_cobranca_em' => 'datetime'];
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }

    public function roteiro(): BelongsTo
    {
        return $this->belongsTo(SuporteRoteiro::class, 'roteiro_id');
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'gestor_registrado_por');
    }

    /** Falta lançar no Gestor e informar o número aqui. */
    public function faltaNumeroDoGestor(): bool
    {
        return $this->destino === 'gestor' && blank($this->gestor_numero);
    }
}
