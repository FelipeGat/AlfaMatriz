<?php

namespace App\Services\Secretario;

use App\Models\CentralIaMensagem;
use App\Models\User;
use App\Services\CentralIa\CanalAtivo;
use App\Services\CentralIa\ConversaService;
use Illuminate\Support\Facades\DB;

/**
 * O Secretário dentro da conversa da Central — no chat do AlfaMatriz e no
 * Telegram (research D10, D11). Quem não tem o perfil "Secretário" não passa
 * por aqui: para essa conta, nada muda (FR-010).
 */
class Conversa
{
    public function __construct(private Propostas $propostas, private ConversaService $conversas, private TelegramDoSecretario $telegram) {}

    public function pode(?User $user): bool
    {
        return $user !== null && $user->canPermissao('secretario', 'incluir');
    }

    /**
     * Chat do AlfaMatriz. Devolve a mensagem do usuário gravada, ou null se o
     * texto não é do Secretário (segue o fluxo da Central).
     */
    public function doWeb(User $user, string $texto, string $clientId, ?string $projeto, ?int $tarefaId): ?CentralIaMensagem
    {
        if (! $this->pode($user)) {
            return null;
        }
        // Reenvio do mesmo envio: já foi tratado, não interpreta de novo.
        if ($ja = CentralIaMensagem::de($user)->where('client_id', $clientId)->first()) {
            return $ja->conteudo === $texto && $this->foiDoSecretario($ja) ? $ja : null;
        }
        $r = $this->interpretar($user, $texto, 'matriz');
        if (! $r) {
            return null;
        }

        return DB::transaction(function () use ($user, $texto, $clientId, $projeto, $tarefaId, $r) {
            $m = $this->conversas->registrarDoUsuario($user, $texto, CentralIaMensagem::ORIGEM_WEB, $clientId, projeto: $projeto, tarefaId: $tarefaId);
            $resposta = $r['mensagem'];
            if ($r['proposta'] && ! $r['item'] && ! $r['proposta']->falta) {
                $resposta = preg_replace('/\nConfirma\?$/u', '', $resposta)."\nResponda **sim** para confirmar, ou diga a correção (ex.: \"não, às 10h\"). Também dá para confirmar em Secretário → Meu Dia.";
            }
            $this->conversas->registrarDoAgente($user, 'Secretário', $resposta, CentralIaMensagem::AUTOR_SISTEMA, $projeto, $tarefaId);

            return $m;
        });
    }

    /**
     * Telegram. true = tratado (já respondeu); false = não é do Secretário.
     * Funciona com o atendimento no AlfaMatriz: registrar um lembrete é
     * comando, não conversa — não tira a conversa da tela. Nesse caso o
     * pedido fica só no registro do Secretário (spec FR-010).
     */
    public function doTelegram(User $user, string $texto, int $updateId): bool
    {
        if (! $this->pode($user)) {
            return false;
        }
        if (CentralIaMensagem::where('user_id', $user->id)->where('telegram_update_id', $updateId)->exists()) {
            return true;
        }
        $r = $this->interpretar($user, $texto, 'telegram');
        if (! $r) {
            return false;
        }

        $canal = app(CanalAtivo::class);
        if (! $canal->pronto() || $canal->ativo($user) === 'telegram') {
            $estado = $canal->pronto() ? $canal->estado($user) : null;
            [$projeto, $tarefa] = [$estado?->projeto_canal, $estado?->tarefa_canal];
            DB::transaction(function () use ($user, $texto, $updateId, $r, $projeto, $tarefa) {
                $this->conversas->registrarDoUsuario($user, $texto, CentralIaMensagem::ORIGEM_TELEGRAM, telegramUpdateId: $updateId, projeto: $projeto, tarefaId: $tarefa);
                // A resposta já sai aqui embaixo, com botões: a outbox não a repete.
                $this->conversas->registrarDoAgente($user, 'Secretário', $r['mensagem'], CentralIaMensagem::AUTOR_SISTEMA, $projeto, $tarefa, entregar: false);
            });
        }

        if ($r['proposta'] && ! $r['item'] && ! $r['proposta']->falta) {
            $id = $this->telegram->enviarProposta($r['proposta'], $r['mensagem']);
            if ($id) {
                $r['proposta']->update(['telegram_message_id' => $id]);
            }
        } else {
            $this->telegram->responder($user, $r['mensagem']);
        }

        return true;
    }

    /**
     * Pergunta de agenda primeiro (Etapa 2: respondida com dado real, sem IA);
     * depois resposta a uma proposta pendente; depois pedido novo.
     */
    private function interpretar(User $user, string $texto, string $origem): ?array
    {
        // "Resume meu dia": o briefing de agora (Etapa 3), sem IA.
        $briefings = app(Briefings::class);
        if ($briefings->frase($texto)) {
            $b = $briefings->gerar($user, \Carbon\CarbonImmutable::now(), \App\Models\SecretarioBriefing::PEDIDO);

            return ['proposta' => null, 'item' => null, 'mensagem' => $b?->texto ?? 'Não consegui montar o resumo agora.'];
        }
        if (($agenda = app(ConsultaDeAgenda::class)->responder($user, $texto)) !== null) {
            return ['proposta' => null, 'item' => null, 'mensagem' => $agenda];
        }

        return $this->propostas->responder($user, $texto) ?? $this->propostas->criar($user, $texto, $origem);
    }

    private function foiDoSecretario(CentralIaMensagem $m): bool
    {
        return CentralIaMensagem::where('user_id', $m->user_id)->where('id', '>', $m->id)->orderBy('id')->value('autor_nome') === 'Secretário';
    }
}
