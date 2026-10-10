<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Pedidos aos agentes: do que a pessoa escreveu até a resposta na conversa.
 *
 * O AlfaMatriz não executa nada. Ele guarda o pedido, entrega ao runner da
 * MESMA pessoa (o runner só enxerga os pedidos de quem é dono do token) e
 * grava a resposta. A resposta de agente nunca vira pedido novo: não existe
 * caminho de uma mensagem de agente até `pedir()`.
 *
 * Todo pedido pertence a uma TAREFA de um projeto. Uma tarefa roda uma
 * execução por vez — é o que impede dois agentes de escreverem na mesma
 * worktree —, e tarefas diferentes rodam em paralelo até o teto da pessoa.
 */
class AgentesService
{
    public function __construct(
        private readonly ConversaService $conversas,
        private readonly Roteador $roteador,
    ) {}

    /**
     * O atalho de texto, para o Telegram e para quem prefere digitar:
     * `/claude alfacontrol analise os erros` — e com `!` para implementar,
     * `/claude! alfacontrol corrija o filtro`. `/gpt` e `/codex` são o mesmo;
     * `/auto` deixa o roteador escolher.
     *
     * @return array{agente: string, modo: string, projeto: string, instrucao: string}|null
     */
    public function interpretar(string $texto): ?array
    {
        if (! preg_match('/^\/(claude|gpt|codex|copilot|auto)(!?)(?:@\w+)?\s+([A-Za-z0-9._-]+)\s+(.+)$/su', trim($texto), $m)) {
            return null;
        }

        return [
            'agente' => $m[1] === 'gpt' ? 'codex' : $m[1],
            'modo' => $m[2] === '!' ? 'implementar' : ($m[1] === 'auto' ? 'auto' : 'analisar'),
            'projeto' => $m[3],
            'instrucao' => trim($m[4]),
        ];
    }

    /**
     * O que a pessoa pode pedir agora: agentes e projetos das máquinas dela,
     * e o estado de cada projeto que o runner mediu.
     *
     * @return array{online: bool, agentes: list<string>, projetos: list<string>, estado: array<string, array>}
     */
    public function disponiveis(User $user): array
    {
        $runners = CentralIaRunner::where('user_id', $user->id)->orderBy('visto_em')->get();

        return [
            'online' => $runners->contains(fn (CentralIaRunner $r) => $r->online()),
            'agentes' => $runners->pluck('agentes')->flatten()->unique()->intersect(array_keys(CentralIaExecucao::AGENTES))->values()->all(),
            'projetos' => $runners->pluck('projetos')->flatten()->unique()->sort()->values()->all(),
            // A batida mais recente vence (ordenado por visto_em).
            'estado' => $runners->reduce(fn (array $acc, CentralIaRunner $r) => array_merge($acc, $r->estado ?? []), []),
        ];
    }

