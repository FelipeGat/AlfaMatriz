<?php

namespace App\Http\Controllers;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A porta do runner — o programa na máquina de cada pessoa que roda os
 * agentes com o login de IA dela.
 *
 * Autentica pelo token pessoal de capacidade `central-ia-runner`
 * (`alfa:central-ia-token`), e TUDO aqui é da conta dona do token: o runner
 * do Rossini não enxerga pedido do Felipe, e o contrário. As portas da tela
 * valem igual — conta ativa, sem revenda, permissão de acionar agentes —, e
 * são relidas a cada chamada: tirar o perfil para o runner na próxima batida.
 */
class CentralIaRunnerController extends Controller
{
    public function __construct(private readonly AgentesService $agentes) {}

    public function batida(Request $request): JsonResponse
    {
        $user = $this->conta($request);
        $dados = $request->validate([
            'maquina' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],
            'agentes' => ['present', 'array', 'max:10'],
            'agentes.*' => ['string', 'in:'.implode(',', array_keys(CentralIaExecucao::AGENTES))],
            'projetos' => ['present', 'array', 'max:300'],
            'projetos.*' => ['string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'estado' => ['nullable', 'array', 'max:300'],
        ]);

        CentralIaRunner::updateOrCreate(
            ['user_id' => $user->id, 'maquina' => $dados['maquina']],
            [
                'agentes' => array_values(array_unique($dados['agentes'])),
                'projetos' => array_values(array_unique($dados['projetos'])),
                'estado' => $this->estado($dados['estado'] ?? [], $dados['projetos']),
                'visto_em' => now(),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function proxima(Request $request): JsonResponse|Response
    {
        $user = $this->conta($request);
        $dados = $request->validate(['maquina' => ['required', 'string', 'max:64']]);

        $runner = CentralIaRunner::where('user_id', $user->id)->where('maquina', $dados['maquina'])->first();
        abort_unless($runner, 409, 'Mande a batida antes de pedir trabalho.');

        $execucao = $this->agentes->proxima($user, $runner);

        return $execucao ? response()->json($execucao) : response()->noContent();
    }

    public function concluir(Request $request, int $execucao): JsonResponse
    {
        $user = $this->conta($request);
        $dados = $request->validate([
            'status' => ['required', 'in:'.CentralIaExecucao::CONCLUIDA.','.CentralIaExecucao::FALHOU],
            'resposta' => ['required_if:status,'.CentralIaExecucao::CONCLUIDA, 'nullable', 'string', 'max:1000000'],
            'erro' => ['nullable', 'string', 'max:2000'],
            'sessao_agente' => ['nullable', 'string', 'max:128'],
            'branch' => ['nullable', 'string', 'max:128'],
            'worktree' => ['nullable', 'string', 'max:255'],
            'commit_base' => ['nullable', 'string', 'max:64'],
            // O estado do git medido pelo runner ao fim — fato, não relato.
            'checkpoint' => ['nullable', 'array'],
            'checkpoint.cwd' => ['nullable', 'string', 'max:255'],
            'checkpoint.branch' => ['nullable', 'string', 'max:128'],
            'checkpoint.head' => ['nullable', 'string', 'max:64'],
            'checkpoint.arquivos' => ['nullable', 'array', 'max:500'],
            'checkpoint.arquivos.*' => ['string', 'max:300'],
            'checkpoint.status' => ['nullable', 'array', 'max:500'],
            'checkpoint.status.*' => ['string', 'max:320'],
            'checkpoint.diff_stat' => ['nullable', 'string', 'max:300'],
        ]);
        $dados['checkpoint'] = isset($dados['checkpoint'])
            ? array_intersect_key($dados['checkpoint'], array_flip(['cwd', 'branch', 'head', 'arquivos', 'status', 'diff_stat']))
            : null;

        $resultado = $this->agentes->concluir($user, $execucao, $dados);

        return response()->json(['id' => $resultado->id, 'status' => $resultado->status]);
    }

    /**
     * O estado de cada projeto, como o runner mediu — só os campos que a tela
     * mostra, com tamanho limitado, e só de projetos que a máquina informou.
     */
    private function estado(array $estado, array $projetos): array
    {
        $limpo = [];

        foreach (array_intersect_key($estado, array_flip($projetos)) as $projeto => $e) {
            if (! is_array($e)) {
                continue;
            }
            $limpo[$projeto] = [
                'git' => (bool) ($e['git'] ?? false),
                'branch' => is_string($e['branch'] ?? null) ? mb_substr($e['branch'], 0, 128) : null,
                'pendentes' => is_int($e['pendentes'] ?? null) ? $e['pendentes'] : null,
                'ultimo_commit' => is_string($e['ultimo_commit'] ?? null) ? mb_substr($e['ultimo_commit'], 0, 40) : null,
                'ultimo_commit_msg' => is_string($e['ultimo_commit_msg'] ?? null) ? mb_substr($e['ultimo_commit_msg'], 0, 120) : null,
                'sessoes' => is_array($e['sessoes'] ?? null) ? array_values(array_intersect($e['sessoes'], ['claude', 'codex', 'copilot'])) : [],
                'fase' => is_string($e['fase'] ?? null) ? mb_substr($e['fase'], 0, 40) : null,
                'atividade' => is_string($e['atividade'] ?? null) ? mb_substr($e['atividade'], 0, 40) : null,
            ];
        }

        return $limpo;
    }

    private function conta(Request $request): User
    {
        $user = $request->user();
        $user->esquecerPermissoes();

        abort_unless($user->ativo && ! $user->temEscopoDeRevenda() && $user->canPermissao('central_ia_agentes', 'incluir'), 403, 'Esta conta não pode acionar agentes.');

        return $user;
    }
}
