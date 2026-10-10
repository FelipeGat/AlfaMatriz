<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CentralIaMensagem extends Model
{
    protected $table = 'central_ia_mensagens';

    public const AUTOR_USUARIO = 'usuario';

    public const AUTOR_AGENTE = 'agente';

    public const AUTOR_SISTEMA = 'sistema';

    public const ORIGEM_WEB = 'web';

    public const ORIGEM_TELEGRAM = 'telegram';

    public const ORIGEM_AGENTE = 'agente';

    protected $fillable = [
        'conversa_id', 'projeto', 'tarefa_id', 'user_id', 'client_id', 'autor_tipo', 'autor_nome',
        'origem', 'conteudo', 'telegram_update_id',
    ];

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(CentralIaConversa::class, 'conversa_id');
    }

    /** Quando a mensagem é um pedido a agente. */
    public function execucao(): HasOne
    {
        return $this->hasOne(CentralIaExecucao::class, 'pedido_id');
    }

    public function entrega(): HasOne
    {
        return $this->hasOne(CentralIaEntrega::class, 'mensagem_id');
    }

    /**
     * A porta de toda leitura: mensagem só sai para o próprio dono.
     */
    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
