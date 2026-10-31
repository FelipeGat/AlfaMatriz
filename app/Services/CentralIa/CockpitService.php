<?php

namespace App\Services\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaProjeto;
use App\Models\CentralIaTarefa;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * O retrato do cockpit: quais projetos aparecem, em que estado está cada um
 * e o que está rodando em todos eles.
 *
 * Aparece quem está FIXADO, quem tem tarefa em curso e quem teve atividade
 * nas últimas 24 h — o resto dos projetos da máquina fica no "fixar
 * projeto", para a barra não virar a lista das 34 pastas.
 */
class CockpitService
{
    /** O dono do repositório no GitHub → o nome do grupo da tela (em minúsculas). */
    // Desligado em 31/10/2026: o Matriz ficou só com a Alfa e o Grupo Soluções. Era: 'vixhouse' => 'vixcard'.
    public const GRUPO_DO_DONO = [/* 'vixhouse' => 'vixcard', */ 'felipegat' => 'grupo soluções'];

    public function __construct(private readonly AgentesService $agentes) {}

    public function montar(User $user): array
    {
        $maquina = $this->agentes->disponiveis($user);
        $linhasFixadas = CentralIaProjeto::where('user_id', $user->id)->orderBy('ordem')->orderBy('projeto')->get();
        $fixados = $linhasFixadas->pluck('projeto')->all();
        $grupoDe = $linhasFixadas->pluck('grupo_id', 'projeto');
        $tarefas = CentralIaTarefa::de($user)->tarefas()->emCurso()->orderByDesc('ultima_atividade_em')->get()->groupBy('projeto');
        $recentes = CentralIaExecucao::where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->distinct()->pluck('projeto')->all();

        // '' é a conversa Geral: tem tarefas, mas não é projeto da barra.
        $nomes = array_values(array_filter(array_unique(array_merge($fixados, $tarefas->keys()->all(), $recentes, $maquina['projetos'])), fn ($n) => $n !== ''));

        // A empresa vem do GitHub do projeto (Felipe, 21/10/2026: "separar por empresa pelo git"): o
        // repositório da VixHouse cai no grupo VixCard e o da conta da Alfa no Grupo Soluções, se a
        // pessoa tiver esses grupos. Projeto sem GitHub conhecido fica no grupo que ela escolheu.
        $gruposPorNome = \App\Models\CentralIaGrupo::where('user_id', $user->id)->get(['id', 'nome'])->mapWithKeys(fn ($g) => [mb_strtolower(trim($g->nome)) => $g->id]);
        $grupoDoGit = fn (?string $remoto) => $remoto ? ($gruposPorNome[self::GRUPO_DO_DONO[mb_strtolower(strtok($remoto, '/'))] ?? ''] ?? null) : null;

        $projetos = array_map(function (string $nome) use ($user, $fixados, $tarefas, $maquina, $grupoDe, $grupoDoGit) {
            $emCurso = $tarefas->get($nome, collect());
            $ultimaMensagem = CentralIaMensagem::de($user)->where('projeto', $nome)->max('created_at');
            $ultimaAtividade = collect([$emCurso->max('ultima_atividade_em'), $ultimaMensagem ? Carbon::parse($ultimaMensagem) : null])->filter()->max();
            $ativa = $emCurso->first();

            $estado = match (true) {
                $emCurso->contains('status', CentralIaTarefa::AGUARDANDO_VOCE) => 'aguardando_voce',
                $emCurso->contains('status', CentralIaTarefa::FALHOU) => 'falhou',
                $emCurso->contains('status', CentralIaTarefa::EXECUTANDO) => 'executando',
                $emCurso->contains('status', CentralIaTarefa::ABERTA) => 'aberta',
                $emCurso->contains('status', CentralIaTarefa::PAUSADA) => 'pausada',
                default => 'ocioso',
            };

            return [
                'nome' => $nome,
                'fixado' => in_array($nome, $fixados, true),
                'grupo_id' => $grupoDoGit($maquina['estado'][$nome]['remoto'] ?? null) ?? $grupoDe[$nome] ?? null,
                'na_maquina' => in_array($nome, $maquina['projetos'], true),
                'estado' => $estado,
                'atencao' => in_array($estado, ['aguardando_voce', 'falhou'], true),
                'agente' => $ativa?->agente_atual ? (CentralIaExecucao::AGENTES[$ativa->agente_atual] ?? $ativa->agente_atual) : null,
                'tarefas_em_curso' => $emCurso->count(),
                'ultima_atividade' => $ultimaAtividade?->toIso8601String(),
                'git' => $maquina['estado'][$nome] ?? null,
            ];
        }, $nomes);
        // Pasta que saiu da máquina (arquivada em 21/10/2026) e não tem conversa em curso não aparece mais.
        $projetos = array_values(array_filter($projetos, fn ($p) => $p['na_maquina'] || $p['tarefas_em_curso'] > 0 || ! $maquina['online']));

        return [
            'projetos' => $projetos,
            'chats' => $this->chats($user),
            'assuntos' => $this->assuntos($user),
            'grupos' => \App\Models\CentralIaGrupo::where('user_id', $user->id)->orderBy('ordem')->orderBy('nome')->get(['id', 'nome', 'ordem'])->toArray(),
            'atividade' => $this->atividade($user),
            'recentes' => $this->recentes($maquina, $nomes),
            'consumo' => $this->consumo($user),
            'sessoes' => $this->sessoes($user),
            'maquina' => [
                'online' => $maquina['online'],
                'agentes' => $maquina['agentes'],
                'projetos' => $maquina['projetos'],
                'politica' => \App\Models\CentralIaRunner::where('user_id', $user->id)->orderByDesc('visto_em')->value('politica'),
            ],
            'paralelo' => min((int) config('central_ia.agentes.paralelo'), app(Preferencias::class)->ler($user)['paralelo']),
        ];
    }

