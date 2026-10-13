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

    public function preferencias(Request $request): JsonResponse
    {
        $servico = app(\App\Services\CentralIa\Preferencias::class);
        abort_unless($servico->pronta(), 503, 'A migração local dos Ajustes ainda não foi aplicada.');
        $categorias = implode(',', array_keys($servico->ler($request->user())['padroes']));
        $dados = $request->validate([
            'user_id' => ['prohibited'],
            'revisao_config' => ['required', 'integer', 'min:0'],
            'padroes' => ['required', 'array:'.$categorias],
            'padroes.*' => ['required', Rule::in(array_keys(CentralIaExecucao::AGENTES))],
            'habilitados' => ['required', 'array:claude,codex,copilot'],
            'habilitados.*' => ['required', 'boolean'],
            'pedidos_dia' => ['required', 'array:claude,codex,copilot'],
            'pedidos_dia.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limite_total' => ['nullable', 'integer', 'min:0', 'max:30000'],
            'paralelo' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) config('central_ia.agentes.paralelo'))],
            'fallback' => ['required', 'boolean'],
            'escalar' => ['sometimes', 'boolean'],
            // Modelo e esforço por nível e por IA (nulo = padrão da CLI). O
            // nome do modelo vira argumento de linha de comando no runner:
            // só letras, números, ponto, hífen e sublinhado.
            'niveis' => ['sometimes', 'array:leve,medio,forte'],
            'niveis.*' => ['array:claude,codex,copilot'],
            'niveis.*.*' => ['array:modelo,esforco'],
            'niveis.*.*.modelo' => ['nullable', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'niveis.*.*.esforco' => ['nullable', Rule::in(\App\Services\CentralIa\Preferencias::ESFORCOS)],
            'coordenador_codex' => ['sometimes', 'boolean'],
        ]);
        foreach (array_keys($servico->ler($request->user())['padroes']) as $c) abort_unless(array_key_exists($c, $dados['padroes']), 422);
        foreach (array_keys(CentralIaExecucao::AGENTES) as $a) {
            abort_unless(array_key_exists($a, $dados['habilitados']) && array_key_exists($a, $dados['pedidos_dia']), 422);
        }
        $regras = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $dados, $servico) {
            \App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($servico->ler($request->user())['revisao_config'] === $dados['revisao_config'], 409, 'Ajustes alterados em outra janela. Recarregue antes de salvar.');
            $dados['revisao_config']++;
            // O dono da conta não pode remover uma restrição administrativa
            // omitindo-a no PUT ou enviando uma versão adulterada dela.
            if ($g = app(\App\Services\CentralIa\Governanca::class)->politica($request->user())) $dados['governanca'] = $g;
            \App\Models\CentralIaPreferencia::updateOrCreate(['user_id' => $request->user()->id], ['regras' => $dados]);
            \App\Services\CentralIa\Eventos::registrar($request->user(), 'ajustes', 'Preferências individuais de roteamento e limites atualizadas.');
            return $servico->ler($request->user());
        });
        return $this->semCache(['regras' => $regras]);
    }

    /** Arquivar oculta a lista e suspende a fila; não apaga histórico nem interrompe agentes. */
    public function arquivarChats(Request $request): JsonResponse
    {
        $dados = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:300'], 'ids.*' => ['integer', 'min:1', 'distinct']]);
        $resultado = \Illuminate\Support\Facades\DB::transaction(function () use ($request, $dados) {
            \App\Models\User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $tarefas = CentralIaTarefa::de($request->user())->whereIn('id', $dados['ids'])->lockForUpdate()->get();
            abort_unless($tarefas->count() === count($dados['ids']), 404);
            $arquivadas = []; $rodando = [];
            foreach ($tarefas as $t) {
                if ($t->execucoes()->where('status', CentralIaExecucao::EXECUTANDO)->exists()) { $rodando[] = $t->id; continue; }
                $t->update(['status' => CentralIaTarefa::ARQUIVADA]);
                $arquivadas[] = $t->id;
            }
            \App\Services\CentralIa\Eventos::registrar($request->user(), 'chats_arquivados', count($arquivadas).' chats arquivados sem excluir histórico.');
            return ['arquivadas' => $arquivadas, 'rodando' => $rodando];
        });
        return $this->semCache($resultado);
    }

    public function relatorio(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'inicio' => ['required', 'date_format:Y-m-d'],
            'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'usuario' => ['nullable', 'integer', 'min:1'],
            'agente' => ['nullable', Rule::in(array_keys(CentralIaExecucao::AGENTES))],
        ]);
        $inicio = \Illuminate\Support\Carbon::parse($dados['inicio'])->startOfDay();
        $fim = \Illuminate\Support\Carbon::parse($dados['fim'])->endOfDay();
        abort_if($inicio->diffInDays($fim) > 366, 422, 'Selecione até um ano.');
        $user = $request->user();
        if (!empty($dados['usuario']) && (int) $dados['usuario'] !== $user->id) {
            abort_unless(app(\App\Services\CentralIa\Governanca::class)->administra($user), 403);
            $user = \App\Models\User::findOrFail($dados['usuario']);
            // Ninguém vê o relatório do dono da Central.
            abort_if($user->ehDonoDaCentral(), 404);
        }
        $privado = $user->id === $request->user()->id;
        $agente = $dados['agente'] ?? null;
        $execucoes = CentralIaExecucao::where('user_id', $user->id)->whereBetween('created_at', [$inicio, $fim])
            ->when($agente, fn ($q) => $q->where('agente', $agente))->latest('id')->limit(1000)->get()
            ->map(fn ($e) => ['id' => $e->id, 'agente' => $e->nomeDoAgente(), 'modo' => $e->modo,
                'status' => $e->status, 'criada_em' => $e->created_at->toIso8601String(),
                'projeto' => $privado ? $e->projeto : null])->all();
        $eventos = \Illuminate\Support\Facades\DB::table('central_ia_eventos')->where('user_id', $user->id)
            ->whereBetween('created_at', [$inicio, $fim])->when($agente, fn ($q) => $q->where('agente', $agente))
            ->latest('id')->limit(1000)->get()->map(fn ($e) => ['id' => $e->id, 'tipo' => $e->tipo, 'nivel' => $e->nivel,
                'em' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String(),
                'descricao' => $privado ? $e->descricao : '', 'projeto' => $privado ? $e->projeto : null,
                'agente' => $e->agente, 'origem' => $privado ? $e->origem : null])->all();
        $linhas = \App\Models\CentralIaRunner::where('user_id', $user->id)->get()->pluck('consumo')->filter()->pluck('linhas')->flatten(1)
            ->filter(fn ($l) => ($l['dia'] ?? '') >= $dados['inicio'] && ($l['dia'] ?? '') <= $dados['fim'] && (!$agente || ($l['agente'] ?? '') === $agente));
        $consumo = $linhas->groupBy(fn ($l) => $l['dia'].'|'.$l['agente'])->map(fn ($g) => [
            'dia' => $g->first()['dia'], 'agente' => $g->first()['agente'],
            'entrada' => $g->sum('entrada'), 'saida' => $g->sum('saida'),
            'cache' => $g->sum('cache_leitura') + $g->sum('cache_escrita'), 'premium' => $g->sum('premium'),
        ])->sortByDesc('dia')->values()->all();
        return $this->semCache(['eventos' => $eventos, 'execucoes' => $execucoes, 'consumo' => $consumo,
            'truncado' => count($eventos) === 1000 || count($execucoes) === 1000]);
    }

    /** POP escrito ou corrigido pela pessoa. Só da própria conta. */
    public function salvarPop(Request $request, ?int $pop = null): JsonResponse
    {
        $dados = $request->validate([
            'sistema' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/'],
            'titulo' => ['required', 'string', 'max:160'],
            'conteudo' => ['required', 'string', 'max:60000'],
        ]);
        $pops = app(\App\Services\CentralIa\Pops::class);
        if ($pop) {
            $atual = \App\Models\CentralIaPop::where('user_id', $request->user()->id)->findOrFail($pop);
            // Mudou sistema ou título: é o mesmo POP com outro nome.
            if ($atual->sistema !== \Illuminate\Support\Str::lower($dados['sistema']) || $atual->titulo !== $dados['titulo']) {
                $atual->update(['sistema' => \Illuminate\Support\Str::lower($dados['sistema']), 'titulo' => $dados['titulo']]);
            }
        }
        $salvo = $pops->gravar($request->user(), $dados['sistema'], $dados['titulo'], $dados['conteudo'], $request->user()->name);

        return $this->semCache(['id' => $salvo->id, 'versao' => $salvo->versao]);
    }

    public function apagarPop(Request $request, int $pop): JsonResponse
    {
        $p = \App\Models\CentralIaPop::where('user_id', $request->user()->id)->findOrFail($pop);
        \App\Services\CentralIa\Eventos::registrar($request->user(), 'pop_apagado', "POP de {$p->sistema} \"{$p->titulo}\" apagado", ['origem' => 'web', 'projeto' => $p->sistema]);
        $p->delete();

        return $this->semCache(['ok' => true]);
    }

    public function confirmar(Request $request, int $execucao): JsonResponse
    {
        $dados = $request->validate(['codigo' => ['required', 'string', 'regex:/^\d{6}$/']]);
        app(\App\Services\CentralIa\AgentesService::class)->confirmar($request->user(), $execucao, $dados['codigo'], 'web');

        return $this->semCache(['ok' => true]);
    }

    public function administracao(Request $request): JsonResponse
    {
        $g = app(\App\Services\CentralIa\Governanca::class);
        abort_unless($g->administra($request->user()), 403);
        $dados = $request->validate(['usuario' => ['required', 'integer', 'min:1']]);
        $alvo = \App\Models\User::findOrFail($dados['usuario']);
        return $this->semCache($g->resumo($request->user(), $alvo));
    }

    public function politicaUsuario(Request $request, int $usuario): JsonResponse
    {
        $g = app(\App\Services\CentralIa\Governanca::class);
        abort_unless($g->administra($request->user()), 403);
        abort_if($usuario === $request->user()->id, 422, 'Use seus próprios Ajustes para esta conta.');
        abort_unless(app(\App\Services\CentralIa\Preferencias::class)->pronta(), 503);
        $alvo = \App\Models\User::findOrFail($usuario);
        abort_if($alvo->ehDonoDaCentral(), 404);
        $dados = $request->validate([
            'revisao' => ['required', 'integer', 'min:0'],
            'habilitados' => ['required', 'array:claude,codex,copilot'], 'habilitados.*' => ['required', 'boolean'],
            'pedidos_dia' => ['required', 'array:claude,codex,copilot'], 'pedidos_dia.*' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limite_total' => ['nullable', 'integer', 'min:0', 'max:30000'],
            'paralelo' => ['required', 'integer', 'min:1', 'max:'.max(1, (int) config('central_ia.agentes.paralelo'))],
            // Liberar a execução é decisão do dono da Central; deploy e
            // OneDrive continuam fora do alcance da tela.
            'execucao_bloqueada' => ['sometimes', 'boolean'], 'deploy' => ['prohibited'], 'onedrive' => ['prohibited'],
        ]);
        foreach (array_keys(CentralIaExecucao::AGENTES) as $a) abort_unless(isset($dados['habilitados'][$a]) && array_key_exists($a, $dados['pedidos_dia']), 422);
        \Illuminate\Support\Facades\DB::transaction(function () use ($alvo, $dados, $g, $request) {
            \App\Models\User::whereKey($alvo->id)->lockForUpdate()->firstOrFail();
            $politica = $g->politica($alvo) ?? $g->padrao();
            abort_unless($politica['revisao'] === $dados['revisao'], 409, 'Política alterada em outra janela. Recarregue.');
            $dados['revisao']++;
            $dados += ['execucao_bloqueada' => true];
            $dados += ['deploy' => false, 'onedrive' => false];
            $registro = \App\Models\CentralIaPreferencia::where('user_id', $alvo->id)->first();
            $regras = $registro?->regras ?? app(\App\Services\CentralIa\Preferencias::class)->ler($alvo);
            $regras['governanca'] = $dados; $regras['revisao_config']++;
            \App\Models\CentralIaPreferencia::updateOrCreate(['user_id' => $alvo->id], ['regras' => $regras]);
            \App\Services\CentralIa\Eventos::registrar($request->user(), 'politica_usuario', 'Política administrativa atualizada para conta #'.$alvo->id.($dados['execucao_bloqueada'] ? '; execução bloqueada.' : '; execução liberada pelo dono.'));
        });
        return $this->semCache($g->resumo($request->user(), $alvo));
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
            'pops' => app(\App\Services\CentralIa\Pops::class)->lista($request->user()),
            // Pedidos parados esperando o código que a máquina mandou ao Telegram.
            'confirmacoes' => app(\App\Services\CentralIa\AgentesService::class)->aguardandoConfirmacao($request->user())
                ->map(fn ($e) => ['id' => $e->id, 'agente' => $e->nomeDoAgente(), 'projeto' => $e->projeto, 'modo' => $e->modo,
                    'tarefa_id' => $e->tarefa_id, 'resumo' => $e->confirmacao['resumo'] ?? null, 'tentativas' => $e->confirmacao['tentativas'] ?? 0,
                    'expira_em' => $e->confirmacao['expira_em'] ?? null])->all(),
            'preferencias' => ['disponivel' => app(\App\Services\CentralIa\Preferencias::class)->pronta(),
                'regras' => app(\App\Services\CentralIa\Preferencias::class)->ler($request->user())],
            'administracao' => ['permitida' => app(\App\Services\CentralIa\Governanca::class)->administra($request->user()),
                'usuarios' => app(\App\Services\CentralIa\Governanca::class)->usuarios($request->user())],
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
            // Nível de custo escolhido na tela; `auto` (ou ausente) = o roteador decide.
            'nivel' => ['nullable', Rule::in(['auto', ...\App\Services\CentralIa\Preferencias::NIVEIS])],
        ]);

        $dados['conteudo'] = $dados['conteudo'] ?? 'Analise os arquivos anexados.';

        // Mesma defesa no servidor: 6 dígitos com pedido esperando código são a
        // confirmação, e não uma mensagem (o código não entra na conversa).
        if (empty($dados['anexos']) && preg_match('/^\s*(\d{6})\s*$/', $dados['conteudo'], $c)) {
            $esperando = $this->agentes->aguardandoConfirmacao($request->user());
            $alvo = $esperando->first(fn ($e) => $e->tarefa_id && (int) $e->tarefa_id === (int) ($dados['tarefa_id'] ?? 0))
                ?? ($esperando->count() === 1 ? $esperando->first() : null);
            if ($alvo) {
                $this->agentes->confirmar($request->user(), $alvo->id, $c[1], 'web');

                return $this->semCache(['confirmacao' => $alvo->id]);
            }
        }

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
            $pedido['nivel'] = $dados['nivel'] ?? 'auto';
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
            'conteudo' => \App\Services\CentralIa\ProtecaoDeSegredos::texto($m->conteudo),
            'visual' => $m->autor_tipo === CentralIaMensagem::AUTOR_AGENTE ? \App\Services\CentralIa\RespostaVisual::apresentar($m->conteudo) : null,
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
