<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CentralIaTelegramVinculo extends Model
{
    protected $table = 'central_ia_telegram_vinculos';

    protected $fillable = ['user_id', 'chat_id', 'telegram_usuario', 'projeto_atual', 'agente_atual', 'assunto_atual_id'];

    protected $casts = ['chat_id' => 'integer'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
