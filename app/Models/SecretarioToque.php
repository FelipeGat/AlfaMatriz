<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Toque já tratado num botão do Telegram (o reenvio do mesmo toque não faz nada). */
class SecretarioToque extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'secretario_toques';

    protected $fillable = ['callback_id', 'user_id'];
}
