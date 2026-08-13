<?php

namespace App\Models;

use App\Concerns\Auditavel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um item do checklist da tarefa.
 *
 * Não tem responsável nem etapa de propósito (ver a migração): ele é passo
 * dentro de uma tarefa, não trabalho com vida própria.
 */
class TarefaItem extends Model
{
    use Auditavel;

    protected string $recursoAuditoria = 'tarefas';

    /** A posição na lista é arrumação, não fato — ver `Tarefa`. */
    public function camposForaDaAuditoria(): array
    {
        return ['ordem'];
    }

    public function descricaoDeAuditoria(): string
    {
        return (string) $this->texto;
    }

    protected $table = 'tarefa_itens';

    protected $fillable = ['tarefa_id', 'texto', 'feito', 'ordem'];

    /**
     * O padrão do banco repetido aqui de propósito.
     *
     * Sem isto, o item recém-criado fica com `feito` nulo em memória — o banco
     * grava `false`, mas o objeto que volta do `create()` responde `null`, e
     * quem contar os feitos logo depois de incluir conta errado.
     */
    protected $attributes = ['feito' => false];

    protected function casts(): array
    {
        return [
            'feito' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // O item novo entra no FIM da lista. Sem isto, todo item nasceria com
        // ordem 0 e a lista se reorganizaria sozinha a cada inclusão — o
        // checklist é uma sequência, e sequência que se embaralha não serve
        // para conferir nada.
        static::creating(function (TarefaItem $item): void {
            $item->ordem ??= (int) static::where('tarefa_id', $item->tarefa_id)->max('ordem') + 1;
        });
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(Tarefa::class);
    }
}
