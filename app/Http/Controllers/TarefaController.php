<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class TarefaController extends Controller
{
    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // O quadro é o trabalho EM CURSO: concluída e cancelada não têm coluna
        // (AC-082, AC-096). Sete colunas não cabiam na tela e as duas terminais
        // eram as de menor valor no dia a dia — encerrou, sai do quadro e passa
        // a viver no histórico (`historico()`), de onde também se reabre.
        // Isso aposenta o antigo recorte de 30 dias: não há mais o que recortar.
        $emCurso = collect(Tarefa::STATUS)->reject(
            fn ($label, $status) => in_array($status, Tarefa::STATUS_TERMINAIS, true)
        );

        $filtros = $this->filtros($request);

        // `eventos` entra no eager load porque o card lê a etapa atual de cada
        // tarefa para o chip de tempo — sem isso é uma consulta por card.
        // `comentarios.autor` pelo mesmo motivo: o quadro já monta o modal de
        // cada card, e a conversa inteira é impressa dentro dele.
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos', 'comentarios.autor'])
            ->whereIn('status', $emCurso->keys())
            ->tap(fn ($q) => $this->aplicarFiltros($q, $filtros))
            ->orderByDesc('created_at')
            ->get();

        $colunas = $emCurso->mapWithKeys(fn ($label, $status) => [
            $status => $this->ordenarColuna($tarefas->where('status', $status)->values()),
        ]);

        // A contagem da coluna é a do RECORTE, não a do quadro inteiro: com
        // filtro ligado, um selo dizendo 12 sobre três cards visíveis mediria
        // outra coisa que não o que está na tela.
        $etapas = $emCurso->map(fn ($label, $status) => [
            'chave' => $status,
            'label' => $label,
            'cor' => $this->corDaEtapa($status),
            'quantidade' => $colunas[$status]->count(),
        ])->values()->all();

        // Quantas tarefas o quadro teria sem filtro nenhum: é o denominador do
        // "X de Y" do cabeçalho, o aviso de que há trabalho fora do recorte.
        $totalNoQuadro = Tarefa::whereIn('status', $emCurso->keys())->count();

        return view('tarefas.index', compact(
            'tarefas', 'colunas', 'etapas', 'filtros', 'totalNoQuadro',
        ) + $this->listasDeFiltro());
    }

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            'prioridade' => 'required|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
        ]);

        $data['criado_por_id'] = auth()->id();

        Tarefa::create($data);

        return redirect()->route('tarefas.index')->with('status', 'Tarefa criada.');
    }

    public function update(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            'prioridade' => 'required|in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
        ]);

        $tarefa->update($data);

        return redirect()->route('tarefas.index')->with('status', 'Tarefa atualizada.');
    }

    public function mover(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'status' => 'required|in:'.implode(',', array_keys(Tarefa::STATUS)),
            'motivo' => 'nullable|string',
            'relatorio_aprovado' => 'nullable|boolean',
            'relatorio_notas' => 'nullable|string',
        ]);

        // A confirmação de "Em testes → Concluída" pede as notas do teste no
        // próprio movimento (ASM-033): registra o relatório antes de checar a
        // transição, para que um relatório aprovado agora já libere a mesma
        // conclusão.
        if ($data['status'] === 'concluida' && $request->filled('relatorio_notas')) {
            TarefaRelatorioTeste::create([
                'tarefa_id' => $tarefa->id,
                'aprovado' => $request->boolean('relatorio_aprovado'),
                'notas' => $data['relatorio_notas'],
            ]);
        }

        try {
            $fluxo->mover($tarefa, $data['status'], ['motivo' => $data['motivo'] ?? null]);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        // Volta para a tela de onde veio, e não para o quadro cru: com filtro
        // ligado, mover um card devolvia o quadro inteiro e o recorte se
        // perdia a cada arrasto. O mesmo vale para o "Reabrir" do histórico,
        // que agora não abandona a página nem a busca em que se estava.
        return redirect()->back(fallback: route('tarefas.index'))->with('status', 'Tarefa movida.');
    }

    /**
     * Um comentário novo na tarefa (US-041).
     *
     * O texto é gravado CRU, do jeito que foi digitado: os marcadores viram
     * lista só na hora de imprimir (`TarefaComentario::marcadoresEmHtml`).
     * Guardar HTML pronto no banco amarraria a conversa antiga à regra de
     * formatação de hoje — e obrigaria a confiar no que já está gravado.
     */
    public function comentar(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'corpo' => 'required|string|max:4000',
        ]);

        $tarefa->comentarios()->create([
            'autor_id' => auth()->id(),
            'corpo' => $data['corpo'],
        ]);

        // Volta para a tela de onde veio, como o mover: comentar a partir do
        // quadro filtrado não pode desfazer o recorte de quem estava lendo.
        // E volta com o modal da tarefa ABERTO (`tarefa-aberta`): conversa em
        // que cada frase fecha a janela não é conversa — quem escreveu quer
        // ver o que escreveu, e normalmente escrever de novo.
        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', 'Comentário adicionado.')
            ->with('tarefa-aberta', $tarefa->id);
    }

    /**
     * Apaga um comentário — só o próprio.
     *
     * Errar o comentário é o erro mais barato de cometer e, sem esta porta, o
     * mais caro de conviver: fica na tarefa para sempre. Mas apagar o
     * comentário alheio seria reescrever a conversa de outra pessoa, então a
     * regra é estreita de propósito, e vale mesmo para quem administra.
     */
    public function excluirComentario(TarefaComentario $comentario)
    {
        $this->bloquearVisaoDaMatriz();

        abort_unless($comentario->autor_id === auth()->id(), 403, 'Só o autor apaga o próprio comentário.');

        $tarefaId = $comentario->tarefa_id;

        $comentario->delete();

        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', 'Comentário removido.')
            ->with('tarefa-aberta', $tarefaId);
    }

    public function historico(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $filtros = $this->filtros($request);

        // Sem recorte de período (AC-097): é o caminho de auditoria para o
        // que o quadro enxuto (`index()`) já tirou de vista.
        // `eventos` para a duração do ciclo de cada linha (AC-133).
        //
        // Paginar não recorta nada — a lista continua inteira, só chega em
        // páginas. É a tabela que mais cresce (nada nunca sai dela) e era a
        // única que carregava o histórico completo, com os eventos de cada
        // tarefa, numa resposta só.
        //
        // `withQueryString` porque a busca só serve se sobreviver ao clique em
        // "próxima": sem isso, a página 2 volta a ser o histórico inteiro.
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos', 'comentarios.autor'])
            ->whereIn('status', $filtros['desfecho'] !== '' ? [$filtros['desfecho']] : Tarefa::STATUS_TERMINAIS)
            ->tap(fn ($q) => $this->aplicarFiltros($q, $filtros))
            ->orderByDesc('updated_at')
            ->paginate(self::POR_PAGINA)
            ->withQueryString();

        // Denominador do "X de Y" do cabeçalho, como no quadro: o histórico
        // inteiro, para o recorte se anunciar como recorte.
        $totalNoHistorico = Tarefa::whereIn('status', Tarefa::STATUS_TERMINAIS)->count();

        return view('tarefas.historico', compact(
            'tarefas', 'filtros', 'totalNoHistorico',
        ) + $this->listasDeFiltro());
    }

    /**
     * Recorte pedido na query string, já normalizado.
     *
     * O mesmo recorte serve as duas abas: quem procurou "boleto" no quadro e
     * não achou vai procurar a mesma coisa no histórico, e trocar de aba não
     * deveria obrigar a redigitar. `desfecho` é o único que só o histórico
     * usa — no quadro não há status terminal para escolher.
     *
     * Prioridade e desfecho passam por lista branca: valor inventado na URL
     * vira "sem filtro" em vez de devolver uma tela vazia sem explicação.
     *
     * @return array<string, string>
     */
    private function filtros(Request $request): array
    {
        $prioridade = $this->textoDaQuery($request, 'prioridade');
        $desfecho = $this->textoDaQuery($request, 'desfecho');

        return [
            'busca' => $this->textoDaQuery($request, 'busca'),
            'sistema' => $this->textoDaQuery($request, 'sistema'),
            'responsavel' => $this->textoDaQuery($request, 'responsavel'),
            'prioridade' => array_key_exists($prioridade, Tarefa::PRIORIDADES) ? $prioridade : '',
            'desfecho' => in_array($desfecho, Tarefa::STATUS_TERMINAIS, true) ? $desfecho : '',
        ];
    }

    /**
     * Um campo da query string como texto limpo.
     *
     * `?sistema[]=1` chega como array e o cast direto para string seria um
     * erro fatal — a URL é digitável por qualquer um, então o que não for
     * texto simplesmente não filtra.
     */
    private function textoDaQuery(Request $request, string $campo): string
    {
        $valor = $request->query($campo, '');

        return is_string($valor) ? trim($valor) : '';
    }

    /**
     * Aplica o recorte comum às duas abas.
     *
     * A busca varre título, resumo E detalhes: quem procura uma tarefa pelo
     * número do chamado ou pelo nome do cliente costuma ter escrito isso no
     * corpo, não no título. As três condições vão dentro de um `where`
     * aninhado — soltas, o `orWhere` escaparia do `whereIn` de status e o
     * quadro passaria a mostrar tarefa concluída.
     *
     * "Sem sistema" e "Sem responsável" são filtro de verdade, não enfeite: a
     * coluna Aberta é a fila de triagem, e achar o que ainda não tem dono é
     * exatamente o que se pergunta ali (AC-130).
     *
     * @param  Builder<Tarefa>  $query
     * @param  array<string, string>  $filtros
     */
    private function aplicarFiltros($query, array $filtros): void
    {
        $query
            ->when($filtros['busca'] !== '', fn ($q) => $q->where(fn ($sub) => $sub
                ->where('titulo', 'like', '%'.$filtros['busca'].'%')
                ->orWhere('resumo', 'like', '%'.$filtros['busca'].'%')
                ->orWhere('detalhes', 'like', '%'.$filtros['busca'].'%')))
            ->when($filtros['sistema'] === 'sem', fn ($q) => $q->whereNull('sistema_id'))
            ->when($filtros['sistema'] !== '' && $filtros['sistema'] !== 'sem',
                fn ($q) => $q->where('sistema_id', $filtros['sistema']))
            ->when($filtros['responsavel'] === 'sem', fn ($q) => $q->whereNull('responsavel_id'))
            ->when($filtros['responsavel'] !== '' && $filtros['responsavel'] !== 'sem',
                fn ($q) => $q->where('responsavel_id', $filtros['responsavel']))
            ->when($filtros['prioridade'] !== '', fn ($q) => $q->where('prioridade', $filtros['prioridade']));
    }

    /**
     * As listas que abastecem os selects — de filtro e de cadastro.
     *
     * Sistemas só os ativos, como no formulário; usuários sem escopo de
     * revenda, que são os que podem responder por tarefa da matriz.
     *
     * @return array<string, Collection<int, mixed>>
     */
    private function listasDeFiltro(): array
    {
        return [
            'sistemas' => Sistema::where('ativo', true)->orderBy('nome')->get(),
            'usuarios' => User::whereNull('revenda_id')->orderBy('name')->get(),
        ];
    }

    /**
     * Ordem dos cards dentro de uma coluna: gravidade primeiro, e no empate a
     * tarefa mais parada na etapa (AC-128).
     *
     * Antes a ordem era só `created_at desc`, o que fazia uma crítica antiga
     * afundar embaixo de tarefas baixas recentes — a prioridade ficava
     * legível no selo e sem efeito nenhum na leitura da coluna.
     *
     * O desempate usa a entrada na etapa ATUAL, o mesmo instante que o card
     * mostra no chip de tempo: se a ordem seguisse outro critério, a coluna
     * pareceria embaralhada para quem lê os chips de cima para baixo.
     *
     * @param  Collection<int, Tarefa>  $tarefas
     * @return Collection<int, Tarefa>
     */
    private function ordenarColuna($tarefas)
    {
        $gravidade = array_flip(['critica', 'alta', 'media', 'baixa']);

        // Chave composta em vez de `sortBy([closure, closure])`: essa forma
        // NÃO ordena por múltiplas chaves — ela considera só a última, e a
        // gravidade era silenciosamente ignorada.
        return $tarefas
            ->sortBy(fn (Tarefa $tarefa) => sprintf(
                '%d-%020d',
                $gravidade[$tarefa->prioridade] ?? count($gravidade),
                $this->entrouNaEtapaEm($tarefa)->getTimestamp(),
            ))
            ->values();
    }

    /**
     * Quando a tarefa entrou na etapa em que está: o evento ainda sem saída.
     * Tarefa que nunca se moveu conta a partir da criação — mesmo critério do
     * card (`_card.blade.php`).
     */
    private function entrouNaEtapaEm(Tarefa $tarefa)
    {
        return $tarefa->eventos->firstWhere('saiu_em', null)?->entrou_em ?? $tarefa->created_at;
    }

    /**
     * Token de cor da etapa, pintado na coluna — nunca no card (AC-127).
     *
     * A coluna é o lugar do status porque ela já o nomeia: repetir a cor na
     * borda de cada card diria sete vezes o que o cabeçalho diz uma, e
     * roubaria a borda do card, que é o único canal do aviso de tarefa
     * esquecida (AC-093).
     *
     * A escala segue o Funil de Vendas: entrada em `accent`, o meio do fluxo
     * na marca, o atrito em `warn`, a chegada em `good`. Cancelada fica
     * neutra de propósito — é terminal sem valor e não disputa atenção.
     */
    private function corDaEtapa(string $status): string
    {
        return match ($status) {
            'aberta', 'backlog' => 'accent',
            'em_desenvolvimento', 'em_testes' => 'brand',
            'ajustes_necessarios' => 'warn',
            'concluida' => 'good',
            default => 'line',
        };
    }
}
