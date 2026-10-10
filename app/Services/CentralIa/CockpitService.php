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
            'maquina' => [
                'online' => $maquina['online'],
                'agentes' => $maquina['agentes'],
                'projetos' => $maquina['projetos'],
            ],
            'paralelo' => (int) config('central_ia.agentes.paralelo'),
        ];
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