    /**
     * Grava o pedido (a mensagem da pessoa), a tarefa (se nova) e a
     * execução, juntos.
     *
     * `$pedido`: projeto, instrucao, agente (`auto` ou um dos agentes), modo
     * (`auto`, `analisar` ou `implementar`) e, opcional, `tarefa_id`.
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

        $agente = $pedido['agente'] ?? 'auto';
        $modo = $pedido['modo'] ?? 'auto';

        if (($agente !== 'auto' && ! array_key_exists($agente, CentralIaExecucao::AGENTES))
            || ($modo !== 'auto' && ! array_key_exists($modo, CentralIaExecucao::MODOS))) {
            throw ValidationException::withMessages(['agente' => 'Agente ou modo desconhecido.']);
        }

        $disponivel = $this->disponiveis($user);
        $projeto = (string) ($pedido['projeto'] ?? '');

        if (! in_array($projeto, $disponivel['projetos'], true)) {
            throw ValidationException::withMessages(['projeto' => "O projeto {$projeto} não está entre os que a sua máquina informou."]);
        }

        if (blank($pedido['instrucao'] ?? null)) {
            throw ValidationException::withMessages(['conteudo' => 'Diga ao agente o que fazer.']);
        }

        $tarefa = null;
        if (filled($pedido['tarefa_id'] ?? null)) {
            $tarefa = CentralIaTarefa::de($user)->where('projeto', $projeto)->find($pedido['tarefa_id']);
            if (! $tarefa) {
                throw ValidationException::withMessages(['tarefa_id' => 'Tarefa não encontrada neste projeto.']);
            }
        }

        $sessaoDoVsCode = $tarefa?->dossie['origem'] ?? null;
        $rota = $agente === 'auto' && $sessaoDoVsCode && in_array($sessaoDoVsCode['agente'], $disponivel['agentes'], true)
            // Continuação de sessão do VS Code: em automático, quem segue é o
            // agente daquela conversa — é ele que tem o histórico.
            ? ['agente' => $sessaoDoVsCode['agente'], 'modo' => 'implementar', 'categoria' => 'implementacao', 'manual' => false,
                'motivo' => 'Automático: continuação da sessão do VS Code']
            : ($agente === 'auto'
            ? $this->roteador->decidir($pedido['instrucao'], $disponivel['agentes'], $tarefa)
            : ['agente' => $agente, 'modo' => null, 'categoria' => null, 'manual' => true, 'motivo' => 'Escolha manual']);

        if ($modo === 'auto') {
            $modo = $rota['modo'] ?? $this->roteador->decidir($pedido['instrucao'], [], $tarefa)['modo'];
        }
        $rota['modo'] = $modo;
        $rota['categoria'] ??= $modo === 'implementar' ? 'implementacao' : 'investigacao';

        if (! $rota['agente']) {
            throw ValidationException::withMessages(['agente' => 'Nenhum agente disponível na sua máquina agora.']);
        }

        return DB::transaction(function () use ($user, $texto, $pedido, $origem, $clientId, $telegramUpdateId, $projeto, $tarefa, $rota) {
            // A mensagem primeiro, sem tarefa: o reenvio idempotente precisa
            // achá-la antes de criar qualquer coisa.
            $mensagem = $this->conversas->registrarDoUsuario($user, $texto, $origem, $clientId, $telegramUpdateId, $projeto, $tarefa?->id);

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

            $tarefa ??= CentralIaTarefa::create([
                'user_id' => $user->id,
                'projeto' => $projeto,
                'titulo' => Str::limit(Str::squish(strtok($pedido['instrucao'], "\n")), 90),
                'objetivo' => $pedido['instrucao'],
                'dossie' => Dossie::inicial($pedido['instrucao']),
            ]);

            // Quem assume uma tarefa que estava com outro agente recebe o
            // dossiê com instrução de handoff (ver `proxima()`); o registro
            // da troca fica aqui, junto do motivo.
            if ($tarefa->agente_atual && $tarefa->agente_atual !== $rota['agente']) {
                $rota['handoff_de'] = $tarefa->agente_atual;
            }

            $mensagem->update(['tarefa_id' => $tarefa->id]);
            $tarefa->update([
                'dossie' => Dossie::registrarPedido($tarefa->dossie ?? Dossie::inicial($tarefa->objetivo), $pedido['instrucao']),
                'status' => $tarefa->status === CentralIaTarefa::EXECUTANDO ? CentralIaTarefa::EXECUTANDO : CentralIaTarefa::ABERTA,
                'ultima_atividade_em' => now(),
            ]);

            return CentralIaExecucao::create([
                'user_id' => $user->id,
                'pedido_id' => $mensagem->id,
                'tarefa_id' => $tarefa->id,
                'agente' => $rota['agente'],
                'projeto' => $projeto,
                'modo' => $rota['modo'],
                'roteamento' => $rota,
                'instrucao' => $pedido['instrucao'],
            ]);
        });
    }

    /**
     * Continua aqui uma conversa começada no VS Code.
     *
     * A tarefa nasce apontando para a sessão de origem e trabalha NA PASTA
     * dela, não numa worktree: a conversa do VS Code mexeu na pasta real, e o
     * que ela deixou sem commit só existe ali. No Claude o primeiro pedido
     * retoma uma CÓPIA da sessão (`--fork-session`) — a do VS Code fica
     * intacta; Codex e Copilot não têm cópia e retomam a própria sessão.
     *
     * Sessão mexida há menos de `PARADA_MINUTOS` é recusada: dois lados
     * escrevendo na mesma pasta ao mesmo tempo é o que a Central evita.
     */
    public const PARADA_MINUTOS = 5;

