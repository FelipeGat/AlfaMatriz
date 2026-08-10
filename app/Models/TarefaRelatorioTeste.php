<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TarefaRelatorioTeste extends Model
{
    protected $table = 'tarefa_relatorios_teste';

    protected $fillable = [
        'tarefa_id', 'aprovado', 'notas',
    ];

    protected function casts(): array
    {
        return [
            'aprovado' => 'boolean',
        ];
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }
}
