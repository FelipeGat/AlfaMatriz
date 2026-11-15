<?php

namespace App\Services\Executivo;

use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use App\Services\IndicadoresService;
use App\Services\ProjecaoFinanceira;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * O financeiro da Alfa para a API Executiva (spec `api-executiva-leitura`, fase 4).
 *
 * Camada fina: saldo, MRR e projeções vêm de `IndicadoresService` e
 * `ProjecaoFinanceira`, que as telas já usam. O que as telas NÃO fazem — e por
 * isso é regra daqui — é separar o que o livro-caixa chama de `entrada` e
 * `saida` do que não é receita nem despesa, e contar vencido sem cancelado.
 *
 * Três decisões que divergem de propósito de uma tela:
 *
 * 1. `IndicadoresService::entradasDoMes()` soma `ajuste` e `transferencia` como
 *    entrada (é o sinal de `reprocessarSaldo()`, e fecha o saldo). Para a
 *    pessoa que decide, "Saldo inicial" de conta nova não é dinheiro que
 *    entrou. Aqui só `entrada` e `saida` com valor positivo entram no
 *    realizado; ajuste e transferência saem em campo próprio, e valor
 *    negativo (estorno lançado como entrada/saída) também.
 * 2. Os cards de Receitas e Despesas usam `status != 'pago'` para vencido e a
 *    vencer, e por isso contam cancelado. Aqui o em aberto é `pendente` /
 *    `em_aberto`, ponto.
 * 3. Diferença de caixa é `resultado_de_caixa`. `lucro` sai sempre `null`: não
 *    há apuração por competência que o sustente.
 *
 * Nada aqui grava. Valor ausente é `null`; zero só quando a fonte existe e
 * respondeu zero.
 */
class FinanceiroExecutivo
{
    public const FONTE = 'Financeiro da Alfa no AlfaMatriz (livro-caixa, receitas e despesas); não inclui o Gestor';

    /** Competência mais antiga em que o Matriz é a fonte única do financeiro da Alfa; antes, o Gestor. */
    public const DESDE_PADRAO = '2026-10-01';

    private const MAX_DIAS = 366;

    public function __construct(private IndicadoresService $indicadores, private ProjecaoFinanceira $projecao) {}

    /**
     * @param  array{de?: ?string, ate?: ?string, referencia?: ?string}  $p
     * @return array<string, mixed>
     */
    public function montar(User $user, array $p = []): array
    {
        $referencia = isset($p['referencia']) ? CarbonImmutable::parse($p['referencia'])->startOfDay() : CarbonImmutable::now()->startOfDay();
        $de = isset($p['de']) ? CarbonImmutable::parse($p['de'])->startOfDay() : $referencia->startOfMonth();
        $ate = isset($p['ate']) ? CarbonImmutable::parse($p['ate'])->startOfDay() : $referencia->endOfMonth()->startOfDay();

        // Período invertido não é "sem movimento": é pedido errado (422, como a validação do controller).
        if ($de->gt($ate)) {
            throw ValidationException::withMessages(['de' => 'O início do período não pode ser depois do fim.']);
        }

        $periodo = $de->format('Y-m-d').' a '.$ate->format('Y-m-d').' (referência '.$referencia->format('Y-m-d').')';

        return Envelope::proteger(self::FONTE, $periodo, function () use ($user, $referencia, $de, $ate, $periodo) {
            if ($de->diffInDays($ate, true) > self::MAX_DIAS) {
                return Envelope::montar(self::FONTE, $periodo, null, 'falha', 'O período do financeiro vai até '.self::MAX_DIAS.' dias.');
            }

            foreach (['financeiro', 'cobrancas', 'contas_pagar'] as $recurso) {
                if (! $user->canPermissao($recurso, 'ler')) {
                    return Envelope::montar(self::FONTE, $periodo, null, 'falha', 'A conta não tem permissão de leitura em "'.$recurso.'": o financeiro não é exposto.');
                }
            }

            $desde = CarbonImmutable::parse(config('services.executivo.financeiro_desde', self::DESDE_PADRAO))->startOfDay();
            $dados = [
                'criterios' => $this->criterios($referencia, $desde),
                'realizado' => $this->realizado($de, $ate),
                'contas_a_receber' => $this->emAberto(Cobranca::query(), 'pendente', $referencia) + ['pagos_a_menor' => $this->pagosAMenor(Cobranca::query(), $de, $ate)],
                'contas_a_pagar' => $this->emAberto(ContaPagar::query(), 'em_aberto', $referencia) + ['pagos_a_menor' => $this->pagosAMenor(ContaPagar::query(), $de, $ate)],
                'previsto' => $this->previsto($de, $ate, $referencia),
                'mrr' => $this->mrr($referencia),
                'lucro' => null,
            ];

            [$situacao, $motivo] = $de->lt($desde)
                ? ['desatualizado', 'O período começa antes de '.$desde->format('d/m/Y').': até setembro de 2026 o financeiro da Alfa está no Gestor, e esta resposta só traz o que o Matriz tem.']
                : ['ok', null];

            return Envelope::montar(self::FONTE, $periodo, $this->ultimaAtualizacao(), $situacao, $motivo, $dados);
        });
    }

