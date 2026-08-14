<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\FaturamentoSnapshot;
use App\Models\MovimentacaoFinanceira;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Support\Carbon;

/**
 * Origem única dos indicadores que aparecem em mais de uma tela.
 *
 * Antes, cada painel contava por conta própria. As contas coincidiam, mas
 * duplicar a regra é justamente como os números começam a divergir: basta
 * alguém ajustar o critério de "ativo" num lugar e esquecer o outro. Quando
 * isso acontece, quem usa não sabe qual tela está certa.
 */
class IndicadoresService
{
    /**
     * A previsão de faturamento por competência, calculada uma vez só.
     *
     * O Centro de Controle pergunta o contratado três vezes no mesmo
     * carregamento — o card, a minitendência e a régua de origem. A previsão
     * varre revenda por revenda, sistema por sistema, e ainda os módulos
     * vigentes de cada um: refazer essa varredura a cada pergunta custa a tela.
     *
     * @var array<string, array{total: float, porRevenda: array<int, float>}>
     */
    private array $previsaoMemo = [];

    public function clientesAtivos(): int
    {
        return Cliente::where('ativo', true)->count();
    }

    public function clientesDiretos(): int
    {
        return Cliente::where('ativo', true)->whereNull('revenda_id')->count();
    }

    /**
     * Clientes que entraram no mês corrente.
     *
     * Conta só ativos, pelo mesmo critério de `clientesAtivos()` — do
     * contrário o card se contradiz, dizendo que entraram mais clientes do que
     * a base inteira tem.
     */
    public function novosClientesNoMes(): int
    {
        return Cliente::where('ativo', true)
            ->whereRaw(Cliente::expressaoDeEntrada().' >= ?', [now()->startOfMonth()->toDateString()])
            ->count();
    }

    /**
     * Quantos clientes ativos a base tinha ao fim de cada um dos últimos
     * meses, do mais antigo ao mais recente.
     *
     * Vive aqui porque a curva aparece no Centro de Controle e no Comercial:
     * cada tela montando a própria faria as duas discordarem sobre o mesmo
     * desenho — que é exatamente o que o AC-062 existe para impedir.
     *
     * Conta pela data de ENTRADA, não por `created_at`: a base veio de
     * importação, e `created_at` marca o dia da migração para todo mundo de
     * uma vez, o que transforma a curva num degrau.
     *
     * A base não data a desativação, então a curva conta quem entrou até
     * aquele mês e SEGUE ativo hoje. Ela mostra a entrada, não o churn, e por
     * isso nunca desce.
     *
     * @return list<float>
     */
    public function serieDeClientesAtivos(int $meses): array
    {
        return $this->serieDeEntrada(fn () => Cliente::where('ativo', true), Cliente::expressaoDeEntrada(), $meses);
    }

    /** A mesma curva, para revendas. @return list<float> */
    public function serieDeRevendasAtivas(int $meses): array
    {
        return $this->serieDeEntrada(fn () => Revenda::where('ativo', true), Revenda::expressaoDeEntrada(), $meses);
    }

    /** A mesma curva, para o catálogo de produtos. @return list<float> */
    public function serieDeSistemasAtivos(int $meses): array
    {
        return $this->serieDeEntrada(fn () => Sistema::produtos()->where('ativo', true), Sistema::expressaoDeEntrada(), $meses);
    }

    /**
     * Quantos registros ativos existiam ao fim de cada mês, pela data de
     * entrada — do mais antigo ao mais recente.
     *
     * Devolve lista VAZIA quando todas as entradas caem num mês só. É o caso
     * da base recém-importada: a curva viraria `[0,0,0,0,0,N]`, que afirma
     * "tudo apareceu de uma vez" — falso sobre a história e verdadeiro apenas
     * sobre o dia da migração. Card sem curva é honesto; curva inventada, não.
     *
     * A regra se cura sozinha: assim que as entradas se espalharem por mais de
     * um mês — por cadastro novo ou por alguém preencher a data que se sabe —
     * a curva aparece.
     *
     * @param  \Closure():\Illuminate\Database\Eloquent\Builder  $base
     * @return list<float>
     */
    private function serieDeEntrada(\Closure $base, string $expressao, int $meses): array
    {
        // `SUBSTR(data, 1, 7)` recorta o "AAAA-MM" e funciona nos dois bancos.
        // `DATE_FORMAT` seria mais expressivo e é só do MySQL: a suíte roda em
        // SQLite, e a tela inteira devolvia 500 no teste enquanto passava no
        // navegador — o tipo de erro que só aparece depois de publicado.
        $mesesComEntrada = (int) $base()
            ->selectRaw("COUNT(DISTINCT SUBSTR($expressao, 1, 7)) as n")
            ->value('n');

        if ($mesesComEntrada <= 1) {
            return [];
        }

        return collect(range($meses - 1, 0))
            ->map(fn (int $atras) => (float) $base()
                ->whereRaw($expressao.' <= ?', [
                    // `startOfMonth()` ANTES de `subMonths()`: subtrair mês a
                    // partir do dia 31 transborda, porque 31/02 não existe e o
                    // Carbon avança para 03/03. Visto do dia 31 de março, a
                    // janela repetia dezembro e março e pulava novembro e
                    // fevereiro. O dia 1º existe em todo mês, então normalizar
                    // primeiro não tem para onde transbordar.
                    now()->startOfMonth()->subMonths($atras)->endOfMonth()->toDateString(),
                ])
                ->count())
            ->all();
    }

