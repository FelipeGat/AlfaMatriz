<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A ida de uma mensagem ao Telegram.
 *
 * `enviando` é marcado ANTES de chamar a API: se o processo morre no meio, a
 * entrega fica presa em `enviando` e vira `incerta` na próxima volta, em vez
 * de voltar a `pendente` e sair de novo.
 */
class CentralIaEntrega extends Model
{
    protected $table = 'central_ia_entregas';

    public const PENDENTE = 'pendente';

    public const ENVIANDO = 'enviando';

    public const ENTREGUE = 'entregue';

    public const INCERTA = 'incerta';

    public const FALHOU = 'falhou';

    protected $fillable = ['mensagem_id', 'user_id', 'chat_id', 'status', 'tentativas', 'telegram_message_id', 'erro'];

    protected $casts = ['chat_id' => 'integer'];

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(CentralIaMensagem::class, 'mensagem_id');
    }
}
