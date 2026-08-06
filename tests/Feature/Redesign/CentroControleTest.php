<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O Centro de Controle: a tela que responde "o que precisa de mim hoje".
 *
 * Aqui os testes montam pendência de verdade no banco e conferem que ela
 * aparece na fila com o caminho para resolvê-la — e que a régua de origem da
 * receita desenha barra proporcional ao valor, que é o bug que o protótipo
 * levou para casa e não pode voltar.
 */
class CentroControleTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-039 A fila de ação lista o que pede decisão e leva até lá — cada
     * pendência vira uma linha com severidade, descrição, valor e um botão que
     * abre a tela onde o problema se resolve.
     */
    public function test_a_fila_lista_a_pendencia_real_e_leva_para_a_tela_que_resolve(): void
    {
        Cobranca::create([
            'descricao' => 'Mensalidade Invest Soluções',
            'valor' => 8940.00,
            'data_vencimento' => now()->subDays(12)->toDateString(),
            'status' => 'pendente',
            'tipo' => 'locacao_sistema',
            'competencia' => now()->format('Y-m'),
        ]);

        ContaPagar::create([
            'descricao' => 'Licenças JetBrains',
            'valor' => 1240.00,
            'data_vencimento' => now()->subDays(3)->toDateString(),
            'status' => 'em_aberto',
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('centro-controle'));

        $resposta->assertOk();

        $fila = $resposta->viewData('fila');
        $titulos = array_column($fila, 'titulo');

        $this->assertNotEmpty($fila, 'Havia receita atrasada e despesa vencida — a fila não podia estar vazia.');

        // A receita atrasada é crítica e conhece o caminho da cobrança.
        $atrasada = collect($fila)->firstWhere('acao', 'Cobrar');
        $this->assertNotNull($atrasada, 'A receita em atraso deveria virar uma linha com ação de cobrar. Fila: '.implode(' | ', $titulos));
        $this->assertSame('critico', $atrasada['nivel']);
        $this->assertStringContainsString('8.940,00', $atrasada['valor']);
        $this->assertStringContainsString(route('cobrancas.index'), $atrasada['rota']);

        // A despesa vencida é atenção e leva para Despesas.
        $vencida = collect($fila)->firstWhere('acao', 'Pagar');
        $this->assertNotNull($vencida, 'A despesa vencida deveria virar uma linha com ação de pagar.');
        $this->assertSame('atencao', $vencida['nivel']);
        $this->assertStringContainsString(route('contas-pagar.index'), $vencida['rota']);

        // Crítico antes de atenção: a ordem da fila é a ordem da urgência.
        $niveis = array_column($fila, 'nivel');
        $this->assertLessThan(
            array_search('atencao', $niveis, true),
            array_search('critico', $niveis, true),
            'O que é crítico precisa vir antes do que é só atenção.'
        );

        // E o botão de cada linha chega ao HTML, não só ao controller.
        $resposta->assertSee('Cobrar', escape: false);
        $resposta->assertSee('Pagar', escape: false);
    }

    /**
     * @spec:AC-040 Os cards de destaque trazem valor, variação e tendência —
     * os quatro números que resumem o negócio, calculados a partir do banco.
     */
    public function test_os_quatro_cards_trazem_valor_variacao_e_tendencia_do_banco(): void
    {
        // Dois meses de receita recorrente: o card precisa comparar um com o outro.
        Cobranca::create([
            'descricao' => 'Recorrente mês passado', 'valor' => 1000.00,
            'data_vencimento' => now()->subMonth()->toDateString(), 'status' => 'pago',
            'tipo' => 'locacao_sistema', 'competencia' => now()->subMonth()->format('Y-m'),
        ]);
        Cobranca::create([
            'descricao' => 'Recorrente deste mês', 'valor' => 1500.00,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'locacao_sistema', 'competencia' => now()->format('Y-m'),
        ]);

        Cliente::create(['nome' => 'Academia Central', 'ativo' => true]);

        $resposta = $this->actingAs($this->operador())->get(route('centro-controle'));
        $resposta->assertOk();

        $cards = $resposta->viewData('cards');
        $this->assertCount(4, $cards, 'São quatro números de resumo no topo da tela.');

        $rotulos = array_column($cards, 'rotulo');
        foreach (['Receita recorrente', 'Saldo em caixa', 'Atrasado', 'Clientes ativos'] as $esperado) {
            $this->assertContains($esperado, $rotulos);
        }

        $recorrente = collect($cards)->firstWhere('rotulo', 'Receita recorrente');

        // O valor sai da soma real das cobranças da competência.
        $this->assertStringContainsString('1.500,00', $recorrente['valor']);

        // 1000 → 1500 é +50%, e isso é um avanço.
        $this->assertStringContainsString('50,0%', $recorrente['delta']);
        $this->assertSame('bom', $recorrente['sinal']);

        // A tendência tem um ponto por mês da janela, senão não é tendência.
        $this->assertCount(6, $recorrente['serie']);
        $this->assertEqualsWithDelta(1000.0, $recorrente['serie'][4], 0.01, 'O penúltimo ponto é o mês passado.');
        $this->assertEqualsWithDelta(1500.0, $recorrente['serie'][5], 0.01, 'O último ponto é o mês corrente.');

        $clientes = collect($cards)->firstWhere('rotulo', 'Clientes ativos');
        $this->assertSame('1', $clientes['valor']);
    }

    /**
     * @spec:AC-041 A régua de origem da receita desenha barras proporcionais ao
     * valor — a maior origem tem a maior barra, na escala comum da régua.
     */
    public function test_a_regua_desenha_barra_proporcional_ao_valor(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $competencia = now()->format('Y-m');

        // A revenda fatura bem menos que a venda direta. No protótipo original
        // a barra da venda direta aparecia MENOR que a da revenda — é
        // exatamente isso que este teste impede de voltar.
        Cobranca::create([
            'descricao' => 'Atacado Invest', 'valor' => 8940.00, 'revenda_id' => $revenda->id,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'locacao_sistema', 'competencia' => $competencia,
        ]);
        Cobranca::create([
            'descricao' => 'Venda direta', 'valor' => 33000.00,
            'data_vencimento' => now()->toDateString(), 'status' => 'pendente',
            'tipo' => 'direta', 'competencia' => $competencia,
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('centro-controle'));
        $resposta->assertOk();

        $regua = $resposta->viewData('origemMrr');
        $linhas = collect($regua['linhas']);

        $direta = $linhas->firstWhere('nome', 'Venda direta');
        $invest = $linhas->firstWhere('nome', 'Invest Soluções');

        $this->assertNotNull($direta);
        $this->assertNotNull($invest);

        // Quem vale mais aparece primeiro e ocupa mais pista.
        $this->assertSame('Venda direta', $linhas->first()['nome'], 'A régua é ordenada por valor.');
        $this->assertGreaterThan(
            $invest['largura'],
            $direta['largura'],
            'A venda direta vale quase 4x a revenda — a barra dela precisa ser maior.'
        );

        // Proporcional de verdade: a razão entre as barras é a razão entre os
        // valores, e a largura é a fração do valor na escala comum.
        $this->assertEqualsWithDelta(
            33000 / 8940,
            $direta['largura'] / $invest['largura'],
            0.001,
            'A razão entre as barras precisa ser a razão entre os valores.'
        );
        $this->assertEqualsWithDelta(33000 / $regua['escala'], $direta['largura'], 0.0001);

        // A escala nunca encolhe abaixo de 35 mil, senão as guias de
        // 10k/20k/30k deixam de fazer sentido.
        $this->assertGreaterThanOrEqual(35000, $regua['escala']);
        $this->assertLessThanOrEqual(1.0, $direta['largura'], 'Nenhuma barra pode estourar a pista.');

        // E a largura chega ao HTML como percentual, não como número mágico.
        $html = $resposta->getContent();
        $this->assertMatchesRegularExpression(
            '/data-barra="Venda direta"[^>]*width:\s*'.preg_quote(rtrim(rtrim(number_format($direta['largura'] * 100, 3, '.', ''), '0'), '.'), '/').'%/',
            $html,
            'A barra da venda direta precisa sair no HTML com a largura calculada.'
        );
    }
}
