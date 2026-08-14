<?php

namespace Tests\Feature\Desempenho;

use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\MovimentacaoFinanceira;
use App\Models\User;
use App\Services\IndicadoresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Os painéis refaziam a mesma conta várias vezes no mesmo carregamento.
 *
 * Medido em 14/08/2026: o Painel Financeiro fazia 76 consultas e o Centro de
 * Controle 72. Dentro delas, 26 `SUM(valor)` idênticos sobre movimentações — o
 * gráfico de entradas × saídas recalculava mês a mês exatamente o que as curvas
 * dos cards já tinham calculado — e a mesma pergunta "esta competência já foi
 * faturada?" repetida oito vezes.
 *
 * O par que estes testes guardam: a tela ficou barata E os números continuam os
 * mesmos. O segundo importa mais — é dinheiro na tela.
 */
class PaineisTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Uma base com movimento espalhado por três meses, saldo em caixa e uma
     * competência faturada. Devolve os valores esperados, para o teste de
     * fidelidade não repetir a conta que está sendo verificada.
     */
    private function semearCaixa(): ContaFinanceira
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Conta movimento', 'tipo' => 'corrente', 'saldo' => 1000, 'ativo' => true,
        ]);

        // Uma conta INATIVA junto: o saldo e as somas ignoram o que ela move, e
        // é fácil uma reescrita passar a incluí-la sem ninguém notar.
        $inativa = ContaFinanceira::create([
            'nome' => 'Conta encerrada', 'tipo' => 'corrente', 'saldo' => 500, 'ativo' => false,
        ]);

        $lancar = fn (ContaFinanceira $onde, string $tipo, float $valor, string $data) => MovimentacaoFinanceira::create([
            'conta_financeira_id' => $onde->id,
            'tipo' => $tipo,
            'descricao' => 'Lançamento de teste',
            'valor' => $valor,
            // Obrigatório na tabela: o extrato mostra o saldo linha a linha. O
            // valor aqui não importa para nenhuma das somas medidas.
            'saldo_resultante' => 0,
            'data' => $data,
        ]);

        $mesAtual = now()->startOfMonth();
        $mesPassado = now()->startOfMonth()->subMonth();
        $retrasado = now()->startOfMonth()->subMonths(2);

        $lancar($conta, 'entrada', 300, $mesAtual->format('Y-m-05'));
        $lancar($conta, 'entrada', 200, $mesAtual->format('Y-m-09'));
        $lancar($conta, 'saida', 120, $mesAtual->format('Y-m-11'));
        $lancar($conta, 'ajuste', 50, $mesAtual->format('Y-m-12'));

        $lancar($conta, 'entrada', 800, $mesPassado->format('Y-m-10'));
        $lancar($conta, 'saida', 400, $mesPassado->format('Y-m-20'));

        $lancar($conta, 'entrada', 100, $retrasado->format('Y-m-15'));

        // Na conta inativa, no mês corrente: não pode entrar em conta nenhuma.
        $lancar($inativa, 'entrada', 9999, $mesAtual->format('Y-m-06'));

        return $conta;
    }

    /** Quantas consultas a requisição fez. */
    private function consultasDe(callable $requisicao): int
    {
        $quantas = 0;

        DB::listen(function () use (&$quantas): void {
            $quantas++;
        });

        $requisicao();

        return $quantas;
    }

    /**
     * @spec:AC-244 O Painel Financeiro cabe em 30 consultas, onde antes fazia 76.
     */
    public function test_painel_financeiro_cabe_em_trinta_consultas(): void
    {
        $usuario = User::factory()->create();
        $this->semearCaixa();

        $quantas = $this->consultasDe(
            fn () => $this->actingAs($usuario)->get('/dashboard')->assertOk()
        );

        $this->assertLessThanOrEqual(30, $quantas,
            "O Painel Financeiro fez {$quantas} consultas — alguma conta voltou a ser refeita.");
    }

    /**
     * @spec:AC-245 O Centro de Controle cabe em 30 consultas, onde antes fazia 72.
     */
    public function test_centro_de_controle_cabe_em_trinta_consultas(): void
    {
        $usuario = User::factory()->create();
        $this->semearCaixa();

        $quantas = $this->consultasDe(
            fn () => $this->actingAs($usuario)->get('/centro-controle')->assertOk()
        );

        $this->assertLessThanOrEqual(30, $quantas,
            "O Centro de Controle fez {$quantas} consultas — alguma conta voltou a ser refeita.");
    }

    /**
     * @spec:AC-246 Os números continuam exatamente os mesmos: entradas, saídas,
     * saldo, as curvas mês a mês e a receita recorrente da competência.
     */
    public function test_os_numeros_dos_paineis_nao_mudam(): void
    {
        $this->semearCaixa();

        $indicadores = app(IndicadoresService::class);

        // Entradas do mês somam entrada E ajuste — só `saida` subtrai —, e
        // ignoram a conta inativa.
        $this->assertSame(550.0, $indicadores->entradasDoMes());
        $this->assertSame(120.0, $indicadores->saidasDoMes());

        // Mês passado, pedido por data: a janela é a do mês inteiro.
        $this->assertSame(800.0, $indicadores->entradasDoMes(now()->startOfMonth()->subMonth()));
        $this->assertSame(400.0, $indicadores->saidasDoMes(now()->startOfMonth()->subMonth()));

        // Só conta ativa entra no saldo.
        $this->assertSame(1000.0, $indicadores->saldoEmCaixa());

        // As curvas, do mês mais antigo ao mais recente. Seis meses: os três
        // primeiros sem movimento nenhum.
        $this->assertSame([0.0, 0.0, 0.0, 100.0, 800.0, 550.0], $indicadores->serieDeEntradas(6));
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 400.0, 120.0], $indicadores->serieDeSaidas(6));

        // O saldo recomposto de trás para frente: o de hoje MENOS tudo que se
        // movimentou depois de cada mês. Não existe foto histórica do saldo, e
        // este é o único jeito honesto de desenhar a curva.
        //
        // Conferindo o mais recente: depois do fim do mês passado moveram-se
        // +300 +200 −120 +50 = 430, então o saldo ao fim dele era 1000 − 430 =
        // 570. Um mês antes, mais 800 − 400 = 400 → 170. E antes disso, mais
        // 100 → 70.
        $this->assertSame(
            [70.0, 70.0, 70.0, 170.0, 570.0, 1000.0],
            $indicadores->serieDoSaldo(6)
        );
    }

    /**
     * @spec:AC-246 A receita recorrente da competência não muda de valor nem de
     * regra: faturado onde o fechamento rodou, contratado enquanto não rodou.
     */
    public function test_a_receita_recorrente_nao_muda_de_valor(): void
    {
        $competencia = now()->format('Y-m');

        Cobranca::create([
            'descricao' => 'Locação do mês', 'valor' => 990, 'tipo' => 'locacao_sistema',
            'status' => 'pendente', 'competencia' => $competencia,
            'data_vencimento' => now()->endOfMonth()->toDateString(),
        ]);
        Cobranca::create([
            'descricao' => 'Locação cancelada', 'valor' => 500, 'tipo' => 'locacao_sistema',
            'status' => 'cancelado', 'competencia' => $competencia,
            'data_vencimento' => now()->endOfMonth()->toDateString(),
        ]);

        $indicadores = app(IndicadoresService::class);

        // A competência FOI faturada — há cobrança gerada —, então o número é o
        // faturado, e o cancelado fica de fora.
        $this->assertTrue($indicadores->competenciaFoiFaturada($competencia));
        $this->assertFalse($indicadores->mrrEhContratado());
        $this->assertSame(990.0, $indicadores->mrrDaCompetencia());

        // Competência sem fechamento nenhum continua respondendo zero pelo
        // caminho do faturado — e não pelo do contratado, que é foto de hoje.
        $this->assertFalse($indicadores->competenciaFoiFaturada('2020-01'));
        $this->assertSame(0.0, $indicadores->mrrDaCompetencia('2020-01'));
    }
}
