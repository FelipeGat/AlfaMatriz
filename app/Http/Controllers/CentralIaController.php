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
        $vinculo = $this->telegram->vinculoDe($request->user());

        return $this->semCache($this->cockpit->montar($request->user()) + [
            'telegram' => ['vinculado' => (bool) $vinculo, 'usuario' => $vinculo?->telegram_usuario],
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
        $mensagens->load('entrega', 'execucao');

        // Entrega e execução mudam DEPOIS de a mensagem já estar na tela: o
        // poll pede só o que veio depois da última, então as recentes voltam
        // aqui com o estado atual, para "entregue" e "concluído" aparecerem.
        $recentes = CentralIaMensagem::de($request->user())
            ->when($projeto === null, fn ($q) => $q->whereNull('projeto'), fn ($q) => $q->where('projeto', $projeto))
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

    public function enviar(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'conteudo' => ['required', 'string', 'max:'.config('central_ia.max_texto')],
            'client_id' => ['required', 'uuid'],
            'projeto' => ['nullable', 'string', 'max:128'],
            'tarefa_id' => ['nullable', 'integer'],
            // Nulo = só registrar na conversa; `auto` = o roteador escolhe.
            'agente' => ['nullable', 'string'],
            'modo' => ['nullable', 'string'],
        ]);

        // O atalho digitado (`/claude projeto …`) vale em qualquer conversa.
        $pedido = $this->agentes->interpretar($dados['conteudo']);

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

        $mensagem = $this->conversas->registrarDoUsuario(
            $request->user(), $dados['conteudo'], CentralIaMensagem::ORIGEM_WEB, $dados['client_id'],
            projeto: $dados['projeto'] ?? null, tarefaId: $tarefaId,
        );

        return $this->semCache(['id' => $mensagem->id], $mensagem->wasRecentlyCreated ? 201 : 200);
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
