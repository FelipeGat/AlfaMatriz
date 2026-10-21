<?php

namespace App\Services\CentralIa;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Models\CentralIaExecucao;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * O quadro do AlfaMatriz para o agente da Central de IA (T-472).
 *
 * Em 21/10/2026 o dono pediu a um agente da Central para abrir uma tarefa e o
 * `/mcp` devolveu 401: o agente só achava o servidor do quadro porque a
 * máquina do dono o tinha configurado à mão, e o token vinha de uma variável
 * que o runner não tem. Na máquina de qualquer outra pessoa não havia nem a
 * configuração. A regra dele para o conserto: valer para TODOS que usam a
 * Central, e cada um agindo em nome de si mesmo — um token só, o dele, faria
 * tudo sair assinado por ele e passaria por cima da permissão de cada um.
 *
 * Por isso o token nasce aqui, POR PEDIDO, em nome de quem pediu: habilidade
 * `mcp` e mais nenhuma (a mesma porta do `alfa:mcp-token`), vence pouco depois
 * do teto da execução (a garantia, se a máquina sumir) e é apagado assim que a
 * execução deixa de estar "executando", seja qual for o caminho
 * (`CentralIaExecucao::booted`). Ninguém configura nada por pessoa.
 *
 * Quem o recebe: quem administra a Central, e quem um administrador liberou
 * (`quadro` na política da conta, desligado por padrão — decisão do dono em
 * 21/10/2026). Pedido "sem ferramentas" (o do Secretário, que leva e-mail e
 * transcrição de terceiros) não recebe nunca: um texto malicioso ali poderia
 * agir no quadro em nome da pessoa.
 */
class TokenDoQuadro
{
    public const HABILIDADE = 'mcp';

    public const PREFIXO = 'central-ia:quadro:';

    /** Folga além do teto: a espera pela confirmação no Telegram conta à parte (até 20 min). */
    public const FOLGA_MINUTOS = 25;

    public const SEM_LIBERACAO = 'o acesso dos agentes ao quadro não está liberado para esta conta (quem libera é um administrador, em Central de IA › administração)';

    public function __construct(private readonly Governanca $governanca) {}

    public function liberado(User $user): bool
    {
        return $this->governanca->administra($user) || (($this->governanca->politica($user)['quadro'] ?? false) === true);
    }

    /**
     * O que vai à máquina junto do pedido: o token, ou o motivo de não haver
     * um (que o runner põe no prompt, para o agente dizer isso em vez de
     * tentar outro servidor). `null` nos dois: o pedido nem fala do quadro.
     *
     * O registro de QUAL pedido abriu a porta fica no evento "começou" dele
     * (`AgentesService::proxima`), com o vencimento — nunca o token.
     *
     * @return array{token: ?string, motivo: ?string, minutos?: int}
     */
    public function paraExecucao(User $user, CentralIaExecucao $e, int $tetoMinutos): array
    {
        if ($e->roteamento['sem_ferramentas'] ?? false) {
            return ['token' => null, 'motivo' => null];
        }
        if (! $this->liberado($user)) {
            return ['token' => null, 'motivo' => self::SEM_LIBERACAO];
        }
        try {
            // As mesmas portas do `/mcp`: lá a conta recusada levaria 403.
            AlfaMatrizServer::conferir($user);
        } catch (RuntimeException $ex) {
            return ['token' => null, 'motivo' => $ex->getMessage()];
        }

        $this->revogar($e);
        $minutos = $tetoMinutos + self::FOLGA_MINUTOS;
        $token = $user->createToken(self::PREFIXO.$e->id, [self::HABILIDADE], now()->addMinutes($minutos))->plainTextToken;

        return ['token' => $token, 'motivo' => null, 'minutos' => $minutos];
    }

    /** Apaga o token do pedido. Sem token, não faz nada. */
    public function revogar(CentralIaExecucao $e): int
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $e->user_id)
            ->where('name', self::PREFIXO.$e->id)
            ->delete();
    }
}
