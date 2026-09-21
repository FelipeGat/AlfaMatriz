<?php

namespace App\Http\Controllers;

use App\Models\Compromisso;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * A tela de Agenda — prazos de tarefas e compromissos do time.
 *
 * Chama-se Agenda, e não Calendário, porque é o nome que o time usa. A terceira
 * visão dentro dela chama-se **Lista** pelo mesmo cuidado invertido: uma visão
 * "Agenda" dentro da tela Agenda faria a barra superior se referir a duas
 * coisas diferentes com a mesma palavra.
 *
 * Todo mundo que entra vê a agenda do TIME INTEIRO, com filtro por pessoa. O
 * recorte por pessoa é uma lente, não uma permissão: combinar prazo é um ato
 * entre pessoas, e esconder metade da combinação de uma delas só produziria
 * reunião marcada em cima de reunião.
 */
class AgendaController extends Controller
{
    public function __construct(private readonly AgendaService $agenda) {}

    /** As três visões da tela. Só a faixa de datas muda entre elas. */
    public const VISOES = ['semana', 'mes', 'lista'];

    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $dados = $request->validate([
            'visao' => 'nullable|in:'.implode(',', self::VISOES),
            'em' => 'nullable|date',
            'pessoas' => 'nullable|array',
            'pessoas.*' => 'integer',
        ]);

        $visao = $dados['visao'] ?? 'semana';
        $hoje = now()->startOfDay();

        // A âncora da navegação `‹ ›`. Vem da URL para que voltar de uma edição
        // devolva a pessoa à semana em que ela estava — sem isso, salvar um
        // compromisso da semana que vem jogaria a tela de volta para hoje, e o
        // trabalho recomeçaria do zero a cada salvamento.
        $em = isset($dados['em']) ? Carbon::parse($dados['em'])->startOfDay() : $hoje->copy();

        $pessoas = array_values(array_filter($dados['pessoas'] ?? [], fn ($id) => (int) $id > 0));

        $faixa = match ($visao) {
            'mes' => $this->agenda->faixaDoMes($em),
            'lista' => $this->agenda->faixaDaLista($hoje),
            default => $this->agenda->faixaDaSemana($em),
        };

        $itens = $this->agenda->itens($faixa['de'], $faixa['ate'], $pessoas, $hoje);

