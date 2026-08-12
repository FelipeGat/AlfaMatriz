<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\MovimentacaoFinanceira;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * As contas por trás dos quatro cards do Centro de Controle.
 *
 * O `CentroControleTest` prova que a tela monta. Aqui a pergunta é outra: os
 * números FECHAM? Cada teste deste arquivo nasceu de um card que se
 * contradizia — a curva que discordava do valor impresso acima dela, o delta
 * que somava mais gente do que o total, a folga de caixa que encolhia quanto
 * mais organizada estivesse a agenda de contas.
 */
class CentroControleKpisTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function cards(): Collection
    {
        $resposta = $this->actingAs($this->operador())->get(route('centro-controle'));
        $resposta->assertOk();

        return collect($resposta->viewData('cards'));
    }

    /**
     * @spec:AC-040 O delta do card usa o MESMO critério do número grande. Ele
     * contava a tabela inteira enquanto o total contava só os ativos, e o card
     * anunciava "8 clientes ativos, +10 no mês" — mais entradas do que base.
     */
    public function test_o_delta_de_clientes_nao_pode_superar_o_total_do_card(): void
    {
        foreach (range(1, 8) as $i) {
            Cliente::create([
                'nome' => "Ativo {$i}", 'ativo' => true,
                'data_cadastro' => now()->startOfMonth()->addDay()->toDateString(),
            ]);
        }

        // Dois cadastrados no mesmo mês, porém inativos: não entram em lugar nenhum.
        foreach (range(1, 2) as $i) {
            Cliente::create([
                'nome' => "Inativo {$i}", 'ativo' => false,
                'data_cadastro' => now()->startOfMonth()->addDay()->toDateString(),
            ]);
        }

        $card = $this->cards()->firstWhere('rotulo', 'Clientes ativos');

        $this->assertSame('8', $card['valor']);
        $this->assertStringContainsString('+8 no mês', $card['delta'], 'O delta contou cliente inativo que o total não conta.');
    }

    /**
     * @spec:AC-040 Quem entrou é medido pela data de cadastro, não pelo dia em
     * que a linha nasceu. A base veio de importação: `created_at` marca a
     * migração para todo mundo de uma vez, e usá-lo fazia a base inteira
     * aparecer como entrada do mês.
     */
    public function test_cliente_antigo_importado_hoje_nao_conta_como_novo_do_mes(): void
    {
        // Cliente de dois anos atrás, cuja LINHA nasceu agora (importação).
        Cliente::create([
            'nome' => 'Cliente antigo', 'ativo' => true,
            'data_cadastro' => now()->subYears(2)->toDateString(),
        ]);

        Cliente::create([
            'nome' => 'Cliente novo', 'ativo' => true,
            'data_cadastro' => now()->startOfMonth()->addDay()->toDateString(),
        ]);

        $card = $this->cards()->firstWhere('rotulo', 'Clientes ativos');

        $this->assertSame('2', $card['valor']);
        $this->assertStringContainsString('+1 no mês', $card['delta'], 'O cliente importado hoje foi contado como entrada do mês.');

        // E a curva não pode ser um degrau: o antigo já estava lá nos meses anteriores.
        $this->assertEqualsWithDelta(1.0, $card['serie'][0], 0.01, 'O cliente de dois anos atrás precisa aparecer no início da série.');
        $this->assertEqualsWithDelta(2.0, $card['serie'][5], 0.01);
    }

    /**
     * @spec:AC-040 A minitendência do saldo usa o MESMO sinal do motor de
     * saldo. `reprocessarSaldo()` só subtrai `saida`; a série subtraía tudo
     * que não fosse `entrada`, então cada ajuste e cada transferência
     * deslocava a curva pelo dobro do próprio valor, para o lado errado.
     */
    public function test_a_curva_do_saldo_trata_ajuste_como_o_motor_de_saldo_trata(): void
    {
        $conta = ContaFinanceira::create([
            'nome' => 'Conta corrente', 'tipo' => 'corrente', 'saldo' => 0, 'ativo' => true,
        ]);

        // Um ajuste POSITIVO de 500, lançado neste mês.
        MovimentacaoFinanceira::create([
            'conta_financeira_id' => $conta->id, 'tipo' => 'ajuste',
            'descricao' => 'Ajuste de abertura', 'valor' => 500.00,
            'saldo_resultante' => 500.00, 'data' => now()->toDateString(),
        ]);

        $conta->reprocessarSaldo();

        // O motor de saldo diz: 500 positivos.
        $this->assertEqualsWithDelta(500.0, (float) $conta->fresh()->saldo, 0.01);

        $serie = $this->cards()->firstWhere('rotulo', 'Saldo em caixa')['serie'];

        // Antes do ajuste o caixa era zero. Como o ajuste soma, o mês anterior
        // fecha em 0 — e não em 1000, que é o que a conta errada devolvia.
        $this->assertEqualsWithDelta(0.0, $serie[4], 0.01, 'A série tratou o ajuste como saída e dobrou o valor no sentido errado.');
        $this->assertEqualsWithDelta(500.0, $serie[5], 0.01, 'O último ponto da curva é o saldo de hoje.');
    }

    /**
     * @spec:AC-040 Movimento de conta desativada não entra na curva, porque
     * também não entra no saldo de hoje. Misturar os dois escopos fazia a
     * curva não fechar com o card que ela acompanha.
     */
    public function test_a_curva_do_saldo_ignora_conta_desativada_como_o_card_ignora(): void
    {
        $ativa = ContaFinanceira::create(['nome' => 'Ativa', 'tipo' => 'corrente', 'saldo' => 300, 'ativo' => true]);
        $morta = ContaFinanceira::create(['nome' => 'Encerrada', 'tipo' => 'corrente', 'saldo' => 900, 'ativo' => false]);

        MovimentacaoFinanceira::create([
            'conta_financeira_id' => $morta->id, 'tipo' => 'entrada',
            'descricao' => 'Entrada em conta encerrada', 'valor' => 900.00,
            'saldo_resultante' => 900.00, 'data' => now()->toDateString(),
        ]);

        $serie = $this->cards()->firstWhere('rotulo', 'Saldo em caixa')['serie'];

        // O card só soma conta ativa: 300. A curva precisa terminar nele.
        $this->assertEqualsWithDelta(300.0, $serie[5], 0.01);
        // E o mês anterior também é 300 — o movimento de 900 é de conta que o card não vê.
        $this->assertEqualsWithDelta(300.0, $serie[4], 0.01, 'A curva descontou movimento de conta que o saldo do card nem enxerga.');

        $this->assertIsNumeric($ativa->saldo);
    }

    /**
     * @spec:AC-040 A folga de caixa mede a despesa que JÁ ocorreu. A soma não
     * tinha limite superior e engolia tudo que já está agendado para a frente
     * — e conta fixa nasce com meses de antecedência —, inflando a média e
     * encolhendo a folga na mesma proporção.
     */
    public function test_a_folga_de_caixa_ignora_despesa_agendada_para_o_futuro(): void
    {
        ContaFinanceira::create(['nome' => 'Caixa', 'tipo' => 'caixa', 'saldo' => 9000, 'ativo' => true]);

        // Três meses de despesa real: 900 por mês.
        foreach ([1, 2, 3] as $atras) {
            ContaPagar::create([
                'descricao' => "Despesa de {$atras} mês(es) atrás", 'valor' => 900.00,
                'data_vencimento' => now()->subMonths($atras)->toDateString(),
                'status' => 'pago', 'tipo' => 'fixa',
            ]);
        }

        // Um ano de contas fixas já agendadas para a frente. Nada disso é
        // despesa passada, e nada disso pode entrar na média.
        foreach (range(1, 12) as $frente) {
            ContaPagar::create([
                'descricao' => "Fixa agendada +{$frente}", 'valor' => 900.00,
                'data_vencimento' => now()->addMonths($frente)->toDateString(),
                'status' => 'em_aberto', 'tipo' => 'fixa',
            ]);
        }

        // E uma cancelada no passado, que também não é despesa.
        ContaPagar::create([
            'descricao' => 'Cancelada', 'valor' => 5000.00,
            'data_vencimento' => now()->subMonth()->toDateString(),
            'status' => 'cancelado', 'tipo' => 'avulsa',
        ]);

        $card = $this->cards()->firstWhere('rotulo', 'Saldo em caixa');

        // Média real: 2700/3 = 900/mês = 30/dia. 9000 / 30 = 300 dias.
        $this->assertStringContainsString('300 dias de folga', $card['delta']);
    }

    /**
     * @spec:AC-040 A curva do atraso termina no número que o card imprime.
     * Cada ponto somava o que VENCIA dentro do mês, então o último ponto
     * incluía título que ainda nem tinha vencido — e a linha contradizia o
     * valor logo acima dela.
     */
    public function test_a_curva_do_atraso_termina_no_valor_do_card(): void
    {
        // Vencido de verdade.
        Cobranca::create([
            'descricao' => 'Vencida', 'valor' => 1000.00,
            'data_vencimento' => now()->subDays(10)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta', 'competencia' => now()->format('Y-m'),
        ]);

        // Vence ainda neste mês, mas no futuro: não é atraso.
        Cobranca::create([
            'descricao' => 'A vencer', 'valor' => 7000.00,
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta', 'competencia' => now()->format('Y-m'),
        ]);

        $card = $this->cards()->firstWhere('rotulo', 'Atrasado');

        $this->assertStringContainsString('1.000,00', $card['valor']);
        $this->assertEqualsWithDelta(
            1000.0,
            end($card['serie']),
            0.01,
            'O último ponto da curva contou título que ainda não venceu — a linha discorda do card.'
        );
    }

    /**
     * @spec:AC-218 Competência sem fechamento mostra o contratado, marcado
     * como tal — e não R$ 0,00, como se a receita tivesse sumido na virada.
     */
    public function test_mes_sem_fechamento_mostra_o_contratado_e_nao_zero(): void
    {
        // Cliente direto com contrato mensal: R$ 2.500 de recorrente contratado.
        Cliente::create([
            'nome' => 'Cliente com contrato', 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 2500.00, 'dia_vencimento' => 10,
        ]);

        // Nenhuma cobrança na competência corrente: o fechamento não rodou.
        $card = $this->cards()->firstWhere('rotulo', 'Receita recorrente');

        $this->assertStringContainsString('2.500,00', $card['valor'], 'Sem fechamento, o card zerava em vez de mostrar o contratado.');
        $this->assertStringContainsString('contratado', $card['delta'], 'O card precisa dizer que o número é contratado, não faturado.');
    }

    /**
     * @spec:AC-218 Assim que o fechamento roda, quem manda é o faturado — o
     * contratado sai de cena e o card para de se anunciar como estimativa.
     */
    public function test_com_fechamento_gerado_o_card_volta_a_ser_o_faturado(): void
    {
        Cliente::create([
            'nome' => 'Cliente com contrato', 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 2500.00, 'dia_vencimento' => 10,
        ]);

        Cobranca::create([
            'descricao' => 'Faturado do mês', 'valor' => 4000.00,
            'data_vencimento' => now()->addDays(5)->toDateString(),
            'status' => 'pendente', 'tipo' => 'direta', 'competencia' => now()->format('Y-m'),
        ]);

        $card = $this->cards()->firstWhere('rotulo', 'Receita recorrente');

        $this->assertStringContainsString('4.000,00', $card['valor']);
        $this->assertStringNotContainsString('contratado', $card['delta']);
    }

    /**
     * @spec:AC-218 A régua de origem soma o mesmo total do card. Se cada um
     * aplicasse a própria regra, a soma das barras discordaria do número
     * impresso logo acima delas.
     */
    public function test_a_regua_soma_o_mesmo_que_o_card_quando_o_mes_nao_foi_fechado(): void
    {
        Cliente::create([
            'nome' => 'Direto com contrato', 'ativo' => true,
            'tipo_cliente' => 'CONTRATO', 'valor_mensal' => 2500.00, 'dia_vencimento' => 10,
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('centro-controle'));
        $resposta->assertOk();

        $card = collect($resposta->viewData('cards'))->firstWhere('rotulo', 'Receita recorrente');
        $regua = $resposta->viewData('origemMrr');

        $this->assertEqualsWithDelta(2500.0, (float) $regua['total'], 0.01);
        $this->assertStringContainsString(number_format($regua['total'], 2, ',', '.'), $card['valor']);
    }

    /**
     * @spec:AC-041 Revenda desativada que ainda tem receita na competência
     * continua na régua. Varrer só as ativas escondia a barra dela, e a soma
     * das barras deixava de bater com o card — sem nada explicando a falta.
     */
    public function test_revenda_desativada_com_receita_continua_na_regua(): void
    {
        $morta = Revenda::create(['nome' => 'Revenda Encerrada', 'ativo' => false]);

        Cobranca::create([
            'descricao' => 'Licenciamento da encerrada', 'valor' => 3000.00,
            'revenda_id' => $morta->id,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'locacao_sistema', 'competencia' => now()->format('Y-m'),
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('centro-controle'));
        $resposta->assertOk();

        $regua = $resposta->viewData('origemMrr');
        $linha = collect($regua['linhas'])->firstWhere('nome', 'Revenda Encerrada');

        $this->assertNotNull($linha, 'A receita da revenda desativada está no card mas sumiu da régua.');
        $this->assertEqualsWithDelta(3000.0, (float) $linha['valor'], 0.01);

        $card = collect($resposta->viewData('cards'))->firstWhere('rotulo', 'Receita recorrente');
        $this->assertEqualsWithDelta(3000.0, (float) $regua['total'], 0.01);
        $this->assertStringContainsString('3.000,00', $card['valor']);
    }
}
