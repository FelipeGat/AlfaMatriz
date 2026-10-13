<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaConversa;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaTelegramVinculo;
use App\Models\CentralIaTarefa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Estado de atendimento; o histórico permanece único e privado. */
class CanalAtivo
{
    public function pronto(): bool
    {
        // Permite publicar código antes da migração, sem quebrar o consumidor existente.
        return config('central_ia.canal_exclusivo', true) && Schema::hasColumn('central_ia_conversas', 'canal_ativo');
    }

    public function estado(User $user): ?CentralIaConversa
    {
        return CentralIaConversa::where('user_id', $user->id)->orderBy('id')->first();
    }

    public function ativo(User $user): string
    {
        return $this->estado($user)?->canal_ativo ?? 'web';
    }

    public function exigir(User $user, string $origem): void
    {
        if ($this->pronto() && $this->ativo($user) !== $origem) {
            throw ValidationException::withMessages(['canal' => $origem === 'web'
                ? 'A conversa está no Telegram. Toque em Retomar no AlfaMatriz antes de enviar.'
                : 'A conversa está no AlfaMatriz. Toque em Continuar pelo Telegram no sistema para ativar este canal.']);
        }
    }

    public function transferir(User $user, string $canal, ?string $projeto = null, ?int $tarefa = null): CentralIaConversa
    {
        abort_unless($this->pronto(), 503, 'Troca de canal aguardando ativação local.');
        return DB::transaction(function () use ($user, $canal, $projeto, $tarefa) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $conversa = app(ConversaService::class)->conversaDe($user);
            if ($canal === 'telegram') {
                $vinculo = CentralIaTelegramVinculo::where('user_id', $user->id)->first();
                if (! $vinculo) throw ValidationException::withMessages(['canal' => 'Vincule seu Telegram primeiro, em Ajustes.']);
                // Tarefa da conversa Geral tem projeto '' (e não nulo).
                $doChat = $tarefa ? CentralIaTarefa::de($user)->where('projeto', $projeto ?? '')->findOrFail($tarefa) : null;
                if ($projeto && ! in_array($projeto, app(AgentesService::class)->disponiveis($user)['projetos'], true)
                    && ! \App\Models\CentralIaProjeto::where('user_id', $user->id)->where('projeto', $projeto)->exists()
                    && ! CentralIaMensagem::de($user)->where('projeto', $projeto)->exists()) {
                    throw ValidationException::withMessages(['projeto' => 'Projeto não disponível para sua conta.']);
                }
                // Reenvio da mesma transferência não gera outra confirmação.
                if ($conversa->canal_ativo === 'telegram' && $conversa->projeto_canal === $projeto && $conversa->tarefa_canal == $tarefa) return $conversa;
                // Chat de assunto (Business): o Telegram aponta para ele, e não
                // para o projeto — senão as mensagens cairiam na Geral.
                $vinculo->update(['projeto_atual' => $doChat?->ehAssunto() ? null : $projeto, 'assunto_atual_id' => $doChat?->ehAssunto() ? $doChat->id : null]);
            } elseif ($conversa->canal_ativo === 'web') {
                return $conversa;
            }
            $conversa->forceFill(['canal_ativo' => $canal, 'projeto_canal' => $projeto, 'tarefa_canal' => $tarefa,
                'canal_desde_id' => CentralIaMensagem::de($user)->max('id') ?? 0])->save();
            Eventos::registrar($user, 'canal_trocado', 'Atendimento transferido para '.($canal === 'web' ? 'AlfaMatriz' : 'Telegram'),
                ['origem' => $canal, 'projeto' => $projeto, 'tarefa_id' => $tarefa]);
            // Confirmação curta persistida, entregue pela fila existente, nunca chama IA.
            if ($canal === 'telegram') app(ConversaService::class)->registrarDoAgente($user, 'Central de IA',
                'Conversa ativada no Telegram · '.($doChat?->ehAssunto() ? '“'.$doChat->titulo.'”' : ($projeto ?: 'Geral').($tarefa ? ' · tarefa #'.$tarefa : '')).'. Continue por aqui. Para voltar ao sistema, envie /matriz.',
                CentralIaMensagem::AUTOR_SISTEMA, $projeto, $tarefa);
            return $conversa;
        });
    }

    public function entregaPermitida(User $user, CentralIaMensagem $mensagem): bool
    {
        if (! $this->pronto()) return true;
        $c = $this->estado($user);
        return $c && $c->canal_ativo === 'telegram' && $mensagem->id > $c->canal_desde_id
            && $mensagem->projeto === $c->projeto_canal
            && (! $c->tarefa_canal || $mensagem->tarefa_id == $c->tarefa_canal);
    }
}