    /** @return array<string, mixed> */
    private function criterios(CarbonImmutable $referencia, CarbonImmutable $desde): array
    {
        return [
            'moeda' => 'centavos (inteiro)',
            'referencia' => $referencia->format('Y-m-d'),
            'competencia' => $referencia->format('Y-m'),
            'realizado' => 'Só até HOJE (lançamento datado depois de hoje não é realizado: fica em `datados_depois_de_hoje`). Livro-caixa (movimentacoes_financeiras) de contas financeiras ATIVAS, por data da movimentação. Entra só `entrada` e `saida` de valor positivo. `ajuste` (ex.: saldo inicial) e `transferencia` ficam em `fora_do_resultado`; valor negativo vai para `possiveis_estornos`. Nenhuma entrada é presumida como receita nem saída como despesa.',
            'resultado_de_caixa' => 'entradas realizadas menos saídas realizadas. É variação de caixa, NÃO é lucro.',
            'em_aberto' => 'Receitas `pendente` e despesas `em_aberto`, com valor maior que zero, SEM filtro de período. Pagas e canceladas ficam fora.',
            'vencido' => 'em aberto com vencimento antes da data de referência.',
            'a_vencer' => 'em aberto com vencimento na data de referência ou depois.',
            'previsto' => '`registrado` = obrigações em aberto com vencimento no período. `projecao_estimada` = estimativa para o que ainda não foi lançado; nunca é somada ao registrado.',
            'mrr' => '`contratado` e `faturado` são medidas diferentes e não se somam, nem aos recebimentos previstos.',
            'saldo' => 'Soma do saldo das contas ativas AGORA (consulta), não na data de referência.',
            'atualizado_em' => 'Data do registro mais recente entre livro-caixa (todas as contas), cobranças e contas a pagar. Mostra o dado mais novo gravado, não que tudo foi lançado até agora.',
            'cancelados' => '`excluidos.cancelados_total_historico` conta todos os cancelados da tabela, sem recorte de período; não entram em em_aberto nem em vencido.',
            'lucro' => 'Não apurado: o Matriz não tem apuração por competência.',
            'cobertura' => 'O Matriz é a fonte do financeiro da Alfa desde '.$desde->format('Y-m-d').'. Não some esta resposta com a do Gestor para o mesmo período: o Gestor não é lido aqui.',
        ];
    }

    // ───────────────────────── realizado