    public function continuarSessao(User $user, string $agente, string $sessaoId): CentralIaTarefa
    {
        $user->esquecerPermissoes();
        if (! $user->canPermissao('central_ia_agentes', 'incluir')) {
            throw ValidationException::withMessages(['agente' => 'Sua conta não pode acionar agentes.']);
        }

        $sessao = $this->sessoes($user)->first(fn ($s) => $s['agente'] === $agente && $s['id'] === $sessaoId);
        if (! $sessao) {
            throw ValidationException::withMessages(['sessao' => 'Sessão não encontrada entre as que a sua máquina informou.']);
        }

        $parada = $sessao['ultima_atividade'] ? now()->diffInMinutes(\Illuminate\Support\Carbon::parse($sessao['ultima_atividade']), true) : 999;
        if ($parada < self::PARADA_MINUTOS) {
            throw ValidationException::withMessages(['sessao' => 'Essa sessão foi usada no VS Code há '.(int) $parada.' min. Pare por lá (ou espere '.self::PARADA_MINUTOS.' min) para continuar aqui sem os dois mexendo na mesma pasta.']);
        }

        // Já existe tarefa em curso continuando esta sessão: volta para ela.
        $existente = CentralIaTarefa::de($user)->emCurso()->where('projeto', $sessao['projeto'])->get()
            ->first(fn (CentralIaTarefa $t) => ($t->dossie['origem']['sessao'] ?? null) === $sessaoId);
        if ($existente) {
            return $existente;
        }

        $nome = CentralIaExecucao::AGENTES[$agente];
        $dossie = Dossie::inicial("Continuar a conversa de {$nome} iniciada no VS Code.");
        $dossie['origem'] = ['agente' => $agente, 'sessao' => $sessaoId, 'cwd' => $sessao['cwd'], 'mensagens' => $sessao['mensagens']];
        $dossie['lacunas'][] = "O histórico anterior está na sessão do VS Code ({$sessao['mensagens']} mensagens), não neste dossiê. Outro agente que assumir só vê o que acontecer daqui em diante.";

        return CentralIaTarefa::create([
            'user_id' => $user->id,
            'projeto' => $sessao['projeto'],
            'titulo' => "Continuação da sessão de {$nome} no VS Code",
            'objetivo' => $dossie['objetivo'],
            'agente_atual' => $agente,
            // A pasta da sessão, e não uma worktree: é onde está o trabalho.
            'worktree' => $sessao['cwd'],
            'dossie' => $dossie,
            'ultima_atividade_em' => now(),
        ]);
    }

    /** Sessões do VS Code que as máquinas da pessoa informaram. */
    public function sessoes(User $user): \Illuminate\Support\Collection
    {
        return CentralIaRunner::where('user_id', $user->id)->get()->pluck('sessoes')->filter()->flatten(1)
            ->sortByDesc('ultima_atividade')->values();
    }

