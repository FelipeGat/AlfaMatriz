<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaConversa;
use App\Models\CentralIaMensagem;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
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
     * @return array{0: Collection<int, CentralIaMensagem>, 1: bool}
     */
    public function historico(User $user, ?int $antesDe = null, ?int $depoisDe = null): array
    {
        $consulta = CentralIaMensagem::de($user);

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
    public function registrarDoUsuario(User $user, string $conteudo, string $origem, ?string $clientId = null, ?int $telegramUpdateId = null): CentralIaMensagem
    {
        if ($clientId !== null && ($existente = $this->porClientId($user, $clientId))) {
            return $this->mesmaMensagemOuRecusa($existente, $conteudo);
        }

        try {
            return CentralIaMensagem::create([
                'conversa_id' => $this->conversaDe($user)->id,
                'user_id' => $user->id,
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