    /** @return array<string, mixed> */
    private function realizado(CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $ativas = ContaFinanceira::where('ativo', true);
        $totalAtivas = (clone $ativas)->count();

        if ($totalAtivas === 0) {
            return [
                'contas_ativas' => 0,
                'saldo_das_contas_ativas_centavos' => null,
                'entradas_realizadas_centavos' => null,
                'saidas_realizadas_centavos' => null,
                'resultado_de_caixa_centavos' => null,
                'motivo' => 'Nenhuma conta financeira ativa: não há livro-caixa para somar.',
            ];
        }

        $hoje = CarbonImmutable::now()->startOfDay();
        $ateEfetivo = $ate->lt($hoje) ? $ate : $hoje;

        $base = MovimentacaoFinanceira::query()
            ->whereIn('conta_financeira_id', (clone $ativas)->select('id'))
            ->whereDate('data', '>=', $de->toDateString());
        $livro = (clone $base)->whereDate('data', '<=', $ateEfetivo->toDateString());
        $futuros = (clone $base)->whereDate('data', '>', $hoje->toDateString())->whereDate('data', '<=', $ate->toDateString())
            ->whereIn('tipo', ['entrada', 'saida'])->where('valor', '>', 0);

        $soma = fn (string $tipo, string $sinal) => $this->centavos((clone $livro)->where('tipo', $tipo)->where('valor', $sinal, 0)->sum('valor'));
        $qtd = fn (string $tipo, string $sinal) => (clone $livro)->where('tipo', $tipo)->where('valor', $sinal, 0)->count();

        $entradas = $soma('entrada', '>');
        $saidas = $soma('saida', '>');

        return [
            'contas_ativas' => $totalAtivas,
            'ate_efetivo' => $ateEfetivo->format('Y-m-d'),
            'saldo_das_contas_ativas_centavos' => $this->centavos($this->indicadores->saldoEmCaixa()),
            'entradas_realizadas_centavos' => $entradas,
            'saidas_realizadas_centavos' => $saidas,
            'resultado_de_caixa_centavos' => $entradas - $saidas,
            'movimentacoes' => ['entradas' => $qtd('entrada', '>'), 'saidas' => $qtd('saida', '>')],
            'fora_do_resultado' => [
                'ajustes_liquido_centavos' => $this->centavos((clone $livro)->where('tipo', 'ajuste')->sum('valor')),
                'ajustes_quantidade' => (clone $livro)->where('tipo', 'ajuste')->count(),
                'transferencias_liquido_centavos' => $this->centavos((clone $livro)->where('tipo', 'transferencia')->sum('valor')),
                'transferencias_quantidade' => (clone $livro)->where('tipo', 'transferencia')->count(),
            ],
            'possiveis_estornos' => [
                'entradas_negativas_centavos' => $this->centavos((clone $livro)->where('tipo', 'entrada')->where('valor', '<', 0)->sum('valor')),
                'saidas_negativas_centavos' => $this->centavos((clone $livro)->where('tipo', 'saida')->where('valor', '<', 0)->sum('valor')),
                'quantidade' => $qtd('entrada', '<') + $qtd('saida', '<'),
            ],
            'possiveis_transferencias_internas' => $this->candidatasATransferencia($livro),
            'conciliacao' => $this->conciliacao($ativas, $de, $ateEfetivo, $entradas, $saidas),
            'datados_depois_de_hoje' => [
                'quantidade' => (clone $futuros)->count(),
                'entradas_centavos' => $this->centavos((clone $futuros)->where('tipo', 'entrada')->sum('valor')),
                'saidas_centavos' => $this->centavos((clone $futuros)->where('tipo', 'saida')->sum('valor')),
                'criterio' => 'lançamentos do período com data depois de hoje: NÃO entram no realizado',
            ],
        ];
    }

    /**
     * Pares saída + entrada do mesmo valor, no mesmo dia, em contas diferentes:
     * o jeito de lançar transferência como duas movimentações comuns. É
     * CANDIDATA, não prova — por isso não é subtraída do realizado, só
     * apontada para quem decide. O livro não tem par de transferência: nada no
     * sistema grava `tipo = transferencia`.
     *
     * @return array{pares: int, valor_centavos: int, criterio: string}
     */
    private function candidatasATransferencia($livro): array
    {
        $linhas = (clone $livro)->whereIn('tipo', ['entrada', 'saida'])->where('valor', '>', 0)->get(['conta_financeira_id', 'tipo', 'valor', 'data']);
        $saidas = $linhas->where('tipo', 'saida')->groupBy(fn ($m) => $m->data->format('Y-m-d').'|'.$m->valor);
        $pares = 0;
        $valor = 0;

        foreach ($linhas->where('tipo', 'entrada')->groupBy(fn ($m) => $m->data->format('Y-m-d').'|'.$m->valor) as $chave => $entradas) {
            $do_dia = $saidas->get($chave, collect());
            $n = 0;
            $usadas = [];
            foreach ($entradas as $e) {
                $s = $do_dia->first(fn ($x, $i) => ! in_array($i, $usadas, true) && $x->conta_financeira_id !== $e->conta_financeira_id);
                if ($s) {
                    $usadas[] = $do_dia->search($s, true);
                    $n++;
                }
            }
            $pares += $n;
            $valor += $n * $this->centavos($entradas->first()->valor);
        }

        return ['pares' => $pares, 'valor_centavos' => $valor, 'criterio' => 'saída e entrada de mesmo valor e mesma data em contas ativas diferentes; candidatas, NÃO descontadas do realizado'];
    }

