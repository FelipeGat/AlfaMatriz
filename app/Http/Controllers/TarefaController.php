<?php

namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\TarefaItem;
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
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos', 'comentarios.autor', 'itens'])
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
        $etapas = $emCurso->map(function ($label, $status) use ($colunas) {
            $daEtapa = $colunas[$status];

            // O WIP conta só o que ANDA: vaga ocupada por tarefa travada não é
            // trabalho em curso, e somá-la faria o limite acusar excesso
            // justamente quando o time está impedido de produzir.
            $andando = $daEtapa->reject->estaBloqueada()->count();
            $limite = Tarefa::LIMITE_DE_WIP[$status] ?? null;

            return [
                'chave' => $status,
                'label' => $label,
                'cor' => $this->corDaEtapa($status),
                'quantidade' => $daEtapa->count(),
                'andando' => $andando,
                'limite' => $limite,
                'acimaDoLimite' => $limite !== null && $andando > $limite,
                // Quantas ainda não foram triadas. A coluna Aberta é a fila de
                // triagem, mas o número aparece onde houver: tarefa sem
                // prioridade no meio do fluxo é a que ninguém vai priorizar.
                'aguardandoTriagem' => $daEtapa->where('prioridade', 'nao_definida')->count(),
            ];
        })->values()->all();

        // Quantas tarefas o quadro teria sem filtro nenhum: é o denominador do
        // "X de Y" do cabeçalho, o aviso de que há trabalho fora do recorte.
        $totalNoQuadro = Tarefa::whereIn('status', $emCurso->keys())->count();

        // O contador da faixa de bloqueio. Ele mede o RECORTE, como os das
        // colunas: com filtro ligado, um número falando do quadro inteiro
        // apontaria para cards que não estão na tela.
        $totalBloqueadas = $tarefas->filter->estaBloqueada()->count();

        return view('tarefas.index', compact(
            'tarefas', 'colunas', 'etapas', 'filtros', 'totalNoQuadro', 'totalBloqueadas',
        ) + $this->listasDeFiltro());
    }

    public function store(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            // `nullable` e não `required`: o tipo tem padrão no modelo, e um
            // envio sem ele (formulário antigo em cache, integração futura) vale
            // como tarefa de desenvolvimento em vez de virar erro de validação.
            'tipo' => 'nullable|in:'.implode(',', array_keys(Tarefa::TIPOS)),
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            // Obrigatória só para quem tem o campo. Para quem não triaga ele
            // nem aparece no formulário (`_form.blade.php`), e exigi-lo aqui
            // faria o salvar recusar com "prioridade é obrigatória" um campo
            // que a pessoa não tem como preencher — a tela funcionaria e a
            // rota diria não.
            'prioridade' => [
                auth()->user()?->podeTriarTarefas() ? 'required' : 'nullable',
                'in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
            ],
        ]);

        // O padrão é resolvido AQUI, e não só no modelo, por causa da linha
        // abaixo: a busca por reenvio compara o formulário inteiro, e um `tipo`
        // nulo viraria `tipo IS NULL` — que não casa com a linha gravada, onde
        // ele é 'desenvolvimento'. O duplo clique voltaria a criar duas tarefas.
        $data['tipo'] ??= 'desenvolvimento';
        $data['criado_por_id'] = auth()->id();

        $data = $this->semTriagemDeQuemNaoTriaga($data);

        // Mesma rede do comentário (AC-137): aqui o clique duplo custa mais
        // caro, porque a segunda tarefa não é uma linha repetida na conversa —
        // é um card a mais no quadro, que alguém vai ter de cancelar na mão.
        if (! $this->reenvioDaMesmaTarefa($data)) {
            Tarefa::create($data);
        }

        return redirect()->route('tarefas.index')->with('status', 'Tarefa criada.');
    }

    /**
     * Tira do envio o que só a triagem decide.
     *
     * Esconder os campos na tela não é regra — é sugestão: a rota continua
     * aceitando `prioridade` e `responsavel_id` de qualquer envio, e um
     * formulário guardado, um "voltar" do navegador ou um POST à mão passariam
     * por cima da tela. A regra mora aqui.
     *
     * Não é erro de validação, é omissão silenciosa: quem não triaga não
     * mandou esses campos por má-fé, e recusar o cadastro inteiro por causa
     * deles transformaria uma capacidade que a pessoa não tem num obstáculo
     * para o trabalho que ela tem.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function semTriagemDeQuemNaoTriaga(array $dados, ?Tarefa $tarefa = null): array
    {
        if (auth()->user()?->podeTriarTarefas()) {
            return $dados;
        }

        // Na criação, a tarefa nasce sem dono e esperando triagem. "Média" por
        // omissão seria uma classificação que ninguém fez, e é o motivo de
        // "A definir" existir (AC-194).
        $dados['prioridade'] = $tarefa?->prioridade ?? 'nao_definida';
        $dados['responsavel_id'] = $tarefa?->responsavel_id;

        return $dados;
    }

    /**
     * A mesma tarefa, do mesmo autor, criada há instantes.
     *
     * A comparação é o FORMULÁRIO INTEIRO — título, sistema, responsável e
     * prioridade — e não só o título: abrir três tarefas "Renovar certificado"
     * em sistemas diferentes é trabalho legítimo de quem está cadastrando em
     * série, e um segundo envio idêntico em tudo é sempre o duplo clique.
     *
     * @param  array<string, mixed>  $dados
     */
    private function reenvioDaMesmaTarefa(array $dados): bool
    {
        return Tarefa::where($dados)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }

    public function update(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'titulo' => 'required|string|max:255',
            'tipo' => 'nullable|in:'.implode(',', array_keys(Tarefa::TIPOS)),
            'sistema_id' => 'nullable|exists:sistemas,id',
            'responsavel_id' => 'nullable|exists:users,id',
            // Obrigatória só para quem tem o campo. Para quem não triaga ele
            // nem aparece no formulário (`_form.blade.php`), e exigi-lo aqui
            // faria o salvar recusar com "prioridade é obrigatória" um campo
            // que a pessoa não tem como preencher — a tela funcionaria e a
            // rota diria não.
            'prioridade' => [
                auth()->user()?->podeTriarTarefas() ? 'required' : 'nullable',
                'in:'.implode(',', array_keys(Tarefa::PRIORIDADES)),
            ],
            'comentario' => 'nullable|string|max:4000',
        ]);

        // Envio sem o campo mantém o tipo que a tarefa já tem: `null` aqui
        // apagaria a coluna, porque o padrão do modelo só vale na criação.
        $data['tipo'] ??= $tarefa->tipo;

        // Na edição, o que a triagem decidiu fica como está: quem não triaga
        // salvar a tarefa não pode zerar a prioridade nem soltar o responsável
        // de passagem.
        $data = $this->semTriagemDeQuemNaoTriaga($data, $tarefa);

        // O comentário viaja no mesmo envio do cadastro (US-049): um botão só
        // no modal, e nada de decidir entre "Salvar" e "Comentar" para o que,
        // para quem edita, é uma passada só na tarefa. Campo em branco não
        // publica nada — é o caso comum de quem abriu o modal só para trocar o
        // responsável.
        $comentario = trim($data['comentario'] ?? '');
        unset($data['comentario']);

        $tarefa->update($data);

        $etapaNova = $this->seguirOResponsavel($tarefa, $fluxo);

        if ($comentario !== '' && ! $this->reenvioDoMesmoComentario($tarefa, $comentario)) {
            $tarefa->comentarios()->create([
                'autor_id' => auth()->id(),
                'corpo' => $comentario,
            ]);
        }

        $aviso = ['Tarefa atualizada.'];

        if ($etapaNova) {
            $aviso[] = 'Movida para '.Tarefa::STATUS[$etapaNova].'.';
        }

        if ($comentario !== '') {
            $aviso[] = 'Comentário publicado.';
        }

        return redirect()->route('tarefas.index')->with('status', implode(' ', $aviso));
    }

    /**
     * Direcionar move a tarefa; tirar o dono a devolve para a fila.
     *
     * Na criação, escolher responsável já fazia a tarefa nascer no Backlog
     * (`Tarefa::booted`) — mas na edição o mesmo gesto a deixava em Aberta, e
     * quem direcionava tinha de arrastar o card em seguida. Era o mesmo fato
     * com dois comportamentos, e dois passos para uma intenção só.
     *
     * O movimento passa pelo motor do fluxo, e não por um `update` direto, para
     * o cronômetro da etapa continuar honesto: um card que troca de coluna sem
     * evento seria tempo de Aberta contado como tempo de Backlog.
     *
     * Só vale entre Aberta e Backlog. Trocar o responsável de uma tarefa que já
     * está em andamento é trocar quem faz, não recomeçar o fluxo dela.
     */
    private function seguirOResponsavel(Tarefa $tarefa, FluxoTarefaService $fluxo): ?string
    {
        $destino = match (true) {
            $tarefa->status === 'aberta' && $tarefa->responsavel_id !== null => 'backlog',
            $tarefa->status === 'backlog' && $tarefa->responsavel_id === null => 'aberta',
            default => null,
        };

        if ($destino) {
            $fluxo->mover($tarefa, $destino);
        }

        return $destino;
    }

    /**
     * O mesmo comentário, do mesmo autor, publicado há instantes.
     *
     * Salvar é um clique só na intenção, mas o clique duplo manda dois envios
     * — e o cadastro aguenta ser regravado igual, enquanto o comentário é
     * linha nova a cada vez. O botão se tranca no primeiro envio
     * (`_form.blade.php`), e isto é a rede embaixo dele: vale quando o JS não
     * roda e quando o "voltar" do navegador reenvia o formulário.
     *
     * A janela é curta de propósito. Ela existe para desfazer um acidente de
     * um segundo, não para proibir alguém de repetir a mesma frase mais tarde
     * — e repetir a mesma frase no MESMO minuto é sempre o acidente.
     */
    private function reenvioDoMesmoComentario(Tarefa $tarefa, string $corpo): bool
    {
        return $tarefa->comentarios()
            ->where('autor_id', auth()->id())
            ->where('corpo', $corpo)
            ->where('created_at', '>=', now()->subMinute())
            ->exists();
    }

    public function mover(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        // Autorização, e não regra de fluxo: o `FluxoTarefaService` responde
        // por onde a tarefa PODE andar, e não tem — nem deve ter — noção de
        // quem está pedindo. A recusa sai com o motivo dito, porque "não
        // permitido" sem dizer de quem é a tarefa manda a pessoa adivinhar.
        if ($impedimento = $tarefa->motivoParaNaoMover(auth()->user())) {
            return back()->with('erro', $impedimento);
        }

        // As notas seguem opcionais AQUI de propósito, mesmo depois de o
        // `required` do textarea (`_mover.blade.php`) se revelar a única trava
        // de verdade: quem manda uma conclusão sem elas não passa mais, mas é o
        // motor do fluxo que recusa, e com a frase que explica o porquê. Um
        // `required` nesta lista responderia "o campo notas é obrigatório" a
        // quem tentou concluir uma tarefa que sequer chegou em Em testes.
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
     * Trava a tarefa sem tirá-la da etapa, ou destrava.
     *
     * Uma rota só para os dois sentidos porque, para quem usa, é um botão só
     * que alterna — e dois caminhos separados abririam a chance de destravar o
     * que já está solto, ou travar duas vezes, sem nada a ganhar.
     */
    public function bloquear(Request $request, Tarefa $tarefa, FluxoTarefaService $fluxo)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'motivo' => 'nullable|string|max:2000',
        ]);

        try {
            $tarefa->estaBloqueada()
                ? $fluxo->destravar($tarefa)
                : $fluxo->bloquear($tarefa, $data['motivo'] ?? null);
        } catch (\RuntimeException $e) {
            return back()->with('erro', $e->getMessage());
        }

        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', $tarefa->fresh()->estaBloqueada() ? 'Tarefa bloqueada.' : 'Tarefa destravada.');
    }

    /** Acrescenta um item ao checklist, no fim da lista. */
    public function criarItem(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate(['texto' => 'required|string|max:255']);

        $tarefa->itens()->create(['texto' => trim($data['texto'])]);

        return $this->voltarParaATarefa($tarefa->id);
    }

    /**
     * Marca, desmarca ou corrige o texto de um item.
     *
     * Uma rota para as duas coisas porque, no checklist, elas são o mesmo
     * gesto de "mexer neste item" — e separá-las criaria duas rotas que fazem
     * `update` na mesma linha, com a mesma autorização e o mesmo retorno.
     *
     * Diferente do comentário, o item NÃO é do autor: checklist é combinado do
     * time, e quem confere um passo raramente é quem o escreveu.
     */
    public function atualizarItem(Request $request, TarefaItem $item)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'texto' => 'nullable|string|max:255',
            'feito' => 'nullable|boolean',
        ]);

        $mudancas = [];

        // Texto em branco não apaga o item: quem quis remover tem o botão de
        // remover, e um item sem texto seria uma linha que ninguém sabe ler.
        if (filled($data['texto'] ?? null)) {
            $mudancas['texto'] = trim($data['texto']);
        }

        if ($request->has('feito')) {
            $mudancas['feito'] = $request->boolean('feito');
        }

        if ($mudancas !== []) {
            $item->update($mudancas);
        }

        return $this->voltarParaATarefa($item->tarefa_id);
    }

    public function excluirItem(TarefaItem $item)
    {
        $this->bloquearVisaoDaMatriz();

        $tarefaId = $item->tarefa_id;

        $item->delete();

        return $this->voltarParaATarefa($tarefaId);
    }

    /**
     * Regrava a ordem do checklist a partir da sequência recebida.
     *
     * A ordem chega inteira, e não como "mova o item X para a posição N":
     * arrastar reordena a lista toda na tela, e mandar só o movimento obrigaria
     * o servidor a recalcular o que o navegador já sabe — divergindo na
     * primeira vez que dois arrastos chegassem fora de ordem.
     *
     * `whereIn` amarrado à tarefa: sem isso, um id de outra tarefa na lista
     * reordenaria checklist alheio.
     */
    public function ordenarItens(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $data = $request->validate([
            'ordem' => 'required|array',
            'ordem.*' => 'integer',
        ]);

        $daTarefa = $tarefa->itens()->pluck('id')->all();

        foreach ($data['ordem'] as $posicao => $id) {
            if (in_array((int) $id, $daTarefa, true)) {
                TarefaItem::where('id', $id)->update(['ordem' => $posicao + 1]);
            }
        }

        return $this->voltarParaATarefa($tarefa->id);
    }

    /**
     * Volta para a tela de onde veio, com a tarefa reaberta.
     *
     * Todo mexer no checklist acontece dentro do modal da tarefa, e voltar sem
     * o `tarefa-aberta` fecharia o modal a cada item marcado — a lista de
     * conferência viraria uma sequência de reaberturas.
     */
    private function voltarParaATarefa(int $tarefaId)
    {
        return redirect()->back(fallback: route('tarefas.index'))
            ->with('tarefa-aberta', $tarefaId);
    }

    /**
     * Corrige um comentário — só o próprio, e a correção fica dita.
     *
     * Comentário errado é o erro mais barato de cometer, e até agora só havia
     * a saída de apagar e reescrever — o que jogava fora a data original e o
     * lugar da frase na conversa. Corrigir preserva os dois.
     *
     * O `editado_em` não é enfeite: reescrever em silêncio faria a tarefa
     * contar uma história que não aconteceu, e quem leu a versão anterior não
     * teria como saber que ela mudou. Editar o comentário alheio continua fora
     * de questão — a regra é a mesma do apagar.
     */
    public function editarComentario(Request $request, TarefaComentario $comentario)
    {
        $this->bloquearVisaoDaMatriz();

        abort_unless($comentario->autor_id === auth()->id(), 403, 'Só o autor corrige o próprio comentário.');

        $data = $request->validate([
            'corpo' => 'required|string|max:4000',
        ]);

        $comentario->update([
            'corpo' => trim($data['corpo']),
            'editado_em' => now(),
        ]);

        return redirect()->back(fallback: route('tarefas.index'))
            ->with('status', 'Comentário corrigido.')
            ->with('tarefa-aberta', $comentario->tarefa_id);
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
        $tarefas = Tarefa::with(['sistema', 'responsavel', 'eventos', 'comentarios.autor', 'itens'])
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
        $tipo = $this->textoDaQuery($request, 'tipo');

        return [
            'busca' => $this->textoDaQuery($request, 'busca'),
            'sistema' => $this->textoDaQuery($request, 'sistema'),
            'responsavel' => $this->textoDaQuery($request, 'responsavel'),
            'prioridade' => array_key_exists($prioridade, Tarefa::PRIORIDADES) ? $prioridade : '',
            // Com os dois tipos no mesmo quadro, "quero ver só o
            // desenvolvimento" passou a ser uma pergunta que a tela recebe.
            'tipo' => array_key_exists($tipo, Tarefa::TIPOS) ? $tipo : '',
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
     * A busca varre título, resumo, detalhes E os comentários: quem procura
     * uma tarefa pelo número do chamado ou pelo nome do cliente costuma ter
     * escrito isso no corpo, não no título — e desde que a tarefa tem
     * conversa, o corpo mais provável é justamente o comentário, que é onde o
     * assunto continua depois de a tarefa nascer. As condições vão dentro de
     * um `where` aninhado — soltas, o `orWhere` escaparia do `whereIn` de
     * status e o quadro passaria a mostrar tarefa concluída.
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
                ->orWhere('detalhes', 'like', '%'.$filtros['busca'].'%')
                ->orWhereHas('comentarios', fn ($comentario) => $comentario
                    ->where('corpo', 'like', '%'.$filtros['busca'].'%'))))
            ->when($filtros['sistema'] === 'sem', fn ($q) => $q->whereNull('sistema_id'))
            ->when($filtros['sistema'] !== '' && $filtros['sistema'] !== 'sem',
                fn ($q) => $q->where('sistema_id', $filtros['sistema']))
            ->when($filtros['responsavel'] === 'sem', fn ($q) => $q->whereNull('responsavel_id'))
            ->when($filtros['responsavel'] !== '' && $filtros['responsavel'] !== 'sem',
                fn ($q) => $q->where('responsavel_id', $filtros['responsavel']))
            ->when($filtros['prioridade'] !== '', fn ($q) => $q->where('prioridade', $filtros['prioridade']))
            ->when($filtros['tipo'] !== '', fn ($q) => $q->where('tipo', $filtros['tipo']));
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
        // "A definir" fecha a lista: ela não é o grau mais baixo da escala, é a
        // decisão que ainda não foi tomada — e colocá-la no topo faria a tarefa
        // que ninguém classificou passar na frente da que alguém chamou de
        // crítica. Quem procura o que triar tem o contador no cabeçalho.
        $gravidade = array_flip(['critica', 'alta', 'media', 'baixa', 'nao_definida']);

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
     *
     * O `warn` do bloqueio saiu daqui junto com a coluna: ele agora é a cor da
     * tarja no card e da faixa de solto, que é onde o bloqueio passou a viver.
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