    /**
     * A próxima execução para esta máquina.
     *
     * Até `paralelo` ao mesmo tempo por pessoa, e nunca duas da MESMA tarefa:
     * a segunda espera a primeira terminar — é isso que garante que só um
     * agente escreve na worktree da tarefa por vez, e que o handoff começa
     * com a execução anterior já encerrada.
     */
    public function proxima(User $user, CentralIaRunner $runner): ?array
    {
        $this->expirarPresas($user);

        $execucao = DB::transaction(function () use ($user, $runner) {
            User::whereKey($user->id)->lockForUpdate()->first();

            $rodando = CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::EXECUTANDO);
            if ((clone $rodando)->count() >= max(1, (int) config('central_ia.agentes.paralelo'))) {
                return null;
            }

            $execucao = CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::PENDENTE)
                ->whereIn('agente', $runner->agentes)->whereIn('projeto', $runner->projetos)
                // `NOT IN` com nulo nunca casa: o pedido sem tarefa (anterior
                // ao cockpit) entra pelo `whereNull`.
                ->where(fn ($q) => $q->whereNull('tarefa_id')
                    ->orWhereNotIn('tarefa_id', (clone $rodando)->whereNotNull('tarefa_id')->select('tarefa_id')))
                ->orderBy('id')->lockForUpdate()->first();

            if ($execucao) {
                $execucao->update(['status' => CentralIaExecucao::EXECUTANDO, 'runner_id' => $runner->id, 'iniciada_em' => now()]);
                $execucao->tarefa?->update(['status' => CentralIaTarefa::EXECUTANDO, 'agente_atual' => $execucao->agente, 'ultima_atividade_em' => now()]);
            }

            return $execucao;
        });

        if (! $execucao) {
            return null;
        }

        $tarefa = $execucao->tarefa;
        // A sessão interna do agente só continua com o MESMO agente na mesma
        // tarefa; entre agentes diferentes, quem leva o contexto é o dossiê.
        $anterior = CentralIaExecucao::where('tarefa_id', $tarefa?->id)->where('id', '<', $execucao->id)
            ->whereIn('status', [CentralIaExecucao::CONCLUIDA, CentralIaExecucao::FALHOU])
            ->orderByDesc('id')->first();
        $mesmoAgente = $anterior && $anterior->agente === $execucao->agente && $anterior->sessao_agente;
        $sessaoDoVsCode = $tarefa?->dossie['origem'] ?? null;
        // Primeira execução da continuação: retoma a sessão do VS Code (no
        // Claude, uma cópia dela). Depois, a sessão já é a desta tarefa.
        $continuar = $mesmoAgente
            ? ['sessao' => $anterior->sessao_agente, 'cwd' => $anterior->checkpoint['cwd'] ?? null, 'fork' => false]
            : (! $anterior && $sessaoDoVsCode && $sessaoDoVsCode['agente'] === $execucao->agente
                ? ['sessao' => $sessaoDoVsCode['sessao'], 'cwd' => $sessaoDoVsCode['cwd'], 'fork' => $execucao->agente === 'claude']
                : null);

        return [
            'id' => $execucao->id,
            'agente' => $execucao->agente,
            'projeto' => $execucao->projeto,
            'modo' => $execucao->modo,
            'instrucao' => $execucao->instrucao,
            'timeout_minutos' => config('central_ia.agentes.timeout_minutos'),
            'tarefa' => $tarefa ? [
                'id' => $tarefa->id,
                'titulo' => $tarefa->titulo,
                'worktree' => $tarefa->worktree,
                'branch' => $tarefa->branch,
                'commit_base' => $tarefa->commit_base,
            ] : null,
            'contexto' => $tarefa && $anterior ? Dossie::paraPrompt($tarefa, $anterior->agente !== $execucao->agente ? $anterior->nomeDoAgente() : null) : null,
            'bloco' => Dossie::instrucaoDoBloco(),
            'continuar' => $continuar,
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
            [$texto, $bloco] = $ok ? Dossie::extrair($dados['resposta'] ?? '') : ['Não consegui concluir: '.($dados['erro'] ?? 'erro desconhecido').'.', null];
            $tarefa = $execucao->tarefa;

            $resposta = $this->conversas->registrarDoAgente($user, $execucao->nomeDoAgente(), $texto ?: '(resposta vazia)', CentralIaMensagem::AUTOR_AGENTE, $execucao->projeto, $tarefa?->id);
            $checkpoint = is_array($dados['checkpoint'] ?? null) ? $dados['checkpoint'] : null;

            $execucao->update([
                'status' => $ok ? CentralIaExecucao::CONCLUIDA : CentralIaExecucao::FALHOU,
                'resposta_id' => $resposta->id,
                'sessao_agente' => $dados['sessao_agente'] ?? null,
                'branch' => $dados['branch'] ?? null,
                'checkpoint' => $checkpoint,
                'erro' => $ok ? null : mb_substr((string) ($dados['erro'] ?? ''), 0, 255),
                'concluida_em' => now(),
            ]);

            if ($tarefa) {
                $dossie = Dossie::aplicarConclusao($tarefa->dossie ?? Dossie::inicial($tarefa->objetivo), $execucao, $bloco, $checkpoint);
                $status = match (true) {
                    ! $ok => CentralIaTarefa::FALHOU,
                    filled($dossie['duvida']) => CentralIaTarefa::AGUARDANDO_VOCE,
                    (bool) ($bloco['tarefa_concluida'] ?? false) => CentralIaTarefa::CONCLUIDA,
                    default => CentralIaTarefa::ABERTA,
                };
                // Ainda há pedido desta tarefa na fila: ela continua em curso.
                if ($status !== CentralIaTarefa::AGUARDANDO_VOCE && $tarefa->execucoes()->where('status', CentralIaExecucao::PENDENTE)->exists()) {
                    $status = CentralIaTarefa::ABERTA;
                }

                $tarefa->update(array_filter([
                    'dossie' => $dossie,
                    'status' => $status,
                    'ultima_atividade_em' => now(),
                    // A worktree nasce na primeira execução de implementar e é
                    // da tarefa daí em diante — quem assume continua nela.
                    'worktree' => $dados['worktree'] ?? null,
                    'branch' => $dados['branch'] ?? null,
                    'commit_base' => $dados['commit_base'] ?? null,
                ], fn ($v) => $v !== null));
            }

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
                $aviso = $this->conversas->registrarDoAgente($user, 'Central', "{$e->nomeDoAgente()} não respondeu a tempo em {$e->projeto}; o pedido foi encerrado.", CentralIaMensagem::AUTOR_SISTEMA, $e->projeto, $e->tarefa_id);
                $e->update(['status' => CentralIaExecucao::EXPIRADA, 'resposta_id' => $aviso->id, 'concluida_em' => now()]);
                $e->tarefa?->update(['status' => CentralIaTarefa::FALHOU, 'ultima_atividade_em' => now()]);
            });
    }
}
