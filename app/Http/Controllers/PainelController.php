<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\IndicadoresService;

class PainelController extends Controller
{
    /**
     * Os indicadores que aparecem em mais de uma tela saem daqui, não de uma
     * contagem local. Duplicar a regra é como os números começam a divergir:
     * basta alguém ajustar o critério de "ativo" num painel e esquecer o
     * outro, e quem usa deixa de saber qual tela está certa.
     */
    public function __construct(private readonly IndicadoresService $indicadores) {}

    public function index()
    {
        $this->bloquearVisaoDaMatriz();

        // A MESMA regra do Centro de Controle, vinda da mesma origem: faturado
        // quando o fechamento rodou, contratado enquanto não rodou. Enquanto
        // esta tela somava só cobrança gerada, as duas mostravam números
        // diferentes sob o rótulo "Receita recorrente" no mesmo dia.
        $mrr = $this->indicadores->mrrDaCompetencia();
        $mrrContratado = $this->indicadores->mrrEhContratado();
        $arr = $mrr * 12;

        $saldoTotal = $this->indicadores->saldoEmCaixa();
        $entradasMes = $this->indicadores->entradasDoMes();
        $saidasMes = $this->indicadores->saidasDoMes();

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

        $totalRevendas = $this->indicadores->revendasAtivas();
        $totalClientes = $this->indicadores->clientesAtivos();
        $clientesDiretos = $this->indicadores->clientesDiretos();

        $historico = $this->historicoSeisMeses();

        return view('dashboard', compact(
            'mrr', 'arr', 'mrrContratado', 'saldoTotal', 'entradasMes', 'saidasMes',
            'receitasPendentes', 'despesasPendentes',
            'totalRevendas', 'totalClientes', 'clientesDiretos', 'historico'
        ) + [
            // As curvas saem do serviço, e voltam vazias quando não têm o que
            // dizer — o card se cala em vez de desenhar uma reta no zero.
            'serieMrr' => $this->indicadores->serieDoMrr(6),
            'serieSaldo' => $this->indicadores->serieDoSaldo(6),
            'serieEntradas' => $this->indicadores->serieDeEntradas(6),
            'serieSaidas' => $this->indicadores->serieDeSaidas(6),
        ]);
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

    /**
     * O gráfico de entradas x saídas.
     *
     * Os valores saem do serviço, não de uma consulta própria: o último mês do
     * gráfico é o mesmo número dos cards "Entradas do mês" e "Saídas do mês"
     * logo acima dele. Enquanto este método tinha a própria cópia da conta,
     * eram duas implementações do mesmo valor esperando divergir — e nenhuma
     * das duas lia o livro-caixa.
     */
    private function historicoSeisMeses(): array
    {
        return collect(range(5, 0))
            // `startOfMonth()` antes do `subMonths()` — ver IndicadoresService:
            // subtrair a partir do dia 31 transborda e desalinha a janela.
            ->map(fn ($i) => now()->startOfMonth()->subMonths($i))
            ->map(fn ($mes) => [
                'label' => ucfirst($mes->translatedFormat('M/y')),
                'entradas' => $this->indicadores->entradasDoMes($mes),
                'saidas' => $this->indicadores->saidasDoMes($mes),
            ])
            ->all();
    }
}
