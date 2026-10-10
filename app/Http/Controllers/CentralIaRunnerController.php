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
            'consumo' => ['nullable', 'array'],
            'sessoes' => ['nullable', 'array', 'max:60'],
        ]);

        CentralIaRunner::updateOrCreate(
            ['user_id' => $user->id, 'maquina' => $dados['maquina']],
            [
                'agentes' => array_values(array_unique($dados['agentes'])),
                'projetos' => array_values(array_unique($dados['projetos'])),
                'estado' => $this->estado($dados['estado'] ?? [], $dados['projetos']),
                // Ausente na batida = runner antigo ou leitura ainda não feita:
                // mantém o último valor em vez de apagar o card.
                ...(array_key_exists('consumo', $dados) ? ['consumo' => $this->consumo($dados['consumo'] ?? [])] : []),
                ...(array_key_exists('sessoes', $dados) ? ['sessoes' => $this->sessoes($dados['sessoes'] ?? [], $dados['projetos'])] : []),
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

    /**
     * Só números, dia, agente, projeto e modelo — o formato que o runner
     * manda. Qualquer outro campo é descartado.
     */
    private function consumo(array $c): array
    {
        $numero = fn ($v) => is_numeric($v) ? max(0, (int) $v) : 0;
        $texto = fn ($v, $n) => is_string($v) ? mb_substr($v, 0, $n) : null;
        $linhas = [];

        foreach (array_slice((array) ($c['linhas'] ?? []), 0, 2000) as $l) {
            if (! is_array($l) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($l['dia'] ?? '')) || ! in_array($l['agente'] ?? null, ['claude', 'codex', 'copilot'], true)) {
                continue;
            }
            $linhas[] = [
                'dia' => $l['dia'], 'agente' => $l['agente'], 'projeto' => $texto($l['projeto'] ?? null, 128), 'modelo' => $texto($l['modelo'] ?? null, 80),
                'entrada' => $numero($l['entrada'] ?? 0), 'saida' => $numero($l['saida'] ?? 0),
                'cache_leitura' => $numero($l['cache_leitura'] ?? 0), 'cache_escrita' => $numero($l['cache_escrita'] ?? 0),
                'premium' => is_numeric($l['premium'] ?? null) ? round((float) $l['premium'], 2) : 0,
            ];
        }

        $janela = fn ($j) => is_array($j) ? [
            'usado_pct' => is_numeric($j['usado_pct'] ?? null) ? (float) $j['usado_pct'] : null,
            'janela_min' => is_numeric($j['janela_min'] ?? null) ? (int) $j['janela_min'] : null,
            'renova_em' => $texto($j['renova_em'] ?? null, 40),
        ] : null;
        $cota = $c['cota_codex'] ?? null;

        $claude = $c['limites_claude'] ?? null;
        $janelasClaude = is_array($claude) ? collect((array) ($claude['janelas'] ?? []))->filter(fn ($j) => is_array($j))->take(6)->map(fn ($j) => [
            'rotulo' => $texto($j['rotulo'] ?? null, 60),
            'usado_pct' => is_numeric($j['usado_pct'] ?? null) ? (float) $j['usado_pct'] : null,
            'renova' => $texto($j['renova'] ?? null, 80),
        ])->values()->all() : [];

        return [
            'linhas' => $linhas,
            'cota_codex' => is_array($cota) ? ['em' => $texto($cota['em'] ?? null, 40), 'primaria' => $janela($cota['primaria'] ?? null), 'semanal' => $janela($cota['semanal'] ?? null)] : null,
            'limites_claude' => $janelasClaude ? ['janelas' => $janelasClaude, 'em' => $texto($claude['em'] ?? null, 40)] : null,
            'copilot_premium_mes' => is_numeric($c['copilot_premium_mes'] ?? null) ? round((float) $c['copilot_premium_mes'], 2) : null,
            'coletado_em' => $texto($c['coletado_em'] ?? null, 40),
        ];
    }

    /**
     * Sessões abertas pela pessoa no VS Code. O id é o que o agente usa para
     * retomar; a pasta precisa estar dentro dos projetos da máquina.
     */
    private function sessoes(array $sessoes, array $projetos): array
    {
        $limpas = [];

        foreach ($sessoes as $s) {
            if (! is_array($s) || ! in_array($s['agente'] ?? null, ['claude', 'codex', 'copilot'], true)
                || ! is_string($s['id'] ?? null) || ! preg_match('/^[A-Za-z0-9-]{8,80}$/', $s['id'])
                || ! in_array($s['projeto'] ?? null, $projetos, true) || ! is_string($s['cwd'] ?? null)) {
                continue;
            }
            $limpas[] = [
                'agente' => $s['agente'], 'id' => $s['id'], 'projeto' => $s['projeto'], 'cwd' => mb_substr($s['cwd'], 0, 255),
                'ultima_atividade' => is_string($s['ultima_atividade'] ?? null) ? mb_substr($s['ultima_atividade'], 0, 40) : null,
                'mensagens' => is_int($s['mensagens'] ?? null) ? $s['mensagens'] : 0,
            ];
        }

        return $limpas;
    }

    private function conta(Request $request): User
    {
        $user = $request->user();
        $user->esquecerPermissoes();

        abort_unless($user->ativo && ! $user->temEscopoDeRevenda() && $user->canPermissao('central_ia_agentes', 'incluir'), 403, 'Esta conta não pode acionar agentes.');

        return $user;
    }
}
