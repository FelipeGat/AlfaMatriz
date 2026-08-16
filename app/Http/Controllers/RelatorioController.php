<?php

namespace App\Http\Controllers;

use App\Models\Auditoria;
use App\Models\CentroCusto;
use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Lead;
use App\Models\Perfil;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\User;
use App\Services\IndicadoresService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RelatorioController extends Controller
{
    /**
     * As quatro seções da aba, na ordem em que aparecem. A chave é o valor de
     * `?secao=` e o nome do partial (`relatorios/_<chave>.blade.php`); o rótulo
     * é o texto da pílula e o título do arquivo exportado.
     */
    public const SECOES = [
        'comercial' => 'Comercial',
        'financeiro' => 'Financeiro',
        'desenvolvimento' => 'Desenvolvimento',
        'sistema' => 'Sistema',
    ];

    /** Os mesmos rótulos da tela de Receitas — dois vocabulários para o mesmo `tipo` seria um terceiro relatório. */
    private const TIPOS_COBRANCA = [
        'locacao_sistema' => 'Recorrente · revenda',
        'locacao_cliente' => 'Recorrente · cliente',
        'avulsa' => 'Avulsa',
        'direta' => 'Direta',
    ];

    /**
     * Os indicadores que aparecem em mais de uma tela saem do serviço, não de
     * contagem local — mesma regra do Painel: relatório que discorda do
     * dashboard sob o mesmo rótulo é pior que relatório nenhum.
     */
    public function __construct(private readonly IndicadoresService $indicadores) {}

    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $secao = $this->secaoSelecionada($request);
        $competencia = $this->competenciaSelecionada($request);
        $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $filtros = $this->filtrosDaSecao($request, $secao);
        $listas = $this->listasDaSecao($secao);

        return view('relatorios.index', $this->dadosDaSecao($secao, $competencia, $mes, $filtros) + [
            'secao' => $secao,
            'competencia' => $competencia,
            'competenciaAnterior' => $mes->copy()->subMonth()->format('Y-m'),
            'competenciaProxima' => $mes->copy()->addMonth()->format('Y-m'),
            'competenciaEhAtual' => $competencia === now()->format('Y-m'),
            'filtros' => $filtros,
            'listas' => $listas,
            'recortes' => $this->recortes($filtros, $listas),
        ]);
    }

    /**
     * A prévia do arquivo: o documento aberto no navegador, com a barra de
     * escolher o formato (CSV, PDF, imprimir). É a MESMA view que o dompdf
     * renderiza — o que se vê é literalmente o que se leva —, e é também o
     * caminho da impressão: imprimir a tela escura direto sairia um borrão.
     */
    public function previa(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $secao = $this->secaoSelecionada($request);
        $competencia = $this->competenciaSelecionada($request);
        $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $filtros = $this->filtrosDaSecao($request, $secao);

        return view('relatorios.documento', [
            'modo' => 'previa',
            'relatorio' => $this->relatorioExportavel($secao, $competencia, $this->dadosDaSecao($secao, $competencia, $mes, $filtros)),
            'recortes' => $this->recortes($filtros, $this->listasDaSecao($secao)),
            'competencia' => $competencia,
            'secao' => $secao,
            'geradoEm' => now(),
        ]);
    }

    /**
     * O mesmo relatório da tela, em arquivo — CSV para conferir em planilha,
     * PDF para mandar pronto. Os dois saem de `dadosDaSecao()`, com o MESMO
     * recorte da URL: exportar o que não é o que se está vendo seria pegar a
     * pessoa de surpresa no pior momento — depois que ela já mandou o arquivo.
     */
    public function exportar(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        $secao = $this->secaoSelecionada($request);
        $competencia = $this->competenciaSelecionada($request);
        $mes = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $filtros = $this->filtrosDaSecao($request, $secao);

        $relatorio = $this->relatorioExportavel($secao, $competencia, $this->dadosDaSecao($secao, $competencia, $mes, $filtros));
        $recortes = $this->recortes($filtros, $this->listasDaSecao($secao));

        return $request->query('formato') === 'pdf'
            ? $this->exportarPdf($relatorio, $recortes, $secao, $competencia)
            : $this->exportarCsv($relatorio, $recortes, $secao, $competencia);
    }

    private function secaoSelecionada(Request $request): string
    {
        $valor = $request->query('secao');

        return array_key_exists((string) $valor, self::SECOES) ? $valor : 'comercial';
    }

    /**
     * Os filtros de cada seção cobrem os eixos que ELA mostra — e nada além:
     * um select de vendedor na seção de Sistema seria promessa de recorte que
     * nenhum painel dali honra. Valor fora da lista volta como vazio, igual à
     * competência malformada: a tela corrige em silêncio em vez de quebrar.
     *
     * Os nomes de parâmetro não colidem entre seções de propósito
     * (`tipo_receita` × `tipo`): a troca de seção carrega a query string, e um
     * nome só significando duas coisas mudaria o relatório ao mudar de aba.
     */
    private function filtrosDaSecao(Request $request, string $secao): array
    {
        $id = fn (string $chave) => ctype_digit((string) $request->query($chave)) ? (string) $request->query($chave) : '';
        $entre = fn (string $chave, array $validos) => in_array($request->query($chave), $validos, true)
            ? (string) $request->query($chave)
            : '';

        return match ($secao) {
            'comercial' => [
                'vendedor' => $id('vendedor'),
                'origem' => $entre('origem', Lead::ORIGENS),
                'sistema' => $id('sistema'),
            ],
            'financeiro' => [
                'tipo_receita' => $entre('tipo_receita', array_keys(self::TIPOS_COBRANCA)),
                'centro_custo' => $id('centro_custo'),
            ],
            'desenvolvimento' => [
                'sistema' => $id('sistema'),
                'responsavel' => $id('responsavel'),
                'prioridade' => $entre('prioridade', array_keys(Tarefa::PRIORIDADES)),
                'tipo' => $entre('tipo', array_keys(Tarefa::TIPOS)),
            ],
            'sistema' => [
                'perfil' => $id('perfil'),
                'recurso' => preg_match('/^[a-z_]+$/', (string) $request->query('recurso'))
                    ? (string) $request->query('recurso')
                    : '',
                'usuario' => $id('usuario'),
            ],
        };
    }

    /** As opções dos selects — só as da seção aberta, pelo mesmo motivo de só ela consultar o banco. */
    private function listasDaSecao(string $secao): array
    {
        $usuariosDaMatriz = fn () => User::whereNull('revenda_id')
            ->where('ativo', true)
            ->orderBy('name')
            ->get();

        return match ($secao) {
            'comercial' => [
                'vendedores' => $usuariosDaMatriz(),
                'sistemas' => Sistema::produtos()->orderBy('nome')->get(),
            ],
            'financeiro' => [
                'centrosCusto' => CentroCusto::orderBy('nome')->get(),
            ],
            'desenvolvimento' => [
                // Produto primeiro, como no filtro de Tarefas, pelo mesmo
                // motivo: a ordem alfabética da natureza enterraria o catálogo.
                'sistemas' => Sistema::orderByRaw("CASE WHEN natureza = 'produto' THEN 0 ELSE 1 END")
                    ->orderBy('nome')->get(),
                'responsaveis' => $usuariosDaMatriz(),
            ],
            'sistema' => [
                'perfis' => Perfil::orderBy('nome')->get(),
                // Só recursos que TÊM rastro: oferecer um recurso sem linha
                // nenhuma é um filtro que só sabe devolver o vazio.
                'recursos' => Auditoria::select('recurso')->distinct()->orderBy('recurso')->pluck('recurso'),
                'atores' => User::whereIn('id', Auditoria::select('user_id')->whereNotNull('user_id'))
                    ->orderBy('name')->get(),
            ],
        };
    }

    /**
     * O recorte ativo, nomeado — as mesmas pílulas do quadro de Tarefas
     * (AC-355): o select guarda o valor mas não o anuncia, e cada ✕ tira só o
     * seu filtro. `Dimensão · valor`, para a pílula dizer as duas coisas.
     */
    private function recortes(array $filtros, array $listas): array
    {
        $nome = fn (?object $colecao, string $id, string $campo = 'name') => $colecao?->firstWhere('id', (int) $id)?->{$campo};

        return collect($filtros)
            ->filter(fn ($valor) => $valor !== '')
            ->map(fn ($valor, $parametro) => [
                'parametro' => $parametro,
                'rotulo' => match ($parametro) {
                    'vendedor' => 'Vendedor · '.($nome($listas['vendedores'] ?? null, $valor) ?? $valor),
                    'origem' => 'Origem · '.$valor,
                    'sistema' => 'Sistema · '.($nome($listas['sistemas'] ?? null, $valor, 'nome') ?? $valor),
                    'tipo_receita' => 'Tipo · '.self::TIPOS_COBRANCA[$valor],
                    'centro_custo' => 'Centro de custo · '.($nome($listas['centrosCusto'] ?? null, $valor, 'nome') ?? $valor),
                    'responsavel' => 'Responsável · '.($nome($listas['responsaveis'] ?? null, $valor) ?? $valor),
                    'prioridade' => 'Prioridade · '.Tarefa::PRIORIDADES[$valor],
                    'tipo' => 'Tipo · '.Tarefa::TIPOS[$valor],
                    'perfil' => 'Perfil · '.($nome($listas['perfis'] ?? null, $valor, 'nome') ?? $valor),
                    'recurso' => 'Recurso · '.$valor,
                    'usuario' => 'Usuário · '.($nome($listas['atores'] ?? null, $valor) ?? $valor),
                },
            ])
            ->values()
            ->all();
    }

    /**
     * Só a seção pedida consulta o banco: as quatro juntas somam dezenas de
     * agregações, e quem abre o relatório comercial não deve pagar pelas
     * outras três.
     */
    private function dadosDaSecao(string $secao, string $competencia, Carbon $mes, array $filtros): array
    {
        return match ($secao) {
            'comercial' => $this->secaoComercial($competencia, $mes, $filtros),
            'financeiro' => $this->secaoFinanceiro($competencia, $mes, $filtros),
            'desenvolvimento' => $this->secaoDesenvolvimento($mes, $filtros),
            'sistema' => $this->secaoSistema($mes, $filtros),
        };
    }

    /**
     * O funil visto de fora: a foto de hoje (aberto, pipeline, conversão) e o
     * que a competência navegada fechou e perdeu — pela data em que o estágio
     * mudou, a mesma régua de `vendasPorVendedor()`. Todos os painéis desta
     * seção são de lead, então o recorte vale para TODOS eles.
     */
    private function secaoComercial(string $competencia, Carbon $mes, array $filtros): array
    {
        $fim = $mes->copy()->endOfMonth();

        $filtrosLead = [
            'vendedor_id' => $filtros['vendedor'] !== '' ? (int) $filtros['vendedor'] : null,
            'origem' => $filtros['origem'] ?: null,
            'sistema_id' => $filtros['sistema'] !== '' ? (int) $filtros['sistema'] : null,
        ];

        $restringirLead = fn ($query) => $query
            ->when($filtrosLead['vendedor_id'], fn ($q, $id) => $q->where('vendedor_id', $id))
            ->when($filtrosLead['origem'], fn ($q, $origem) => $q->where('origem', $origem))
            ->when($filtrosLead['sistema_id'], fn ($q, $id) => $q->where('sistema_id', $id));

        $kpis = $this->indicadores->funilKpis(null, $filtrosLead);

        $fechados = Lead::where('estagio', 'cliente_ativo')
            ->whereBetween('estagio_atualizado_em', [$mes, $fim])
            ->where($restringirLead)
            ->get();
        $perdidos = Lead::where('estagio', 'perdido')
            ->whereBetween('estagio_atualizado_em', [$mes, $fim])
            ->where($restringirLead)
            ->get();

        // Os abertos uma vez só: temperatura, origem e a mesa de maiores
        // negócios são três leituras da MESMA lista.
        $abertos = Lead::whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS)
            ->where($restringirLead)
            ->with('vendedor')
            ->get();

        // A régua é a de `Lead::temperatura()` — quente < 7 dias no estágio,
        // esfriando de 7 a 15, frio dali em diante.
        $porTemperatura = $abertos->groupBy(fn ($lead) => $lead->temperatura());
        $temperaturas = collect(['quente' => 'Quente', 'esfriando' => 'Esfriando', 'frio' => 'Frio'])
            ->map(fn ($rotulo, $chave) => [
                'chave' => $chave,
                'rotulo' => $rotulo,
                'quantidade' => $porTemperatura->get($chave)?->count() ?? 0,
                'valor' => (float) ($porTemperatura->get($chave)?->sum('valor_estimado') ?? 0),
            ])
            ->values()
            ->all();

        $rankingOrigens = $this->ranking(
            $abertos->groupBy(fn ($lead) => $lead->origem ?: 'Sem origem')
                ->map(fn ($leads, $origem) => ['nome' => $origem, 'valor' => (float) $leads->count()])
                ->values(),
            'accent'
        );

        $maioresLeads = $abertos->sortByDesc('valor_estimado')->take(10)->values();

        $rankingFunil = $this->ranking(
            collect($this->indicadores->funilPorEstagio(null, $filtrosLead))
                ->map(fn ($e) => ['nome' => $e['label'], 'valor' => (float) $e['quantidade']]),
            'brand'
        );

        $rankingVendedores = $this->ranking(
            $this->indicadores->vendasPorVendedor($competencia, $filtrosLead)
                ->map(fn ($v) => ['nome' => $v['nome'], 'valor' => $v['valor']]),
            'good'
        );

        // Perda sem motivo vira "Sem motivo", e não some: o buraco no cadastro
        // é exatamente o que este ranking existe para mostrar.
        $rankingMotivosPerda = $this->ranking(
            $perdidos->groupBy(fn ($lead) => $lead->motivo_perda ?? '')
                ->map(fn ($leads, $motivo) => [
                    'nome' => Lead::MOTIVOS_PERDA[$motivo] ?? ($motivo ?: 'Sem motivo'),
                    'valor' => (float) $leads->count(),
                ])
                ->values(),
            'chart-out'
        );

        return [
            'kpis' => $kpis,
            'fechadosQtd' => $fechados->count(),
            'fechadosValor' => (float) $fechados->sum('valor_estimado'),
            'perdidosQtd' => $perdidos->count(),
            'perdidosValor' => (float) $perdidos->sum('valor_estimado'),
            // Entrada de verdade na base, não movimento de funil — e da
            // COMPETÊNCIA navegada, pela mesma expressão SQL que a série de
            // clientes usa (`Cliente::expressaoDeEntrada()`).
            'novosClientesQtd' => Cliente::whereRaw(
                Cliente::expressaoDeEntrada().' BETWEEN ? AND ?',
                [$mes->toDateString(), $fim->toDateString()]
            )->count(),
            'temperaturas' => $temperaturas,
            'abertosQtd' => $abertos->count(),
            'rankingOrigens' => $rankingOrigens,
            'maioresLeads' => $maioresLeads,
            'rankingFunil' => $rankingFunil,
            'rankingVendedores' => $rankingVendedores,
            'rankingMotivosPerda' => $rankingMotivosPerda,
        ];
    }

    /**
     * O mês fechado em quatro números — os mesmos do Painel Financeiro, da
     * mesma origem — mais o que está em aberto dos dois lados e para onde a
     * despesa da competência foi.
     *
     * O recorte desta seção vale para os painéis de TÍTULOS (a receber, a
     * pagar, centro de custo). O caixa e o gráfico são da casa inteira — um
     * "saldo do centro de custo X" não existe no livro-caixa, e fingir que
     * existe seria inventar número. A view avisa quando o recorte está ligado.
     */
    private function secaoFinanceiro(string $competencia, Carbon $mes, array $filtros): array
    {
        $mrr = $this->indicadores->mrrDaCompetencia($competencia);
        $entradas = $this->indicadores->entradasDoMes($mes);
        $saidas = $this->indicadores->saidasDoMes($mes);

        // O ano DA COMPETÊNCIA, não o corrente: quem navegou para março de um
        // ano fechado quer o gráfico daquele ano ao lado dos cards daquele mês.
        $historico = $this->indicadores->serieDeCaixaEntre(
            $mes->copy()->startOfYear(),
            $mes->copy()->startOfYear()->addMonths(11)
        );

        $centroCustoId = $filtros['centro_custo'] !== '' ? (int) $filtros['centro_custo'] : null;

        // As pendentes UMA vez, enxutas — aging, total em aberto e a mesa de
        // maiores atrasos são três leituras da MESMA lista, e as três precisam
        // concordar sob o mesmo recorte de tipo. Só o top 10 carrega relação.
        $pendentes = Cobranca::where('status', 'pendente')
            ->when($filtros['tipo_receita'], fn ($q, $tipo) => $q->where('tipo', $tipo))
            ->get(['id', 'descricao', 'valor', 'data_vencimento', 'revenda_id', 'cliente_id']);

        $hoje = now()->startOfDay();
        $estaVencida = fn ($cobranca) => Carbon::parse($cobranca->data_vencimento)->lessThan($hoje);

        $aReceber = (object) [
            'qtd' => $pendentes->count(),
            'total' => (float) $pendentes->sum('valor'),
            'vencido' => (float) $pendentes->filter($estaVencida)->sum('valor'),
        ];
        $faixasAReceber = $this->faixasDeAging($pendentes, $hoje);
        $maioresVencidos = $pendentes->filter($estaVencida)
            ->sortByDesc(fn ($c) => (float) $c->valor)
            ->take(10)
            ->values();
        $maioresVencidos->load(['revenda', 'cliente']);

        // O a-pagar segue agregado no banco: dele a tela só mostra os três
        // números, e o em-aberto acumulado não tem teto.
        $aPagar = ContaPagar::where('status', 'em_aberto')
            ->when($centroCustoId, fn ($q, $id) => $q->where('centro_custo_id', $id))
            ->selectRaw('COUNT(*) as qtd, COALESCE(SUM(valor), 0) as total')
            ->selectRaw('COALESCE(SUM(CASE WHEN data_vencimento < ? THEN valor ELSE 0 END), 0) as vencido', [$hoje->toDateString()])
            ->first();

        // O que a competência FATUROU, por tipo — o outro lado do painel de
        // centro de custo: entrada e saída do mesmo mês, lado a lado.
        $rankingTiposReceita = $this->ranking(
            Cobranca::where('competencia', $competencia)
                ->when($filtros['tipo_receita'], fn ($q, $tipo) => $q->where('tipo', $tipo))
                ->selectRaw('tipo, COALESCE(SUM(valor), 0) as total')
                ->groupBy('tipo')
                ->get()
                ->map(fn ($linha) => [
                    'nome' => self::TIPOS_COBRANCA[$linha->tipo] ?? ucfirst($linha->tipo),
                    'valor' => (float) $linha->total,
                ]),
            'accent'
        );

        // Toda a despesa LANÇADA para a competência, paga ou não: a pergunta é
        // "para onde o mês foi", e conta em aberto já é destino decidido.
        $rankingCentrosDeCusto = $this->ranking(
            ContaPagar::where('competencia', $competencia)
                ->when($centroCustoId, fn ($q, $id) => $q->where('centro_custo_id', $id))
                ->with('centroCusto')
                ->get()
                ->groupBy('centro_custo_id')
                ->map(fn ($contas) => [
                    'nome' => $contas->first()->centroCusto?->nome ?? 'Sem centro de custo',
                    'valor' => (float) $contas->sum('valor'),
                ])
                ->values(),
            'chart-out'
        );

        return [
            'mrr' => $mrr,
            'mrrContratado' => $this->indicadores->mrrEhContratado($competencia),
            'entradasMes' => $entradas,
            'saidasMes' => $saidas,
            'resultadoMes' => $entradas - $saidas,
            'saldoTotal' => $this->indicadores->saldoAoFimDe($mes),
            'historico' => $historico,
            'aReceber' => $aReceber,
            'aPagar' => $aPagar,
            'faixasAReceber' => $faixasAReceber,
            'maioresVencidos' => $maioresVencidos,
            'rankingTiposReceita' => $rankingTiposReceita,
            'rankingCentrosDeCusto' => $rankingCentrosDeCusto,
            'recorteNosTitulos' => $filtros['tipo_receita'] !== '' || $centroCustoId !== null,
            // As curvas dos cards são a tendência recente (últimos 6 meses até
            // hoje), não a competência navegada — a mesma nota do dashboard.
            'serieMrr' => $this->indicadores->serieDoMrr(6),
            'serieSaldo' => $this->indicadores->serieDoSaldo(6),
            'serieEntradas' => $this->indicadores->serieDeEntradas(6),
            'serieSaidas' => $this->indicadores->serieDeSaidas(6),
        ];
    }

    /**
     * O quadro visto de fora: o que a competência concluiu (pelo evento de
     * chegada em `concluida`, que é quem sabe a data), o que entrou, e onde o
     * trabalho está agora. Todos os painéis são de tarefa, então o recorte
     * vale para TODOS eles.
     */
    private function secaoDesenvolvimento(Carbon $mes, array $filtros): array
    {
        $fim = $mes->copy()->endOfMonth();

        $restringirTarefa = fn ($query) => $query
            ->when($filtros['sistema'] !== '', fn ($q) => $q->where('sistema_id', (int) $filtros['sistema']))
            ->when($filtros['responsavel'] !== '', fn ($q) => $q->where('responsavel_id', (int) $filtros['responsavel']))
            ->when($filtros['prioridade'] !== '', fn ($q) => $q->where('prioridade', $filtros['prioridade']))
            ->when($filtros['tipo'] !== '', fn ($q) => $q->where('tipo', $filtros['tipo']));

        // `unique('tarefa_id')` porque reabrir e concluir de novo no mesmo mês
        // geraria dois eventos — e a tarefa continua sendo UMA entrega.
        $conclusoes = TarefaEvento::where('para_status', 'concluida')
            ->whereBetween('entrou_em', [$mes, $fim])
            ->whereHas('tarefa', $restringirTarefa)
            ->with('tarefa.sistema', 'tarefa.responsavel')
            ->get()
            ->unique('tarefa_id')
            ->values();

        // Ciclo = da largada real (`iniciada_em`, com `created_at` de rede
        // para tarefa antiga sem o carimbo) até o evento de conclusão.
        // `abs` porque o `diffInDays` do Carbon 3 vem com sinal — mesma
        // anotação do `ClienteController`.
        $cicloMedioDias = $conclusoes
            ->filter(fn ($evento) => $evento->tarefa !== null)
            ->map(fn ($evento) => abs($evento->entrou_em
                ->diffInDays($evento->tarefa->iniciada_em ?? $evento->tarefa->created_at)))
            ->avg();

        $porEtapa = Tarefa::whereNotIn('status', Tarefa::STATUS_TERMINAIS)
            ->where($restringirTarefa)
            ->selectRaw('status, COUNT(*) as qtd')
            ->groupBy('status')
            ->pluck('qtd', 'status');

        // As seis etapas SEMPRE, na ordem do quadro, mesmo zeradas: etapa
        // ausente se leria como etapa que não existe, não como etapa vazia.
        $quadroPorEtapa = collect(Tarefa::STATUS)
            ->except(Tarefa::STATUS_TERMINAIS)
            ->map(fn ($rotulo, $status) => [
                'status' => $status,
                'rotulo' => $rotulo,
                'quantidade' => (int) ($porEtapa[$status] ?? 0),
            ])
            ->values()
            ->all();

        $rankingSistemas = $this->ranking(
            $conclusoes->groupBy(fn ($evento) => $evento->tarefa?->sistema?->nome ?? 'Sem sistema')
                ->map(fn ($eventos, $nome) => ['nome' => $nome, 'valor' => (float) $eventos->count()])
                ->values(),
            'accent'
        );

        $rankingResponsaveis = $this->ranking(
            $conclusoes->groupBy(fn ($evento) => $evento->tarefa?->responsavel?->name ?? 'Sem responsável')
                ->map(fn ($eventos, $nome) => ['nome' => $nome, 'valor' => (float) $eventos->count()])
                ->values(),
            'good'
        );

        // Onde o tempo mora: a média de permanência em cada etapa, pelos
        // eventos JÁ FECHADOS (`duracao_segundos` só existe quando a tarefa
        // saiu da etapa). Histórico inteiro, não a competência — permanência
        // atravessa meses, e cortar por mês contaria só as estadias curtas.
        $duracaoPorEtapa = TarefaEvento::whereNotNull('duracao_segundos')
            ->whereIn('para_status', array_keys(collect(Tarefa::STATUS)->except(Tarefa::STATUS_TERMINAIS)->all()))
            ->whereHas('tarefa', $restringirTarefa)
            ->selectRaw('para_status, AVG(duracao_segundos) as media')
            ->groupBy('para_status')
            ->pluck('media', 'para_status');

        $tempoPorEtapa = collect(Tarefa::STATUS)
            ->except(Tarefa::STATUS_TERMINAIS)
            ->map(fn ($rotulo, $status) => [
                'status' => $status,
                'rotulo' => $rotulo,
                'dias' => isset($duracaoPorEtapa[$status]) ? (float) $duracaoPorEtapa[$status] / 86400 : null,
            ])
            ->values()
            ->all();

        // Reprovação em portão: o exame devolveu a tarefa para a bancada —
        // é a medida de retrabalho da competência.
        $devolvidasQtd = TarefaEvento::whereIn('de_status', Tarefa::PORTOES)
            ->where('para_status', 'em_desenvolvimento')
            ->whereBetween('entrou_em', [$mes, $fim])
            ->whereHas('tarefa', $restringirTarefa)
            ->count();

        return [
            'concluidasQtd' => $conclusoes->count(),
            'devolvidasQtd' => $devolvidasQtd,
            'tempoPorEtapa' => $tempoPorEtapa,
            'conclusoes' => $conclusoes,
            'cicloMedioDias' => $cicloMedioDias,
            'criadasQtd' => Tarefa::whereBetween('created_at', [$mes, $fim])->where($restringirTarefa)->count(),
            'emAndamentoQtd' => Tarefa::whereIn('status', ['em_desenvolvimento', 'em_revisao', 'em_staging', 'pronta_producao'])
                ->where($restringirTarefa)->count(),
            'naFilaQtd' => Tarefa::whereIn('status', ['aberta', 'backlog'])->where($restringirTarefa)->count(),
            'quadroPorEtapa' => $quadroPorEtapa,
            'rankingSistemas' => $rankingSistemas,
            'rankingResponsaveis' => $rankingResponsaveis,
        ];
    }

    /**
     * A administração vista de fora: quem usa (contas por perfil), o que está
     * instalado (base por sistema) e o que se mexeu (auditoria da competência).
     * O recorte de perfil vale para os números de conta; recurso e usuário
     * valem para a auditoria. A base instalada é foto — não tem o que filtrar.
     */
    private function secaoSistema(Carbon $mes, array $filtros): array
    {
        $fim = $mes->copy()->endOfMonth();
        $perfilId = $filtros['perfil'] !== '' ? (int) $filtros['perfil'] : null;

        // Um usuário com dois perfis conta duas vezes aqui — o total desta
        // lista é de VÍNCULOS, e a nota do painel avisa. O card ao lado, esse
        // sim, conta contas.
        $rankingPerfis = $this->ranking(
            Perfil::when($perfilId, fn ($q, $id) => $q->where('id', $id))
                ->withCount(['users' => fn ($q) => $q->where('ativo', true)])
                ->get()
                ->map(fn ($perfil) => ['nome' => $perfil->nome, 'valor' => (float) $perfil->users_count]),
            'brand'
        );

        $rankingBaseInstalada = $this->ranking(
            $this->indicadores->rankingSistemas()
                ->map(fn ($linha) => ['nome' => $linha['sistema']->nome, 'valor' => (float) $linha['clientes_ativos']]),
            'accent'
        );

        $auditoriaDaCompetencia = fn () => Auditoria::whereBetween('created_at', [$mes, $fim])
            ->when($filtros['recurso'], fn ($q, $recurso) => $q->where('recurso', $recurso))
            ->when($filtros['usuario'] !== '', fn ($q) => $q->where('user_id', (int) $filtros['usuario']));

        $rankingAuditoria = $this->ranking(
            $auditoriaDaCompetencia()
                ->selectRaw('recurso, COUNT(*) as qtd')
                ->groupBy('recurso')
                ->get()
                ->map(fn ($linha) => ['nome' => $linha->recurso, 'valor' => (float) $linha->qtd]),
            'chart-out'
        );

        // O mesmo rastro pelo OUTRO eixo: recurso diz onde mexeram, ação diz
        // o que fizeram — e é o par que responde "muito editar ou muito
        // excluir?" sem abrir a tela de Auditoria.
        $rankingAcoesAuditoria = $this->ranking(
            $auditoriaDaCompetencia()
                ->selectRaw('acao, COUNT(*) as qtd')
                ->groupBy('acao')
                ->get()
                ->map(fn ($linha) => ['nome' => ucfirst($linha->acao), 'valor' => (float) $linha->qtd]),
            'good'
        );

        $ultimasAcoes = $auditoriaDaCompetencia()
            ->latest()
            ->limit(12)
            ->get(['usuario_nome', 'recurso', 'acao', 'descricao', 'created_at']);

        // `groupByRaw` da MESMA expressão do select: agrupar pela coluna crua
        // separaria NULL de string vazia e o ranking mostraria dois "Sem UF".
        $rankingUfs = $this->ranking(
            Cliente::where('ativo', true)
                ->selectRaw("COALESCE(NULLIF(uf, ''), 'Sem UF') as regiao, COUNT(*) as qtd")
                ->groupByRaw("COALESCE(NULLIF(uf, ''), 'Sem UF')")
                ->get()
                ->map(fn ($linha) => ['nome' => $linha->regiao, 'valor' => (float) $linha->qtd]),
            'amber'
        );

        return [
            'sistemasAtivos' => $this->indicadores->sistemasAtivos(),
            'clientesAtivos' => $this->indicadores->clientesAtivos(),
            'revendasAtivas' => $this->indicadores->revendasAtivas(),
            'usuariosAtivos' => User::where('ativo', true)
                ->when($perfilId, fn ($q, $id) => $q->whereHas('perfis', fn ($p) => $p->where('perfis.id', $id)))
                ->count(),
            'acoesAuditoria' => (int) $rankingAuditoria['total'],
            'rankingPerfis' => $rankingPerfis,
            'rankingBaseInstalada' => $rankingBaseInstalada,
            'rankingAuditoria' => $rankingAuditoria,
            'rankingAcoesAuditoria' => $rankingAcoesAuditoria,
            'rankingUfs' => $rankingUfs,
            'ultimasAcoes' => $ultimasAcoes,
        ];
    }

    /**
     * O relatório em forma neutra — título, indicadores e blocos tabulares —
     * que o CSV e o PDF consomem sem saber de qual seção vieram. Uma forma só
     * para os dois formatos: é o que impede o CSV de dizer um número e o PDF
     * outro.
     *
     * @return array{titulo: string, kpis: list<array{0: string, 1: string}>, blocos: list<array{titulo: string, colunas: list<string>, linhas: list<list<string>>}>}
     */
    private function relatorioExportavel(string $secao, string $competencia, array $dados): array
    {
        $reais = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');
        $inteiro = fn (float|int $v) => number_format($v, 0, ',', '.');

        [$kpis, $blocos] = match ($secao) {
            'comercial' => [
                [
                    ['Leads em aberto', $inteiro($dados['kpis']['abertos'])],
                    ['Pipeline em aberto', $reais($dados['kpis']['pipeline_valor'])],
                    ['Fechados na competência', $inteiro($dados['fechadosQtd'])],
                    ['Valor fechado', $reais($dados['fechadosValor'])],
                    ['Perdidos na competência', $inteiro($dados['perdidosQtd'])],
                    ['Valor perdido', $reais($dados['perdidosValor'])],
                    ['Novos clientes na competência', $inteiro($dados['novosClientesQtd'])],
                    ['Taxa de conversão', number_format($dados['kpis']['taxa_conversao'], 1, ',', '.').'%'],
                ],
                [
                    [
                        'titulo' => 'Leads em aberto por temperatura',
                        'colunas' => ['Temperatura', 'Leads', 'Valor'],
                        'linhas' => collect($dados['temperaturas'])
                            ->map(fn ($t) => [$t['rotulo'], $inteiro($t['quantidade']), $reais($t['valor'])])->all(),
                    ],
                    $this->blocoDeRanking('Avanço do funil (foto de hoje)', $dados['rankingFunil'], 'Estágio'),
                    $this->blocoDeRanking('Leads em aberto por origem', $dados['rankingOrigens'], 'Origem'),
                    $this->blocoDeRanking('Vendas por vendedor (na competência)', $dados['rankingVendedores'], 'Vendedor', 'reais'),
                    $this->blocoDeRanking('Perdas por motivo (na competência)', $dados['rankingMotivosPerda'], 'Motivo'),
                    [
                        'titulo' => 'Maiores negócios em aberto',
                        'colunas' => ['Lead', 'Estágio', 'Vendedor', 'Dias no estágio', 'Valor estimado'],
                        'linhas' => $dados['maioresLeads']->map(fn ($lead) => [
                            $lead->nome,
                            Lead::ESTAGIOS[$lead->estagio] ?? $lead->estagio,
                            $lead->vendedor?->name ?? 'Sem vendedor',
                            $inteiro($lead->diasNoEstagio()),
                            $reais((float) $lead->valor_estimado),
                        ])->all(),
                    ],
                ],
            ],
            'financeiro' => [
                [
                    ['Receita recorrente'.($dados['mrrContratado'] ? ' (contratado)' : ''), $reais($dados['mrr'])],
                    ['Entradas do mês', $reais($dados['entradasMes'])],
                    ['Saídas do mês', $reais($dados['saidasMes'])],
                    ['Resultado do mês', $reais($dados['resultadoMes'])],
                    ['Saldo ao fim da competência', $reais($dados['saldoTotal'])],
                ],
                [
                    [
                        'titulo' => 'Entradas x saídas — ano de '.substr($competencia, 0, 4),
                        'colunas' => ['Mês', 'Entradas', 'Saídas', 'Origem'],
                        'linhas' => collect($dados['historico'])->map(fn ($m) => [
                            $m['label'], $reais($m['entradas']), $reais($m['saidas']),
                            ($m['previsto'] ?? false) ? 'previsto' : 'realizado',
                        ])->all(),
                    ],
                    [
                        'titulo' => 'A receber em aberto (acumulado)',
                        'colunas' => ['Indicador', 'Valor'],
                        'linhas' => [
                            ['Total em aberto', $reais((float) $dados['aReceber']->total)],
                            ['Vencido', $reais((float) $dados['aReceber']->vencido)],
                            ['Títulos', $inteiro($dados['aReceber']->qtd)],
                        ],
                    ],
                    [
                        'titulo' => 'A pagar em aberto (acumulado)',
                        'colunas' => ['Indicador', 'Valor'],
                        'linhas' => [
                            ['Total em aberto', $reais((float) $dados['aPagar']->total)],
                            ['Vencido', $reais((float) $dados['aPagar']->vencido)],
                            ['Títulos', $inteiro($dados['aPagar']->qtd)],
                        ],
                    ],
                    [
                        'titulo' => 'A receber por faixa de vencimento',
                        'colunas' => ['Faixa', 'Valor'],
                        'linhas' => collect($dados['faixasAReceber'])
                            ->map(fn ($faixa) => [$faixa['rotulo'], $reais($faixa['valor'])])->values()->all(),
                    ],
                    [
                        'titulo' => 'Maiores títulos vencidos (a receber)',
                        'colunas' => ['Título', 'Origem', 'Venceu em', 'Valor'],
                        'linhas' => $dados['maioresVencidos']->map(fn ($c) => [
                            $c->descricao,
                            $c->revenda?->nome ?? $c->cliente?->nome_exibicao ?? 'Sem origem',
                            Carbon::parse($c->data_vencimento)->format('d/m/Y'),
                            $reais((float) $c->valor),
                        ])->all(),
                    ],
                    $this->blocoDeRanking('Receita da competência por tipo', $dados['rankingTiposReceita'], 'Tipo', 'reais'),
                    $this->blocoDeRanking('Despesa por centro de custo (lançado na competência)', $dados['rankingCentrosDeCusto'], 'Centro de custo', 'reais'),
                ],
            ],
            'desenvolvimento' => [
                array_values(array_filter([
                    ['Concluídas na competência', $inteiro($dados['concluidasQtd'])],
                    $dados['cicloMedioDias'] !== null
                        ? ['Ciclo médio (dias)', $inteiro($dados['cicloMedioDias'])]
                        : null,
                    ['Criadas na competência', $inteiro($dados['criadasQtd'])],
                    ['Devolvidas de portão na competência', $inteiro($dados['devolvidasQtd'])],
                    ['Em andamento agora', $inteiro($dados['emAndamentoQtd'])],
                    ['Na fila agora', $inteiro($dados['naFilaQtd'])],
                ])),
                [
                    [
                        'titulo' => 'O quadro agora',
                        'colunas' => ['Etapa', 'Tarefas'],
                        'linhas' => collect($dados['quadroPorEtapa'])
                            ->map(fn ($e) => [$e['rotulo'], $inteiro($e['quantidade'])])->all(),
                    ],
                    [
                        'titulo' => 'Permanência média por etapa (histórico)',
                        'colunas' => ['Etapa', 'Dias'],
                        'linhas' => collect($dados['tempoPorEtapa'])
                            ->map(fn ($e) => [$e['rotulo'], $e['dias'] !== null ? number_format($e['dias'], 1, ',', '.') : 'sem registro'])
                            ->all(),
                    ],
                    $this->blocoDeRanking('Concluídas por sistema (na competência)', $dados['rankingSistemas'], 'Sistema'),
                    $this->blocoDeRanking('Concluídas por responsável (na competência)', $dados['rankingResponsaveis'], 'Responsável'),
                    [
                        'titulo' => 'Concluídas na competência',
                        'colunas' => ['Tarefa', 'Sistema', 'Responsável', 'Concluída em', 'Ciclo (dias)'],
                        'linhas' => $dados['conclusoes']->map(fn ($evento) => [
                            $evento->tarefa?->titulo ?? '—',
                            $evento->tarefa?->sistema?->nome ?? 'Sem sistema',
                            $evento->tarefa?->responsavel?->name ?? 'Sem responsável',
                            $evento->entrou_em->format('d/m/Y'),
                            $evento->tarefa !== null
                                ? $inteiro(abs($evento->entrou_em->diffInDays($evento->tarefa->iniciada_em ?? $evento->tarefa->created_at)))
                                : '—',
                        ])->all(),
                    ],
                ],
            ],
            'sistema' => [
                [
                    ['Sistemas ativos', $inteiro($dados['sistemasAtivos'])],
                    ['Clientes ativos', $inteiro($dados['clientesAtivos'])],
                    ['Revendas ativas', $inteiro($dados['revendasAtivas'])],
                    ['Usuários ativos', $inteiro($dados['usuariosAtivos'])],
                    ['Ações de auditoria na competência', $inteiro($dados['acoesAuditoria'])],
                ],
                [
                    $this->blocoDeRanking('Base instalada por sistema', $dados['rankingBaseInstalada'], 'Sistema'),
                    $this->blocoDeRanking('Clientes ativos por UF', $dados['rankingUfs'], 'UF'),
                    $this->blocoDeRanking('Usuários por perfil (vínculos)', $dados['rankingPerfis'], 'Perfil'),
                    $this->blocoDeRanking('Auditoria por recurso (na competência)', $dados['rankingAuditoria'], 'Recurso'),
                    $this->blocoDeRanking('Auditoria por ação (na competência)', $dados['rankingAcoesAuditoria'], 'Ação'),
                    [
                        'titulo' => 'Últimas ações registradas (na competência)',
                        'colunas' => ['Quando', 'Quem', 'Ação', 'Recurso', 'Descrição'],
                        'linhas' => $dados['ultimasAcoes']->map(fn ($acao) => [
                            $acao->created_at->format('d/m/Y H:i'),
                            $acao->usuario_nome ?? 'Sistema',
                            ucfirst($acao->acao),
                            $acao->recurso,
                            (string) $acao->descricao,
                        ])->all(),
                    ],
                ],
            ],
        };

        return [
            'titulo' => 'Relatório '.self::SECOES[$secao],
            'kpis' => $kpis,
            'blocos' => $blocos,
        ];
    }

    /**
     * Um ranking em forma de tabela exportável — as mesmas três leituras das
     * linhas do `<x-ranking>` (posição, valor, participação), porque o arquivo
     * é a tela levada embora.
     */
    private function blocoDeRanking(string $titulo, array $ranking, string $rotuloNome, string $formato = 'numero'): array
    {
        $formatar = fn (float $v) => $formato === 'reais'
            ? 'R$ '.number_format($v, 2, ',', '.')
            : number_format($v, 0, ',', '.');

        return [
            'titulo' => $titulo,
            'colunas' => ['#', $rotuloNome, $formato === 'reais' ? 'Valor' : 'Quantidade', 'Participação'],
            'linhas' => collect($ranking['itens'])->map(fn ($item) => [
                $item['posicao'],
                $item['nome'],
                $formatar($item['valor']),
                number_format($item['share'] * 100, 1, ',', '.').'%',
            ])->all(),
        ];
    }

    /**
     * `;` como separador e BOM no começo porque o destino é o Excel em
     * português — mesma decisão, pelo mesmo motivo, do CSV do Faturamento.
     */
    private function exportarCsv(array $relatorio, array $recortes, string $secao, string $competencia): StreamedResponse
    {
        return response()->streamDownload(function () use ($relatorio, $recortes, $competencia) {
            $saida = fopen('php://output', 'w');

            fwrite($saida, "\xEF\xBB\xBF");

            fputcsv($saida, [$relatorio['titulo']], ';');
            fputcsv($saida, ['Competência', Carbon::createFromFormat('Y-m', $competencia)->format('m/Y')], ';');
            if ($recortes !== []) {
                fputcsv($saida, ['Recorte', collect($recortes)->pluck('rotulo')->implode(' | ')], ';');
            }

            fwrite($saida, "\n");
            fputcsv($saida, ['Indicador', 'Valor'], ';');
            foreach ($relatorio['kpis'] as $kpi) {
                fputcsv($saida, $kpi, ';');
            }

            foreach ($relatorio['blocos'] as $bloco) {
                fwrite($saida, "\n");
                fputcsv($saida, [$bloco['titulo']], ';');
                fputcsv($saida, $bloco['colunas'], ';');
                foreach ($bloco['linhas'] as $linha) {
                    fputcsv($saida, $linha, ';');
                }
            }

            fclose($saida);
        }, "relatorio-{$secao}-{$competencia}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function exportarPdf(array $relatorio, array $recortes, string $secao, string $competencia)
    {
        // A MESMA view da prévia, em modo 'pdf': o que a pessoa conferiu no
        // navegador é o que o dompdf desenha.
        return Pdf::loadView('relatorios.documento', [
            'modo' => 'pdf',
            'relatorio' => $relatorio,
            'recortes' => $recortes,
            'competencia' => $competencia,
            'secao' => $secao,
            'geradoEm' => now(),
        ])->download("relatorio-{$secao}-{$competencia}.pdf");
    }
}
