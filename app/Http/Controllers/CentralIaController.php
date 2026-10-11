<?php

namespace App\Http\Controllers;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaProjeto;
use App\Models\CentralIaTarefa;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\CockpitService;
use App\Services\CentralIa\ConversaService;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * O cockpit da Central de IA e as chamadas que ele faz.
 *
 * Tudo é da conta logada — não há id de usuário na rota, e tarefa e projeto
 * são sempre procurados DENTRO das linhas da pessoa: o que não é dela dá 404,
 * nunca "acesso negado a algo que existe".
 */
class CentralIaController extends Controller
{
    public function __construct(
        private readonly ConversaService $conversas,
        private readonly TelegramCanal $telegram,
        private readonly AgentesService $agentes,
        private readonly CockpitService $cockpit,
    ) {}

    public function index(Request $request): View
    {
        return view('central-ia.index', [
            'podeEnviar' => $request->user()->canPermissao('central_ia', 'incluir'),
            'podeParear' => $request->user()->canPermissao('central_ia', 'editar'),
            'podeAcionar' => $request->user()->canPermissao('central_ia_agentes', 'incluir'),
            'agentes' => CentralIaExecucao::AGENTES,
            'modos' => CentralIaExecucao::MODOS,
        ]);
    }

    /**
     * Projetos, atividade de todos eles, máquina e Telegram — o poll do
     * cockpit, que corre mesmo quando se olha um projeto só.
     */
    public function painel(Request $request): JsonResponse
    {
        app(\App\Services\CentralIa\ProgressoService::class)->publicar($request->user());
        $vinculo = $this->telegram->vinculoDe($request->user());
        $ultimaTelegram = $vinculo ? CentralIaMensagem::de($request->user())
            ->where('origem', CentralIaMensagem::ORIGEM_TELEGRAM)->latest('id')->first(['id', 'projeto', 'tarefa_id']) : null;

        return $this->semCache($this->cockpit->montar($request->user()) + [
            'conta_nome' => $request->user()->name,
            'canal' => ['disponivel' => app(\App\Services\CentralIa\CanalAtivo::class)->pronto(),
                'ativo' => app(\App\Services\CentralIa\CanalAtivo::class)->ativo($request->user()),
                'projeto' => app(\App\Services\CentralIa\CanalAtivo::class)->estado($request->user())?->projeto_canal,
                'tarefa_id' => app(\App\Services\CentralIa\CanalAtivo::class)->estado($request->user())?->tarefa_canal],
            'telegram' => ['vinculado' => (bool) $vinculo, 'usuario' => $vinculo?->telegram_usuario,
                'projeto' => $ultimaTelegram ? $ultimaTelegram->projeto : $vinculo?->projeto_atual,
                'tarefa_id' => $ultimaTelegram?->tarefa_id, 'mensagem_id' => $ultimaTelegram?->id],
            'eventos' => \App\Services\CentralIa\Eventos::recentes($request->user()),
        ]);
    }

