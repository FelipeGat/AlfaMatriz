<?php

namespace App\Models;

use App\Concerns\Auditavel;
use App\Services\ArquivoDeTarefas;
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

    /**
     * O comentário de quem abriu uma tarefa arquivada a traz de volta (#208).
     *
     * No evento do modelo, e não em quem comenta: são cinco caminhos que
     * escrevem comentário (a rota, o salvar, perguntar, responder e o MCP), e
     * a promessa do aviso — "responda aqui que ela volta" — não pode valer
     * por um e falhar por outro.
     */
    protected static function booted(): void
    {
        static::created(function (TarefaComentario $comentario): void {
            app(ArquivoDeTarefas::class)->reabrirSeQuemAbriuRespondeu($comentario);
        });
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
