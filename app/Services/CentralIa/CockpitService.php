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
    public function __construct(private readonly AgentesService $agentes) {}

    public function montar(User $user): array
    {
        $maquina = $this->agentes->disponiveis($user);
        $fixados = CentralIaProjeto::where('user_id', $user->id)->orderBy('ordem')->orderBy('projeto')->pluck('projeto')->all();
        $tarefas = CentralIaTarefa::de($user)->emCurso()->orderByDesc('ultima_atividade_em')->get()->groupBy('projeto');
        $recentes = CentralIaExecucao::where('user_id', $user->id)->where('created_at', '>=', now()->subDay())->distinct()->pluck('projeto')->all();

        $nomes = array_values(array_unique(array_merge($fixados, $tarefas->keys()->all(), $recentes)));

        $projetos = array_map(function (string $nome) use ($user, $fixados, $tarefas, $maquina) {
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
                'na_maquina' => in_array($nome, $maquina['projetos'], true),
                'estado' => $estado,
                'atencao' => in_array($estado, ['aguardando_voce', 'falhou'], true),
                'agente' => $ativa?->agente_atual ? (CentralIaExecucao::AGENTES[$ativa->agente_atual] ?? $ativa->agente_atual) : null,
                'tarefas_em_curso' => $emCurso->count(),
                'ultima_atividade' => $ultimaAtividade?->toIso8601String(),
                'git' => $maquina['estado'][$nome] ?? null,
            ];
        }, $nomes);

        return [
            'projetos' => $projetos,
            'atividade' => $this->atividade($user),
            'recentes' => $this->recentes($maquina, $nomes),
            'consumo' => $this->consumo($user),
            'sessoes' => $this->sessoes($user),
            'maquina' => [
                'online' => $maquina['online'],
                'agentes' => $maquina['agentes'],
                'projetos' => $maquina['projetos'],
            ],
            'paralelo' => (int) config('central_ia.agentes.paralelo'),
        ];
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

        return [
            'por_agente' => $porAgente,
            'projetos_hoje' => $linhas->where('dia', $hoje)->groupBy('projeto')
                ->map(fn ($g, $p) => ['projeto' => $p, 'tokens' => $g->sum('entrada') + $g->sum('saida'),
                    'por_agente' => $g->groupBy('agente')->map(fn ($x) => $x->sum('entrada') + $x->sum('saida'))->all()])
                ->sortByDesc('tokens')->take(6)->values()->all(),
            'cota_codex' => $cota,
            'coletado_em' => $runners->pluck('consumo')->filter()->pluck('coletado_em')->filter()->max(),
        ];
    }

    private function sessoes(User $user): array
    {
        return $this->agentes->sessoes($user)->take(25)->map(fn ($s) => $s + [
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
        return CentralIaTarefa::de($user)->where('projeto', $projeto)
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
                    'lacunas' => $d['lacunas'] ?? [],
                    'resumo' => collect($d['resumos'] ?? [])->last()['texto'] ?? null,
                ];
            })->all();
    }
}
