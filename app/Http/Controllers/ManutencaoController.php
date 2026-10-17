<?php

namespace App\Http\Controllers;

use App\Models\Atualizacao;
use App\Models\Compromisso;
use App\Models\MonitorAlerta;
use App\Models\MonitorEvento;
use App\Models\Sistema;
use App\Models\VigiaErro;
use App\Models\VigiaErroHora;
use App\Models\VigiaIgnorado;
use App\Services\Vigia\IgnoradosDoVigia;
use App\Services\Vigia\VigiaDeLogs;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Manutenção e atualizações: o que acontece com os sistemas depois que saem
 * do quadro.
 *
 * A aba Erros (#224) é a vitrine do vigia de logs (#219). O vigia já abria
 * Bug e avisava no Telegram, mas o que ele SABE — quantas vezes, desde
 * quando, se teve pico, o que está calado — só se via no banco, e calibrar o
 * ruído exigia SSH até o servidor para rodar o `alfa:vigia-ignorar`. Aqui as
 * duas coisas viram tela.
 *
 * As abas Atualizações e Programadas (#225) são a vitrine de duas coisas que
 * nascem em outro lugar: o changelog que o `publicar-changelog.sh` manda ao
 * Telegram (e registra aqui) e a janela de manutenção marcada na Agenda.
 *
 * A aba Monitor (#394) é a vitrine do AlfaMonitor: o que está fora agora e o
 * histórico de quedas e voltas, empurrados pelo webhook dele. Só mostra — o
 * aviso continua saindo do monitor.
 */
class ManutencaoController extends Controller
{
    public const ABAS = ['erros', 'monitor', 'atualizacoes', 'programadas'];

    /** Os recortes do histórico do Monitor, em dias. */
    public const PERIODOS_DO_MONITOR = [7, 30, 90];

    public const EVENTOS_POR_PAGINA = 30;

    public const SITUACOES = ['vigiados', 'ignorados', 'todos'];

    /**
     * Quantos erros a aba lista de uma vez. A tabela é uma linha por
     * ASSINATURA, não por ocorrência — em produção eram 2 linhas no dia em
     * que a tela nasceu —, então o teto é rede, não paginação: se um dia
     * chegar nele, a tela diz que cortou.
     */
    public const LIMITE_DE_ERROS = 200;

    public const ATUALIZACOES_POR_PAGINA = 15;

    /** Os dias da coluna "Semana": hoje e os seis anteriores. */
    public const DIAS_DA_SEMANA = 7;

    public function __construct(private readonly IgnoradosDoVigia $ignorados) {}

    public function index(Request $request): View
    {
        $this->bloquearVisaoDaMatriz();

        $aba = in_array($request->query('aba'), self::ABAS, true) ? $request->query('aba') : 'erros';

        $dados = match ($aba) {
            'monitor' => $this->abaMonitor($request),
            'atualizacoes' => $this->abaAtualizacoes($request),
            'programadas' => $this->abaProgramadas(),
            default => $this->abaErros($request),
        };

        return view('manutencao.index', ['aba' => $aba] + $dados);
    }

    /** O botão "Ignorar" da linha: o padrão do erro, só no sistema dele. */
    public function ignorarErro(VigiaErro $erro): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();

        $regra = $this->ignorados->ignorarErro($erro->load('sistema'));

        return $this->voltar('Ignorando «'.$regra->padrao.'» no '.$erro->sistema->nome
            .'. O erro continua contado, mas não abre tarefa nem avisa.');
    }

    /** O formulário da lista: o mesmo do `alfa:vigia-ignorar "<padrão>" [--sistema]`. */
    public function ignorar(Request $request): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();

        $dados = $request->validate([
            'padrao' => 'required|string|max:500',
            'sistema_id' => 'nullable|integer|exists:sistemas,id',
        ], [
            'padrao.required' => 'Diga o texto ou a /regex/ a ignorar.',
        ]);

        $sistema = isset($dados['sistema_id']) ? Sistema::find($dados['sistema_id']) : null;

        try {
            $regra = $this->ignorados->ignorar($dados['padrao'], $sistema);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['padrao' => $e->getMessage()]);
        }

        return $this->voltar('Ignorando '.($regra->ehRegex() ? 'a regex' : 'o texto').' «'.$regra->padrao.'» '
            .($sistema ? 'no '.$sistema->nome : 'em todos os sistemas').'.');
    }

    public function deixarDeIgnorar(VigiaIgnorado $ignorado): RedirectResponse
    {
        $this->bloquearVisaoDaMatriz();

        $padrao = $ignorado->padrao;
        $this->ignorados->deixarDeIgnorar($ignorado);

        // O que acontece depois é o que quem clica precisa saber: nada muda
        // na hora no quadro; é o próximo lote que traz o erro que decide.
        return $this->voltar('O vigia voltou a acompanhar «'.$padrao.'». Se o erro aparecer de novo, '
            .'ele abre tarefa — a não ser que já exista uma em curso.');
    }

    /**
     * @return array<string, mixed>
     */
    private function abaErros(Request $request): array
    {
        $filtros = [
            'sistema' => (int) $request->query('sistema', 0),
            'ambiente' => array_key_exists($request->query('ambiente'), VigiaDeLogs::AMBIENTES) ? $request->query('ambiente') : '',
            'situacao' => in_array($request->query('situacao'), self::SITUACOES, true) ? $request->query('situacao') : 'vigiados',
        ];

        $erros = VigiaErro::query()
            ->with(['sistema', 'tarefa'])
            ->when($filtros['sistema'], fn ($q, $id) => $q->where('sistema_id', $id))
            ->when($filtros['ambiente'], fn ($q, $ambiente) => $q->where('ambiente', $ambiente))
            ->when($filtros['situacao'] === 'vigiados', fn ($q) => $q->where('ignorado', false))
            ->when($filtros['situacao'] === 'ignorados', fn ($q) => $q->where('ignorado', true))
            ->orderByDesc('ultima_vez')
            ->limit(self::LIMITE_DE_ERROS + 1)
            ->get();

        $cortou = $erros->count() > self::LIMITE_DE_ERROS;
        $erros = $erros->take(self::LIMITE_DE_ERROS);

        $regras = VigiaIgnorado::with('sistema')->orderBy('sistema_id')->orderBy('padrao')->get();

        // Quantos erros cada regra cala, contado sobre a BASE inteira e não
        // sobre o recorte: é o que diz o tamanho do estrago de tirá-la.
        $base = VigiaErro::query()->get(['id', 'sistema_id', 'padrao', 'excecao', 'mensagem']);
        $cobertos = $regras->mapWithKeys(fn (VigiaIgnorado $regra) => [
            $regra->id => $base->filter(fn (VigiaErro $e) => ($regra->sistema_id === null || (int) $regra->sistema_id === (int) $e->sistema_id)
                && IgnoradosDoVigia::cobre($regra, $e))->count(),
        ]);

        // Sistema com o vigia ligado (tem token) e nenhum erro também aparece:
        // "nada a mostrar" e "o vigia nem está ligado lá" são coisas
        // diferentes, e só a primeira é boa notícia.
        $sistemas = Sistema::query()
            ->where(fn ($q) => $q->whereNotNull('vigia_token_hash')
                ->orWhereIn('id', VigiaErro::query()->select('sistema_id')))
            ->orderBy('nome')
            ->get(['id', 'nome', 'slug']);

        $porSistema = $sistemas
            ->when($filtros['sistema'], fn ($c, $id) => $c->where('id', $id))
            ->map(fn (Sistema $sistema) => [
                'sistema' => $sistema,
                'erros' => $erros->where('sistema_id', $sistema->id)->values(),
            ])
            ->values();

        return [
            'filtros' => $filtros,
            'sistemas' => $sistemas,
            'porSistema' => $porSistema,
            'cortou' => $cortou,
            'semana' => $this->semana($erros),
            'regras' => $regras,
            'cobertos' => $cobertos,
            'regraDoErro' => $erros->mapWithKeys(fn (VigiaErro $e) => [$e->id => $e->ignorado ? $this->ignorados->regraDe($e, $regras) : null]),
            // Os KPIs falam da BASE, não do recorte — o mesmo contrato do resto
            // do painel (ver `UsuarioController::index`).
            'kpis' => [
                'vigiados' => VigiaErro::where('ignorado', false)->count(),
                'ocorrencias24h' => (int) VigiaErroHora::where('hora', '>=', now()->subDay()->startOfHour()->format('Y-m-d H:i:s'))->sum('total'),
                'picos' => VigiaErro::where('pico_avisado_em', '>=', now()->subDays(self::DIAS_DA_SEMANA)->format('Y-m-d H:i:s'))->count(),
                'ignorados' => VigiaErro::where('ignorado', true)->count(),
            ],
        ];
    }

    /**
     * O AlfaMonitor (#394): o que está fora AGORA e o histórico.
     *
     * "Agora" é o estado (`MonitorAlerta`), não a soma do histórico: é o que
     * sobrevive a evento repetido ou fora de ordem. O histórico esconde os
     * lembretes por padrão — "ainda fora há 4h" de 4 em 4 horas enterraria as
     * quedas, que são o que se procura nele.
     *
     * @return array<string, mixed>
     */
    private function abaMonitor(Request $request): array
    {
        $filtros = [
            'kind' => array_key_exists($request->query('kind'), MonitorAlerta::KINDS) ? $request->query('kind') : '',
            'periodo' => in_array((int) $request->query('periodo'), self::PERIODOS_DO_MONITOR, true) ? (int) $request->query('periodo') : 7,
            'lembretes' => $request->boolean('lembretes'),
        ];

        $rank = array_flip(array_keys(MonitorAlerta::SEVERIDADES));

        $agora = MonitorAlerta::query()
            ->with('clienteDoMatriz')
            ->where('aberto', true)
            ->when($filtros['kind'], fn ($q, $kind) => $q->where('kind', $kind))
            ->get()
            // Crítico primeiro e, dentro da gravidade, quem caiu antes: o que
            // está fora há mais tempo é o que mais pede olho.
            ->sortBy([
                fn ($a, $b) => ($rank[$a->severity] ?? 9) <=> ($rank[$b->severity] ?? 9),
                fn ($a, $b) => $a->caiu_em <=> $b->caiu_em,
            ])
            ->values();

        $historico = MonitorEvento::query()
            ->with('alerta')
            ->when($filtros['kind'], fn ($q, $kind) => $q->whereHas('alerta', fn ($a) => $a->where('kind', $kind)))
            ->when(! $filtros['lembretes'], fn ($q) => $q->where('derived', false))
            ->where('ocorreu_em', '>=', now()->subDays($filtros['periodo'])->format('Y-m-d H:i:s'))
            ->orderByDesc('ocorreu_em')
            ->orderByDesc('event_id')
            ->paginate(self::EVENTOS_POR_PAGINA)
            ->withQueryString();

        // Quanto tempo ficou fora: a volta aponta a queda pelo incident_id.
        $quedas = MonitorEvento::query()
            ->whereIn('event_id', $historico->getCollection()->where('resolved', true)->pluck('incident_id')->filter())
            ->pluck('ocorreu_em', 'event_id');

        $ultimo = MonitorEvento::query()->latest('id')->first();

        return [
            'filtros' => $filtros,
            'agora' => $agora,
            'historico' => $historico,
            'quedas' => $quedas,
            // Se o monitor está ligado (tem token) e quando falou por último:
            // tela vazia com o monitor mudo há dias não é boa notícia.
            'ligado' => Sistema::whereNotNull('monitor_token_hash')->exists(),
            'ultimoRecebido' => $ultimo?->created_at,
            'kpis' => [
                'fora' => MonitorAlerta::where('aberto', true)->count(),
                'criticos' => MonitorAlerta::where('aberto', true)->where('severity', 'critical')->count(),
                'quedas7d' => MonitorEvento::where('derived', false)->where('resolved', false)
                    ->where('ocorreu_em', '>=', now()->subDays(7)->format('Y-m-d H:i:s'))->count(),
                'voltas24h' => MonitorEvento::where('derived', false)->where('resolved', true)
                    ->where('ocorreu_em', '>=', now()->subDay()->format('Y-m-d H:i:s'))->count(),
            ],
        ];
    }

    /**
     * Os changelogs publicados (#225), do mais recente para o mais antigo.
     *
     * @return array<string, mixed>
     */
    private function abaAtualizacoes(Request $request): array
    {
        $sistemaId = (int) $request->query('sistema', 0);

        return [
            'filtros' => ['sistema' => $sistemaId],
            // Só os sistemas que já têm changelog: filtrar por um que nunca
            // publicou nada daria sempre a mesma tela vazia.
            'sistemas' => Sistema::query()
                ->whereIn('id', Atualizacao::query()->select('sistema_id'))
                ->orderBy('nome')
                ->get(['id', 'nome', 'slug']),
            'atualizacoes' => Atualizacao::query()
                ->with(['sistema', 'tarefas', 'registradoPor'])
                ->when($sistemaId, fn ($q, $id) => $q->where('sistema_id', $id))
                ->orderByDesc('data')
                ->orderByDesc('id')
                ->paginate(self::ATUALIZACOES_POR_PAGINA)
                ->withQueryString(),
        ];
    }

    /**
     * As próximas janelas de manutenção (#225): os compromissos da Agenda na
     * categoria Deploy / manutenção que ainda não terminaram, por sistema.
     *
     * A Agenda continua sendo o lugar de marcar; aqui é só a vitrine. Uma
     * segunda tela de cadastro para a mesma coisa faria a janela existir em
     * dois lugares, e um deles ficaria para trás.
     *
     * @return array<string, mixed>
     */
    private function abaProgramadas(): array
    {
        $janelas = Compromisso::query()
            ->with(['sistema', 'tarefa.sistema', 'participantes'])
            ->where('categoria', 'deploy')
            // `whereDate`, e não comparação crua: ver a nota da Agenda no
            // CLAUDE.md (o SQLite dos testes não trunca a coluna DATE).
            ->whereDate('data_fim', '>=', now()->toDateString())
            ->orderBy('data')
            ->orderBy('hora')
            ->get()
            // A que já terminou hoje sai: "próxima" é o que ainda vai
            // acontecer ou está acontecendo.
            ->filter(fn (Compromisso $c) => $c->terminaEm()->gte(now()))
            ->values();

        return [
            'porSistema' => $janelas
                ->groupBy(fn (Compromisso $c) => $c->sistemaDaJanela()?->nome ?? '')
                ->sortKeys()
                // "Sem sistema" por último: é o que precisa de alguém dizer de
                // qual sistema é, não o que abre a lista.
                ->sortBy(fn ($grupo, $nome) => $nome === '' ? 1 : 0),
            'quantas' => $janelas->count(),
        ];
    }

    /**
     * Os sete dias de cada erro: o total por dia (a sparkline) e a hora mais
     * cheia da semana.
     *
     * A hora mais cheia é o "pico da semana" da tarefa, e ela aparece mesmo
     * quando não bateu a régua do aviso (10× a média): quem olha a tela quer
     * saber QUANDO o erro se concentrou, não só se o Telegram tocou.
     *
     * @param  Collection<int, VigiaErro>  $erros
     * @return array<int, array{dias: list<int>, total: int, maior: ?array{total: int, hora: Carbon}}>
     */
    private function semana(Collection $erros): array
    {
        $inicio = now()->startOfDay()->subDays(self::DIAS_DA_SEMANA - 1);
        $dias = collect(range(0, self::DIAS_DA_SEMANA - 1))->map(fn ($i) => $inicio->copy()->addDays($i)->format('Y-m-d'));

        $horas = VigiaErroHora::query()
            ->whereIn('vigia_erro_id', $erros->pluck('id'))
            ->where('hora', '>=', $inicio->format('Y-m-d H:i:s'))
            ->get()
            ->groupBy('vigia_erro_id');

        return $erros->mapWithKeys(function (VigiaErro $erro) use ($horas, $dias) {
            $doErro = $horas->get($erro->id, collect());
            $porDia = $doErro->groupBy(fn (VigiaErroHora $h) => $h->hora->format('Y-m-d'));
            $maior = $doErro->sortByDesc('total')->first();

            return [$erro->id => [
                'dias' => $dias->map(fn ($dia) => (int) $porDia->get($dia, collect())->sum('total'))->all(),
                'total' => (int) $doErro->sum('total'),
                'maior' => $maior ? ['total' => $maior->total, 'hora' => $maior->hora] : null,
            ]];
        })->all();
    }

    private function voltar(string $status): RedirectResponse
    {
        return redirect()->back(fallback: route('manutencao.index'))->with('status', $status);
    }
}
