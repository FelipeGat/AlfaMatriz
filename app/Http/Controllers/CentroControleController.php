<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Lead;
use App\Models\MovimentacaoFinanceira;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Centro de Controle — a tela que responde "o que precisa de mim hoje".
 *
 * Tudo aqui sai do que já está no banco. Nenhum número é digitado: a fila de
 * ação é o resultado de procurar pendência de verdade, e as séries dos cards
 * são recompostas mês a mês a partir dos lançamentos.
 */
class CentroControleController extends Controller
{
    /** Quantos meses cada minitendência mostra. */
    private const MESES_DA_SERIE = 6;

    public function index()
    {
        $hoje = now()->startOfDay();

        return view('centro-controle.index', [
            'saudacao' => $this->saudacao(),
            'cards' => $this->cards($hoje),
            'fila' => $this->filaDeAcao($hoje),
            'origemMrr' => $this->origemDoMrr(),
            'proximos' => $this->proximosSeteDias($hoje),
            'pipeline' => $this->pipeline(),
            'entraram' => Cliente::where('created_at', '>=', now()->subDays(7))->latest()->limit(5)->get(),
        ]);
    }

    // ── Saudação ──────────────────────────────────────────────────────────

    private function saudacao(): array
    {
        $agora = now();

        return [
            'data' => $agora->translatedFormat('D · d M Y'),
            'periodo' => match (true) {
                $agora->hour < 12 => 'Bom dia',
                $agora->hour < 18 => 'Boa tarde',
                default => 'Boa noite',
            },
            'nome' => explode(' ', trim(auth()->user()->name))[0],
            // O fechamento do mês é a data que organiza o trabalho de todo mundo.
            'diasParaFechamento' => (int) $agora->copy()->startOfDay()->diffInDays($agora->copy()->endOfMonth()->startOfDay()),
            'competencia' => $agora->format('m/Y'),
        ];
    }

    // ── Cards de destaque ─────────────────────────────────────────────────

    /** @return array<int, array<string, mixed>> */
    private function cards(Carbon $hoje): array
    {
        $mrrAtual = $this->mrrDaCompetencia(now()->format('Y-m'));
        $mrrAnterior = $this->mrrDaCompetencia(now()->subMonth()->format('Y-m'));

        $saldo = (float) ContaFinanceira::where('ativo', true)->sum('saldo');

        $atrasadas = Cobranca::where('status', 'pendente')
            ->whereDate('data_vencimento', '<', $hoje)
            ->get(['valor', 'data_vencimento']);

        $clientesAtivos = Cliente::where('ativo', true)->count();
        $novosNoMes = Cliente::where('created_at', '>=', now()->startOfMonth())->count();

        return [
            [
                'rotulo' => 'Receita recorrente',
                'valor' => $this->emReais($mrrAtual),
                'delta' => $this->variacao($mrrAtual, $mrrAnterior, now()->subMonth()->translatedFormat('M')),
                'sinal' => $mrrAtual >= $mrrAnterior ? 'bom' : 'ruim',
                'acento' => 'accent',
                'icone' => 'trending-up',
                'serie' => $this->serieDoMrr(),
            ],
            [
                'rotulo' => 'Saldo em caixa',
                'valor' => $this->emReais($saldo),
                'delta' => $this->folgaDeCaixa($saldo),
                'sinal' => $saldo >= 0 ? 'neutro' : 'ruim',
                'acento' => 'brand',
                'icone' => 'banknotes',
                'serie' => $this->serieDoSaldo($saldo),
            ],
            [
                'rotulo' => 'Atrasado',
                'valor' => $this->emReais($atrasadas->sum('valor')),
                'delta' => $atrasadas->isEmpty()
                    ? 'nada em atraso'
                    : $atrasadas->count().' título'.($atrasadas->count() > 1 ? 's' : '')
                        .' · até '.(int) $hoje->diffInDays(Carbon::parse($atrasadas->min('data_vencimento'))).'d',
                'sinal' => $atrasadas->isEmpty() ? 'bom' : 'ruim',
                'acento' => 'crit',
                'icone' => 'alert-triangle',
                'serie' => $this->serieDoAtraso(),
            ],
            [
                'rotulo' => 'Clientes ativos',
                'valor' => number_format($clientesAtivos, 0, ',', '.'),
                'delta' => $novosNoMes > 0 ? '+'.$novosNoMes.' no mês' : 'nenhum novo no mês',
                'sinal' => $novosNoMes > 0 ? 'bom' : 'neutro',
                'acento' => 'amber',
                'icone' => 'users',
                'serie' => $this->serieDeClientes(),
            ],
        ];
    }

