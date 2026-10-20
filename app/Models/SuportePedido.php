<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um pedido ao Pedro: responder a conversa até a mensagem `ate_mensagem_id` (docs/specs/suporte-pedro-whatsapp). */
class SuportePedido extends Model
{
    protected $table = 'suporte_pedidos';

    protected $fillable = ['conversa_id', 'ate_mensagem_id', 'situacao', 'maquina', 'reservado_em', 'acao', 'resumo', 'erro', 'roteiro_id'];

    protected function casts(): array
    {
        return ['reservado_em' => 'datetime'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }
}
