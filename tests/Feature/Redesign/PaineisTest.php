<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Os painéis Financeiro e Comercial — as duas telas de leitura.
 *
 * O que se prova aqui é que elas deixam COMPARAR: o financeiro mostra o mês
 * contra o histórico e contra o que está em aberto; o comercial mostra cada
 * produto contra o líder e contra o total. Número solto não precisa de painel.
 */
class PaineisTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-042 O painel Financeiro mostra o mês, o histórico e o que está
     * em aberto — cinco números, o gráfico de seis meses e as duas listas de
     * pendências, cada uma com atalho para a tela cheia.
     */
    public function test_o_painel_financeiro_mostra_mes_historico_e_pendencias(): void
    {
        $conta = ContaFinanceira::create(['nome' => 'Bradesco PJ', 'tipo' => 'corrente', 'saldo' => 0, 'ativo' => true]);

        // Saldo de abertura, lançado no mês PASSADO. Entradas e saídas agora
        // saem do livro-caixa, e um ajuste de abertura lançado hoje entraria
        // em "Entradas do mês" — ele é dinheiro que entrou na conta, mas não é
        // receita do mês. Datado no mês anterior, ele compõe o saldo sem
        // poluir o número do mês corrente.
        $conta->movimentacoes()->create([
            'tipo' => 'ajuste', 'descricao' => 'Saldo inicial', 'valor' => 92418.40,
            'saldo_resultante' => 0, 'data' => now()->subMonth()->startOfMonth()->toDateString(),
        ]);

        Cobranca::create([
            'descricao' => 'Mensalidade Invest', 'valor' => 8940.00,
            'data_vencimento' => now()->addDays(4)->toDateString(),
            'status' => 'pendente', 'tipo' => 'locacao_sistema',
            'competencia' => now()->format('Y-m'),
        ]);

        // Uma entrada e uma saída já liquidadas: é delas que sai o gráfico.
        // Liquidadas HOJE de propósito: com data relativa (subDays) o
        // lançamento atravessa a virada do mês nos primeiros dias e cai no
        // ponto anterior do gráfico, fazendo o teste falhar por calendário.
        // Baixadas pelo caminho real (`baixar`), que é o que escreve no
        // livro-caixa. Marcar `status = pago` direto na linha move o título e
        // não move dinheiro nenhum — o saldo não mudaria, e uma "entrada" sem
        // lastro no caixa é justamente o que esta tela deixou de contar.
        Cobranca::create([
            'descricao' => 'Recebida', 'valor' => 5000.00,
            'data_vencimento' => now()->toDateString(),
            'status' => 'pendente', 'tipo' => 'avulsa',
            'conta_financeira_id' => $conta->id,
        ])->baixar(5000.00, now()->toDateString());

        ContaPagar::create([
            'descricao' => 'Aluguel', 'valor' => 3200.00,
            'data_vencimento' => now()->toDateString(),
            'status' => 'em_aberto',
            'conta_financeira_id' => $conta->id,
        ])->baixar(3200.00, now()->toDateString());
        ContaPagar::create([
            'descricao' => 'Servidores', 'valor' => 1800.00,
            'data_vencimento' => now()->addDays(9)->toDateString(),
            'status' => 'em_aberto',
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('dashboard'));
        $resposta->assertOk();

        // Os cinco números do mês.
        foreach (['Receita recorrente', 'Projeção anual', 'Saldo em caixa', 'Entradas do mês', 'Saídas do mês'] as $rotulo) {
            $resposta->assertSee($rotulo, escape: false);
        }
        $resposta->assertSee('94.218,40', escape: false);

        // A projeção anual é derivada, e a tela declara a simplificação.
        $this->assertEqualsWithDelta(
            $resposta->viewData('mrr') * 12,
            $resposta->viewData('arr'),
            0.01
        );
        $resposta->assertSee('não considera sazonalidade', escape: false);

        // O histórico tem um ponto por mês da janela — por padrão o ano
        // inteiro, janeiro a dezembro, com o realizado até o mês corrente e o
        // previsto dali em diante. O ponto do mês corrente é o que se moveu de
        // fato no caixa.
        $historico = $resposta->viewData('historico');
        $this->assertCount(12, $historico);
        $this->assertEqualsWithDelta(5000.0, $historico[now()->month - 1]['entradas'], 0.01);
        $this->assertEqualsWithDelta(3200.0, $historico[now()->month - 1]['saidas'], 0.01);

        // As duas listas de pendência, cada uma com o caminho da tela cheia.
        $this->assertCount(1, $resposta->viewData('receitasPendentes'));
        $this->assertCount(1, $resposta->viewData('despesasPendentes'));
        $resposta->assertSee('Mensalidade Invest', escape: false);
        $resposta->assertSee('Servidores', escape: false);
        $resposta->assertSee(route('cobrancas.index', ['status' => 'pendente']), escape: false);
        $resposta->assertSee(route('contas-pagar.index', ['status' => 'em_aberto']), escape: false);
    }

    /**
     * @spec:AC-043 O painel Comercial ranqueia os produtos com grandeza
     * comparável — total, líder e participação de cada produto, com a barra
     * proporcional ao líder e a faixa segmentada proporcional ao total.
     */
    public function test_o_ranking_comercial_deixa_comparar_produto_a_produto(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $lider = Sistema::create(['nome' => 'AlfaJornada', 'slug' => 'alfajornada', 'unidade_cobranca' => 'vida agregada', 'ativo' => true]);
        $segundo = Sistema::create(['nome' => 'AlfaHome', 'slug' => 'alfahome', 'unidade_cobranca' => 'condomínio ativo', 'ativo' => true]);
        $terceiro = Sistema::create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'unidade_cobranca' => 'academia ativa', 'ativo' => true]);

        // 6 clientes no líder, 3 no segundo, 1 no terceiro.
        foreach ([[$lider, 6], [$segundo, 3], [$terceiro, 1]] as [$sistema, $quantos]) {
            for ($i = 0; $i < $quantos; $i++) {
                $cliente = Cliente::create([
                    'nome' => $sistema->nome.' cliente '.$i,
                    'revenda_id' => $revenda->id,
                    'ativo' => true,
                ]);
                $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);
            }
        }

        $resposta = $this->actingAs($this->operador())->get(route('comercial'));
        $resposta->assertOk();

        $ranking = $resposta->viewData('rankingClientes');
        $itens = collect($ranking['itens']);

        // Ordenado por grandeza, com o líder declarado no topo.
        $this->assertSame('AlfaJornada', $itens->first()['nome']);
        $this->assertSame('AlfaJornada', $ranking['lider']['nome']);
        $this->assertEqualsWithDelta(10.0, $ranking['total'], 0.01, 'O total é a soma dos clientes ativos ranqueados.');

        // Participação = fatia do total. 6 de 10 é 60%.
        $this->assertEqualsWithDelta(0.6, $itens->first()['share'], 0.001);
        $this->assertEqualsWithDelta(0.3, $itens->firstWhere('nome', 'AlfaHome')['share'], 0.001);

        // Barra = tamanho relativo AO LÍDER. O líder ocupa a pista inteira;
        // o segundo, metade dela. Usar o total aqui achataria o ranking.
        $this->assertEqualsWithDelta(1.0, $itens->first()['largura'], 0.001);
        $this->assertEqualsWithDelta(0.5, $itens->firstWhere('nome', 'AlfaHome')['largura'], 0.001);

        // Posição em dois dígitos, como no desenho.
        $this->assertSame('01', $itens->first()['posicao']);
        $this->assertSame('02', $itens->get(1)['posicao']);

        // E as barras chegam ao HTML com a largura calculada.
        $html = $resposta->getContent();
        $this->assertStringContainsString('data-barra="AlfaJornada"', $html);
        $this->assertMatchesRegularExpression(
            '/data-barra="AlfaHome"[^>]*width:\s*50%/',
            $html,
            'A barra do segundo colocado precisa sair com metade da pista.'
        );

        // O painel de valor gerado existe e usa a mesma gramática.
        $this->assertIsArray($resposta->viewData('rankingValor'));
        $resposta->assertSee('Produtos por clientes ativos', escape: false);
        $resposta->assertSee('Produtos por valor gerado', escape: false);
        $resposta->assertSee('Clientes por revenda', escape: false);
        $resposta->assertSee('Portfólio por categoria', escape: false);
    }
}
