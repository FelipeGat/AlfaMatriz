<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Um orçamento que um proprietário confirmou com o "sim", à espera da máquina que o grava no Gestor (pedro-orcamento). */
class SuporteGestorOrcamento extends Model
{
    protected $table = 'suporte_gestor_orcamentos';

    protected $fillable = ['conversa_id', 'chave', 'pedido_por', 'pedido', 'situacao', 'resultado', 'erro', 'reservado_em'];

    protected function casts(): array
    {
        return ['pedido' => 'array', 'resultado' => 'array', 'reservado_em' => 'datetime'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }
}