    private function mrrDaCompetencia(string $competencia): float
    {
        return (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia)
            ->where('status', '!=', 'cancelado')
            ->sum('valor');
    }

    /** @return list<float> */
    private function serieDoMrr(): array
    {
        return $this->ultimosMeses()
            ->map(fn (Carbon $mes) => $this->mrrDaCompetencia($mes->format('Y-m')))
            ->all();
    }

    /**
     * Saldo no fim de cada mês, recomposto de trás para frente: o saldo de
     * hoje menos tudo que se movimentou depois daquele mês. É o único jeito
     * honesto — não existe foto histórica do saldo gravada.
     *
     * @return list<float>
     */
    private function serieDoSaldo(float $saldoAtual): array
    {
        return $this->ultimosMeses()
            ->map(function (Carbon $mes) use ($saldoAtual) {
                $depois = (float) MovimentacaoFinanceira::where('data', '>', $mes->copy()->endOfMonth()->toDateString())
                    ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'entrada' THEN valor ELSE -valor END), 0) as total")
                    ->value('total');

                return $saldoAtual - $depois;
            })
            ->all();
    }

    /** @return list<float> */
    private function serieDoAtraso(): array
    {
        return $this->ultimosMeses()
            ->map(fn (Carbon $mes) => (float) Cobranca::where('status', 'pendente')
                ->whereBetween('data_vencimento', [$mes->copy()->startOfMonth(), $mes->copy()->endOfMonth()])
                ->sum('valor'))
            ->all();
    }

    /** @return list<float> */
    private function serieDeClientes(): array
    {
        return $this->ultimosMeses()
            ->map(fn (Carbon $mes) => (float) Cliente::where('ativo', true)
                ->where('created_at', '<=', $mes->copy()->endOfMonth())
                ->count())
            ->all();
    }

    /** @return Collection<int, Carbon> */
    private function ultimosMeses(): Collection
    {
        return collect(range(self::MESES_DA_SERIE - 1, 0))
            ->map(fn (int $atras) => now()->copy()->subMonths($atras)->startOfMonth());
    }

    // ── Fila de ação ──────────────────────────────────────────────────────

    /**
     * Cada linha é uma pendência de verdade, com o botão que abre a tela onde
     * ela se resolve. A ordem é a da urgência: crítico primeiro.
     *
     * @return list<array<string, mixed>>
     */
    private function filaDeAcao(Carbon $hoje): array
    {
        $fila = [];

        $receitasAtrasadas = Cobranca::with('revenda')
            ->where('status', 'pendente')
            ->whereDate('data_vencimento', '<', $hoje)
            ->get();

        if ($receitasAtrasadas->isNotEmpty()) {
            $dias = (int) $hoje->diffInDays(Carbon::parse($receitasAtrasadas->min('data_vencimento')));
            $fila[] = [
                'nivel' => 'critico',
                'icone' => 'trending-up',
                'titulo' => $receitasAtrasadas->count().' receita'.($receitasAtrasadas->count() > 1 ? 's' : '').' em atraso',
                'subtitulo' => 'a mais antiga venceu há '.$dias.' dia'.($dias > 1 ? 's' : ''),
                'valor' => $this->emReais($receitasAtrasadas->sum('valor')),
                'acao' => 'Cobrar',
                'rota' => route('cobrancas.index', ['status' => 'pendente']),
            ];
        }

        $despesasVencidas = ContaPagar::where('status', 'em_aberto')
            ->whereDate('data_vencimento', '<', $hoje)
            ->get();

        if ($despesasVencidas->isNotEmpty()) {
            $dias = (int) $hoje->diffInDays(Carbon::parse($despesasVencidas->min('data_vencimento')));
            $fila[] = [
                'nivel' => 'atencao',
                'icone' => 'trending-down',
                'titulo' => $despesasVencidas->count().' despesa'.($despesasVencidas->count() > 1 ? 's' : '').' vencida'.($despesasVencidas->count() > 1 ? 's' : ''),
                'subtitulo' => 'a mais antiga venceu há '.$dias.' dia'.($dias > 1 ? 's' : ''),
                'valor' => $this->emReais($despesasVencidas->sum('valor')),
                'acao' => 'Pagar',
                'rota' => route('contas-pagar.index', ['status' => 'em_aberto']),
            ];
        }

        $saldo = (float) ContaFinanceira::where('ativo', true)->sum('saldo');
        if ($saldo < 0) {
            $fila[] = [
                'nivel' => 'critico',
                'icone' => 'banknotes',
                'titulo' => 'Saldo em caixa negativo',
                'subtitulo' => 'a soma das contas ativas está no vermelho',
                'valor' => $this->emReais($saldo),
                'acao' => 'Abrir caixa',
                'rota' => route('contas-financeiras.index'),
            ];
        }

        $abertos = Lead::whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS)->get();
        $parados = $abertos->filter(fn (Lead $lead) => $lead->diasNoEstagio() > 30);

        if ($parados->isNotEmpty()) {
            $fila[] = [
                'nivel' => 'atencao',
                'icone' => 'clock',
                'titulo' => $parados->count().' lead'.($parados->count() > 1 ? 's' : '').' parado'.($parados->count() > 1 ? 's' : '').' há mais de 30 dias',
                'subtitulo' => 'sem avançar de estágio no funil',
                'valor' => $this->emReais($parados->sum('valor_estimado')),
                'acao' => 'Revisar',
                'rota' => route('leads.index'),
            ];
        }

        foreach ($this->sistemasSemTier() as $sistema) {
            $fila[] = [
                'nivel' => 'marca',
                'icone' => 'cube-outline',
                'titulo' => $sistema->nome.' sem tier de atacado',
                'subtitulo' => 'as unidades ativas dele ficam fora do faturamento',
                'valor' => null,
                'acao' => 'Definir',
                'rota' => route('produtos.index'),
            ];
        }

        $ordem = ['critico' => 0, 'atencao' => 1, 'marca' => 2];
        usort($fila, fn ($a, $b) => $ordem[$a['nivel']] <=> $ordem[$b['nivel']]);

        return $fila;
    }

    /** @return Collection<int, Sistema> */
    private function sistemasSemTier(): Collection
    {
        return Sistema::where('ativo', true)->get()->filter(function (Sistema $sistema) {
            $porRevenda = $sistema->clientes()
                ->where('clientes.ativo', true)
                ->where('cliente_sistema.ativo', true)
                ->get(['clientes.id', 'clientes.revenda_id'])
                ->groupBy('revenda_id');

            foreach ($porRevenda as $revendaId => $clientes) {
                if (! $sistema->tierParaVolume($clientes->count(), $revendaId)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    // ── Origem da receita recorrente ──────────────────────────────────────

    /**
     * Régua de origem do MRR: quanto vem de cada revenda e quanto vem de
     * venda direta, ordenado por valor.
     *
     * A escala é COMUM a todas as linhas — é isso que faz a barra maior ser
     * de fato a origem maior. Ela nunca encolhe abaixo de 35 mil para as
     * linhas-guia de 10k/20k/30k continuarem fazendo sentido, e cresce em
     * degraus de 10 mil quando o maior valor passa disso.
     */
    private function origemDoMrr(): array
    {
        $competencia = now()->format('Y-m');
        $anterior = now()->subMonth()->format('Y-m');

        $linhas = collect();

        foreach (Revenda::where('ativo', true)->get() as $revenda) {
            $linhas->push([
                'nome' => $revenda->nome,
                'valor' => $this->mrrPorOrigem($competencia, $revenda->id),
                'anterior' => $this->mrrPorOrigem($anterior, $revenda->id),
                'clientes' => Cliente::where('revenda_id', $revenda->id)->where('ativo', true)->count(),
                'cor' => 'accent',
            ]);
        }

        $linhas->push([
            'nome' => 'Venda direta',
            'valor' => $this->mrrPorOrigem($competencia, null),
            'anterior' => $this->mrrPorOrigem($anterior, null),
            'clientes' => Cliente::whereNull('revenda_id')->where('ativo', true)->count(),
            'cor' => 'brand',
        ]);

        $linhas = $linhas->filter(fn ($l) => $l['valor'] > 0)->sortByDesc('valor')->values();

        $total = (float) $linhas->sum('valor');
        $maior = (float) ($linhas->max('valor') ?? 0);
        $escala = max(35000.0, ceil($maior / 10000) * 10000);

        return [
            'linhas' => $linhas->map(fn ($l) => $l + [
                'share' => $total > 0 ? $l['valor'] / $total : 0,
                'largura' => $escala > 0 ? $l['valor'] / $escala : 0,
                'delta' => $this->variacao($l['valor'], $l['anterior'], null),
            ])->all(),
            'total' => $total,
            'escala' => $escala,
            'guias' => [10000, 20000, 30000],
            'competencia' => now()->format('m/Y'),
        ];
    }

    private function mrrPorOrigem(string $competencia, ?int $revendaId): float
    {
        return (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia)
            ->where('status', '!=', 'cancelado')
            ->when($revendaId === null,
                fn ($q) => $q->whereNull('revenda_id'),
                fn ($q) => $q->where('revenda_id', $revendaId))
            ->sum('valor');
    }

    // ── Coluna direita ────────────────────────────────────────────────────

    private function proximosSeteDias(Carbon $hoje): Collection
    {
        $limite = $hoje->copy()->addDays(7);

        $receitas = Cobranca::where('status', 'pendente')
            ->whereBetween('data_vencimento', [$hoje, $limite])
            ->orderBy('data_vencimento')->get()
            ->map(fn (Cobranca $c) => [
                'sinal' => 'entrada',
                'descricao' => $c->descricao,
                'valor' => (float) $c->valor,
                'data' => Carbon::parse($c->data_vencimento),
                'rota' => route('cobrancas.index'),
            ]);

        $despesas = ContaPagar::where('status', 'em_aberto')
            ->whereBetween('data_vencimento', [$hoje, $limite])
            ->orderBy('data_vencimento')->get()
            ->map(fn (ContaPagar $c) => [
                'sinal' => 'saida',
                'descricao' => $c->descricao,
                'valor' => (float) $c->valor,
                'data' => Carbon::parse($c->data_vencimento),
                'rota' => route('contas-pagar.index'),
            ]);

        return $receitas->merge($despesas)->sortBy('data')->take(6)->values();
    }

    private function pipeline(): array
    {
        $abertos = Lead::whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS)->get();

        return [
            'valor' => (float) $abertos->sum('valor_estimado'),
            'quantidade' => $abertos->count(),
        ];
    }

    // ── Formatação ────────────────────────────────────────────────────────

    private function emReais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }

    private function variacao(float $atual, float $anterior, ?string $referencia): string
    {
        if ($anterior <= 0) {
            return $atual > 0 ? 'primeiro mês com valor' : 'sem histórico';
        }

        $percentual = (($atual - $anterior) / $anterior) * 100;
        $texto = ($percentual >= 0 ? '+' : '−').number_format(abs($percentual), 1, ',', '.').'%';

        return $referencia ? $texto.' vs '.$referencia : $texto;
    }

    /**
     * Quantos dias o caixa cobre da despesa fixa média — o número que decide
     * se dá para respirar. Sem histórico de despesa, não inventa.
     */
    private function folgaDeCaixa(float $saldo): string
    {
        $mediaMensal = (float) ContaPagar::where('data_vencimento', '>=', now()->subMonths(3))
            ->sum('valor') / 3;

        if ($mediaMensal <= 0 || $saldo <= 0) {
            return 'sem base para projetar folga';
        }

        return (int) floor($saldo / ($mediaMensal / 30)).' dias de folga';
    }
}
