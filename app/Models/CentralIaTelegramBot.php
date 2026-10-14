<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O bot do Telegram de uma pessoa na Central (T-374). O token nunca aparece
 * na tela nem no JSON: `$hidden` e o cast `encrypted`.
 */
class CentralIaTelegramBot extends Model
{
    public const OK = 'ok';

    public const TOKEN_INVALIDO = 'token_invalido';

    public const WEBHOOK = 'webhook';

    public const CONFLITO = 'conflito';

    protected $table = 'central_ia_telegram_bots';

    protected $fillable = ['user_id', 'telegram_bot_id', 'usuario', 'token', 'servidor', 'proximo_update_id', 'situacao', 'situacao_em'];

    protected $hidden = ['token'];

    protected $casts = [
        'token' => 'encrypted',
        'telegram_bot_id' => 'integer',
        'proximo_update_id' => 'integer',
        'situacao_em' => 'datetime',
    ];

    /** A marca do servidor que ouve este bot: o hash do APP_URL. */
    public static function servidorAtual(): string
    {
        return hash('sha256', rtrim((string) config('app.url'), '/'));
    }

    /** Os bots que ESTE servidor ouve — nunca os de uma cópia do banco. */
    public function scopeDesteServidor(Builder $q): Builder
    {
        return $q->where('servidor', self::servidorAtual());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
