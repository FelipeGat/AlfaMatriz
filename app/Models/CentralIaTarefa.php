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

    /** As que aparecem no cockpit como trabalho em curso. */
    public const EM_CURSO = [self::ABERTA, self::EXECUTANDO, self::AGUARDANDO_VOCE, self::FALHOU, self::PAUSADA];

    protected $fillable = [
        'user_id', 'projeto', 'titulo', 'objetivo', 'status', 'agente_atual',
        'worktree', 'branch', 'commit_base', 'dossie', 'ultima_atividade_em',
    ];

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

    public function scopeEmCurso(Builder $query): Builder
    {
        return $query->whereIn('status', self::EM_CURSO);
    }
}
