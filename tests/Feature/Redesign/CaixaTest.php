<?php

namespace Tests\Feature\Redesign;

use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Caixa e extrato.
 *
 * O caixa responde "quanto temos e por quanto tempo isso dura"; o extrato
 * responde "como chegamos a esse número". Por isso o teste do extrato cobra o
 * saldo resultante linha a linha: sem ele, conferir a conta exige somar de
 * cabeça.
 */
class CaixaTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function conta(string $nome, float $saldo, bool $ativo = true): ContaFinanceira
    {
        return ContaFinanceira::create([
            'nome' => $nome, 'tipo' => 'corrente', 'saldo' => $saldo, 'ativo' => $ativo,
        ]);
    }

    /**
     * @spec:AC-056 O caixa mostra o consolidado, cada conta e o mês — saldo
     * total, um card por conta com variação, participação e tendência, e o
     * resumo de entradas, saídas e resultado.
     */
    public function test_o_caixa_mostra_consolidado_conta_a_conta_e_o_mes(): void
    {
        $principal = $this->conta('Bradesco PJ', 94218.40);
        $reserva = $this->conta('Reserva CDB', 50000.00);
        $encerrada = $this->conta('Conta antiga', 1000.00, ativo: false);

        // Movimentação do mês corrente, dos dois sinais.
        MovimentacaoFinanceira::create([
            'conta_financeira_id' => $principal->id, 'tipo' => 'entrada',
            'descricao' => 'Recebimento Invest', 'valor' => 8940.00,
            'saldo_resultante' => 94218.40, 'data' => now()->toDateString(),
        ]);
        MovimentacaoFinanceira::create([
            'conta_financeira_id' => $principal->id, 'tipo' => 'saida',
            'descricao' => 'Aluguel', 'valor' => 3200.00,
            'saldo_resultante' => 91018.40, 'data' => now()->toDateString(),
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('contas-financeiras.index'));
        $resposta->assertOk();

        // O consolidado soma só as contas ATIVAS: uma conta encerrada no total
        // faria o caixa parecer maior do que é.
        $this->assertEqualsWithDelta(144218.40, $resposta->viewData('saldoTotal'), 0.01);
        $resposta->assertSee('144.218,40', escape: false);
        $resposta->assertSee('Saldo total consolidado', escape: false);

        // Um card por conta, com participação e tendência.
        $cartoes = $resposta->viewData('cartoes');
        $this->assertCount(3, $cartoes);

        $cartaoPrincipal = $cartoes->firstWhere('conta.id', $principal->id);
        $this->assertEqualsWithDelta(94218.40 / 144218.40, $cartaoPrincipal['share'], 0.0001);
        $this->assertCount(6, $cartaoPrincipal['serie'], 'A tendência mostra seis meses.');

        // A conta sem movimento no mês é declarada estável, não deixada em branco.
        $this->assertSame('estável', $cartoes->firstWhere('conta.id', $reserva->id)['variacao']);

        // Resumo do mês: entradas, saídas e resultado.
        $mes = $resposta->viewData('mes');
        $this->assertEqualsWithDelta(8940.00, $mes['entradas'], 0.01);
        $this->assertEqualsWithDelta(3200.00, $mes['saidas'], 0.01);
        $this->assertEqualsWithDelta(5740.00, $mes['resultado'], 0.01);

        // As barras do resumo compartilham a escala — é o que deixa comparar.
        $html = $resposta->getContent();
        $this->assertMatchesRegularExpression('/data-barra="entradas"[^>]*width:\s*100%/', $html);
        $this->assertMatchesRegularExpression(
            '/data-barra="saidas"[^>]*width:\s*35\.\d+%/',
            $html,
            '3200 sobre 8940 é ~35,8% da maior barra.'
        );

        // E as últimas movimentações aparecem com o sinal.
        $this->assertCount(2, $resposta->viewData('ultimas'));
        $resposta->assertSee('Recebimento Invest', escape: false);
        $resposta->assertSee('Extrato', escape: false);
    }

    /**
     * @spec:AC-057 O extrato mostra o saldo resultante linha a linha, com data,
     * descrição, tipo e valor com sinal.
     */
    public function test_o_extrato_mostra_o_saldo_depois_de_cada_lancamento(): void
    {
        $conta = $this->conta('Bradesco PJ', 11800.00);

        MovimentacaoFinanceira::create([
            'conta_financeira_id' => $conta->id, 'tipo' => 'entrada',
            'descricao' => 'Recebimento de mensalidade', 'valor' => 5000.00,
            'saldo_resultante' => 15000.00, 'data' => now()->subDays(2)->toDateString(),
        ]);
        MovimentacaoFinanceira::create([
            'conta_financeira_id' => $conta->id, 'tipo' => 'saida',
            'descricao' => 'Pagamento de servidores', 'valor' => 3200.00,
            'saldo_resultante' => 11800.00, 'data' => now()->toDateString(),
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('contas-financeiras.extrato', $conta));
        $resposta->assertOk();

        $this->assertCount(2, $resposta->viewData('movimentacoes'));

        $html = $resposta->getContent();

        // Data, descrição e tipo.
        $resposta->assertSee('Recebimento de mensalidade', escape: false);
        $resposta->assertSee('Pagamento de servidores', escape: false);
        $resposta->assertSee('Entrada', escape: false);
        $resposta->assertSee('Saida', escape: false);

        // Valor COM SINAL: sem ele, entrada e saída viram a mesma coisa numa
        // varredura rápida da coluna.
        $this->assertStringContainsString('+R$ 5.000,00', $html);
        $this->assertStringContainsString('−R$ 3.200,00', $html);

        // E o saldo depois de cada lançamento — é ele que permite conferir a
        // conta linha a linha.
        $this->assertStringContainsString('15.000,00', $html);
        $this->assertStringContainsString('11.800,00', $html);
        $resposta->assertSee('Saldo resultante', escape: false);
        $resposta->assertSee('2 de 2 movimentações', escape: false);
    }
}
