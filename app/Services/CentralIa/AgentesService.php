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
            'esgotados' => $this->esgotados($runners) + $this->falhasRecentes($user),
        ];
    }

    /**
     * Agentes no limite da assinatura agora, com o motivo — o roteador não os
     * escolhe (escolha manual ainda pode). Em 10/10/2026 a semana do Claude
     * estava em 100%: mandar implementação para ele seria pedido que falha.
     *
     * @return array<string, string>
     */
    public function esgotados(\Illuminate\Support\Collection $runners): array
    {
        $consumo = $runners->pluck('consumo')->filter()->sortByDesc('coletado_em')->first() ?? [];
        $fora = [];

        // Só a janela de 5 h corta de fato: em 10/10/2026, com a semana em
        // 100%, o Claude ainda rodou 4 min sem erro (a continuação do gestor).
        // A semana cheia vira aviso na tela, não desvio.
        foreach ($consumo['limites_claude']['janelas'] ?? [] as $j) {
            if (($j['usado_pct'] ?? 0) >= 100 && str_starts_with((string) ($j['rotulo'] ?? ''), 'Sessão')) {
                $fora['claude'] = "Claude no limite: {$j['rotulo']} em 100%".($j['renova'] ? " até {$j['renova']}" : '');
                break;
            }
        }

        foreach (['primaria' => 'janela de 5 h', 'semanal' => 'semana'] as $chave => $rotulo) {
            $j = $consumo['cota_codex'][$chave] ?? null;
            $renova = isset($j['renova_em']) ? \Illuminate\Support\Carbon::parse($j['renova_em']) : null;
            if ($j && ($j['usado_pct'] ?? 0) >= 100 && (! $renova || $renova->isFuture())) {
                $fora['codex'] = "Codex no limite da {$rotulo}".($renova ? ' até '.$renova->timezone(config('app.timezone'))->format('d/m H:i') : '');
                break;
            }
        }

        return $fora;
    }

    /** Falhas reais valem mesmo quando o painel de franquia está atrasado. */
    public function falhasRecentes(User $user): array
    {
        $fora = [];
        foreach (array_keys(CentralIaExecucao::AGENTES) as $agente) {
            $ultima = CentralIaExecucao::where('user_id', $user->id)->where('agente', $agente)
                ->whereIn('status', [CentralIaExecucao::CONCLUIDA, CentralIaExecucao::FALHOU])
                ->orderByDesc('concluida_em')->orderByDesc('id')->first();
            $tipo = $ultima?->status === CentralIaExecucao::FALHOU ? FalhaDeProvedor::tipo($ultima->erro ?? '') : null;
            if ($tipo && $ultima->concluida_em?->gt(now()->subMinutes((int) config('central_ia.agentes.fallback_pausa_'.$tipo.'_minutos')))) {
                $fora[$agente] = CentralIaExecucao::AGENTES[$agente].' temporariamente fora da seleção: '.($tipo === 'limite' ? 'franquia atingida' : 'provedor indisponível');
            }
        }
        return $fora;
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

        // Confirmar um envio já recebido não consome outro lugar da cota,
        // mesmo que a IA tenha sido desabilitada ou atingido o teto depois.
        if ($clientId && ($m = CentralIaMensagem::de($user)->where('client_id', $clientId)->first()) && ($e = $m->execucao)) {
            $this->conversas->registrarDoUsuario($user, $texto, $origem, $clientId);
            app(AnexosService::class)->associar($user, $m, $pedido['anexos'] ?? []);
            return $e;
        }

        $agente = $pedido['agente'] ?? 'auto';
        $modo = $pedido['modo'] ?? 'auto';

        if (($agente !== 'auto' && ! array_key_exists($agente, CentralIaExecucao::AGENTES))
            || ($modo !== 'auto' && ! array_key_exists($modo, CentralIaExecucao::MODOS))) {
            throw ValidationException::withMessages(['agente' => 'Agente ou modo desconhecido.']);
        }

        $disponivel = $this->disponiveis($user);
        $preferencias = app(Preferencias::class);
        $ordens = collect(array_keys($preferencias->ler($user)['padroes']))->mapWithKeys(fn ($c) => [$c => $preferencias->ordem($user, $c)])->all();

        $disponivel['agentes'] = array_values(array_filter($disponivel['agentes'], fn ($a) => $preferencias->pode($user, $a)));
        $projeto = (string) ($pedido['projeto'] ?? '');
        if ($origem === CentralIaMensagem::ORIGEM_TELEGRAM && app(CanalAtivo::class)->pronto()) {
            app(CanalAtivo::class)->exigir($user, 'telegram');
            $contexto = app(CanalAtivo::class)->estado($user);
            if (($contexto?->projeto_canal ?? '') !== $projeto) {
                throw ValidationException::withMessages(['canal' => 'Este projeto não é o da conversa ativa. Use /usar projeto para mudar antes de pedir ao agente.']);
            }
            if ($contexto?->tarefa_canal) $pedido['tarefa_id'] = $contexto->tarefa_canal;
        }


        // Projeto vazio é a conversa Geral: pergunta que não é de projeto
        // nenhum ("resumo da minha agenda"). Só análise — sem pasta, não há
        // onde implementar.
        if ($projeto === '' && ($pedido['modo'] ?? 'auto') === 'implementar') {
            throw ValidationException::withMessages(['projeto' => 'Para implementar, escolha um projeto.']);
        }

        if ($projeto !== '' && ! in_array($projeto, $disponivel['projetos'], true)) {
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
            if ($tarefa->status === CentralIaTarefa::ARQUIVADA) {
                throw ValidationException::withMessages(['tarefa_id' => 'Reabra o chat arquivado antes de enviar outra solicitação.']);
            }
        }

        $sessaoDoVsCode = $tarefa?->dossie['origem'] ?? null;
        $origemEsgotada = $sessaoDoVsCode && isset($disponivel['esgotados'][$sessaoDoVsCode['agente']]);
        $rota = $agente === 'auto' && $sessaoDoVsCode && ! $origemEsgotada && in_array($sessaoDoVsCode['agente'], $disponivel['agentes'], true)
            // Continuação de sessão do VS Code: em automático, quem segue é o
            // agente daquela conversa — é ele que tem o histórico.
            ? ['agente' => $sessaoDoVsCode['agente'], 'modo' => 'implementar', 'categoria' => 'implementacao', 'manual' => false,
                'motivo' => 'Automático: continuação da sessão do VS Code']
            : ($agente === 'auto' && $origemEsgotada && in_array('codex', $disponivel['agentes'], true) && ! isset($disponivel['esgotados']['codex'])
            // A IA da conversa está no limite: o Codex assume, com o
            // histórico dela importado (ver `proxima()`).
            ? ['agente' => 'codex', 'modo' => 'implementar', 'categoria' => 'implementacao', 'manual' => false,
                'motivo' => 'Automático: '.$disponivel['esgotados'][$sessaoDoVsCode['agente']].'; o GPT (Codex) assume a conversa com o histórico dela']
            : ($agente === 'auto'
            ? $this->roteador->decidir($pedido['instrucao'], $disponivel['agentes'], $tarefa, $disponivel['esgotados'], $ordens)
            : ['agente' => $agente, 'modo' => null, 'categoria' => null, 'manual' => true, 'motivo' => 'Escolha manual']));

        if ($modo === 'auto') {
            $modo = $rota['modo'] ?? $this->roteador->decidir($pedido['instrucao'], [], $tarefa)['modo'];
        }
        $rota['modo'] = $projeto === '' ? 'analisar' : $modo;
        // Depois de saber o modo: implementação não é relatório de dados.
        app(FontesDeDados::class)->exigirPeriodo($pedido['instrucao'], $rota['modo']);
        $rota['categoria'] ??= $rota['modo'] === 'implementar' ? 'implementacao' : 'investigacao';
        // Dados oficiais que o runner lê e entrega ao agente (ver FontesDeDados).
        $rota['fontes'] = app(FontesDeDados::class)->detectar($pedido['instrucao']);
        if (app(Preferencias::class)->ler($user)['coordenador_codex']) {
            if (! in_array('codex', $disponivel['agentes'], true) || isset($disponivel['esgotados']['codex'])) {
                throw ValidationException::withMessages(['agente' => 'O coordenador Codex não está disponível ou atingiu seu limite. Pedido não executado; nenhum outro provedor assumirá a coordenação silenciosamente.']);
            }
            $rota = ['agente' => 'codex', 'modo' => 'analisar', 'categoria' => 'planejamento', 'manual' => false,
                'motivo' => 'Codex coordenador: planejar e propor distribuição antes da execução',
                'coordenador' => true, 'destino_sugerido' => $rota['agente'], 'modo_sugerido' => $rota['modo'], 'fontes' => $rota['fontes']];
        }


        if (! $rota['agente']) {
            throw ValidationException::withMessages(['agente' => 'Nenhum agente disponível na sua máquina agora.']);
        }

        // Nível de custo: o menor que resolve (dono, 12/10/2026). A escolha
        // da pessoa vence; "sem IA" só existe com dado oficial para mostrar.
        $nivel = in_array($pedido['nivel'] ?? null, Preferencias::NIVEIS, true) ? $pedido['nivel']
            : $this->roteador->nivel($pedido['instrucao'], $rota['categoria'] ?? 'investigacao', $rota['modo'], $rota['fontes'] ?? []);
        if ($nivel === 'sem_ia' && ($rota['modo'] === 'implementar' || ! collect($rota['fontes'] ?? [])->contains('tipo', 'agenda'))) {
            $nivel = $rota['modo'] === 'implementar' ? 'forte' : 'leve';
        }
        $rota['nivel'] = $nivel;
        $rota += app(Preferencias::class)->modelo($user, $nivel, $rota['agente']);
        $rota['motivo'] = ($rota['motivo'] ?? '').' · '.self::rotuloDoNivel($rota);

        return DB::transaction(function () use ($user, $texto, $pedido, $origem, $clientId, $telegramUpdateId, $projeto, $tarefa, $rota) {
            // A mensagem primeiro, sem tarefa: o reenvio idempotente precisa
            // achá-la antes de criar qualquer coisa.
            $mensagem = $this->conversas->registrarDoUsuario($user, $texto, $origem, $clientId, $telegramUpdateId, $projeto ?: null, $tarefa?->id);

            app(AnexosService::class)->associar($user, $mensagem, $pedido['anexos'] ?? []);

            if ($existente = CentralIaExecucao::where('pedido_id', $mensagem->id)->first()) {
                return $existente;
            }

            // Contado dentro da transação, com a trava na conta: dois pedidos
            // simultâneos não passam juntos pelo último lugar do dia.
            User::whereKey($user->id)->lockForUpdate()->first();
            app(Preferencias::class)->exigir($user, $rota['agente']);
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

            $execucao = CentralIaExecucao::create([
                'user_id' => $user->id,
                'pedido_id' => $mensagem->id,
                'tarefa_id' => $tarefa->id,
                'agente' => $rota['agente'],
                'projeto' => $projeto,
                'modo' => $rota['modo'],
                'roteamento' => $rota,
                'instrucao' => $pedido['instrucao'],
            ]);

            Eventos::registrar($user, 'pedido', "{$user->name} pediu ({$execucao->modo}): ".Str::limit($pedido['instrucao'], 200).' — '.($rota['motivo'] ?? ''),
                Eventos::da($execucao) + ['origem' => $origem]);
            if (isset($rota['handoff_de'])) {
                Eventos::registrar($user, 'troca_de_agente', 'Tarefa #'.$tarefa->id.' passa de '.(CentralIaExecucao::AGENTES[$rota['handoff_de']] ?? $rota['handoff_de']).' para '.$execucao->nomeDoAgente(),
                    Eventos::da($execucao) + ['origem' => $origem], 'aviso');
            }

            return $execucao;
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

        Eventos::registrar($user, 'sessao_continuada', "Conversa de {$nome} do VS Code ({$sessao['mensagens']} mensagens) passa a seguir pela Central",
            ['agente' => $agente, 'projeto' => $sessao['projeto']]);

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
            if (app(Preferencias::class)->ler($user)['execucao_bloqueada']) return null;

            $rodando = CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::EXECUTANDO);
            if ((clone $rodando)->count() >= min(max(1, (int) config('central_ia.agentes.paralelo')), app(Preferencias::class)->ler($user)['paralelo'])) {
                return null;
            }

            // Implementando direto na pasta, dois agentes no mesmo projeto
            // escreveriam um por cima do outro: um por projeto.
            $projetosOcupados = config('central_ia.agentes.implementar_na_pasta')
                ? (clone $rodando)->where('modo', 'implementar')->pluck('projeto')->all() : [];

            $execucao = CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::PENDENTE)
                ->whereIn('agente', $runner->agentes)
                ->whereIn('agente', array_keys(array_filter(app(Preferencias::class)->ler($user)['habilitados'])))
                ->where(fn ($q) => $q->whereNull('tarefa_id')->orWhereHas('tarefa', fn ($t) => $t->where('status', '!=', CentralIaTarefa::ARQUIVADA)))
                ->where(fn ($q) => $q->whereIn('projeto', $runner->projetos)->orWhere('projeto', ''))
                // `NOT IN` com nulo nunca casa: o pedido sem tarefa (anterior
                // ao cockpit) entra pelo `whereNull`.
                ->where(fn ($q) => $q->whereNull('tarefa_id')
                    ->orWhereNotIn('tarefa_id', (clone $rodando)->whereNotNull('tarefa_id')->select('tarefa_id')))
                ->where(fn ($q) => $q->where('modo', '!=', 'implementar')->orWhereNotIn('projeto', $projetosOcupados ?: ['']))
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

        Eventos::registrar($user, 'inicio', "{$execucao->nomeDoAgente()} começou ({$execucao->modo}) na máquina {$runner->maquina}", Eventos::da($execucao) + ['origem' => 'runner']);
        $tarefa = $execucao->tarefa;
        // A sessão interna do agente só continua com o MESMO agente na mesma
        // tarefa; entre agentes diferentes, quem leva o contexto é o dossiê.
        $anterior = CentralIaExecucao::where('tarefa_id', $tarefa?->id)->where('id', '<', $execucao->id)
            ->whereIn('status', [CentralIaExecucao::CONCLUIDA, CentralIaExecucao::FALHOU])
            ->orderByDesc('id')->first();
        // A última sessão CONHECIDA deste agente na tarefa — não a da última
        // execução: uma execução cortada no meio (teto de tempo) pode não ter
        // devolvido a sessão, e retomar "do nada" perderia a conversa inteira.
        // Depois de um ponto salvo, nada de antes dele é retomado: começa
        // sessão nova, com o resumo do ponto no contexto.
        $corte = (int) ($tarefa?->dossie['ponto']['execucao'] ?? 0);
        $sessaoConhecida = CentralIaExecucao::where('tarefa_id', $tarefa?->id)->where('id', '<', $execucao->id)->where('id', '>', $corte)
            ->where('agente', $execucao->agente)->whereNotNull('sessao_agente')->orderByDesc('id')->first();
        $mesmoAgente = $anterior && $anterior->agente === $execucao->agente && $sessaoConhecida;
        $sessaoDoVsCode = $tarefa?->dossie['origem'] ?? null;
        // Primeira execução da continuação: retoma a sessão do VS Code (no
        // Claude, uma cópia dela). Depois, a sessão já é a desta tarefa.
        $continuar = $mesmoAgente
            // A pasta vem do checkpoint (o runner o manda também na falha);
            // execuções antigas sem ele caem na pasta da tarefa.
            ? ['sessao' => $sessaoConhecida->sessao_agente, 'cwd' => $sessaoConhecida->checkpoint['cwd'] ?? $tarefa?->worktree, 'fork' => false]
            : (! $anterior && ! $corte && $sessaoDoVsCode && $sessaoDoVsCode['agente'] === $execucao->agente
                ? ['sessao' => $sessaoDoVsCode['sessao'], 'cwd' => $sessaoDoVsCode['cwd'], 'fork' => $execucao->agente === 'claude']
                : null);

        return [
            'id' => $execucao->id,
            'agente' => $execucao->agente,
            'projeto' => $execucao->projeto,
            'modo' => $execucao->modo,
            'instrucao' => $execucao->instrucao,
            // O handoff não move nem duplica arquivos: lê os anexos do pedido
            // original, sempre dentro da conta dona da execução.
            'anexos' => CentralIaMensagem::where('user_id', $user->id)
                ->findOrFail($execucao->roteamento['fallback']['pedido_original'] ?? $execucao->pedido_id)
                ->anexos->map(fn ($a) => $a->publico())->all(),
            'timeout_minutos' => $this->teto($execucao),
            'tarefa' => $tarefa ? [
                'id' => $tarefa->id,
                'titulo' => $tarefa->titulo,
                'worktree' => $tarefa->worktree,
                'branch' => $tarefa->branch,
                'commit_base' => $tarefa->commit_base,
            ] : null,
            'contexto' => $tarefa && ($anterior || $corte) ? Dossie::paraPrompt($tarefa, $anterior && $anterior->agente !== $execucao->agente ? $anterior->nomeDoAgente() : null) : null,
            'bloco' => (($execucao->roteamento['coordenador'] ?? false) ? "Você atua como coordenador deste pedido. Nesta etapa apenas responda, planeje e proponha quem deve fazer o trabalho; não implemente, não execute ferramentas ou comandos e não altere arquivos. Claude faz análise de código e desenvolvimento; Codex faz análise de dados; Copilot faz Git e QA. Identifique fontes disponíveis e impedimentos, não invente números. Delegação automática ainda não está habilitada: descreva a próxima etapa, sem afirmar que outro agente executou algo.\n" : '').Dossie::instrucaoDoBloco()."\nRegras de evidência: nunca invente dados, valores, fontes ou consultas. Relatórios de dados exigem período explícito e fonte autorizada identificada, data da consulta e critérios dos totais. Sem acesso à fonte, informe a limitação e solicite os dados; código, exemplos e testes não comprovam saldos reais. Para esta semana, confirme no texto as datas usadas. Para pedidos executivos com esta semana, use segunda-feira até hoje no fuso local e explicite as datas; para esta semana útil, segunda a sexta (até hoje se ainda estiver em curso). Pergunte somente se a ambiguidade alterar materialmente a decisão ou tornar a consulta insegura. Sem período informado, mantenha o pedido de esclarecimento. Claude só deve apoiar análise de dados quando houver impedimento técnico identificado; não troque de agente apenas para tentar obter números ausentes.",
            'fontes' => $execucao->roteamento['fontes'] ?? [],
            'nivel' => $execucao->roteamento['nivel'] ?? null,
            'modelo' => $execucao->roteamento['modelo'] ?? null,
            'esforco' => $execucao->roteamento['esforco'] ?? null,
            'continuar' => $continuar,
            'acesso' => config('central_ia.agentes.acesso'),
            'na_pasta' => (bool) config('central_ia.agentes.implementar_na_pasta'),
            // Outro agente assumindo uma conversa do VS Code: o runner lê o
            // histórico dela e põe no prompt (só texto, sem ferramentas).
            'importar' => ! $continuar && ! $corte && $sessaoDoVsCode && $sessaoDoVsCode['agente'] !== $execucao->agente
                && ! CentralIaExecucao::where('tarefa_id', $tarefa?->id)->where('agente', $execucao->agente)->where('id', '<', $execucao->id)->exists()
                ? $sessaoDoVsCode : null,
        ];
    }

    /**
     * O runner conta como terminou. Só a execução em andamento DESTA pessoa
     * aceita conclusão, e uma vez: a segunda chamada (reenvio do runner que
     * não viu a resposta) devolve o que já está gravado.
     */
    public function concluir(User $user, int $id, array $dados): CentralIaExecucao
    {
        // Só o erro e o git medido são mascarados aqui. A resposta é mascarada
        // DEPOIS de separar o bloco do dossiê (ver abaixo): mascarar antes
        // podia quebrar o JSON do bloco.
        foreach (['erro', 'checkpoint'] as $campo) {
            if (isset($dados[$campo])) $dados[$campo] = ProtecaoDeSegredos::dados($dados[$campo]);
        }
        return DB::transaction(function () use ($user, $id, $dados) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $execucao = CentralIaExecucao::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();

            if ($execucao->status !== CentralIaExecucao::EXECUTANDO) {
                return $execucao;
            }

            $ok = $dados['status'] === CentralIaExecucao::CONCLUIDA;
            [$texto, $bloco] = $ok ? Dossie::extrair($dados['resposta'] ?? '') : ['Não consegui concluir: '.($dados['erro'] ?? 'erro desconhecido').'.', null];
            $bloco = $bloco === null ? null : ProtecaoDeSegredos::dados($bloco);
            $tarefa = $execucao->tarefa;

            $resposta = $this->conversas->registrarDoAgente($user, $execucao->nomeDoAgente(), $texto ?: '(resposta vazia)', CentralIaMensagem::AUTOR_AGENTE, $execucao->projeto ?: null, $tarefa?->id);
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

            $ok
                ? Eventos::registrar($user, 'concluida', "{$execucao->nomeDoAgente()} concluiu em ".$execucao->iniciada_em?->diffForHumans(now(), true).($bloco ? '' : ' (sem bloco de contexto)')
                    .(filled($bloco['duvida'] ?? null) ? ' — com dúvida para você' : ''), Eventos::da($execucao) + ['origem' => 'runner'], filled($bloco['duvida'] ?? null) ? 'aviso' : 'info')
                : Eventos::registrar($user, 'falhou', "{$execucao->nomeDoAgente()} falhou: ".($dados['erro'] ?? 'erro desconhecido'), Eventos::da($execucao) + ['origem' => 'runner'], 'erro');

            if ($tarefa) {
                $dossie = Dossie::aplicarConclusao($tarefa->dossie ?? Dossie::inicial($tarefa->objetivo), $execucao, $bloco, $checkpoint);
                if ($ok && ($execucao->roteamento['tipo'] ?? null) === 'ponto') {
                    $dossie['ponto'] = ['mensagem_id' => $resposta->id, 'execucao' => $execucao->id, 'agente' => $execucao->nomeDoAgente(), 'em' => now()->toIso8601String(),
                        'resumo' => \Illuminate\Support\Str::limit($texto, 12000)];
                    Eventos::registrar($user, 'ponto_salvo', "Ponto salvo na tarefa #{$tarefa->id} por {$execucao->nomeDoAgente()}: os próximos pedidos começam sessão nova com o resumo",
                        Eventos::da($execucao) + ['origem' => 'runner']);
                }
                $status = match (true) {
                    ! $ok => CentralIaTarefa::FALHOU,
                    filled($dossie['duvida']) => CentralIaTarefa::AGUARDANDO_VOCE,
                    // Só implementação encerra a tarefa: numa análise ("me diga
                    // onde paramos") o agente marcava concluída a tarefa inteira
                    // porque aquela pergunta tinha sido respondida.
                    $execucao->modo === 'implementar' && (bool) ($bloco['tarefa_concluida'] ?? false) => CentralIaTarefa::CONCLUIDA,
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

            if (! $ok) {
                $this->tentarOutroProvedor($user, $execucao, $dados['erro'] ?? '');
            }

            return $execucao;
        });
    }

    /**
     * O runner conta em que pé está a confirmação que ELE pediu ao dono, e
     * leva o código que a pessoa digitou (se houver), uma vez só.
     *
     * Quem decide se o código vale é a máquina: ela gera o código com um
     * segredo que este servidor nunca vê e o manda direto ao Telegram do dono,
     * junto do pedido exato. Daqui só sai o que a pessoa digitou — um servidor
     * comprometido não tem como forjar a confirmação nem trocar o pedido.
     *
     * @return array{codigo: string|null}
     */
    public function confirmacao(User $user, CentralIaRunner $runner, int $id, array $dados): array
    {
        return DB::transaction(function () use ($user, $runner, $id, $dados) {
            $e = CentralIaExecucao::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($e->status === CentralIaExecucao::EXECUTANDO && $e->runner_id === $runner->id, 409, 'Execução não está com esta máquina.');

            $antes = $e->confirmacao ?? [];
            $estado = $dados['estado'];
            $codigo = in_array($estado, ['aguardando', 'codigo_errado'], true) ? ($antes['codigo_digitado'] ?? null) : null;
            $e->update(['confirmacao' => [
                'estado' => $estado,
                'tentativas' => (int) ($dados['tentativas'] ?? 0),
                'expira_em' => $dados['expira_em'] ?? ($antes['expira_em'] ?? null),
                'resumo' => \Illuminate\Support\Str::limit((string) ($dados['resumo'] ?? ($antes['resumo'] ?? '')), 300),
                'atualizado_em' => now()->toIso8601String(),
                // Entregue uma vez: o runner confere e, se errar, a pessoa digita de novo.
                'codigo_digitado' => null,
            ]]);

            $mudou = ($antes['estado'] ?? null) !== $estado || ($antes['tentativas'] ?? 0) !== (int) ($dados['tentativas'] ?? 0);
            if ($mudou) {
                $quem = $e->nomeDoAgente();
                [$tipo, $nivel, $texto] = match ($estado) {
                    'aguardando' => ['confirmacao_pedida', 'aviso', "🔐 Sua máquina pede confirmação antes de {$quem} {$this->verbo($e)}. O pedido exato e o código foram enviados ao seu Telegram pela própria máquina. Digite o código aqui ou responda lá com /confirmar <código>."],
                    'codigo_errado' => ['confirmacao_codigo_errado', 'aviso', 'O código não confere ('.(int) ($dados['tentativas'] ?? 0).' de 3 tentativas). Confira a mensagem da sua máquina no Telegram e digite de novo.'],
                    'confirmada' => ['confirmacao_aceita', 'info', "✅ Confirmado na sua máquina. {$quem} começou."],
                    'recusada' => ['confirmacao_recusada', 'erro', '⛔ Três códigos errados: a máquina recusou o pedido e nada foi modificado.'],
                    'expirada' => ['confirmacao_expirada', 'erro', '⌛ O código não foi digitado a tempo: a máquina recusou o pedido e nada foi modificado.'],
                    'sem_canal' => ['confirmacao_sem_canal', 'erro', '⛔ A sua máquina não tem como pedir confirmação (Telegram não configurado nela). Nada foi modificado.'],
                };
                Eventos::registrar($user, $tipo, $texto, Eventos::da($e) + ['origem' => 'runner'], $nivel);
                $this->conversas->registrarDoAgente($user, 'Central', $texto, CentralIaMensagem::AUTOR_SISTEMA, $e->projeto ?: null, $e->tarefa_id);
            }

            return ['codigo' => $codigo];
        });
    }

    /**
     * A pessoa digitou o código (na tela ou no Telegram). Fica guardado até a
     * máquina buscar; quem confere é ela.
     */
    public function confirmar(User $user, int $id, string $codigo, string $origem): CentralIaExecucao
    {
        if (! preg_match('/^\d{6}$/', $codigo)) {
            throw ValidationException::withMessages(['codigo' => 'O código tem 6 dígitos.']);
        }

        return DB::transaction(function () use ($user, $id, $codigo, $origem) {
            $e = CentralIaExecucao::where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! $e->aguardandoConfirmacao()) {
                throw ValidationException::withMessages(['codigo' => 'Este pedido não está esperando confirmação.']);
            }
            $e->update(['confirmacao' => ['codigo_digitado' => $codigo] + $e->confirmacao]);
            Eventos::registrar($user, 'confirmacao_digitada', "{$user->name} digitou o código de confirmação ({$origem}); a máquina confere.", Eventos::da($e) + ['origem' => $origem]);

            return $e;
        });
    }

    /** Execuções desta pessoa esperando o código, para a tela e o Telegram. */
    public function aguardandoConfirmacao(User $user): \Illuminate\Support\Collection
    {
        return CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::EXECUTANDO)
            ->whereNotNull('confirmacao')->orderBy('id')->get()->filter->aguardandoConfirmacao()->values();
    }

    private function verbo(CentralIaExecucao $e): string
    {
        return $e->modo === 'implementar' ? 'modificar arquivos em '.($e->projeto ?: 'sua máquina') : 'executar o pedido';
    }

    /**
     * Uma tentativa por provedor, sem repetir o mesmo agente nem criar loop.
     * IDs distintos tornam idempotente a conclusão reenviada do runner anterior.
     * O pedido de sistema só nasce aqui; mensagens de agentes não são roteadas.
     */
    private function tentarOutroProvedor(User $user, CentralIaExecucao $anterior, string $erro): void
    {
        $tipo = FalhaDeProvedor::tipo($erro);
        if (! app(Preferencias::class)->ler($user)['fallback'] || ! $tipo || ! $anterior->tarefa_id) {
            return;
        }
        $user->esquecerPermissoes();
        if (! $user->ativo || ! $user->canPermissao('central_ia_agentes', 'incluir')) {
            return;
        }
        $tarefa = CentralIaTarefa::de($user)->whereKey($anterior->tarefa_id)->lockForUpdate()->firstOrFail();
        // Uma instrução nova já aguardando vence a repetição do pedido antigo.
        if ($tarefa->execucoes()->where('status', CentralIaExecucao::PENDENTE)->exists()) {
            Eventos::registrar($user, 'fallback_adiado', 'Troca automática não repete uma instrução que já tem continuação na fila.', Eventos::da($anterior));
            return;
        }
        if ($anterior->roteamento['coordenador'] ?? false) {
            Eventos::registrar($user, 'coordenador_indisponivel', 'Coordenação Codex interrompida; não delegada silenciosamente.', Eventos::da($anterior), 'aviso');
            return;
        }
        $historico = $anterior->roteamento['fallback'] ?? [];
        $tentados = array_values(array_unique([...($historico['tentados'] ?? []), $anterior->agente]));
        $disponivel = $this->disponiveis($user);
        $online = CentralIaRunner::where('user_id', $user->id)->get()
            ->filter(fn ($r) => $r->online() && ($anterior->projeto === '' || in_array($anterior->projeto, $r->projetos, true)))
            ->pluck('agentes')->flatten()->unique()->all();
        if ($tarefa->status === CentralIaTarefa::ARQUIVADA) return;
        $preferidos = app(Preferencias::class)->ordem($user, $anterior->roteamento['categoria'] ?? ($anterior->modo === 'implementar' ? 'implementacao' : 'investigacao'));
        $agente = collect($preferidos)->first(fn ($a) => in_array($a, $online, true)
            && ! in_array($a, $tentados, true) && ! isset($disponivel['esgotados'][$a]) && app(Preferencias::class)->pode($user, $a));
        if (! $agente) {
            $this->conversas->registrarDoAgente($user, 'Central', 'Não há outro provedor disponível para este pedido. A tarefa e o histórico foram preservados; nenhuma API paga será acionada.', CentralIaMensagem::AUTOR_SISTEMA, $anterior->projeto ?: null, $tarefa->id);
            Eventos::registrar($user, 'fallback_esgotado', 'Alternativas disponíveis esgotadas; tarefa preservada.', Eventos::da($anterior), 'aviso');
            return;
        }
        $nome = CentralIaExecucao::AGENTES[$agente];
        $aviso = $this->conversas->registrarDoAgente($user, 'Central', $anterior->nomeDoAgente().' atingiu um limite ou ficou indisponível. '.$nome.' assumirá a mesma tarefa, com objetivo, instrução, anexos e dossiê. Antes de continuar, deve conferir o estado atual dos arquivos e os testes.', CentralIaMensagem::AUTOR_SISTEMA, $anterior->projeto ?: null, $tarefa->id);
        $rota = $anterior->roteamento;
        $rota['agente'] = $agente;
        $rota = array_merge($rota, app(Preferencias::class)->modelo($user, $rota['nivel'] ?? ($anterior->modo === 'implementar' ? 'forte' : 'medio'), $agente));
        $rota['manual'] = false;
        $rota['handoff_de'] = $anterior->agente;
        $rota['motivo'] = 'Troca automática após '.($tipo === 'limite' ? 'limite de franquia' : 'indisponibilidade').' do '.$anterior->nomeDoAgente();
        $rota['fallback'] = ['raiz' => $historico['raiz'] ?? $anterior->id,
            'pedido_original' => $historico['pedido_original'] ?? $anterior->pedido_id,
            'tentados' => $tentados, 'anterior' => $anterior->id, 'tipo' => $tipo];
        $nova = CentralIaExecucao::create(['user_id' => $user->id, 'pedido_id' => $aviso->id, 'tarefa_id' => $tarefa->id,
            'agente' => $agente, 'projeto' => $anterior->projeto, 'modo' => $anterior->modo,
            'instrucao' => $anterior->instrucao, 'roteamento' => $rota]);
        $tarefa->update(['status' => CentralIaTarefa::ABERTA, 'ultima_atividade_em' => now()]);
        Eventos::registrar($user, 'troca_de_agente', $rota['motivo'].'; '.$nome.' assume a tarefa #'.$tarefa->id, Eventos::da($nova) + ['origem' => 'central'], 'aviso');
    }

    public const INSTRUCAO_DO_PONTO = 'PONTO DE SALVAMENTO desta tarefa. Não altere nada. Escreva um resumo completo para que uma '
        .'sessão NOVA continue só com ele, sem o histórico desta conversa: objetivo, o que já está pronto, o que está em andamento, '
        .'decisões tomadas (e por quê), arquivos alterados, testes e resultados, estado do git, pendências, riscos e o próximo passo exato. '
        .'Seja específico (caminhos, comandos, nomes). Preencha também o bloco final.';

    /**
     * "Armazenar" a conversa num ponto (pedido do dono em 10/10/2026: "para
     * não ficar um chat enorme no contexto"). O agente que conduz a tarefa
     * escreve o resumo; a partir dele, os pedidos começam sessão NOVA com o
     * resumo e o dossiê, em vez de arrastar o histórico inteiro.
     */
    public function salvarPonto(User $user, CentralIaTarefa $tarefa, string $origem): CentralIaExecucao
    {
        $agente = $tarefa->agente_atual ?: 'auto';
        $execucao = $this->pedir($user, '💾 Salvar ponto da tarefa #'.$tarefa->id, [
            'projeto' => $tarefa->projeto, 'agente' => $agente, 'modo' => 'analisar',
            'instrucao' => self::INSTRUCAO_DO_PONTO, 'tarefa_id' => $tarefa->id,
        ], $origem);
        $execucao->update(['roteamento' => ['tipo' => 'ponto'] + ($execucao->roteamento ?? [])]);

        return $execucao;
    }

    public static function rotuloDoNivel(array $rota): string
    {
        return match ($rota['nivel'] ?? null) {
            'sem_ia' => 'sem IA (só os dados oficiais)',
            'leve' => 'nível leve'.(($rota['modelo'] ?? null) ? " ({$rota['modelo']})" : ''),
            'medio' => 'nível médio'.(($rota['modelo'] ?? null) ? " ({$rota['modelo']})" : ''),
            'forte' => 'nível forte'.(($rota['modelo'] ?? null) ? " ({$rota['modelo']})" : ' (modelo principal)'),
            default => '',
        };
    }

    /** Teto de uma execução no runner: implementar tem mais tempo que analisar. */
    public function teto(CentralIaExecucao $e): int
    {
        return $e->modo === 'implementar'
            ? (int) config('central_ia.agentes.timeout_implementar_minutos')
            : (int) config('central_ia.agentes.timeout_minutos');
    }

    /**
     * Execução sem notícia além do teto: o runner caiu ou a máquina desligou.
     * Vira `expirada` com aviso na conversa, e a fila da pessoa anda.
     */
    public function expirarPresas(User $user): void
    {
        // Fila parada: pedido sem máquina capaz há 30 min não seria pego tão
        // cedo, e recebia um aviso "na fila" para sempre. (Tarefa arquivada
        // só suspende a fila — volta ao desarquivar — e não expira.)
        $maquinas = CentralIaRunner::where('user_id', $user->id)->get()->filter->online();
        CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::PENDENTE)->with('tarefa')->get()
            ->filter(fn (CentralIaExecucao $e) => $e->tarefa?->status !== CentralIaTarefa::ARQUIVADA
                && $e->created_at->lt(now()->subMinutes(30)) && ! $maquinas->contains(fn ($r) => in_array($e->agente, $r->agentes ?? [], true)
                    && ($e->projeto === '' || in_array($e->projeto, $r->projetos ?? [], true))))
            ->each(fn (CentralIaExecucao $e) => DB::transaction(function () use ($user, $e) {
                $e = CentralIaExecucao::whereKey($e->id)->lockForUpdate()->first();
                if (! $e || $e->status !== CentralIaExecucao::PENDENTE) return;
                $motivo = 'nenhuma máquina com '.$e->nomeDoAgente().($e->projeto ? " e o projeto {$e->projeto}" : '').' ficou disponível em 30 min';
                $aviso = $this->conversas->registrarDoAgente($user, 'Central', "Pedido #{$e->id} saiu da fila: {$motivo}. Nada foi executado; peça de novo quando quiser.", CentralIaMensagem::AUTOR_SISTEMA, $e->projeto ?: null, $e->tarefa_id);
                $e->update(['status' => CentralIaExecucao::EXPIRADA, 'resposta_id' => $aviso->id, 'concluida_em' => now(), 'erro' => mb_substr("Fila: {$motivo}", 0, 255)]);
                Eventos::registrar($user, 'expirada', "Pedido #{$e->id} saiu da fila: {$motivo}", Eventos::da($e) + ['origem' => 'central'], 'aviso');
            }));

        CentralIaExecucao::where('user_id', $user->id)->where('status', CentralIaExecucao::EXECUTANDO)
            ->where('iniciada_em', '<', now()->subMinutes(config('central_ia.agentes.timeout_minutos') + 5))->get()
            // A espera pela confirmação (até 15 min) conta à parte do teto.
            ->filter(fn (CentralIaExecucao $e) => $e->iniciada_em->lt(now()->subMinutes($this->teto($e) + ($e->confirmacao ? 20 : 5))))
            ->each(fn (CentralIaExecucao $e) => DB::transaction(function () use ($user, $e) {
                // De novo, travado: a conclusão do runner pode ter chegado
                // entre a consulta e aqui — e ela vence.
                $e = CentralIaExecucao::whereKey($e->id)->lockForUpdate()->first();
                if (! $e || $e->status !== CentralIaExecucao::EXECUTANDO) return;
                $aviso = $this->conversas->registrarDoAgente($user, 'Central', "{$e->nomeDoAgente()} não respondeu a tempo em {$e->projeto}; o pedido foi encerrado.", CentralIaMensagem::AUTOR_SISTEMA, $e->projeto ?: null, $e->tarefa_id);
                $e->update(['status' => CentralIaExecucao::EXPIRADA, 'resposta_id' => $aviso->id, 'concluida_em' => now()]);
                Eventos::registrar($user, 'expirada', "{$e->nomeDoAgente()} ficou sem dar notícia além do teto; pedido encerrado", Eventos::da($e) + ['origem' => 'central'], 'erro');
                $e->tarefa?->update(['status' => CentralIaTarefa::FALHOU, 'ultima_atividade_em' => now()]);
            }));
    }
}