    /**
     * O livro representa o que foi recebido/pago? Compara com as baixas.
     *
     * `Cobranca::baixar()` e `ContaPagar::baixar()` lançam o movimento, mas a
     * ligação (`origem_*`) não é gravada — os campos não estão no `$fillable` —,
     * então não dá para ligar movimento a título. A conferência é por TOTAL:
     * baixas do período em contas ativas × entradas/saídas do livro. Diferença
     * diferente de zero é entrada/saída lançada à mão (ou baixa apagada) e
     * precisa de classificação humana antes de ser chamada de receita/despesa.
     *
     * @return array<string, mixed>
     */
    private function conciliacao($ativas, CarbonImmutable $de, CarbonImmutable $ate, int $entradas, int $saidas): array
    {
        $ids = (clone $ativas)->select('id');
        $recebido = $this->centavos(Cobranca::where('status', 'pago')->whereIn('conta_financeira_id', $ids)
            ->whereDate('data_pagamento', '>=', $de->toDateString())->whereDate('data_pagamento', '<=', $ate->toDateString())->sum('valor_pago'));
        $pago = $this->centavos(ContaPagar::where('status', 'pago')->whereIn('conta_financeira_id', $ids)
            ->whereDate('data_pagamento', '>=', $de->toDateString())->whereDate('data_pagamento', '<=', $ate->toDateString())->sum('valor_pago'));

        return [
            'recebido_por_baixas_centavos' => $recebido,
            'pago_por_baixas_centavos' => $pago,
            'entradas_sem_baixa_correspondente_centavos' => $entradas - $recebido,
            'saidas_sem_baixa_correspondente_centavos' => $saidas - $pago,
            'criterio' => 'baixas (status pago, data de pagamento no período, conta ativa) comparadas ao total do livro. Diferença não é erro provado: é lançamento manual, baixa apagada ou data divergente a classificar.',
        ];
    }

    // ───────────────────────── contas a receber e a pagar

    /**
     * Em aberto, vencido e a vencer na data de referência.
     *
     * Só o status que significa "devido": `pendente`/`em_aberto`. O valor tem de
     * ser positivo — registro de valor zero não é obrigação. A tela usa
     * `status != 'pago'`, que deixa cancelado dentro do vencido.
     *
     * @return array<string, mixed>
     */
    private function emAberto($query, string $statusAberto, CarbonImmutable $referencia): array
    {
        $base = $query->where('status', $statusAberto)->where('valor', '>', 0);
        $hoje = $referencia->toDateString();

        $vencido = (clone $base)->whereDate('data_vencimento', '<', $hoje);
        $aVencer = (clone $base)->whereDate('data_vencimento', '>=', $hoje);

        return [
            'em_aberto_centavos' => $this->centavos((clone $base)->sum('valor')),
            'em_aberto_quantidade' => (clone $base)->count(),
            'vencido_centavos' => $this->centavos((clone $vencido)->sum('valor')),
            'vencido_quantidade' => (clone $vencido)->count(),
            'a_vencer_centavos' => $this->centavos((clone $aVencer)->sum('valor')),
            'a_vencer_quantidade' => (clone $aVencer)->count(),
            'excluidos' => [
                // Total histórico da tabela, sem recorte de período: o nome diz isso para não ser lido como "cancelados do período".
                'cancelados_total_historico' => (clone $query->getModel()->newQuery())->where('status', 'cancelado')->count(),
            ],
        ];
    }

    /**
     * Baixas com `valor_pago` menor que `valor`. O sistema marca `pago` e não
     * deixa saldo remanescente em aberto: a diferença some do a receber/a pagar
     * sem aviso. Aqui só se mostra o tamanho dela; não é reclassificada.
     *
     * @return array<string, mixed>
     */
    private function pagosAMenor($query, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $q = $query->where('status', 'pago')->whereNotNull('valor_pago')->whereColumn('valor_pago', '<', 'valor')
            ->whereDate('data_pagamento', '>=', $de->toDateString())->whereDate('data_pagamento', '<=', $ate->toDateString());

        return [
            'quantidade' => (clone $q)->count(),
            'diferenca_centavos' => $this->centavos((clone $q)->sum(DB::raw('valor - valor_pago'))),
            'criterio' => 'títulos pagos (data de pagamento no período) com valor pago menor que o valor: o sistema os trata como quitados, e a diferença não aparece em aberto',
        ];
    }

