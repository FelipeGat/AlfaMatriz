<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Uma entrega de um item, num horário e num canal. A chave única impede a segunda. */
class SecretarioAviso extends Model
{
    public const PENDENTE = 'pendente';
    public const ENVIANDO = 'enviando';
    public const ENTREGUE = 'entregue';
    public const FALHOU = 'falhou';
    public const INCERTA = 'incerta';
    public const CANCELADO = 'cancelado';

    public const SINO = 'sino';
    public const TELEGRAM = 'telegram';

    protected $table = 'secretario_avisos';

    /** Os padrões da tabela também no objeto recém-criado (sem eles, `status` nulo parecia encerrado). */
    protected $attributes = ['status' => self::PENDENTE, 'tentativas' => 0, 'atrasado' => false];

    protected $fillable = [
        'item_id', 'user_id', 'para', 'canal', 'status', 'tentativas', 'proxima_tentativa_em', 'atrasado',
        'telegram_message_id', 'erro', 'entregue_em',
    ];

    protected function casts(): array
    {
        return ['para' => 'datetime', 'proxima_tentativa_em' => 'datetime', 'entregue_em' => 'datetime', 'atrasado' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(SecretarioItem::class, 'item_id');
    }
}