    public function mensagens(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'antes' => ['nullable', 'integer', 'min:1', 'prohibits:depois'],
            'depois' => ['nullable', 'integer', 'min:0'],
            'projeto' => ['nullable', 'string', 'max:128'],
            'tarefa' => ['nullable', 'integer'],
        ]);
        $projeto = $dados['projeto'] ?? null;

        [$mensagens, $haMais] = $this->conversas->historico(
            $request->user(),
            isset($dados['antes']) ? (int) $dados['antes'] : null,
            isset($dados['depois']) ? (int) $dados['depois'] : null,
            $projeto,
            isset($dados['tarefa']) ? (int) $dados['tarefa'] : null,
        );
        $mensagens->load('entrega', 'execucao', 'anexos');

        // Entrega e execução mudam DEPOIS de a mensagem já estar na tela: o
        // poll pede só o que veio depois da última, então as recentes voltam
        // aqui com o estado atual, para "entregue" e "concluído" aparecerem.
        $recentes = CentralIaMensagem::de($request->user())
            ->when($projeto === null, fn ($q) => $q->whereNull('projeto'), fn ($q) => $q->where('projeto', $projeto))
            ->when(isset($dados['tarefa']), fn ($q) => $q->where('tarefa_id', $dados['tarefa']))
            ->where(fn ($q) => $q->whereHas('entrega')->orWhereHas('execucao'))
            ->with('entrega', 'execucao')->orderByDesc('id')->limit(20)->get();

        return $this->semCache([
            'mensagens' => $mensagens->map(fn (CentralIaMensagem $m) => $this->publica($m))->all(),
            'recentes' => $recentes->map(fn (CentralIaMensagem $m) => [
                'id' => $m->id, 'entrega' => $m->entrega?->status, 'pedido' => $this->pedido($m),
            ])->all(),
            'ha_mais' => $haMais,
        ]);
    }

    public function tarefas(Request $request, string $projeto): JsonResponse
    {
        return $this->semCache(['tarefas' => $this->cockpit->tarefas($request->user(), $projeto)]);
    }

    public function canal(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'canal' => ['required', 'in:web,telegram'],
            'projeto' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'tarefa_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $c = app(\App\Services\CentralIa\CanalAtivo::class)->transferir($request->user(), $dados['canal'],
            $dados['projeto'] ?? null, $dados['tarefa_id'] ?? null);
        return $this->semCache(['ativo' => $c->canal_ativo, 'projeto' => $c->projeto_canal, 'tarefa_id' => $c->tarefa_canal]);
    }

    public function enviar(Request $request): JsonResponse
    {
        app(\App\Services\CentralIa\CanalAtivo::class)->exigir($request->user(), 'web');
        $dados = $request->validate([
            'conteudo' => ['required_without:anexos', 'nullable', 'string', 'max:'.config('central_ia.max_texto')],
            'anexos' => ['sometimes', 'array', 'max:5'],
            'anexos.*' => ['required', 'uuid', 'distinct'],
            'client_id' => ['required', 'uuid'],
            'projeto' => ['nullable', 'string', 'max:128'],
            'tarefa_id' => ['nullable', 'integer'],
            // Nulo = só registrar na conversa; `auto` = o roteador escolhe.
            'agente' => ['nullable', 'string'],
            'modo' => ['nullable', 'string'],
        ]);

        $dados['conteudo'] = $dados['conteudo'] ?? 'Analise os arquivos anexados.';

        // Consulta operacional não espera na fila da IA, mesmo durante execução.
        if (empty($dados['anexos']) && app(\App\Services\CentralIa\ProgressoService::class)->consulta($dados['conteudo'])) {
            $mensagem = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $dados) {
                $user = $request->user();
                \App\Models\User::whereKey($user->id)->lockForUpdate()->first();
                $tarefa = filled($dados['tarefa_id'] ?? null) ? CentralIaTarefa::de($user)
                    ->where('projeto', $dados['projeto'] ?? '')->findOrFail($dados['tarefa_id']) : null;
                $m = $this->conversas->registrarDoUsuario($user, $dados['conteudo'], CentralIaMensagem::ORIGEM_WEB,
                    $dados['client_id'], projeto: $dados['projeto'] ?? null, tarefaId: $tarefa?->id);
                if ($m->wasRecentlyCreated) {
                    $this->conversas->registrarDoAgente($user, 'Central de IA',
                        app(\App\Services\CentralIa\ProgressoService::class)->resumo($user, $dados['projeto'] ?? null, $tarefa?->id),
                        CentralIaMensagem::AUTOR_SISTEMA, $dados['projeto'] ?? null, $tarefa?->id);
                }
                return $m;
            });
            return $this->semCache(['id' => $mensagem->id, 'status_direto' => true], $mensagem->wasRecentlyCreated ? 201 : 200);
        }

        // O atalho digitado (`/claude projeto …`) vale em qualquer conversa.
        $pedido = $this->agentes->interpretar($dados['conteudo']);

        // Na Geral, "automático" só aciona agente quando a mensagem pede uma
        // resposta; o resto é anotação (não gasta cota). Agente escolhido
        // explicitamente aciona sempre.
        $geralAuto = blank($dados['projeto'] ?? null) && ($dados['agente'] ?? null) === 'auto';
        if ($geralAuto && ! app(\App\Services\CentralIa\Roteador::class)->pedeResposta($dados['conteudo'])) {
            $dados['agente'] = null;
        }

        if (! $pedido && filled($dados['agente'] ?? null)) {
            $pedido = [
                'agente' => $dados['agente'],
                'projeto' => (string) ($dados['projeto'] ?? ''),
                'modo' => $dados['modo'] ?? 'auto',
                'instrucao' => $dados['conteudo'],
                'tarefa_id' => $dados['tarefa_id'] ?? null,
            ];
        }

        if ($pedido) {
            $pedido['anexos'] = $dados['anexos'] ?? [];
            abort_unless($request->user()->canPermissao('central_ia_agentes', 'incluir'), 403, 'Sua conta não pode acionar agentes.');
            $execucao = $this->agentes->pedir($request->user(), $dados['conteudo'], $pedido, CentralIaMensagem::ORIGEM_WEB, $dados['client_id']);

            return $this->semCache(['id' => $execucao->pedido_id, 'tarefa_id' => $execucao->tarefa_id], $execucao->wasRecentlyCreated ? 201 : 200);
        }

        // Nota sem agente: fica na conversa do projeto (e da tarefa, se veio).
        $tarefaId = null;
        if (filled($dados['tarefa_id'] ?? null)) {
            $tarefaId = CentralIaTarefa::de($request->user())->whereKey($dados['tarefa_id'])->value('id');
            abort_unless($tarefaId, 404);
        }

        $mensagem = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $dados, $tarefaId) {
            $mensagem = $this->conversas->registrarDoUsuario(
                $request->user(), $dados['conteudo'], CentralIaMensagem::ORIGEM_WEB, $dados['client_id'],
                projeto: $dados['projeto'] ?? null, tarefaId: $tarefaId,
            );
            app(\App\Services\CentralIa\AnexosService::class)->associar($request->user(), $mensagem, $dados['anexos'] ?? []);
            return $mensagem;
        });

        return $this->semCache(['id' => $mensagem->id], $mensagem->wasRecentlyCreated ? 201 : 200);
    }

    /**
     * Continua aqui uma conversa do VS Code: abre (ou reabre) a tarefa que
     * aponta para ela. O pedido em si vem depois, pelo compositor.
     */
    public function continuarSessao(Request $request): JsonResponse
    {
        abort_unless($request->user()->canPermissao('central_ia_agentes', 'incluir'), 403, 'Sua conta não pode acionar agentes.');
        $dados = $request->validate([
            'agente' => ['required', Rule::in(array_keys(CentralIaExecucao::AGENTES))],
            'sessao' => ['required', 'string', 'max:80'],
        ]);

        $tarefa = $this->agentes->continuarSessao($request->user(), $dados['agente'], $dados['sessao']);

        return $this->semCache(['tarefa_id' => $tarefa->id, 'projeto' => $tarefa->projeto]);
    }

    public function criarGrupo(Request $request): JsonResponse
    {
        $dados = $request->validate(['nome' => ['required', 'string', 'max:60',
            Rule::unique('central_ia_grupos', 'nome')->where('user_id', $request->user()->id)]]);

        $grupo = \App\Models\CentralIaGrupo::create(['user_id' => $request->user()->id, 'nome' => trim($dados['nome']),
            'ordem' => (int) \App\Models\CentralIaGrupo::where('user_id', $request->user()->id)->max('ordem') + 1]);

        return $this->semCache(['id' => $grupo->id, 'nome' => $grupo->nome], 201);
    }

    public function renomearGrupo(Request $request, int $grupo): JsonResponse
    {
        $g = \App\Models\CentralIaGrupo::where('user_id', $request->user()->id)->findOrFail($grupo);
        $dados = $request->validate(['nome' => ['required', 'string', 'max:60',
            Rule::unique('central_ia_grupos', 'nome')->where('user_id', $request->user()->id)->ignore($g->id)]]);
        $g->update(['nome' => trim($dados['nome'])]);

        return $this->semCache(['id' => $g->id, 'nome' => $g->nome]);
    }

    /** Apagar o grupo não apaga projeto: eles ficam "sem grupo" (nullOnDelete). */
    public function apagarGrupo(Request $request, int $grupo): JsonResponse
    {
        \App\Models\CentralIaGrupo::where('user_id', $request->user()->id)->findOrFail($grupo)->delete();

        return $this->semCache(['ok' => true]);
    }

    /**
     * Põe o projeto num grupo (ou tira). Pôr num grupo também o fixa: estar
     * num grupo é querer vê-lo sempre.
     */
    public function grupoDoProjeto(Request $request, string $projeto): JsonResponse
    {
        $user = $request->user();
        $dados = $request->validate(['grupo_id' => ['nullable', 'integer']]);
        $grupoId = $dados['grupo_id'] ?? null;
        abort_if($grupoId && ! \App\Models\CentralIaGrupo::where('user_id', $user->id)->whereKey($grupoId)->exists(), 404);
        abort_unless(in_array($projeto, $this->agentes->disponiveis($user)['projetos'], true)
            || CentralIaTarefa::de($user)->where('projeto', $projeto)->exists(), 404);

        CentralIaProjeto::updateOrCreate(['user_id' => $user->id, 'projeto' => $projeto], ['grupo_id' => $grupoId]);

        return $this->semCache(['projeto' => $projeto, 'grupo_id' => $grupoId]);
    }

    /**
     * Fixa ou solta um projeto da barra. Só projetos que a máquina informou,
     * ou que já aparecem por ter tarefa.
     */
    public function fixar(Request $request, string $projeto): JsonResponse
    {
        $user = $request->user();
        $conhecido = in_array($projeto, $this->agentes->disponiveis($user)['projetos'], true)
            || CentralIaTarefa::de($user)->where('projeto', $projeto)->exists();
        abort_unless($conhecido, 404);

        $existente = CentralIaProjeto::where('user_id', $user->id)->where('projeto', $projeto)->first();
        $existente
            ? $existente->delete()
            : CentralIaProjeto::create(['user_id' => $user->id, 'projeto' => $projeto,
                'ordem' => (int) CentralIaProjeto::where('user_id', $user->id)->max('ordem') + 1]);

        return $this->semCache(['fixado' => ! $existente]);
    }

    /**
     * A pessoa encerra, pausa ou reabre a tarefa. Com execução na fila ou
     * rodando, não encerra: primeiro termina o que está em curso.
     */
    public function statusDaTarefa(Request $request, int $tarefa): JsonResponse
    {
        $dados = $request->validate(['status' => ['required', Rule::in([CentralIaTarefa::CONCLUIDA, CentralIaTarefa::PAUSADA, CentralIaTarefa::ABERTA])]]);
        $t = CentralIaTarefa::de($request->user())->findOrFail($tarefa);

        $ocupada = $t->execucoes()->whereIn('status', [CentralIaExecucao::PENDENTE, CentralIaExecucao::EXECUTANDO])->exists();
        abort_if($ocupada && $dados['status'] !== CentralIaTarefa::ABERTA, 422, 'Há pedido desta tarefa na fila ou rodando.');

        $t->update(['status' => $dados['status'], 'ultima_atividade_em' => now()]);

        return $this->semCache(['status' => $t->status]);
    }

    /** Salva um ponto na tarefa: o agente resume, e a conversa recomeça enxuta. */
    public function salvarPonto(Request $request, int $tarefa): JsonResponse
    {
        abort_unless($request->user()->canPermissao('central_ia_agentes', 'incluir'), 403, 'Sua conta não pode acionar agentes.');
        $t = CentralIaTarefa::de($request->user())->findOrFail($tarefa);
        $execucao = $this->agentes->salvarPonto($request->user(), $t, CentralIaMensagem::ORIGEM_WEB);

        return $this->semCache(['id' => $execucao->id], 201);
    }

    public function anexar(Request $request): JsonResponse
    {
        app(\App\Services\CentralIa\CanalAtivo::class)->exigir($request->user(), 'web');
        $dados = $request->validate(['arquivo' => ['required', 'file', 'max:20480'], 'client_id' => ['required', 'uuid']]);
        $anexo = app(\App\Services\CentralIa\AnexosService::class)->guardar($request->user(), $dados['arquivo'], $dados['client_id']);
        return $this->semCache($anexo->publico(), $anexo->wasRecentlyCreated ? 201 : 200);
    }

    public function baixarAnexo(Request $request, string $anexo): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        return app(\App\Services\CentralIa\AnexosService::class)->baixar($request->user(), $anexo);
    }

    public function codigoTelegram(Request $request): JsonResponse
    {
        $codigo = $this->telegram->gerarCodigo($request->user());

        return $this->semCache([
            'link' => 'https://t.me/'.config('central_ia.telegram.bot').'?start='.$codigo,
            'comando' => '/start '.$codigo,
            'minutos' => config('central_ia.telegram.codigo_minutos'),
        ]);
    }

    public function desvincularTelegram(Request $request): JsonResponse
    {
        $this->telegram->desvincular($request->user());

        return $this->semCache(['ok' => true]);
    }

    /**
     * Só o que a tela mostra. `user_id`, `conversa_id` e o id do Telegram
     * ficam no servidor.
     */
    private function publica(CentralIaMensagem $m): array
    {
        return [
            'id' => $m->id,
            'client_id' => $m->client_id,
            'tarefa_id' => $m->tarefa_id,
            'autor_tipo' => $m->autor_tipo,
            'autor_nome' => $m->autor_nome,
            'origem' => $m->origem,
            'conteudo' => $m->conteudo,
            'anexos' => $m->anexos->map(fn ($a) => $a->publico())->all(),
            'criada_em' => $m->created_at->toIso8601String(),
            'entrega' => $m->entrega?->status,
            'pedido' => $this->pedido($m),
        ];
    }

    private function pedido(CentralIaMensagem $m): ?array
    {
        $e = $m->execucao;

        return $e ? [
            'agente' => $e->nomeDoAgente(), 'projeto' => $e->projeto, 'modo' => $e->modo, 'status' => $e->status,
            'motivo' => $e->roteamento['motivo'] ?? null,
            'handoff_de' => isset($e->roteamento['handoff_de']) ? (CentralIaExecucao::AGENTES[$e->roteamento['handoff_de']] ?? null) : null,
        ] : null;
    }

    /**
     * Conversa privada não vai para cache de navegador nem de proxy.
     */
    private function semCache(array $dados, int $status = 200): JsonResponse
    {
        return response()->json($dados, $status)->header('Cache-Control', 'no-store, private');
    }
}
