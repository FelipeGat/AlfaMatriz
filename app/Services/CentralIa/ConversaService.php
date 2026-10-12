<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaConversa;
use App\Models\CentralIaEntrega;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaTelegramVinculo;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * A conversa da pessoa com a Central — a mesma pela tela e pelo Telegram.
 *
 * Mora aqui, e não no controller, porque são três as portas que escrevem nela:
 * a tela, o Telegram e o runner que devolve a resposta do agente. Cada uma com
 * a sua regra de quem pode entrar; a de como a mensagem é gravada é uma só.
 */
class ConversaService
{
    public const POR_PAGINA = 50;

    public function conversaDe(User $user): CentralIaConversa
    {
        return CentralIaConversa::where('user_id', $user->id)->orderBy('id')->first()
            ?? CentralIaConversa::create(['user_id' => $user->id]);
    }

    /**
     * Uma página do histórico do PRÓPRIO dono.
     *
     * `after` é a pergunta do poll ("chegou algo depois do que já tenho?") e
     * `before` a de quem rola para cima. Sem nenhum dos dois, as últimas.
     *
     * Cada projeto tem a sua conversa; `projeto` nulo é a "Geral" — onde
     * ficou tudo o que existia antes do cockpit e o que não é de projeto.
     *
     * @return array{0: Collection<int, CentralIaMensagem>, 1: bool}
     */
    public function historico(User $user, ?int $antesDe = null, ?int $depoisDe = null, ?string $projeto = null, ?int $tarefaId = null): array
    {
        $consulta = CentralIaMensagem::de($user)
            ->when($projeto === null, fn ($q) => $q->whereNull('projeto'), fn ($q) => $q->where('projeto', $projeto))
            ->when($tarefaId, fn ($q) => $q->where('tarefa_id', $tarefaId));

        if ($depoisDe !== null) {
            $linhas = $consulta->where('id', '>', $depoisDe)->orderBy('id')->limit(self::POR_PAGINA + 1)->get();
            $haMais = $linhas->count() > self::POR_PAGINA;

            return [$linhas->take(self::POR_PAGINA)->values(), $haMais];
        }

        if ($antesDe !== null) {
            $consulta->where('id', '<', $antesDe);
        }

        $linhas = $consulta->orderByDesc('id')->limit(self::POR_PAGINA + 1)->get();
        $haMais = $linhas->count() > self::POR_PAGINA;

        return [$linhas->take(self::POR_PAGINA)->reverse()->values(), $haMais];
    }

    /**
     * Grava o que a própria pessoa escreveu.
     *
     * O reenvio com o mesmo `client_id` devolve a mensagem que já entrou: o
     * navegador que não viu a resposta não tem como saber se o primeiro envio
     * chegou, e gravar de novo dobraria a mensagem — e, mais adiante, o
     * pedido ao agente. Mesmo id com OUTRO texto não é reenvio, é erro.
     */
    public function registrarDoUsuario(User $user, string $conteudo, string $origem, ?string $clientId = null, ?int $telegramUpdateId = null, ?string $projeto = null, ?int $tarefaId = null): CentralIaMensagem
    {
        if ($clientId !== null && ($existente = $this->porClientId($user, $clientId))) {
            return $this->mesmaMensagemOuRecusa($existente, $conteudo);
        }

        try {
            return $this->gravar($user, [
                'projeto' => $projeto,
                'tarefa_id' => $tarefaId,
                'client_id' => $clientId,
                'autor_tipo' => CentralIaMensagem::AUTOR_USUARIO,
                'autor_nome' => $user->name,
                'origem' => $origem,
                'conteudo' => $conteudo,
                'telegram_update_id' => $telegramUpdateId,
            ]);
        } catch (UniqueConstraintViolationException $e) {
            // Dois envios do mesmo id ao mesmo tempo: o segundo perde a corrida
            // no índice único e devolve o que o primeiro gravou.
            $existente = $clientId !== null ? $this->porClientId($user, $clientId) : null;

            if (! $existente) {
                throw $e;
            }

            return $this->mesmaMensagemOuRecusa($existente, $conteudo);
        }
    }

    /**
     * A resposta de um agente (ou um aviso da própria Central) na conversa
     * da pessoa. Não passa por `AgentesService::pedir()` — resposta de agente
     * nunca dispara outro agente.
     */
    public function registrarDoAgente(User $user, string $autor, string $conteudo, string $tipo = CentralIaMensagem::AUTOR_AGENTE, ?string $projeto = null, ?int $tarefaId = null): CentralIaMensagem
    {
        return $this->gravar($user, [
            'projeto' => $projeto,
            'tarefa_id' => $tarefaId,
            'autor_tipo' => $tipo,
            'autor_nome' => $autor,
            'origem' => CentralIaMensagem::ORIGEM_AGENTE,
            // MEDIUMTEXT guarda 16 MB; isto é folga para o que uma tela lê.
            'conteudo' => mb_strcut(ProtecaoDeSegredos::texto($conteudo), 0, 1_000_000) ?: '(resposta vazia)',
        ]);
    }

    /**
     * A mensagem e a sua ida ao Telegram nascem juntas, na mesma transação:
     * sem isso, uma queda entre as duas deixaria a mensagem na tela e
     * ausente no celular, sem nada que avisasse.
     *
     * O que veio do próprio Telegram não volta para ele.
     */
    private function gravar(User $user, array $dados): CentralIaMensagem
    {
        return DB::transaction(function () use ($user, $dados) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if (($dados['autor_tipo'] ?? null) === CentralIaMensagem::AUTOR_USUARIO) {
                app(CanalAtivo::class)->exigir($user, $dados['origem']);
            }
            $mensagem = CentralIaMensagem::create($dados + [
                'conversa_id' => $this->conversaDe($user)->id,
                'user_id' => $user->id,
            ]);

            $vinculo = CentralIaTelegramVinculo::where('user_id', $user->id)->first();

            if ($vinculo && $mensagem->origem !== CentralIaMensagem::ORIGEM_TELEGRAM
                && app(CanalAtivo::class)->entregaPermitida($user, $mensagem)) {
                CentralIaEntrega::create([
                    'mensagem_id' => $mensagem->id,
                    'user_id' => $user->id,
                    'chat_id' => $vinculo->chat_id,
                ]);
            }

            return $mensagem;
        });
    }

    private function porClientId(User $user, string $clientId): ?CentralIaMensagem
    {
        return CentralIaMensagem::de($user)->where('client_id', $clientId)->first();
    }

    private function mesmaMensagemOuRecusa(CentralIaMensagem $existente, string $conteudo): CentralIaMensagem
    {
        if ($existente->conteudo !== $conteudo) {
            throw ValidationException::withMessages(['client_id' => 'Este envio já foi usado por outra mensagem.']);
        }

        return $existente;
    }
}
