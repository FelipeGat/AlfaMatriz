<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma mensagem da conversa de suporte: do cliente, do Pedro ou de um humano (pelo app ou pelo Matriz). */
class SuporteMensagem extends Model
{
    public const DIRECOES = ['entrada' => 'Cliente', 'pedro' => 'Pedro', 'humano_app' => 'Humano (celular)', 'humano_matriz' => 'Humano (Matriz)'];

    protected $table = 'suporte_mensagens';

    protected $fillable = ['conversa_id', 'wamid', 'direcao', 'tipo', 'texto', 'autor_id', 'status', 'erro', 'enviada_em', 'midia_path', 'midia_mime', 'transcricao'];

    protected function casts(): array
    {
        return ['enviada_em' => 'datetime'];
    }

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(SuporteConversa::class, 'conversa_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }

    public function daEmpresa(): bool
    {
        return $this->direcao !== 'entrada';
    }
}
