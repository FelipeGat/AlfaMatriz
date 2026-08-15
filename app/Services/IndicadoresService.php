<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\FaturamentoSnapshot;
use App\Models\Lead;
use App\Models\MovimentacaoFinanceira;
use App\Models\Revenda;
use App\Models\Sistema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * O caixa de cada mês, `AAAA-MM` => entradas e saídas.
     *
     * O livro-caixa era somado UM MÊS POR CONSULTA, e as mesmas somas eram
     * pedidas de três lugares no mesmo carregamento: as duas curvas dos cards e
     * o gráfico de entradas × saídas, que refazia mês a mês o que as curvas já
     * tinham calculado. Medido em 14/08/2026, davam 26 `SUM(valor)` idênticos
     * por abertura do Painel Financeiro.
     *
     * @var array<string, array{entradas: float, saidas: float}>
     */
    private array $caixaPorMes = [];

    /** O mês mais antigo já carregado em `caixaPorMes` — antes dele, é preciso ir ao banco. */
    private ?string $caixaCarregadaDe = null;

    /**
     * Respostas que não mudam dentro de uma requisição, guardadas pela chave da
     * pergunta. `competenciaFoiFaturada` sozinha era perguntada oito vezes no
     * Painel Financeiro e onze no Centro de Controle — sempre sobre a mesma
     * competência, sempre com a mesma resposta.
     *
     * @var array<string, mixed>
     */
    private array $memo = [];

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
        $competencias = $this->ultimosMeses($meses)->map(fn (Carbon $mes) => $mes->format('Y-m'));

        // As seis competências numa consulta. Cada ponto da curva perguntava
        // duas coisas ao banco — "foi faturada?" e "quanto?" — sobre a mesma
        // tabela e o mesmo filtro, o que dava onze consultas para desenhar seis
        // pontos.
        $this->carregarMrrDe($competencias->all());

        return $this->semSinal(
            $competencias->map(fn (string $competencia) => $this->mrrDaCompetencia($competencia))->all()
        );
    }

    /**
     * O faturado de várias competências de uma vez.
     *
     * Preenche as duas respostas que `mrrDaCompetencia()` precisa por mês: a
     * soma e o "houve fechamento". Uma competência que aparece no agrupamento é
     * uma competência com cobrança gerada — que é exatamente o que o `exists()`
     * de `competenciaFoiFaturada()` responde, inclusive quando a soma dá zero.
     *
     * @param  list<string>  $competencias
     */
    private function carregarMrrDe(array $competencias): void
    {
        $faltando = array_values(array_filter(
            $competencias,
            fn (string $competencia) => ! array_key_exists("mrr:{$competencia}", $this->memo)
        ));

        if ($faltando === []) {
            return;
        }

        $somas = Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->whereIn('competencia', $faltando)
            ->where('status', '!=', 'cancelado')
            ->selectRaw('competencia, COALESCE(SUM(valor), 0) as total')
            ->groupBy('competencia')
            ->get()
            ->mapWithKeys(fn ($linha) => [$linha->competencia => (float) $linha->total]);

        foreach ($faltando as $competencia) {
            $this->memo["mrr:{$competencia}"] = $somas[$competencia] ?? 0.0;
            $this->memo["faturada:{$competencia}"] = $somas->has($competencia);
        }
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

        // O livro-caixa inteiro da janela, numa consulta. Era uma por mês, e as
        // seis somavam o mesmo intervalo em pedaços que se sobrepunham.
        $this->carregarCaixa($this->ultimosMeses($meses)->first());

        return $this->semSinal(
            $this->ultimosMeses($meses)
                ->map(fn (Carbon $mes) => $saldoAtual - $this->movimentadoDepoisDe($mes))
                ->all()
        );
    }

    /** Entradas de caixa mês a mês. @return list<float> */
    public function serieDeEntradas(int $meses): array
    {
        $this->carregarCaixa($this->ultimosMeses($meses)->first());

        return $this->semSinal($this->ultimosMeses($meses)->map(fn (Carbon $m) => $this->entradasDoMes($m))->all());
    }

    /** Saídas de caixa mês a mês. @return list<float> */
    public function serieDeSaidas(int $meses): array
    {
        $this->carregarCaixa($this->ultimosMeses($meses)->first());

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
        $competencia ??= now()->format('Y-m');

        return $this->memo["mrr:{$competencia}"] ??= (float) Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
            ->where('competencia', $competencia)
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
        // A pergunta mais repetida dos dois painéis: onze vezes no Centro de
        // Controle, sempre sobre a mesma competência.
        return $this->memo["faturada:{$competencia}"] ??= Cobranca::whereIn('tipo', ['locacao_sistema', 'direta'])
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
        return $this->memo['contratosDiretos'] ??= (float) Cliente::where('ativo', true)
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
        // As três relações que `mrrEstimado()` e `mrrModulos()` leem, trazidas
        // de uma vez. Sem elas, cada produto custava as próprias consultas — os
        // clientes dele duas vezes, os tiers uma por revenda, e as contratações
        // de módulo —, e um catálogo com o dobro de produtos custava o dobro.
        return Sistema::produtos()->where('ativo', true)
            ->with(['clientes', 'precosAtacado', 'modulos.contratacoes'])
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
        return $this->memo['saldo'] ??= (float) ContaFinanceira::where('ativo', true)->sum('saldo');
    }

    /**
     * O saldo que a conta tinha ao FIM de um mês — não existe foto histórica
     * do saldo (ver `serieDoSaldo()`), então ele é recomposto de trás para
     * frente: o saldo de hoje menos tudo que se movimentou depois daquele mês.
     *
     * Serve para o Painel Financeiro mostrar o saldo da competência que a
     * pessoa está navegando, e não sempre o saldo de hoje — mês corrente
     * devolve o mesmo valor de `saldoEmCaixa()`.
     */
    public function saldoAoFimDe(Carbon $mes): float
    {
        $this->carregarCaixa($mes);

        return $this->saldoEmCaixa() - $this->movimentadoDepoisDe($mes);
    }

    /**
     * O livro-caixa mês a mês, de `$inicio` a `$fim`, os dois inclusive —
     * REALIZADO até o mês corrente, PREVISTO dali em diante.
     *
     * Generaliza `historicoSeisMeses()` do Painel Financeiro para um período
     * escolhido: mês avulso (`$inicio` == `$fim`), janela de meses ou o ano
     * inteiro.
     *
     * Mês passado ou corrente é o que de fato se moveu no caixa
     * (`entradasDoMes`/`saidasDoMes`, do livro-caixa). Mês FUTURO não tem
     * caixa nenhum lançado ainda — mostrar zero ali diria "nada esperado",
     * quando o que há é "nada aconteceu ainda". Por isso ele entra com a
     * PREVISÃO: `mrrDaCompetencia()` do lado da receita (a mesma conta do
     * card "Receita recorrente") e `despesaPrevistaDaCompetencia()` do lado
     * da saída — pedido explícito em 15/08/2026 ("não está me apresentando o
     * que tem previsto para os próximos meses").
     *
     * @return list<array{label: string, entradas: float, saidas: float, previsto: bool}>
     */
    public function serieDeCaixaEntre(Carbon $inicio, Carbon $fim): array
    {
        $inicio = $inicio->copy()->startOfMonth();
        $fim = $fim->copy()->startOfMonth();

        $this->carregarCaixa($inicio);

        $mesAtual = now()->startOfMonth();

        $meses = [];
        for ($mes = $inicio->copy(); $mes->lessThanOrEqualTo($fim); $mes->addMonth()) {
            $meses[] = $mes->copy();
        }

        // As previsões dos meses futuros, de uma vez: perguntar mês a mês
        // custava até quatro consultas por mês — em janeiro, com onze meses
        // pela frente, era o painel voltando aos 76 de antes de AC-244.
        $futuras = collect($meses)
            ->filter(fn (Carbon $mes) => $mes->greaterThan($mesAtual))
            ->map(fn (Carbon $mes) => $mes->format('Y-m'))
            ->values()
            ->all();

        $this->carregarMrrDe($futuras);
        $this->carregarDespesaPrevistaDe($futuras);

        return collect($meses)->map(function (Carbon $mes) use ($mesAtual) {
            $futuro = $mes->greaterThan($mesAtual);
            $competencia = $mes->format('Y-m');

            return [
                'label' => ucfirst($mes->translatedFormat('M/y')),
                'entradas' => $futuro ? $this->mrrDaCompetencia($competencia) : $this->entradasDoMes($mes),
                'saidas' => $futuro ? $this->despesaPrevistaDaCompetencia($competencia) : $this->saidasDoMes($mes),
                'previsto' => $futuro,
            ];
        })->all();
    }

    /**
     * A despesa esperada de uma competência: o que já foi lançado em Contas a
     * Pagar quando existe, ou — mês que ainda não gerou nada — a soma das
     * despesas fixas ativas que valeriam naquele mês.
     *
     * A mesma dualidade "faturado ou contratado" de `mrrDaCompetencia()`, do
     * outro lado do livro-caixa: mês com `ContaPagar` gerada usa o que foi
     * de fato lançado (pode ter conta avulsa, fora do fixo); mês sem nada
     * gerado ainda projeta pelas recorrentes — é a melhor previsão possível
     * antes de `DespesaFixaService::gerarParaCompetencia()` rodar para ele.
     */
    public function despesaPrevistaDaCompetencia(string $competencia): float
    {
        $this->carregarDespesaPrevistaDe([$competencia]);

        return $this->memo["despesaPrevista:{$competencia}"];
    }

    /**
     * A despesa prevista de várias competências de uma vez — o mesmo desenho
     * de `carregarMrrDe()`: uma competência que aparece no agrupamento é uma
     * competência com `ContaPagar` gerada, mesmo que a soma dê zero; as que
     * não aparecem projetam pelas fixas, decididas em memória pela MESMA
     * régua da tela (`ContaFixaPagar::vigenteNaCompetencia()`).
     *
     * @param  list<string>  $competencias
     */
    private function carregarDespesaPrevistaDe(array $competencias): void
    {
        $faltando = array_values(array_filter(
            $competencias,
            fn (string $competencia) => ! array_key_exists("despesaPrevista:{$competencia}", $this->memo)
        ));

        if ($faltando === []) {
            return;
        }

        $geradas = ContaPagar::whereIn('competencia', $faltando)
            ->where('status', '!=', 'cancelado')
            ->selectRaw('competencia, COALESCE(SUM(valor), 0) as total')
            ->groupBy('competencia')
            ->get()
            ->mapWithKeys(fn ($linha) => [$linha->competencia => (float) $linha->total]);

        $fixas = null;

        foreach ($faltando as $competencia) {
            if ($geradas->has($competencia)) {
                $this->memo["despesaPrevista:{$competencia}"] = $geradas[$competencia];

                continue;
            }

            $fixas ??= ContaFixaPagar::where('ativo', true)->get();

            $this->memo["despesaPrevista:{$competencia}"] = (float) $fixas
                ->filter(fn (ContaFixaPagar $fixa) => $fixa->vigenteNaCompetencia($competencia))
                ->sum('valor');
        }
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
        return $this->caixaDoMes($mes ?? now())['entradas'];
    }

    /** O que SAIU do caixa no mês — ver `entradasDoMes()`. */
    public function saidasDoMes(?Carbon $mes = null): float
    {
        return $this->caixaDoMes($mes ?? now())['saidas'];
    }

    /**
     * O caixa de um mês, do que já foi carregado ou indo buscar.
     *
     * @return array{entradas: float, saidas: float}
     */
    private function caixaDoMes(Carbon $mes): array
    {
        $chave = $mes->format('Y-m');

        if (! array_key_exists($chave, $this->caixaPorMes)) {
            $this->carregarCaixa($mes);
        }

        // Mês além de hoje fica de fora do preenchimento de `carregarCaixa()`
        // (que só cobre até "agora"): "próximo mês" sem nada lançado ainda é
        // zero, não ausência — sem o padrão aqui, pedir esse mês estourava.
        return $this->caixaPorMes[$chave] ?? ['entradas' => 0.0, 'saidas' => 0.0];
    }

    /**
     * Carrega o livro-caixa de `$desde` em diante, mês a mês, NUMA consulta.
     *
     * Sem teto: movimento lançado com data futura conta para quem pergunta o
     * saldo de um mês passado — `serieDoSaldo()` desconta "tudo que se moveu
     * depois". Fechar a janela no mês corrente esconderia esse movimento do
     * desconto e faria a curva do saldo subir sem motivo.
     *
     * Meses sem movimento nenhum entram zerados: sem isso, cada mês vazio
     * voltaria a ser perguntado ao banco toda vez — e mês vazio é justamente o
     * caso de quem acabou de instalar.
     */
    private function carregarCaixa(Carbon $desde): void
    {
        $inicio = $desde->copy()->startOfMonth();

        // Já temos, e vindo de antes: nada a fazer.
        if ($this->caixaCarregadaDe !== null && $this->caixaCarregadaDe <= $inicio->format('Y-m')) {
            return;
        }

        // `SUBSTR(data, 1, 7)` recorta o "AAAA-MM" e funciona nos dois bancos —
        // o mesmo motivo de `serieDeEntrada()`: `DATE_FORMAT` é só do MySQL e a
        // suíte roda em SQLite.
        //
        // O sinal é o de `ContaFinanceira::reprocessarSaldo()`: só `saida`
        // subtrai, e `ajuste`/`transferencia` entram como entrada. E o escopo é
        // o de `saldoEmCaixa()`: só conta ativa — somar movimento de conta que
        // o saldo ignora é como as duas medidas param de fechar.
        $porMes = MovimentacaoFinanceira::query()
            ->whereIn('conta_financeira_id', ContaFinanceira::where('ativo', true)->select('id'))
            ->where('data', '>=', $inicio->toDateString())
            ->selectRaw('SUBSTR(data, 1, 7) as mes')
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo != 'saida' THEN valor ELSE 0 END), 0) as entradas")
            ->selectRaw("COALESCE(SUM(CASE WHEN tipo = 'saida' THEN valor ELSE 0 END), 0) as saidas")
            ->groupBy('mes')
            ->get()
            ->mapWithKeys(fn ($linha) => [$linha->mes => [
                'entradas' => (float) $linha->entradas,
                'saidas' => (float) $linha->saidas,
            ]])
            ->all();

        $this->caixaPorMes = $porMes;
        $this->caixaCarregadaDe = $inicio->format('Y-m');

        // Do início da janela até hoje, todo mês existe — com movimento ou sem.
        for ($mes = $inicio->copy(); $mes->lessThanOrEqualTo(now()); $mes->addMonth()) {
            $this->caixaPorMes[$mes->format('Y-m')] ??= ['entradas' => 0.0, 'saidas' => 0.0];
        }
    }

    /**
     * O que se movimentou DEPOIS do fim de um mês, com sinal — entradas menos
     * saídas. É o desconto que recompõe o saldo histórico em `serieDoSaldo()`.
     */
    private function movimentadoDepoisDe(Carbon $mes): float
    {
        $limite = $mes->format('Y-m');

        return array_sum(array_map(
            fn (array $caixa) => $caixa['entradas'] - $caixa['saidas'],
            array_filter(
                $this->caixaPorMes,
                fn (string $chave) => $chave > $limite,
                ARRAY_FILTER_USE_KEY
            )
        ));
    }

    /**
     * Os mesmos quatro números do topo do Funil de Vendas, reaproveitados
     * pelo Dashboard Comercial — `$vendedorId` restringe ao funil de UMA
     * pessoa, a mesma pergunta que `LeadController::index()` já faz para
     * montar os KPIs do quadro.
     *
     * @return array{total: int, abertos: int, fechados: int, perdidos: int, taxa_conversao: float, pipeline_valor: float, ticket_medio: float}
     */
    public function funilKpis(?int $vendedorId = null): array
    {
        $leads = Lead::query()->when($vendedorId, fn ($q) => $q->where('vendedor_id', $vendedorId))->get();

        $abertos = $leads->whereNotIn('estagio', Lead::ESTAGIOS_TERMINAIS);
        $fechados = $leads->where('estagio', 'cliente_ativo');
        $perdidos = $leads->where('estagio', 'perdido');
        $total = $leads->count();

        return [
            'total' => $total,
            'abertos' => $abertos->count(),
            'fechados' => $fechados->count(),
            'perdidos' => $perdidos->count(),
            'taxa_conversao' => $total > 0 ? ($fechados->count() / $total) * 100 : 0,
            'pipeline_valor' => (float) $abertos->sum('valor_estimado'),
            'ticket_medio' => $fechados->count() > 0 ? (float) $fechados->sum('valor_estimado') / $fechados->count() : 0,
        ];
    }

    /**
     * Quantos leads existem em cada estágio agora, e quanto valem — o
     * "avanço do funil" do Dashboard Comercial.
     *
     * `$vendedorId` restringe ao funil de UMA pessoa: é o mesmo recorte que
     * `Lead::vendedor_id` e `User::temEscopoComercial()` já aplicam no quadro
     * do Funil de Vendas — aqui, para o card de avanço.
     *
     * Devolve as sete etapas SEMPRE, mesmo as vazias: o card desenha um funil
     * com degrau em cada uma, e uma etapa ausente quebraria o desenho, não
     * apareceria como zero.
     *
     * @return list<array{estagio: string, label: string, quantidade: int, valor: float}>
     */
    public function funilPorEstagio(?int $vendedorId = null): array
    {
        $porEstagio = Lead::query()
            ->when($vendedorId, fn ($q) => $q->where('vendedor_id', $vendedorId))
            ->selectRaw('estagio, COUNT(*) as quantidade, COALESCE(SUM(valor_estimado), 0) as valor')
            ->groupBy('estagio')
            ->get()
            ->keyBy('estagio');

        return collect(Lead::ESTAGIOS)->map(fn ($label, $estagio) => [
            'estagio' => $estagio,
            'label' => $label,
            'quantidade' => (int) ($porEstagio[$estagio]->quantidade ?? 0),
            'valor' => (float) ($porEstagio[$estagio]->valor ?? 0),
        ])->values()->all();
    }

    /**
     * O fechado de cada vendedor numa competência — quem venceu o lead
     * (`estagio = cliente_ativo`) naquele mês, pela data em que o estágio
     * mudou. É a pergunta que "Vendas por vendedor" faz no Dashboard
     * Comercial, e a base do "realizado" que a Meta compara.
     *
     * @return Collection<int, array{vendedor_id: int, nome: string, valor: float, quantidade: int}>
     */
    public function vendasPorVendedor(string $competencia): Collection
    {
        $inicio = Carbon::createFromFormat('Y-m', $competencia)->startOfMonth();
        $fim = $inicio->copy()->endOfMonth();

        return Lead::query()
            ->where('estagio', 'cliente_ativo')
            ->whereBetween('estagio_atualizado_em', [$inicio, $fim])
            ->whereNotNull('vendedor_id')
            ->with('vendedor')
            ->get()
            ->groupBy('vendedor_id')
            ->map(fn ($leads, $vendedorId) => [
                'vendedor_id' => (int) $vendedorId,
                'nome' => $leads->first()->vendedor?->name ?? 'Vendedor removido',
                'valor' => (float) $leads->sum('valor_estimado'),
                'quantidade' => $leads->count(),
            ])
            ->values();
    }
}