    /**
     * A receita recorrente de cada um dos últimos meses, pela mesma regra do
     * card — faturado onde houve fechamento, contratado no mês corrente.
     *
     * @return list<float>
     */
    public function serieDoMrr(int $meses): array
    {
        return $this->semSinal(
            $this->ultimosMeses($meses)
                ->map(fn (Carbon $mes) => $this->mrrDaCompetencia($mes->format('Y-m')))
                ->all()
        );
    }

    /**
     * O saldo em caixa ao fim de cada um dos últimos meses, recomposto de trás
     * para frente: o saldo de hoje menos tudo que se movimentou depois daquele
     * mês. É o único jeito honesto — não existe foto histórica do saldo.
     *
     * @return list<float>
     */
    public function serieDoSaldo(int $meses): array
    {
        $saldoAtual = $this->saldoEmCaixa();

        return $this->semSinal(
            $this->ultimosMeses($meses)
                ->map(function (Carbon $mes) use ($saldoAtual) {
                    $depois = (float) MovimentacaoFinanceira::query()
                        ->whereIn('conta_financeira_id', ContaFinanceira::where('ativo', true)->select('id'))
                        ->whereDate('data', '>', $mes->copy()->endOfMonth()->toDateString())
                        ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'saida' THEN -valor ELSE valor END), 0) as total")
                        ->value('total');

                    return $saldoAtual - $depois;
                })
                ->all()
        );
    }

    /** Entradas de caixa mês a mês. @return list<float> */
    public function serieDeEntradas(int $meses): array
    {
        return $this->semSinal($this->ultimosMeses($meses)->map(fn (Carbon $m) => $this->entradasDoMes($m))->all());
    }

    /** Saídas de caixa mês a mês. @return list<float> */
    public function serieDeSaidas(int $meses): array
    {
        return $this->semSinal($this->ultimosMeses($meses)->map(fn (Carbon $m) => $this->saidasDoMes($m))->all());
    }

    /** @return \Illuminate\Support\Collection<int, Carbon> */
    private function ultimosMeses(int $meses)
    {
        return collect(range($meses - 1, 0))
            // Mesma ordem de `serieDeEntrada()`, pelo mesmo motivo: normalizar
            // para o dia 1º antes de subtrair, senão a janela transborda nos
            // dias 29 a 31.
            ->map(fn (int $atras) => now()->startOfMonth()->subMonths($atras));
    }

    /**
     * Curva toda zerada não é tendência, é ruído: uma reta no meio do card
     * dizendo nada. Mesma escolha de `serieDeEntrada()` — o card sabe se calar
     * quando a série vem vazia.
     *
     * @param  list<float>  $serie
     * @return list<float>
     */
    private function semSinal(array $serie): array
    {
        return array_sum(array_map('abs', $serie)) > 0 ? $serie : [];
    }

    /**
     * O atacado efetivamente faturado, um ponto por fechamento gravado.
     *
     * É a única história real do MRR: não existe foto mensal do estimado, e o
     * estimado é sempre a foto de HOJE. Mês sem fechamento não vira ponto zero
     * — zero diria "faturamos nada", quando o que houve foi "ninguém fechou o
     * mês". Com menos de dois fechamentos não há tendência, e a curva não sai.
     *
     * Cobre só o que vai para revenda, que é o que o fechamento consolida.
     *
     * @return list<float>
     */
    public function serieDeAtacadoFaturado(int $competencias): array
    {
        $porCompetencia = FaturamentoSnapshot::selectRaw('competencia, SUM(total) as total')
            ->groupBy('competencia')
            ->orderBy('competencia')
            ->pluck('total', 'competencia');

        if ($porCompetencia->count() < 2) {
            return [];
        }

        return $porCompetencia->take(-$competencias)->map(fn ($t) => (float) $t)->values()->all();
    }

    public function revendasAtivas(): int
    {
        return Revenda::where('ativo', true)->count();
    }

    public function sistemasAtivos(): int
    {
        return Sistema::produtos()->where('ativo', true)->count();
    }

    /**
     * Receita recorrente da competência: o que foi cobrado, sem os cancelados.
     */
    public function mrr(?string $competencia = null): float
    {
        return (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia ?? now()->format('Y-m'))
            ->where('status', '!=', 'cancelado')
            ->sum('valor');
    }

    /**
     * A competência já teve fechamento rodado?
     *
     * Distinguir "ninguém faturou ainda" de "faturou e deu zero" é o que
     * impede o card de receita recorrente de tratar mês não fechado como mês
     * sem receita.
     */
    public function competenciaFoiFaturada(string $competencia): bool
    {
        return Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia)
            ->where('status', '!=', 'cancelado')
            ->exists();
    }

    /**
     * O recorrente dos clientes que a matriz atende direto.
     *
     * Fora do faturamento de propósito: `FaturamentoService` só consolida
     * revenda, e cliente direto é cobrado à mão pela tela de Receitas. Sem
     * somar aqui, ele sumiria da receita contratada.
     *
     * A regra é a mesma de `Cliente::isContratoMensal()` — se ela mudar lá,
     * muda aqui.
     */
    public function contratosDiretos(): float
    {
        return (float) Cliente::where('ativo', true)
            ->whereNull('revenda_id')
            ->where('tipo_cliente', 'CONTRATO')
            ->where('valor_mensal', '>', 0)
            ->where('dia_vencimento', '>', 0)
            ->sum('valor_mensal');
    }

    /**
     * Receita recorrente contratada: o que o fechamento cobraria se rodasse
     * agora, mais os contratos diretos.
     *
     * É a foto de HOJE. Serve para a competência corrente, não para remontar
     * mês passado — para trás, quem manda é a cobrança que foi de fato gerada.
     *
     * O serviço de faturamento é resolvido aqui dentro, e não no construtor,
     * porque os testes trocam este serviço por uma classe anônima sem
     * argumentos: exigir dependência no construtor quebraria todos eles.
     */
    public function mrrContratado(?string $competencia = null): float
    {
        return $this->previsao($competencia)['total'] + $this->contratosDiretos();
    }

    /**
     * A receita recorrente da competência, pela regra COMPLETA: o faturado
     * quando o fechamento rodou, o contratado enquanto não rodou.
     *
     * Mora aqui porque a mesma pergunta é feita no Centro de Controle e no
     * painel Financeiro. Enquanto a regra viveu só dentro do Centro de
     * Controle, as duas telas mostraram números diferentes sob o mesmo rótulo
     * — R$ 99,00 numa e R$ 0,00 na outra, no mesmo dia e na mesma competência.
     *
     * A troca vale só para o mês corrente: o contratado é a foto de HOJE, e
     * aplicá-lo a um mês passado inventaria histórico que não houve.
     */
    public function mrrDaCompetencia(?string $competencia = null): float
    {
        $competencia ??= now()->format('Y-m');

        if ($this->competenciaFoiFaturada($competencia) || $competencia !== now()->format('Y-m')) {
            return $this->mrr($competencia);
        }

        return $this->mrrContratado($competencia);
    }

    /**
     * A competência corrente ainda não foi fechada? É o que autoriza a tela a
     * dizer "contratado" em vez de deixar o número passar por faturado.
     */
    public function mrrEhContratado(?string $competencia = null): bool
    {
        $competencia ??= now()->format('Y-m');

        return $competencia === now()->format('Y-m') && ! $this->competenciaFoiFaturada($competencia);
    }

    /** @return array{total: float, porRevenda: array<int, float>} */
    private function previsao(?string $competencia): array
    {
        $chave = $competencia ?? now()->format('Y-m');

        return $this->previsaoMemo[$chave] ??= app(FaturamentoService::class)->previsaoDaCompetencia($chave);
    }

    /**
     * O contratado aberto por origem: cada revenda e a venda direta.
     *
     * A régua de origem precisa somar exatamente o mesmo que o card — se cada
     * um aplicasse a própria regra, a soma das barras discordaria do número
     * impresso logo acima delas.
     *
     * @return array{revendas: array<int, float>, direta: float}
     */
    public function mrrContratadoPorOrigem(?string $competencia = null): array
    {
        return [
            'revendas' => $this->previsao($competencia)['porRevenda'],
            'direta' => $this->contratosDiretos(),
        ];
    }

    /**
     * Valor de atacado por sistema, com o tier aplicado por revenda.
     *
     * Vive aqui porque aparece no painel Comercial e na tela de Sistemas: se
     * cada uma calculasse por conta própria, bastaria alguém ajustar a regra
     * de um lado para os dois números passarem a discordar.
     *
     * O cálculo do tier é o de `Sistema::mrrEstimado()`, e não uma cópia dele:
     * este método repetia aquele laço linha por linha, de modo que a tela de
     * Produtos e o painel Comercial podiam passar a discordar sobre o valor do
     * MESMO sistema ao primeiro ajuste em um dos dois.
     *
     * `valor_estimado` é o recorrente inteiro — licença mais módulos —, porque
     * é ele que a fatura cobra. Quem precisa das partes tem `valor_licenca` e
     * `valor_modulos`.
     *
     * @return \Illuminate\Support\Collection<int, array{sistema: Sistema, clientes_ativos: int, valor_estimado: float, valor_licenca: float, valor_modulos: float}>
     */
    public function rankingSistemas()
    {
        // Só produto ATIVO. Sem este filtro, um sistema desativado continuava
        // aparecendo nos rankings do Comercial logo abaixo de um card que diz
        // quantos sistemas estão ativos — a tela contradizia a si mesma — e
        // ainda entrava no MRR, que o fechamento nunca cobraria.
        return Sistema::produtos()->where('ativo', true)
            ->withCount(['clientes' => fn ($q) => $q->where('clientes.ativo', true)->where('cliente_sistema.ativo', true)])
            ->get()
            ->map(function (Sistema $sistema) {
                $licenca = $sistema->mrrEstimado();
                $modulos = $sistema->mrrModulos();

                return [
                    'sistema' => $sistema,
                    'clientes_ativos' => $sistema->clientes_count,
                    'valor_estimado' => $licenca + $modulos,
                    'valor_licenca' => $licenca,
                    'valor_modulos' => $modulos,
                ];
            });
    }

    /**
     * Soma do atacado de todos os sistemas: licença mais módulos.
     *
     * Sem os módulos este número saía abaixo do que a fatura cobra — módulo é
     * receita recorrente e entra na cobrança da revenda como segunda parcela
     * da mesma linha.
     */
    public function mrrAtacado(): float
    {
        return (float) $this->rankingSistemas()->sum('valor_estimado');
    }

    public function saldoEmCaixa(): float
    {
        return (float) ContaFinanceira::where('ativo', true)->sum('saldo');
    }

    /**
     * O que ENTROU no caixa no mês, pelo livro-caixa.
     *
     * Antes somava cobrança baixada, que é outra coisa: o livro tem também
     * `ajuste` e `transferencia` — o "Saldo inicial" de uma conta nova, por
     * exemplo. O saldo em caixa se movia por valores que os cards ao lado dele
     * não explicavam, e não havia como fechar "saldo anterior + entradas −
     * saídas" olhando a tela.
     *
     * O sinal é o de `ContaFinanceira::reprocessarSaldo()`: só `saida`
     * subtrai. E o escopo é o de `saldoEmCaixa()`: só conta ativa — somar
     * movimento de conta que o saldo ignora é como as duas medidas param de
     * fechar.
     */
    public function entradasDoMes(?Carbon $mes = null): float
    {
        return $this->movimentadoNoMes($mes, entradas: true);
    }

    /** O que SAIU do caixa no mês — ver `entradasDoMes()`. */
    public function saidasDoMes(?Carbon $mes = null): float
    {
        return $this->movimentadoNoMes($mes, entradas: false);
    }

    private function movimentadoNoMes(?Carbon $mes, bool $entradas): float
    {
        $mes ??= now();

        return (float) MovimentacaoFinanceira::query()
            ->whereIn('conta_financeira_id', ContaFinanceira::where('ativo', true)->select('id'))
            ->whereBetween('data', [
                $mes->copy()->startOfMonth()->toDateString(),
                $mes->copy()->endOfMonth()->toDateString(),
            ])
            ->when($entradas,
                fn ($q) => $q->where('tipo', '!=', 'saida'),
                fn ($q) => $q->where('tipo', 'saida'))
            ->sum('valor');
    }
}
