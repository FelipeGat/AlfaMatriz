<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A conversa de uma pessoa com a Central de IA.
 *
 * Hoje cada conta tem uma só, a mesma na tela e no Telegram. A tabela não
 * impõe isso de propósito: conversa por projeto é o próximo passo provável.
 */
class CentralIaConversa extends Model
{
    protected $table = 'central_ia_conversas';

    protected $fillable = ['user_id', 'titulo'];

    protected $casts = ['tarefa_canal' => 'integer', 'canal_desde_id' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(CentralIaMensagem::class, 'conversa_id');
    }
}
