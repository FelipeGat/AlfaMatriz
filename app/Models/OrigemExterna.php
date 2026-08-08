<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Âncora de um registro local num sistema externo, por (sistema, id_externo).
 *
 * O mesmo cliente pode existir no AlfaGym (id_externo 7) e no AlfaControl
 * (id_externo 3): cada origem vira uma linha própria, e a sincronização de
 * cada sistema resolve a entidade pela âncora dele, sem colidir.
 */
class OrigemExterna extends Model
{
    protected $table = 'origens_externas';

    protected $fillable = ['entidade_type', 'entidade_id', 'sistema_id', 'id_externo'];

    public function entidade(): MorphTo
    {
        return $this->morphTo();
    }

    public function sistema(): BelongsTo
    {
        return $this->belongsTo(Sistema::class);
    }
}
