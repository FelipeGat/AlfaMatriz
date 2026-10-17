<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Só os metadados de uma mensagem (o corpo nunca é guardado — FR-004). */
class SecretarioEmail extends Model
{
    protected $table = 'secretario_emails';

    protected $fillable = ['user_id', 'conta_id', 'pasta', 'uidvalidity', 'uid', 'message_id', 'responde_a', 'de_nome', 'de_email', 'assunto',
        'recebido_em', 'tipo', 'urgencia', 'pede_retorno', 'enviado', 'respondido', 'lido'];

    protected function casts(): array
    {
        return ['recebido_em' => 'datetime', 'pede_retorno' => 'boolean', 'enviado' => 'boolean', 'respondido' => 'boolean', 'lido' => 'boolean'];
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /** Aguardando resposta: pede retorno, veio de fora, e não há resposta nos enviados. */
    public function scopeAguardando(Builder $query): Builder
    {
        return $query->where('enviado', false)->where('pede_retorno', true)->where('respondido', false);
    }

    public function conta(): BelongsTo
    {
        return $this->belongsTo(SecretarioEmailConta::class, 'conta_id');
    }

    /** Link para abrir no Gmail (busca pelo Message-ID); outros provedores mostram o identificador. */
    public function link(): ?string
    {
        $host = $this->conta?->host ?? '';
        if ($this->message_id && str_contains($host, 'gmail.com')) {
            return 'https://mail.google.com/mail/u/0/#search/rfc822msgid%3A'.rawurlencode(trim($this->message_id, '<>'));
        }

        return null;
    }
}
