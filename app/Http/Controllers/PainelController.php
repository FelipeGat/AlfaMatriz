<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\IndicadoresService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PainelController extends Controller
{
    /**
     * Os indicadores que aparecem em mais de uma tela saem daqui, não de uma
     * contagem local. Duplicar a regra é como os números começam a divergir:
     * basta alguém ajustar o critério de "ativo" num painel e esquecer o
     * outro, e quem usa deixa de saber qual tela está certa.
     */
    public function __construct(private readonly IndicadoresService $indicadores) {}

    public function index(Request $request)
    {
        $this->bloquearVisaoDaMatriz();

        // O mês que os cinco cards do topo mostram. Livre para navegar — "?
        // competencia=2026-03" volta a tela para março —, e não só o mês
        // corrente como era antes: sem isso não havia como olhar um mês
        // fechado sem sair da tela.
        $competencia = $this->competenciaSelecionada($request);
        $mesSelecionado = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();

        // A MESMA regra do Centro de Controle, vinda da mesma origem: faturado
        // quando o fechamento rodou, contratado enquanto não rodou. Enquanto
        // esta tela somava só cobrança gerada, as duas mostravam números
        // diferentes sob o rótulo "Receita recorrente" no mesmo dia.
        $mrr = $this->indicadores->mrrDaCompetencia($competencia);
        $mrrContratado = $this->indicadores->mrrEhContratado($competencia);
        $arr = $mrr * 12;

        // `saldoAoFimDe()` e não `saldoEmCaixa()`: no mês corrente os dois
        // devolvem o mesmo número, mas navegar para um mês fechado precisa do
        // saldo QUE A CONTA TINHA naquele fim de mês, não o saldo de hoje.
        $saldoTotal = $this->indicadores->saldoAoFimDe($mesSelecionado);
        $entradasMes = $this->indicadores->entradasDoMes($mesSelecionado);
        $saidasMes = $this->indicadores->saidasDoMes($mesSelecionado);

        $receitasPendentes = Cobranca::where('status', 'pendente')
            ->orderBy('data_vencimento')
            ->with(['revenda', 'cliente'])
            ->limit(5)
            ->get();

        $despesasPendentes = ContaPagar::where('status', 'em_aberto')
            ->orderBy('data_vencimento')
            ->with('fornecedor')
            ->limit(5)
            ->get();

        // Base instalada continua a foto de HOJE, não da competência
        // navegada: "quantos clientes eu atendo" é pergunta do presente, e
        // reconstituir esse número mês a mês é outra tela (ver
        // `serieDeClientesAtivos()`, que não é o que os cards aqui usam.
        $totalRevendas = $this->indicadores->revendasAtivas();
        $totalClientes = $this->indicadores->clientesAtivos();
        $clientesDiretos = $this->indicadores->clientesDiretos();

        [$graficoInicio, $graficoFim] = $this->periodoDoGrafico($request);
        $historico = $this->indicadores->serieDeCaixaEntre($graficoInicio, $graficoFim);

        $rotuloGrafico = $graficoInicio->equalTo($graficoFim)
            ? ucfirst($graficoInicio->translatedFormat('M/Y'))
            : ucfirst($graficoInicio->translatedFormat('M/Y')).' – '.ucfirst($graficoFim->translatedFormat('M/Y'));

        return view('dashboard', compact(
            'mrr', 'arr', 'mrrContratado', 'saldoTotal', 'entradasMes', 'saidasMes',
            'receitasPendentes', 'despesasPendentes',
            'totalRevendas', 'totalClientes', 'clientesDiretos', 'historico'
        ) + [
            'competencia' => $competencia,
            'competenciaAnterior' => $mesSelecionado->copy()->subMonth()->format('Y-m'),
            'competenciaProxima' => $mesSelecionado->copy()->addMonth()->format('Y-m'),
            'competenciaEhAtual' => $competencia === now()->format('Y-m'),
            'filtroGrafico' => $this->filtroGraficoSelecionado($request),
            'graficoDe' => $graficoInicio->format('Y-m'),
            'graficoAte' => $graficoFim->format('Y-m'),
            'rotuloGrafico' => $rotuloGrafico,
            // As curvas saem do serviço, e voltam vazias quando não têm o que
            // dizer — o card se cala em vez de desenhar uma reta no zero.
            // Continuam sempre "últimos 6 meses até hoje": são a tendência
            // recente do card, não a competência que o topo está navegando.
            'serieMrr' => $this->indicadores->serieDoMrr(6),
            'serieSaldo' => $this->indicadores->serieDoSaldo(6),
            'serieEntradas' => $this->indicadores->serieDeEntradas(6),
            'serieSaidas' => $this->indicadores->serieDeSaidas(6),
        ]);
    }

    /**
     * A competência dos cinco cards do topo — "AAAA-MM" validado, ou o mês
     * corrente quando a URL não pede um em especial ou pede algo malformado.
     */
    private function competenciaSelecionada(Request $request): string
    {
        $valor = $request->query('competencia');

        if (is_string($valor) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $valor)) {
            return $valor;
        }

        return now()->format('Y-m');
    }

    /**
     * As opções rápidas do filtro do gráfico — mês anterior, mês atual ou
     * próximo mês são só o rótulo de um período de UM mês; o personalizado é
     * quem de fato abre o intervalo. Sem opção reconhecida na URL, o padrão é
     * o ano inteiro (AC pedido: "por padrão... os 12 meses do ano, de Janeiro
     * a Dezembro do ano vigente") — e não mais os "últimos 6 meses" fixos.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodoDoGrafico(Request $request): array
    {
        return match ($this->filtroGraficoSelecionado($request)) {
            'mes_anterior' => [now()->startOfMonth()->subMonth(), now()->startOfMonth()->subMonth()],
            'mes_atual' => [now()->startOfMonth(), now()->startOfMonth()],
            'proximo_mes' => [now()->startOfMonth()->addMonth(), now()->startOfMonth()->addMonth()],
            'personalizado' => $this->periodoPersonalizado($request),
            default => [now()->startOfYear(), now()->startOfYear()->addMonths(11)],
        };
    }

    private function filtroGraficoSelecionado(Request $request): string
    {
        $valor = $request->query('grafico');

        return in_array($valor, ['mes_anterior', 'mes_atual', 'proximo_mes', 'personalizado'], true)
            ? $valor
            : 'ano_atual';
    }

    /**
     * O intervalo do "Período personalizado". `grafico_ate` antes de
     * `grafico_de` é trocado, e não erro de validação — quem está escolhendo
     * duas caixas de mês digita fora de ordem sem perceber, e a tela corrige
     * em vez de reclamar.
     *
     * Limitado a 36 meses: sem teto, um intervalo digitado errado (ex.: "de
     * 2020 até 2026") desenharia um gráfico de centenas de barras.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function periodoPersonalizado(Request $request): array
    {
        $de = $request->query('grafico_de');
        $ate = $request->query('grafico_ate');

        $inicio = (is_string($de) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $de))
            ? Carbon::createFromFormat('Y-m', $de)->startOfMonth()
            : now()->startOfYear();

        $fim = (is_string($ate) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ate))
            ? Carbon::createFromFormat('Y-m', $ate)->startOfMonth()
            : now()->startOfMonth();

        if ($fim->lessThan($inicio)) {
            [$inicio, $fim] = [$fim, $inicio];
        }

        if ($inicio->diffInMonths($fim) > 36) {
            $fim = $inicio->copy()->addMonths(36);
        }

        return [$inicio, $fim];
    }

    public function comercial()
    {
        $this->bloquearVisaoDaMatriz();

        // O ranking também vem do serviço: ele aparece aqui e na tela de
        // Sistemas, e é o valor de atacado — o número mais fácil de as duas
        // telas passarem a discordar se cada uma calcular por conta própria.
        $ranking = $this->indicadores->rankingSistemas();
        $sistemas = $ranking->pluck('sistema');

        // Os dois rankings da tela, já em três camadas: o total e o líder no
        // topo, a faixa segmentada no meio e as linhas embaixo. Cada linha
        // conhece a própria participação e o próprio tamanho relativo ao
        // líder — comparar é o motivo desta tela existir.
        //
        // O `ranking()` já ordena por valor, então ordenar antes era trabalho
        // jogado fora.
        $rankingClientes = $this->ranking(
            $ranking->map(fn ($l) => ['nome' => $l['sistema']->nome, 'valor' => (float) $l['clientes_ativos']]),
            'accent'
        );
        $rankingValor = $this->ranking(
            $ranking->map(fn ($l) => ['nome' => $l['sistema']->nome, 'valor' => (float) $l['valor_estimado']]),
            'chart-out'
        );

        $totalClientesAtivos = $this->indicadores->clientesAtivos();
        $totalSistemasAtivos = $this->indicadores->sistemasAtivos();
        $totalRevendasAtivas = $this->indicadores->revendasAtivas();
        $mrrEstimado = $this->indicadores->mrrAtacado();

        // Agrupa por ID, não por nome: duas revendas homônimas viravam uma
        // linha só, somando a base das duas sob um nome que não distingue
        // qual é qual. O nome continua sendo o rótulo, mas não é a chave.
        //
        // Cliente de revenda desativada continua contado — ele está ativo, e o
        // total desta lista precisa fechar com o card "Clientes ativos". Por
        // isso a lista pode trazer mais linhas do que o card de revendas ATIVAS
        // anuncia: são coisas diferentes, base de clientes e cadastro vigente.
        $porRevenda = Cliente::where('ativo', true)
            ->with('revenda')
            ->get()
            ->groupBy(fn ($c) => $c->revenda_id ?? 0)
            ->map(fn ($clientes) => [
                'nome' => $clientes->first()->revenda?->nome ?? 'Venda direta',
                'valor' => (float) $clientes->count(),
            ])
            ->values();

        // Categoria dos sistemas ATIVOS — `$sistemas` vem do ranking, que já
        // não traz produto desativado.
        $porCategoria = $sistemas->groupBy('categoria')->map->count();

        // Os dois painéis de apoio usam a mesma gramática das linhas do
        // ranking, para a tela inteira se ler do mesmo jeito.
        $rankingRevendas = $this->ranking($porRevenda, 'accent');
        $rankingCategorias = $this->ranking(
            $porCategoria->map(fn ($qtd, $nome) => ['nome' => $nome ?: 'Sem categoria', 'valor' => (float) $qtd])
                ->values(),
            'brand'
        );

        // As quatro curvas saem do serviço, e cada uma volta VAZIA quando não
        // tem o que dizer — base cujas entradas caem todas num mês só, ou MRR
        // sem dois fechamentos para comparar. O card sabe se esconder a linha;
        // o que ele não pode é desenhar um degrau que afirma "tudo apareceu de
        // uma vez", que é o que sairia de `created_at` numa base importada.
        $novosClientes = $this->indicadores->novosClientesNoMes();

        return view('dashboard-comercial', compact(
            'totalClientesAtivos', 'totalSistemasAtivos', 'totalRevendasAtivas',
            'mrrEstimado', 'novosClientes',
            'rankingClientes', 'rankingValor', 'rankingRevendas', 'rankingCategorias'
        ) + [
            'serieClientes' => $this->indicadores->serieDeClientesAtivos(6),
            'serieSistemas' => $this->indicadores->serieDeSistemasAtivos(6),
            'serieRevendas' => $this->indicadores->serieDeRevendasAtivas(6),
            'serieMrr' => $this->indicadores->serieDeAtacadoFaturado(6),
        ]);
    }

    /**
     * Monta um ranking comparável a partir de uma lista de `nome`/`valor`.
     *
     * `share` é a fatia do total (o que a faixa segmentada desenha) e
     * `largura` é o tamanho relativo AO LÍDER (o que a barra da linha
     * desenha). São duas perguntas diferentes — "quanto disso é meu?" e
     * "quão longe estou do primeiro?" — e usar uma no lugar da outra achata
     * o ranking inteiro.
     *
     * @param  \Illuminate\Support\Collection<int, array{nome: string, valor: float}>  $itens
     */
    private function ranking(\Illuminate\Support\Collection $itens, string $cor): array
    {
        $itens = $itens->filter(fn ($i) => $i['valor'] > 0)->sortByDesc('valor')->values();

        $total = (float) $itens->sum('valor');
        $lider = $itens->first();
        $maior = (float) ($lider['valor'] ?? 0);

        return [
            'cor' => $cor,
            'total' => $total,
            'lider' => $lider ? [
                'nome' => $lider['nome'],
                'valor' => $lider['valor'],
                'share' => $total > 0 ? $lider['valor'] / $total : 0,
            ] : null,
            'itens' => $itens->map(fn ($item, $i) => $item + [
                'posicao' => str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'share' => $total > 0 ? $item['valor'] / $total : 0,
                'largura' => $maior > 0 ? $item['valor'] / $maior : 0,
            ])->all(),
        ];
    }

}