        return view('agenda.index', [
            'visao' => $visao,
            'em' => $em,
            'hoje' => $hoje,
            'faixa' => $faixa,
            'pessoas' => $pessoas,
            'itens' => $itens,
            'itensPorDia' => $itens->groupBy('data'),

            // Só a Semana é grade de horários (estilo Google): régua de horas à
            // esquerda e blocos com altura pela duração. Mês e Lista seguem como
            // estão. Montada só quando é a visão à vista, para as outras não
            // pagarem a consulta.
            'grade' => $visao === 'semana'
                ? $this->agenda->gradeSemana($faixa['de'], $faixa['ate'], $pessoas)
                : null,
            'faixaLabel' => $this->faixaLabel($visao, $em, $faixa),
            'equipe' => $this->equipe(),
            'podeReagendar' => $request->user()?->podeTriarTarefas() ?? false,
            'usuarioId' => $request->user()->id,

            // O que o modal precisa para ABRIR um compromisso já salvo, pelos
            // ids que os itens da tela carregam. Vai junto com a página, e não
            // por requisição no clique, porque o modal é de edição: buscar ao
            // abrir mostraria um formulário vazio enquanto a resposta não
            // chega, e o campo de título piscaria por cima do que a pessoa
            // acabou de ver no card.
            'compromissos' => $this->compromissosDaFaixa($faixa, $pessoas, $request),

            // Os dois selects do modal. Carregados aqui, e não por requisição
            // ao abrir, porque o modal nasce junto com a tela: uma consulta na
            // abertura faria o formulário piscar vazio antes de se preencher.
            // Por id crescente, e não por título: o select mostra "#12 — …",
            // então ordenar por nome embaralhava os números. O código da tarefa
            // é o que a pessoa procura ali, e ele cresce com o id.
            'tarefasVinculaveis' => Tarefa::query()
                ->whereNotIn('status', Tarefa::STATUS_TERMINAIS)
                ->orderBy('id')
                ->get(['id', 'titulo']),
            'sistemas' => Sistema::orderBy('nome')->get(['id', 'nome']),
        ]);
    }

    /**
     * O drawer de um dia: tudo o que acontece nele, mais a carga por pessoa.
     *
     * Rota própria, e não um recorte que a tela já tem em mãos, porque o mês
     * mostra só duas linhas por célula — o dia 14 pode ter sete itens, e seis
     * deles só existem aqui.
     */
    public function dia(Request $request, string $data)
    {
        $this->bloquearVisaoDaMatriz();

        $dia = Carbon::parse($data)->startOfDay();

        $dados = $request->validate([
            'pessoas' => 'nullable|array',
            'pessoas.*' => 'integer',
        ]);

        $pessoas = array_values(array_filter($dados['pessoas'] ?? [], fn ($id) => (int) $id > 0));
        $itens = $this->agenda->itens($dia, $dia, $pessoas);

        return response()->json([
            'data' => $dia->toDateString(),
            'titulo' => $dia->translatedFormat('D, d \d\e F'),
            'itens' => $itens,
            // Só com mais de uma pessoa envolvida: a tira de carga existe para
            // comparar, e comparar uma pessoa com ela mesma não diz nada.
            'carga' => $this->agenda->cargaPorPessoa($itens)->count() > 1
                ? $this->agenda->cargaPorPessoa($itens)
                : [],
        ]);
    }

    /**
     * O detalhe de uma tarefa, aberto a partir da Agenda.
     *
     * Ele NÃO é o modal do quadro: aqui a pergunta é sobre a data, e as
     * respostas são prioridade, sistema, responsável, prazo e as reuniões
     * vinculadas. Quem quer o card inteiro tem o botão "Ver no quadro", que
     * leva para lá em vez de reproduzir 2 MB de formulário dentro da Agenda.
     */
    public function tarefa(Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        $tarefa->load(['sistema', 'responsavel', 'compromissos.participantes']);

        return response()->json([
            'id' => $tarefa->id,
            'codigo' => $tarefa->codigo(),
            'titulo' => $tarefa->titulo,
            'prioridade' => Tarefa::PRIORIDADES[$tarefa->prioridade] ?? $tarefa->prioridade,
            'tom' => Tarefa::TOM_DA_PRIORIDADE[$tarefa->prioridade] ?? 'neutro',
            'sistema' => $tarefa->sistema?->nome ?? '—',
            'responsavel' => $tarefa->responsavel?->name ?? 'Sem responsável',
            'responsavel_id' => $tarefa->responsavel_id,
            'prazo' => $tarefa->prazo ? Carbon::parse($tarefa->prazo)->toDateString() : null,
            'prazoLegivel' => $tarefa->prazo
                ? Carbon::parse($tarefa->prazo)->translatedFormat('D, d \d\e F \d\e Y')
                : 'Sem prazo',
            'marca' => $tarefa->marcaDaAgenda(),
            'reunioes' => $tarefa->compromissos->map(fn (Compromisso $c) => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'quando' => Carbon::parse($c->data)->translatedFormat('d/m').' · '.$c->intervalo(),
            ])->values(),
            'rotaNoQuadro' => route('tarefas.index', ['tarefa' => $tarefa->id]),
        ]);
    }

    /**
     * Reagenda o prazo de uma tarefa — o arraste da Agenda.
     *
     * Duas travas, e as duas já existiam no quadro:
     *
     * 1. **Só quem faz triagem.** Mudar prazo é triagem, exatamente como mudar
     *    prioridade e responsável: é decidir sobre o trabalho dos outros. Quem
     *    não triaga arrasta o próprio card no quadro e continua sem remarcar o
     *    combinado com o time.
     * 2. **`de_prazo` obrigatório.** É o mesmo contrato de `de_status` do
     *    `tarefas.mover`: quem arrasta manda de ONDE o item saiu, e a rota
     *    recusa se alguém já mexeu. Sem ele, duas pessoas arrastando a mesma
     *    tarefa em telas diferentes fazem a última ganhar em silêncio — e nem a
     *    primeira nem a segunda ficam sabendo que houve disputa.
     */
    public function reagendar(Request $request, Tarefa $tarefa)
    {
        $this->bloquearVisaoDaMatriz();

        if (! $request->user()?->podeTriarTarefas()) {
            return $this->recusar($request, 'Só quem faz triagem remarca o prazo de uma tarefa.');
        }

        $dados = $request->validate([
            'prazo' => 'required|date',
            // `present` e não `required`: tarefa que ainda não tinha prazo sai
            // de null, e `required` recusaria justamente o primeiro arrasto.
            'de_prazo' => 'present|nullable|date',
        ]);

        $prazoAtual = $tarefa->prazo ? Carbon::parse($tarefa->prazo)->toDateString() : null;
        $deInformado = $dados['de_prazo'] ? Carbon::parse($dados['de_prazo'])->toDateString() : null;

        if ($prazoAtual !== $deInformado) {
            return $this->recusar($request, 'Alguém já remarcou esta tarefa. Confira a agenda antes de mover de novo.');
        }

        $tarefa->update(['prazo' => Carbon::parse($dados['prazo'])->toDateString()]);

        return $this->voltar($request, 'Prazo remarcado para '
            .Carbon::parse($dados['prazo'])->translatedFormat('d/m').'.');
    }

    /**
     * Os compromissos da faixa no formato que o modal edita.
     *
     * `somenteLeitura` vem calculado do servidor, e não deduzido na tela: a
     * regra é "quem criou, ou quem triaga", e reproduzi-la no Alpine criaria
     * uma segunda verdade que a rota desmentiria no salvamento.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function compromissosDaFaixa(array $faixa, array $pessoas, Request $request)
    {
        return Compromisso::query()
            ->with('participantes:id')
            ->naFaixa($faixa['de'], $faixa['ate'])
            ->deParticipantes($pessoas)
            ->get()
            ->mapWithKeys(fn (Compromisso $c) => [$c->id => [
                'id' => $c->id,
                'titulo' => $c->titulo,
                'descricao' => $c->descricao,
                'data' => Carbon::parse($c->data)->toDateString(),
                'hora' => Carbon::parse($c->hora)->format('H:i'),
                'data_fim' => Carbon::parse($c->data_fim)->toDateString(),
                'hora_fim' => Carbon::parse($c->hora_fim)->format('H:i'),
                'duracao_modo' => $c->duracao_modo,
                'duracao_horas' => $c->duracao_horas ? (float) $c->duracao_horas : 1.0,
                'tarefa_id' => $c->tarefa_id,
                'participantes' => $c->participantes->pluck('id')->all(),
                'criado_por_id' => $c->criado_por_id,
                'somenteLeitura' => ! $c->podeSerEditadoPor($request->user()),
            ]]);
    }

    /**
     * As pessoas que aparecem nos chips de filtro e na lista de participantes.
     *
     * Só quem tem a porta da Agenda: oferecer como participante alguém que não
     * consegue abrir a tela produz reunião que o convidado nunca vê. Conta
     * desativada fica de fora pela mesma razão, um passo antes.
     */
    private function equipe()
    {
        return User::query()
            ->where('ativo', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->filter(fn (User $u) => $u->canPermissao('agenda', 'ler'))
            ->values();
    }

    /** O rótulo da faixa, ao lado da navegação: "12 – 18 de outubro". */
    private function faixaLabel(string $visao, Carbon $em, array $faixa): string
    {
        if ($visao === 'lista') {
            return 'Próximos '.AgendaService::DIAS_DA_LISTA.' dias';
        }

        if ($visao === 'mes') {
            return ucfirst($em->translatedFormat('F \d\e Y'));
        }

        $de = $faixa['de'];
        $ate = $faixa['ate'];

        // Mês repetido só uma vez quando a semana não atravessa a virada:
        // "12 – 18 de outubro", e não "12 de outubro – 18 de outubro".
        return $de->month === $ate->month
            ? $de->format('d').' – '.$ate->translatedFormat('d \d\e F')
            : $de->translatedFormat('d \d\e M').' – '.$ate->translatedFormat('d \d\e M');
    }

    private function recusar(Request $request, string $mensagem)
    {
        // A recusa também flasha: o arraste que bate em remarcação alheia
        // recarrega, e sem o flash a tela voltava sem dizer por que não mexeu.
        $request->session()->flash('erro', $mensagem);

        if ($request->expectsJson()) {
            return response()->json(['erro' => $mensagem], 422);
        }

        return redirect()->back(fallback: route('agenda.index'))->with('erro', $mensagem);
    }

    private function voltar(Request $request, string $mensagem)
    {
        // Flash SEMPRE, mesmo no caminho JSON: a tela salva por fetch e depois
        // recarrega, e a mensagem só no corpo do JSON morria no reload — quem
        // marcava um compromisso não via confirmação nenhuma. Flashada na
        // sessão, ela sobrevive à recarga e o `<x-aviso>` da tela a mostra.
        $request->session()->flash('status', $mensagem);

        if ($request->expectsJson()) {
            return response()->json(['status' => $mensagem]);
        }

        return redirect()->back(fallback: route('agenda.index'))->with('status', $mensagem);
    }
}
