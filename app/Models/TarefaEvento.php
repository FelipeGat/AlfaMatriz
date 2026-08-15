<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TarefaEvento extends Model
{
    protected $fillable = [
        'tarefa_id', 'user_id', 'de_status', 'para_status', 'motivo', 'entrou_em', 'saiu_em', 'duracao_segundos',
    ];

    protected function casts(): array
    {
        return [
            'entrou_em' => 'datetime',
            'saiu_em' => 'datetime',
        ];
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    /**
     * Quem fez o movimento. Nulo em dois casos legítimos: o evento anterior à
     * coluna (o autor nunca foi gravado) e o movimento sem sessão. A exibição
     * trata o nulo; ninguém deve assumir que há autor.
     */
    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
