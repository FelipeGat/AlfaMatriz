<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A avaliação pedida ao encerrar um atendimento do WhatsApp (docs/specs/suporte-pedro-whatsapp/encerramento.md). */
class SuporteAvaliacao extends Model
{
    protected $table = 'suporte_avaliacoes';

    protected $fillable = ['conversa_id', 'atendente_id', 'encerrado_por_id', 'nota', 'comentario', 'respondida_em'];

    public const NOTAS = [5 => 'Excelente', 4 => 'Bom', 3 => 'Regular', 2 => 'Ruim', 1 => 'Péssimo'];

    protected function casts(): array
    {
        return ['nota' => 'integer', 'respondida_em' => 'datetime'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }

    public function atendente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'atendente_id');
    }
}
