<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos aos agentes: do que a pessoa escreveu até a resposta na conversa.
 *
 * O AlfaMatriz não executa nada. Ele guarda o pedido, entrega ao runner da
 * MESMA pessoa (o runner só enxerga os pedidos de quem é dono do token) e
 * grava a resposta. A resposta de agente nunca vira pedido novo: não existe
 * caminho de uma mensagem de agente até `pedir()`.
 */
class AgentesService
{
    public function __construct(private readonly ConversaService $conversas) {}

    /**
     * O atalho de texto, para o Telegram e para quem prefere digitar:
     * `/claude alfacontrol analise os erros` — e com `!` para implementar,
     * `/claude! alfacontrol corrija o filtro`. `/gpt` e `/codex` são o mesmo.
     *
     * @return array{agente: string, modo: string, projeto: string, instrucao: string}|null
     */
    public function interpretar(string $texto): ?array
    {
        if (! preg_match('/^\/(claude|gpt|codex|copilot)(!?)(?:@\w+)?\s+([A-Za-z0-9._-]+)\s+(.+)$/su', trim($texto), $m)) {
            return null;
        }

        return [
            'agente' => $m[1] === 'gpt' ? 'codex' : $m[1],
            'modo' => $m[2] === '!' ? 'implementar' : 'analisar',
            'projeto' => $m[3],
            'instrucao' => trim($m[4]),
        ];
    }

    /**
     * O que a pessoa pode pedir agora: agentes e projetos das máquinas dela.
     *
     * @return array{online: bool, agentes: list<string>, projetos: list<string>}
     */
    public function disponiveis(User $user): array
    {
        $runners = CentralIaRunner::where('user_id', $user->id)->get();

        return [
            'online' => $runners->contains(fn (CentralIaRunner $r) => $r->online()),
            'agentes' => $runners->pluck('agentes')->flatten()->unique()->intersect(array_keys(CentralIaExecucao::AGENTES))->values()->all(),
            'projetos' => $runners->pluck('projetos')->flatten()->unique()->sort()->values()->all(),
        ];
    }

    /**
     * Grava o pedido (a mensagem da pessoa) e a execução, juntos.
     *
     * Reenvio com o mesmo `client_id` devolve a mesma execução — o pedido ao
     * agente não dobra, como a mensagem não dobra.
     */
    public function pedir(User $user, string $texto, array $pedido, string $origem, ?string $clientId = null, ?int $telegramUpdateId = null): CentralIaExecucao
    {
        $user->esquecerPermissoes();

        if (! $user->canPermissao('central_ia_agentes', 'incluir')) {
            throw ValidationException::withMessages(['agente' => 'Sua conta não pode acionar agentes.']);
        }

        if (! array_key_exists($pedido['agente'], CentralIaExecucao::AGENTES) || ! array_key_exists($pedido['modo'], CentralIaExecucao::MODOS)) {
            throw ValidationException::withMessages(['agente' => 'Agente ou modo desconhecido.']);
        }

        $disponivel = $this->disponiveis($user);

        if (! in_array($pedido['projeto'], $disponivel['projetos'], true)) {
            throw ValidationException::withMessages(['projeto' => "O projeto {$pedido['projeto']} não está entre os que a sua máquina informou."]);
        }

        if (blank($pedido['instrucao'])) {
            throw ValidationException::withMessages(['conteudo' => 'Diga ao agente o que fazer.']);
        }

        return DB::transaction(function () use ($user, $texto, $pedido, $origem, $clientId, $telegramUpdateId) {
            $mensagem = $this->conversas->registrarDoUsuario($user, $texto, $origem, $clientId, $telegramUpdateId);

            if ($existente = CentralIaExecucao::where('pedido_id', $mensagem->id)->first()) {
                return $existente;
            }

            // Contado dentro da transação, com a trava na conta: dois pedidos
            // simultâneos não passam juntos pelo último lugar do dia.
            User::whereKey($user->id)->lockForUpdate()->first();
            $hoje = CentralIaExecucao::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();

            if ($hoje >= config('central_ia.agentes.limite_diario')) {
                throw ValidationException::withMessages(['agente' => 'Limite de '.config('central_ia.agentes.limite_diario').' pedidos a agentes por dia atingido.']);
            }

            return CentralIaExecucao::create([
                'user_id' => $user->id,
                'pedido_id' => $mensagem->id,
                'agente' => $pedido['agente'],
                'projeto' => $pedido['projeto'],
                'modo' => $pedido['modo'],
                'instrucao' => $pedido['instrucao'],
            ]);
        });
    }