    /** Chats da Central são assuntos/tarefas; sessões externas não são importadas aqui. */
    public function chats(User $user): array
    {
        // Concluída fica 24 h na lista (dono, 13/10/2026: "a #15 sumiu da
        // tela" — terminou e saiu antes de ele ver o desfecho).
        return CentralIaTarefa::de($user)->tarefas()
            ->where(fn ($q) => $q->emCurso()->orWhere(fn ($c) => $c->where('status', CentralIaTarefa::CONCLUIDA)->where('ultima_atividade_em', '>=', now()->subDay())))
            ->orderByDesc('ultima_atividade_em')->orderByDesc('id')
            ->get(['id', 'projeto', 'titulo', 'status', 'agente_atual', 'ultima_atividade_em', 'created_at'])
            ->map(fn (CentralIaTarefa $t) => [
                'id' => $t->id, 'projeto' => $t->projeto, 'titulo' => $t->titulo, 'status' => $t->status,
                'agente' => CentralIaExecucao::AGENTES[$t->agente_atual] ?? null,
                'ultima_atividade' => ($t->ultima_atividade_em ?? $t->created_at)?->toIso8601String(),
            ])->all();
    }

    /** Os chats de assunto (aba Business), sem os arquivados. */
    public function assuntos(User $user): array
    {
        return CentralIaTarefa::de($user)->assuntos()->where('status', '!=', CentralIaTarefa::ARQUIVADA)
            ->orderByDesc('ultima_atividade_em')->orderByDesc('id')
            ->get(['id', 'titulo', 'grupo_id', 'projeto', 'status', 'agente_atual', 'ultima_atividade_em', 'created_at', 'dossie'])
            ->map(fn (CentralIaTarefa $t) => [
                'id' => $t->id, 'titulo' => $t->titulo, 'grupo_id' => $t->grupo_id, 'projeto' => $t->projeto, 'status' => $t->status,
                'agente' => CentralIaExecucao::AGENTES[$t->agente_atual] ?? null,
                'ultima_atividade' => ($t->ultima_atividade_em ?? $t->created_at)?->toIso8601String(),
                // A pergunta pendente vai junto: assunto não passa pela lista de tarefas
                // do projeto, e sem isto a tela dizia "Aguardando você" sem dizer o quê (Felipe, 20/10/2026).
                'duvida' => $t->status === CentralIaTarefa::AGUARDANDO_VOCE ? ($t->dossie['duvida'] ?? null) : null,
                'opcoes_duvida' => $t->status === CentralIaTarefa::AGUARDANDO_VOCE ? ($t->dossie['opcoes_duvida'] ?? []) : [],
            ])->all();
    }

