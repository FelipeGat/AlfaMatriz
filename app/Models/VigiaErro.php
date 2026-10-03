<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um erro de log visto pelo vigia (#219), agrupado por assinatura.
 *
 * Uma linha por (sistema, ambiente, assinatura): as 36 falhas do Wellhub
 * eram UM erro, e é uma linha só — com a conta de quantas vezes e desde
 * quando. Ver `App\Services\Vigia\VigiaDeLogs`.
 *
 * Sem `Auditavel`, de propósito: a linha muda a cada lote que chega, de hora
 * em hora, e cada mudança é contagem — ruído que enterraria a tela de
 * auditoria. O fato que interessa (a tarefa aberta) já é auditado na tarefa.
 */
class VigiaErro extends Model
{
    protected $table = 'vigia_erros';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'primeira_vez' => 'datetime',
            'ultima_vez' => 'datetime',
            'comentado_em' => 'datetime',
            'pico_avisado_em' => 'datetime',
            'ignorado' => 'boolean',
            'total' => 'integer',
        ];
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }

    /** `withTrashed`: a tarefa apagada (soft) ainda diz que o erro teve dono. */
    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class)->withTrashed();
    }

    public function horas(): HasMany
    {
        return $this->hasMany(VigiaErroHora::class);
    }
}