    /**
     * A próxima execução para esta máquina — uma por pessoa de cada vez.
     *
     * Leva junto a sessão do agente no último pedido igual (mesmo agente,
     * projeto e modo), para a conversa com ele continuar de onde parou.
     */
    public function proxima(User $user, CentralIaRunner $runner): ?array
    {
        $this->expirarPresas($user);

        $execucao = DB::transaction(function () use ($user, $runner) {
            User::whereKey($user->id)->lockForUpdate()->first();

            if (CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::EXECUTANDO)->exists()) {
                return null;
            }

            $execucao = CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::PENDENTE)
                ->whereIn('agente', $runner->agentes)->whereIn('projeto', $runner->projetos)
                ->orderBy('id')->lockForUpdate()->first();

            $execucao?->update(['status' => CentralIaExecucao::EXECUTANDO, 'runner_id' => $runner->id, 'iniciada_em' => now()]);

            return $execucao;
        });

        if (! $execucao) {
            return null;
        }

        $anterior = CentralIaExecucao::where('user_id', $user->id)->where('id', '<', $execucao->id)
            ->where('agente', $execucao->agente)->where('projeto', $execucao->projeto)->where('modo', $execucao->modo)
            ->where('status', CentralIaExecucao::CONCLUIDA)->whereNotNull('sessao_agente')
            ->orderByDesc('id')->first();

        return [
            'id' => $execucao->id,
            'agente' => $execucao->agente,
            'projeto' => $execucao->projeto,
            'modo' => $execucao->modo,
            'instrucao' => $execucao->instrucao,
            'timeout_minutos' => config('central_ia.agentes.timeout_minutos'),
            'continuar' => $anterior ? ['sessao' => $anterior->sessao_agente, 'branch' => $anterior->branch] : null,
        ];
    }

    /**
     * O runner conta como terminou. Só a execução em andamento DESTA pessoa
     * aceita conclusão, e uma vez: a segunda chamada (reenvio do runner que
     * não viu a resposta) devolve o que já está gravado.
     */
    public function concluir(User $user, int $id, array $dados): CentralIaExecucao
    {
        return DB::transaction(function () use ($user, $id, $dados) {
            $execucao = CentralIaExecucao::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($execucao->status !== CentralIaExecucao::EXECUTANDO) {
                return $execucao;
            }

            $ok = $dados['status'] === CentralIaExecucao::CONCLUIDA;
            $texto = $ok ? $dados['resposta'] : 'Não consegui concluir: '.($dados['erro'] ?? 'erro desconhecido').'.';

            $resposta = $this->conversas->registrarDoAgente($user, $execucao->nomeDoAgente(), $texto);

            $execucao->update([
                'status' => $ok ? CentralIaExecucao::CONCLUIDA : CentralIaExecucao::FALHOU,
                'resposta_id' => $resposta->id,
                'sessao_agente' => $dados['sessao_agente'] ?? null,
                'branch' => $dados['branch'] ?? null,
                'erro' => $ok ? null : mb_substr((string) ($dados['erro'] ?? ''), 0, 255),
                'concluida_em' => now(),
            ]);

            return $execucao;
        });
    }

    /**
     * Execução sem notícia além do teto: o runner caiu ou a máquina desligou.
     * Vira `expirada` com aviso na conversa, e a fila da pessoa anda.
     */
    public function expirarPresas(User $user): void
    {
        $limite = now()->subMinutes(config('central_ia.agentes.timeout_minutos') + 5);

        CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::EXECUTANDO)
            ->where('iniciada_em', '<', $limite)->get()
            ->each(function (CentralIaExecucao $e) use ($user) {
                $aviso = $this->conversas->registrarDoAgente($user, 'Central', "{$e->nomeDoAgente()} não respondeu a tempo em {$e->projeto}; o pedido foi encerrado.", CentralIaMensagem::AUTOR_SISTEMA);
                $e->update(['status' => CentralIaExecucao::EXPIRADA, 'resposta_id' => $aviso->id, 'concluida_em' => now()]);
            });
    }
}