    /**
     * Todos os projetos da máquina pelo último commit — os que mexeram por
     * último no git, estejam ou não no cockpit.
     */
    private function recentes(array $maquina, array $noCockpit): array
    {
        return collect($maquina['estado'])
            ->filter(fn ($e) => filled($e['ultimo_commit'] ?? null))
            ->sortByDesc('ultimo_commit')->take(12)
            ->map(fn ($e, $nome) => [
                'nome' => $nome, 'branch' => $e['branch'] ?? null, 'pendentes' => $e['pendentes'] ?? null,
                'ultimo_commit' => $e['ultimo_commit'], 'ultimo_commit_msg' => $e['ultimo_commit_msg'] ?? null,
                'fase' => $e['fase'] ?? null, 'atividade' => $e['atividade'] ?? null,
                'no_cockpit' => in_array($nome, $noCockpit, true),
            ])->values()->all();
    }

    /**
     * Tokens por IA (hoje e últimos 7 dias), os projetos que mais gastaram
     * hoje, a cota do Codex e as requisições premium do Copilot — somados das
     * máquinas da pessoa, como cada runner leu dos registros locais.
     */
    private function consumo(User $user): array
    {
        $runners = \App\Models\CentralIaRunner::where('user_id', $user->id)->get();
        $linhas = $runners->pluck('consumo')->filter()->pluck('linhas')->flatten(1);
        $hoje = now()->toDateString();
        $semana = now()->subDays(6)->toDateString();

        $somar = fn ($grupo) => [
            'entrada' => $grupo->sum('entrada'), 'saida' => $grupo->sum('saida'),
            'cache_leitura' => $grupo->sum('cache_leitura'), 'cache_escrita' => $grupo->sum('cache_escrita'),
            'premium' => round($grupo->sum('premium'), 1),
        ];

        $porAgente = [];
        foreach (array_keys(CentralIaExecucao::AGENTES) as $a) {
            $doAgente = $linhas->where('agente', $a);
            $porAgente[] = ['agente' => $a, 'nome' => CentralIaExecucao::AGENTES[$a],
                'hoje' => $somar($doAgente->where('dia', $hoje)), 'semana' => $somar($doAgente->where('dia', '>=', $semana))];
        }

        $cota = $runners->pluck('consumo')->filter()->pluck('cota_codex')->filter()->sortByDesc('em')->first();
        $ultimo = $runners->pluck('consumo')->filter()->sortByDesc('coletado_em')->first() ?? [];

        // Série diária (7 dias) por IA, para o gráfico: tokens de entrada e
        // saída, sem cache — o cache é barato e inflaria tudo por 100x.
        $dias = collect(range(6, 0))->map(fn ($n) => now()->subDays($n)->toDateString());
        $serie = $dias->map(fn ($d) => ['dia' => $d] + collect(array_keys(CentralIaExecucao::AGENTES))
            ->mapWithKeys(fn ($a) => [$a => (int) $linhas->where('dia', $d)->where('agente', $a)->sum(fn ($l) => $l['entrada'] + $l['saida'])])->all())->all();

        // A janela do Codex que já renovou não está mais "usada": o número
        // gravado é do último uso, e sem uso novo ele não se atualiza.
        $janelaCodex = function ($j) {
            if (! $j) {
                return null;
            }
            $renova = isset($j['renova_em']) ? \Illuminate\Support\Carbon::parse($j['renova_em']) : null;

            return $renova && $renova->isPast() ? ['usado_pct' => 0.0, 'renova_em' => null, 'renovou' => true] + $j : $j + ['renovou' => false];
        };

        $pedidosHoje = CentralIaExecucao::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())
            ->whereHas('pedido', fn ($q) => $q->where('autor_tipo', CentralIaMensagem::AUTOR_USUARIO))->count();

