<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As contas por trás do painel Financeiro.
 *
 * Dois problemas moravam aqui. O primeiro: "Receita recorrente" era somada
 * por uma regra diferente da do Centro de Controle, e as duas telas exibiam
 * valores distintos sob o mesmo rótulo, no mesmo dia. O segundo: "Entradas" e
 * "Saídas do mês" contavam TÍTULOS baixados, não caixa — então o "Saldo em
 * caixa" ao lado se movia por valores que os dois cards não explicavam.
 */
class FinanceiroKpisTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function conta(float $abertura = 0, string $quando = 'agora'): ContaFinanceira
    {
        $conta = ContaFinanceira::create(['nome' => 'Bradesco PJ', 'tipo' => 'corrente', 'saldo' => 0, 'ativo' => true]);

        if ($abertura !== 0.0) {
            $conta->movimentacoes()->create([
                'tipo' => 'ajuste', 'descricao' => 'Saldo inicial', 'valor' => $abertura,
                'saldo_resultante' => 0,
                'data' => ($quando === 'agora' ? now() : now()->subMonth())->toDateString(),
            ]);
            $conta->reprocessarSaldo();
        }

        return $conta;
    }

    /**
     * @spec:AC-062 O MESMO indicador dá o mesmo número nas duas telas.
     *
     * O Centro de Controle ganhou a regra do contratado e o Financeiro não:
     * durante uma versão, um mostrava a receita contratada e o outro R$ 0,00,
     * ambos rotulados "Receita recorrente".
     */
    public function test_a_receita_recorrente_bate_com_o_centro_de_controle(): void
    {
        Cliente::create([
            'nome' => 'Cliente com contrato', 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 2500.00, 'dia_vencimento' => 10,
        ]);

        $operador = $this->operador();
        $financeiro = $this->actingAs($operador)->get(route('dashboard'));
        $centro = $this->actingAs($operador)->get(route('centro-controle'));

        $doCentro = collect($centro->viewData('cards'))->firstWhere('rotulo', 'Receita recorrente');

        $this->assertEqualsWithDelta(2500.0, (float) $financeiro->viewData('mrr'), 0.01);
        $this->assertStringContainsString('2.500,00', $doCentro['valor'], 'As duas telas divergiram sobre o mesmo indicador.');

        // E o Financeiro também declara que o número é contratado.
        $this->assertTrue($financeiro->viewData('mrrContratado'));
        $financeiro->assertSee('contratado', escape: false);

        // A projeção anual acompanha, em vez de multiplicar um zero.
        $this->assertEqualsWithDelta(30000.0, (float) $financeiro->viewData('arr'), 0.01);
    }

    /**
     * @spec:AC-062 Fechada a competência, as duas telas passam juntas para o
     * faturado — e o Financeiro para de se anunciar como estimativa.
     */
    public function test_com_fechamento_as_duas_telas_passam_juntas_para_o_faturado(): void
    {
        Cobranca::create([
            'descricao' => 'Faturado do mês', 'valor' => 4000.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta', 'competencia' => now()->format('Y-m'),
        ]);

        $financeiro = $this->actingAs($this->operador())->get(route('dashboard'));

        $this->assertEqualsWithDelta(4000.0, (float) $financeiro->viewData('mrr'), 0.01);
        $this->assertFalse($financeiro->viewData('mrrContratado'));
    }

    /**
     * @spec:AC-042 Entradas menos saídas explicam o movimento do saldo. Era o
     * que não fechava: o saldo vinha do livro-caixa e os dois cards, de
     * títulos baixados.
     */
    public function test_entradas_menos_saidas_explicam_o_movimento_do_saldo(): void
    {
        $conta = $this->conta(10000.00, 'mes passado');

        Cobranca::create([
            'descricao' => 'Recebida', 'valor' => 5000.00,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'avulsa', 'conta_financeira_id' => $conta->id,
        ])->baixar(5000.00, now()->toDateString());

        ContaPagar::create([
            'descricao' => 'Aluguel', 'valor' => 3200.00,
            'data_vencimento' => now()->toDateString(), 'status' => 'em_aberto',
            'conta_financeira_id' => $conta->id,
        ])->baixar(3200.00, now()->toDateString());

        $resposta = $this->actingAs($this->operador())->get(route('dashboard'));

        $entradas = (float) $resposta->viewData('entradasMes');
        $saidas = (float) $resposta->viewData('saidasMes');
        $saldo = (float) $resposta->viewData('saldoTotal');

        $this->assertEqualsWithDelta(5000.0, $entradas, 0.01);
        $this->assertEqualsWithDelta(3200.0, $saidas, 0.01);

        // A conta que a tela precisa deixar fechar: abertura + entradas − saídas.
        $this->assertEqualsWithDelta(10000.0 + $entradas - $saidas, $saldo, 0.01);
    }

    /**
     * @spec:AC-042 Ajuste de caixa é entrada de dinheiro, e agora aparece.
     * Antes ele mexia no saldo e não constava em lugar nenhum da tela.
     */
    public function test_ajuste_de_caixa_entra_no_mes(): void
    {
        $this->conta(7500.00);

        $resposta = $this->actingAs($this->operador())->get(route('dashboard'));

        $this->assertEqualsWithDelta(7500.0, (float) $resposta->viewData('entradasMes'), 0.01);
        $this->assertEqualsWithDelta(7500.0, (float) $resposta->viewData('saldoTotal'), 0.01);
    }

    /**
     * @spec:AC-042 Título marcado como pago SEM conta financeira não move
     * caixa nenhum — e deixou de ser contado como entrada. O dinheiro não
     * entrou em conta alguma: contá-lo era anunciar um recebimento que o saldo
     * não confirma.
     */
    public function test_titulo_pago_sem_conta_nao_vira_entrada(): void
    {
        $this->conta(1000.00, 'mes passado');

        Cobranca::create([
            'descricao' => 'Paga sem conta', 'valor' => 9999.00, 'valor_pago' => 9999.00,
            'data_vencimento' => now()->toDateString(), 'data_pagamento' => now()->toDateString(),
            'status' => 'pago', 'tipo' => 'avulsa',
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('dashboard'));

        $this->assertEqualsWithDelta(0.0, (float) $resposta->viewData('entradasMes'), 0.01);
        $this->assertEqualsWithDelta(1000.0, (float) $resposta->viewData('saldoTotal'), 0.01);
    }

    /**
     * @spec:AC-042 O último ponto do gráfico é o número dos cards. Enquanto o
     * gráfico tinha a própria cópia da conta, eram duas implementações do
     * mesmo valor esperando divergir.
     */
    public function test_o_ultimo_ponto_do_grafico_e_o_card(): void
    {
        $conta = $this->conta(2000.00, 'mes passado');

        Cobranca::create([
            'descricao' => 'Recebida', 'valor' => 800.00,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'avulsa', 'conta_financeira_id' => $conta->id,
        ])->baixar(800.00, now()->toDateString());

        $resposta = $this->actingAs($this->operador())->get(route('dashboard'));
        $historico = $resposta->viewData('historico');

        $this->assertCount(6, $historico);
        $this->assertEqualsWithDelta((float) $resposta->viewData('entradasMes'), $historico[5]['entradas'], 0.01);
        $this->assertEqualsWithDelta((float) $resposta->viewData('saidasMes'), $historico[5]['saidas'], 0.01);
    }

    /**
     * @spec:AC-062 As curvas do Financeiro são as mesmas do Centro de
     * Controle, e vêm da mesma origem.
     */
    public function test_as_curvas_batem_com_as_do_centro_de_controle(): void
    {
        $conta = $this->conta(4000.00, 'mes passado');

        Cobranca::create([
            'descricao' => 'Recebida', 'valor' => 600.00,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'avulsa', 'conta_financeira_id' => $conta->id,
        ])->baixar(600.00, now()->toDateString());

        $operador = $this->operador();
        $financeiro = $this->actingAs($operador)->get(route('dashboard'));
        $centro = $this->actingAs($operador)->get(route('centro-controle'));

        $doCentro = collect($centro->viewData('cards'))->firstWhere('rotulo', 'Saldo em caixa');

        $this->assertSame($doCentro['serie'], $financeiro->viewData('serieSaldo'), 'As duas telas desenham curvas diferentes do mesmo saldo.');
    }

    /**
     * @spec:AC-062 Base sem movimento não ganha uma reta no zero: curva toda
     * zerada não é tendência, é ruído.
     */
    public function test_sem_movimento_as_curvas_ficam_vazias(): void
    {
        $resposta = $this->actingAs($this->operador())->get(route('dashboard'));

        foreach (['serieSaldo', 'serieEntradas', 'serieSaidas', 'serieMrr'] as $serie) {
            $this->assertSame([], $resposta->viewData($serie), "A curva $serie desenhou uma reta no zero.");
        }
    }
}
