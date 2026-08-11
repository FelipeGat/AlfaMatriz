<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um comentário da tarefa: texto puro, do jeito que foi digitado.
 *
 * Não há marcação nenhuma — nem markdown, nem lista, nem negrito. O corpo sai
 * na tela pelo escape normal do Blade (`{{ }}`), com as quebras de linha
 * preservadas pelo CSS: nada que se digite aqui vira HTML, e por isso não há
 * conversão para auditar nem sanitizador para manter.
 */
class TarefaComentario extends Model
{
    protected $table = 'tarefa_comentarios';

    protected $fillable = ['tarefa_id', 'autor_id', 'corpo', 'editado_em'];

    protected function casts(): array
    {
        return [
            'editado_em' => 'datetime',
        ];
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