        $limites = [
            'central' => ['usados' => app(Preferencias::class)->usados($user), 'limite' => app(Preferencias::class)->ler($user)['limite_total']],
            'claude' => $ultimo['limites_claude'] ?? null,
            'codex' => $cota ? ['primaria' => $janelaCodex($cota['primaria'] ?? null), 'semanal' => $janelaCodex($cota['semanal'] ?? null), 'em' => $cota['em'] ?? null] : null,
            'copilot' => ['premium_mes' => $ultimo['copilot_premium_mes'] ?? null, 'franquia' => config('central_ia.agentes.copilot_premium_mes')],
            'esgotados' => $this->agentes->esgotados($runners) + $this->agentes->falhasRecentes($user),
            'por_agente_central' => CentralIaExecucao::where('user_id', $user->id)->where('created_at', '>=', now()->startOfDay())
                ->selectRaw('agente, count(*) as n')->groupBy('agente')->pluck('n', 'agente')->all(),
        ];

        return [
            'serie' => $serie,
            'limites' => $limites,
            'por_agente' => $porAgente,
            // "(sem projeto)" e "(fora dos projetos)" não são projetos: o gasto
            // entra nos totais por IA, não no ranking.
            'projetos_hoje' => $linhas->where('dia', $hoje)->reject(fn ($l) => str_starts_with((string) ($l['projeto'] ?? ''), '('))->groupBy('projeto')
                ->map(fn ($g, $p) => ['projeto' => $p, 'tokens' => $g->sum('entrada') + $g->sum('saida'),
                    'por_agente' => $g->groupBy('agente')->map(fn ($x) => $x->sum('entrada') + $x->sum('saida'))->all()])
                ->sortByDesc('tokens')->take(6)->values()->all(),
            'cota_codex' => $cota,
            'coletado_em' => $runners->pluck('consumo')->filter()->pluck('coletado_em')->filter()->max(),
        ];
    }

    private function sessoes(User $user): array
    {
        // Conversa que já semeou um chat de assunto: a tela marca (Chats Business).
        $semeadas = [];
        foreach (CentralIaTarefa::de($user)->assuntos()->get(['id', 'dossie']) as $a) {
            foreach ($a->dossie['sementes'] ?? [] as $s) $semeadas[$s['id'] ?? ''] = $a->id;
        }

        return $this->agentes->sessoes($user)->take(120)->map(fn ($s) => $s + [
            'semeada_em' => $semeadas[$s['id']] ?? null,
            'nome' => CentralIaExecucao::AGENTES[$s['agente']] ?? $s['agente'],
            'ativa_agora' => $s['ultima_atividade'] && Carbon::parse($s['ultima_atividade'])->greaterThan(now()->subMinutes(AgentesService::PARADA_MINUTOS)),
        ])->all();
    }

    /**
     * O que roda e o que acabou de rodar, em TODOS os projetos — o painel de
     * atividades fica à vista mesmo quando se olha outro projeto.
     */
    private function atividade(User $user): array
    {
        $ativas = CentralIaExecucao::where('user_id', $user->id)
            ->whereIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO])
            ->with('tarefa')->orderBy('id')->get();
        $encerradas = CentralIaExecucao::where('user_id', $user->id)
            ->whereNotIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO])
            ->with('tarefa')->orderByDesc('id')->limit(12)->get();

        return $ativas->concat($encerradas)->map(fn (CentralIaExecucao $e) => [
            'id' => $e->id,
            'projeto' => $e->projeto,
            'tarefa_id' => $e->tarefa_id,
            'tarefa' => $e->tarefa?->titulo,
            'agente' => $e->nomeDoAgente(),
            'modo' => $e->modo,
            'status' => $e->status,
            'motivo' => $e->roteamento['motivo'] ?? null,
            'handoff_de' => isset($e->roteamento['handoff_de']) ? (CentralIaExecucao::AGENTES[$e->roteamento['handoff_de']] ?? null) : null,
            'progresso' => $e->progresso,
            'progresso_em' => $e->progresso_em?->toIso8601String(),
            'criada_em' => $e->created_at->toIso8601String(),
            'iniciada_em' => $e->iniciada_em?->toIso8601String(),
            'concluida_em' => $e->concluida_em?->toIso8601String(),
        ])->values()->all();
    }

    /**
     * As tarefas de um projeto, com o que o dossiê tem de útil para a tela.
     */
    public function tarefas(User $user, string $projeto): array
    {
        return CentralIaTarefa::de($user)->tarefas()->where('projeto', $projeto)
            ->orderByRaw('CASE WHEN status IN (?, ?, ?, ?, ?) THEN 0 ELSE 1 END', CentralIaTarefa::EM_CURSO)
            ->orderByDesc('ultima_atividade_em')->limit(30)->get()
            ->map(function (CentralIaTarefa $t) {
                $d = $t->dossie ?? [];
                $ultima = $t->execucoes()->latest('id')->first();

                return [
                    'id' => $t->id,
                    'titulo' => $t->titulo,
                    'objetivo' => $t->objetivo,
                    'status' => $t->status,
                    'agente' => $t->agente_atual ? (CentralIaExecucao::AGENTES[$t->agente_atual] ?? $t->agente_atual) : null,
                    'branch' => $t->branch,
                    'worktree' => $t->worktree,
                    'commit_base' => $t->commit_base,
                    'ultima_atividade' => $t->ultima_atividade_em?->toIso8601String(),
                    'ultima_execucao' => $ultima ? ['status' => $ultima->status, 'agente' => $ultima->nomeDoAgente(), 'motivo' => $ultima->roteamento['motivo'] ?? null] : null,
                    'agentes' => array_column($d['agentes'] ?? [], 'nome'),
                    'git' => $d['git'] ?? null,
                    'testes' => array_slice($d['testes'] ?? [], -5),
                    'decisoes' => array_slice(array_column($d['decisoes'] ?? [], 'texto'), -5),
                    'proximos_passos' => $d['proximos_passos'] ?? [],
                    'pendencias' => $d['pendencias'] ?? [],
                    'riscos' => $d['riscos'] ?? [],
                    'duvida' => $d['duvida'] ?? null,
                    'opcoes_duvida' => $d['opcoes_duvida'] ?? [],
                    'lacunas' => $d['lacunas'] ?? [],
                    'resumo' => collect($d['resumos'] ?? [])->last()['texto'] ?? null,
                    // Quem viu o quê e quem alterou o quê, execução por execução.
                    'execucoes' => $t->execucoes()->latest('id')->limit(10)->get()->map(fn (CentralIaExecucao $e) => [
                        'id' => $e->id, 'agente' => $e->nomeDoAgente(), 'modo' => $e->modo, 'status' => $e->status,
                        'nivel' => $e->roteamento['nivel'] ?? null, 'modelo' => $e->roteamento['modelo'] ?? null,
                        'em' => ($e->concluida_em ?? $e->created_at)?->toIso8601String(),
                        'acesso' => $e->auditoria['acesso'] ?? null,
                        'informado' => $e->auditoria['informado'] ?? null,
                        'acessos' => array_slice($e->auditoria['acessos'] ?? [], 0, 60),
                        'total_acessos' => count($e->auditoria['acessos'] ?? []),
                        'arquivos' => $e->auditoria['git']['pelo_agente'] ?? ($e->auditoria['git']['arquivos'] ?? []),
                        'na_pasta' => isset($e->auditoria['git']['pelo_agente']) ? ($e->auditoria['git']['na_pasta'] ?? []) : [],
                        'commits' => $e->auditoria['git']['commits'] ?? [],
                    ])->all(),
                    'ponto' => isset($d['ponto']) ? ['em' => $d['ponto']['em'], 'agente' => $d['ponto']['agente'], 'mensagem_id' => $d['ponto']['mensagem_id'] ?? null] : null,
                ];
            })->all();
    }
}
