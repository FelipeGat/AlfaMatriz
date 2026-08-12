<?php

namespace App\Services;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\FaturamentoSnapshot;
use App\Models\Revenda;
use App\Models\Sistema;

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
        return $this->serieDeEntrada(fn () => Sistema::where('ativo', true), Sistema::expressaoDeEntrada(), $meses);
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
                    now()->copy()->subMonths($atras)->endOfMonth()->toDateString(),
                ])
                ->count())
            ->all();
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
        return Sistema::where('ativo', true)->count();
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
        return Sistema::where('ativo', true)
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

    public function entradasDoMes(): float
    {
        return (float) Cobranca::where('status', 'pago')
            ->whereBetween('data_pagamento', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('valor_pago');
    }

    public function saidasDoMes(): float
    {
        return (float) ContaPagar::where('status', 'pago')
            ->whereBetween('data_pagamento', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('valor_pago');
    }
}