    // ───────────────────────── previsto

    /** @return array<string, mixed> */
    private function previsto(CarbonImmutable $de, CarbonImmutable $ate, CarbonImmutable $referencia): array
    {
        $registrado = fn ($query, string $status) => $this->centavos($query->where('status', $status)->where('valor', '>', 0)
            ->whereDate('data_vencimento', '>=', $de->toDateString())->whereDate('data_vencimento', '<=', $ate->toDateString())->sum('valor'));

        $previsto = [
            'registrado' => [
                'a_receber_centavos' => $registrado(Cobranca::query(), 'pendente'),
                'a_pagar_centavos' => $registrado(ContaPagar::query(), 'em_aberto'),
                'criterio' => 'obrigações já lançadas, em aberto, com vencimento no período',
            ],
        ];

        // A projeção olha o relógio ("do mês corrente em diante"), não a data de
        // referência: para outra data ela responderia a pergunta errada.
        if (! $referencia->isSameDay(now())) {
            $previsto['projecao_estimada'] = ['a_receber_centavos' => null, 'a_pagar_centavos' => null,
                'motivo' => 'A projeção só é calculada para a data de hoje; a referência pedida é outra.'];

            return $previsto;
        }

        $dePro = \Illuminate\Support\Carbon::instance($de->toDateTime());
        $atePro = \Illuminate\Support\Carbon::instance($ate->toDateTime());
        $despesas = $this->projecao->despesas($dePro, $atePro);
        $receitas = $this->projecao->receitas($dePro, $atePro);

        $previsto['projecao_estimada'] = [
            'estimativa' => true,
            'a_pagar_despesas_fixas_centavos' => $this->soma($despesas),
            'a_pagar_despesas_fixas_quantidade' => $despesas->count(),
            'a_receber_licenciamento_centavos' => $this->soma($receitas),
            'a_receber_licenciamento_quantidade' => $receitas->count(),
            'criterio' => 'Despesa fixa vigente SEM conta gerada na competência (a gerada já está em `registrado`, e some da projeção); licenciamento de revenda "por cliente" ainda sem cobrança na competência. A projeção olha do início do mês corrente em diante.',
        ];

        return $previsto;
    }

    // ───────────────────────── MRR

    /** @return array<string, mixed> */
    private function mrr(CarbonImmutable $referencia): array
    {
        $competencia = $referencia->format('Y-m');
        $faturada = $this->indicadores->competenciaFoiFaturada($competencia);
        $passada = $competencia < now()->format('Y-m');

        return [
            'competencia' => $competencia,
            'faturado_centavos' => $faturada ? $this->centavos($this->indicadores->mrr($competencia)) : null,
            'faturado_motivo' => $faturada ? null : 'Nenhuma cobrança recorrente gerada nesta competência: o faturado não existe, não é zero.',
            // O contratado é a foto de HOJE (ver IndicadoresService::mrrContratado): para mês passado seria inventar.
            'contratado_centavos' => $passada ? null : $this->centavos($this->indicadores->mrrContratado($competencia)),
            'contratado_motivo' => $passada ? 'O contratado é a foto de hoje; não se reconstrói competência passada.' : null,
            'criterio' => 'faturado = cobranças recorrentes (locação de sistema/cliente e direta) da competência, sem canceladas; contratado = o que o fechamento cobraria agora, mais contratos diretos. Não somar um ao outro nem a recebimentos previstos.',
        ];
    }

    // ───────────────────────── apoio

    /**
     * O registro mais recente entre as três fontes da resposta (livro, cobranças e contas a pagar):
     * quem não tem rotina de leitura só pode declarar "o dado mais novo que existe". Não prova que
     * tudo foi lançado até agora, só que nada mais novo que isso está gravado.
     */
    private function ultimaAtualizacao(): ?CarbonImmutable
    {
        $datas = array_filter([
            MovimentacaoFinanceira::query()->max('updated_at'),
            Cobranca::query()->max('updated_at'),
            ContaPagar::query()->max('updated_at'),
        ]);

        return $datas ? CarbonImmutable::parse(max($datas)) : null;
    }

    private function soma(Collection $itens): int
    {
        return $this->centavos($itens->sum('valor'));
    }

    private function centavos(mixed $valor): int
    {
        return (int) round(((float) $valor) * 100);
    }
}
