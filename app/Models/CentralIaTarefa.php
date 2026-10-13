<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma frente de trabalho num projeto: junta pedidos, respostas e execuções,
 * e guarda o dossiê que sobrevive à troca de agente.
 */
class CentralIaTarefa extends Model
{
    protected $table = 'central_ia_tarefas';

    public const ABERTA = 'aberta';

    public const EXECUTANDO = 'executando';

    /** O agente parou com uma dúvida, ou pediu deploy: a bola está com a pessoa. */
    public const AGUARDANDO_VOCE = 'aguardando_voce';

    public const FALHOU = 'falhou';

    public const CONCLUIDA = 'concluida';

    public const PAUSADA = 'pausada';
    public const ARQUIVADA = 'arquivada';

    /**
     * Tipos: a tarefa comum termina; o ASSUNTO (Chats Business) é um chat
     * contínuo por tema — "Comercial VixCard" — que nunca se conclui sozinho
     * e cuja memória qualquer agente recebe (docs/specs/chats-business).
     */
    public const TAREFA = 'tarefa';

    public const ASSUNTO = 'assunto';

    /** As que aparecem no cockpit como trabalho em curso. */
    public const EM_CURSO = [self::ABERTA, self::EXECUTANDO, self::AGUARDANDO_VOCE, self::FALHOU, self::PAUSADA];

    protected $fillable = [
        'user_id', 'projeto', 'titulo', 'objetivo', 'status', 'agente_atual',
        'worktree', 'branch', 'commit_base', 'dossie', 'ultima_atividade_em', 'tipo', 'grupo_id',
    ];

    protected $attributes = ['tipo' => self::TAREFA];

    protected $casts = [
        'dossie' => 'array',
        'ultima_atividade_em' => 'datetime',
    ];

    public function execucoes(): HasMany
    {
        return $this->hasMany(CentralIaExecucao::class, 'tarefa_id');
    }

    public function scopeDe(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    public function grupo(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(CentralIaGrupo::class, 'grupo_id');
    }

    public function ehAssunto(): bool
    {
        return $this->tipo === self::ASSUNTO;
    }

    public function scopeAssuntos(Builder $query): Builder
    {
        return $query->where('tipo', self::ASSUNTO);
    }

    /** Só as tarefas comuns: Chats Ativos, Projetos e a aba Tarefas não listam assunto. */
    public function scopeTarefas(Builder $query): Builder
    {
        return $query->where('tipo', self::TAREFA);
    }

    public function scopeEmCurso(Builder $query): Builder
    {
        return $query->whereIn('status', self::EM_CURSO);
    }
}
