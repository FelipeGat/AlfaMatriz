<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TarefaEvento extends Model
{
    protected $fillable = [
        'tarefa_id', 'de_status', 'para_status', 'motivo', 'entrou_em', 'saiu_em', 'duracao_segundos',
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
}
