<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da conversa registrada no lead — data da última ligação, o que
 * ficou combinado, o que ainda falta para fechar. É o histórico; o estado
 * ATUAL de "o que falta" mora em `Lead::proximo_passo`.
 */
class LeadComentario extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'leads';

    protected $table = 'lead_comentarios';

    protected $fillable = ['lead_id', 'autor_id', 'texto'];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function descricaoDeAuditoria(): string
    {
        return 'comentário em '.($this->lead?->nome ?? 'lead removido');
    }
}
