<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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
    use Auditavel;

    protected string $recursoAuditoria = 'tarefas';

    /**
     * O comentário se apresenta pelo começo do próprio texto: é o que permite
     * reconhecê-lo numa lista sem abrir a tarefa. O corpo inteiro fica no
     * antes/depois, que é onde a edição de um comentário publicado se lê.
     */
    public function descricaoDeAuditoria(): string
    {
        return Str::limit((string) $this->corpo, 60);
    }

    protected $table = 'tarefa_comentarios';

    protected $fillable = ['tarefa_id', 'autor_id', 'corpo', 'editado_em', 'pergunta'];

    protected function casts(): array
    {
        return [
            'editado_em' => 'datetime',
            'pergunta' => 'boolean',
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
