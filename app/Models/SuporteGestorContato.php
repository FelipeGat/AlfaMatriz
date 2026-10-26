<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A pessoa identificada ao Pedro, à espera da máquina que a grava como contato do cliente no Gestor (T-478/T-479). */
class SuporteGestorContato extends Model
{
    protected $table = 'suporte_gestor_contatos';

    protected $fillable = ['conversa_id', 'gestor_cliente_id', 'nome', 'papel', 'telefone', 'telefone_chave', 'situacao', 'erro', 'reservado_em'];

    protected function casts(): array
    {
        return ['reservado_em' => 'datetime'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }
}
