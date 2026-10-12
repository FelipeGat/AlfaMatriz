<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um pedido a um agente: quem pediu, para qual agente, em qual projeto, em
 * que modo — e o que aconteceu.
 */
class CentralIaExecucao extends Model
{
    protected $table = 'central_ia_execucoes';

    public const AGENTES = [
        'claude' => 'Claude',
        'codex' => 'GPT (Codex)',
        'copilot' => 'Copilot',
    ];

    /**
     * `analisar` lê e responde, direto na pasta do projeto. `implementar`
     * altera arquivos — numa worktree e branch próprias, para não misturar
     * com o que já está pendente no projeto nem com outra sessão aberta.
     */
    public const MODOS = [
        'analisar' => 'Analisar / responder',
        'implementar' => 'Implementar',
    ];

    public const PENDENTE = 'pendente';

    public const EXECUTANDO = 'executando';

    public const CONCLUIDA = 'concluida';

    public const FALHOU = 'falhou';

    public const EXPIRADA = 'expirada';

    /**
     * Estados da confirmação pedida pela máquina (ver
     * `AgentesService::confirmacao`). `aguardando` e `codigo_errado` esperam
     * a pessoa; os outros encerram a espera.
     */
    public const CONFIRMACAO_ESTADOS = ['aguardando', 'confirmada', 'codigo_errado', 'recusada', 'expirada', 'sem_canal'];

    public function aguardandoConfirmacao(): bool
    {
        return $this->status === self::EXECUTANDO
            && in_array($this->confirmacao['estado'] ?? null, ['aguardando', 'codigo_errado'], true);
    }

    protected $fillable = [
        'user_id', 'pedido_id', 'resposta_id', 'runner_id', 'tarefa_id', 'agente', 'projeto', 'modo', 'roteamento', 'checkpoint', 'instrucao',
        'progresso', 'progresso_em', 'status_avisado_em', 'confirmacao', 'status', 'sessao_agente', 'branch', 'erro', 'iniciada_em', 'concluida_em',
    ];

    protected $casts = [
        'roteamento' => 'array',
        'checkpoint' => 'array',
        'progresso' => 'array',
        'confirmacao' => 'array',
        'progresso_em' => 'datetime',
        'status_avisado_em' => 'datetime',
        'iniciada_em' => 'datetime',
        'concluida_em' => 'datetime',
    ];

    public function pedido(): BelongsTo
    {
        return $this->belongsTo(CentralIaMensagem::class, 'pedido_id');
    }

    public function tarefa(): BelongsTo
    {
        return $this->belongsTo(CentralIaTarefa::class, 'tarefa_id');
    }

    public function nomeDoAgente(): string
    {
        return self::AGENTES[$this->agente] ?? $this->agente;
    }
}
