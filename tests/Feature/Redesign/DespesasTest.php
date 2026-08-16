<?php

namespace Tests\Feature\Redesign;

use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela de Despesas.
 *
 * Mesma gramática das Receitas — faixa de atraso, seleção em massa, prazo real
 * ao lado da data — porque a pergunta é a mesma dos dois lados do caixa: onde
 * o dinheiro está travado e o que dá para resolver de uma vez.
 */
class DespesasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function despesa(string $descricao, float $valor, int $diasAteVencer, string $status = 'em_aberto'): ContaPagar
    {
        return ContaPagar::create([
            'descricao' => $descricao,
            'valor' => $valor,
            'data_vencimento' => now()->addDays($diasAteVencer)->toDateString(),
            'status' => $status,
        ]);
    }

    /**
     * @spec:AC-053 A faixa de atraso distribui o que está em aberto nas quatro
     * faixas de vencimento, com barra proporcional a cada uma.
     */
    public function test_a_faixa_de_atraso_distribui_o_aberto_por_vencimento(): void
    {
        $this->despesa('A vencer', 1000.00, 10);      // a vencer
        $this->despesa('Atraso curto', 500.00, -8);   // 1 a 15
        $this->despesa('Atraso médio', 300.00, -20);  // 16 a 30
        $this->despesa('Atraso longo', 200.00, -45);  // +30
        $this->despesa('Já paga', 900.00, -5, 'pago'); // fora: não está em aberto

        $resposta = $this->actingAs($this->operador())->get(route('contas-pagar.index'));
        $resposta->assertOk();

        $faixas = $resposta->viewData('faixas');

        $this->assertEqualsWithDelta(1000.0, $faixas['a_vencer']['valor'], 0.01);
        $this->assertEqualsWithDelta(500.0, $faixas['1_15']['valor'], 0.01);
        $this->assertEqualsWithDelta(300.0, $faixas['16_30']['valor'], 0.01);
        $this->assertEqualsWithDelta(200.0, $faixas['mais_30']['valor'], 0.01);

        // O que já foi pago não é "em aberto" e não pode inflar a faixa.
        $this->assertEqualsWithDelta(2000.0, collect($faixas)->sum('valor'), 0.01);

        // A barra de cada faixa é proporcional ao total em aberto: 500 de
        // 2000 é um quarto da largura.
        $html = $resposta->getContent();
        $this->assertMatchesRegularExpression(
            '/data-faixa="1_15"[^>]*width:\s*25%/',
            $html,
            'A faixa de 1 a 15 dias vale 500 de 2000 — precisa ocupar 25% da barra.'
        );
        $resposta->assertSee('Em aberto por faixa de vencimento', escape: false);
    }

    /**
     * @spec:AC-054 Selecionar títulos mostra a contagem e a soma, e a baixa em
     * massa conclui todos os marcados de uma vez.
     */
    public function test_selecionar_mostra_o_quanto_e_a_baixa_em_massa_conclui_todos(): void
    {
        // A baixa exige conta financeira: é dela que o valor sai. Sem isso o
        // sistema pula o título de propósito, e o teste mediria o pulo.
        $caixa = \App\Models\ContaFinanceira::create([
            'nome' => 'Bradesco PJ', 'tipo' => 'corrente', 'saldo' => 50000.00, 'ativo' => true,
        ]);

        $uma = $this->despesa('Servidores', 1800.00, 4);
        $outra = $this->despesa('Licenças', 1240.00, -3);
        $intocada = $this->despesa('Aluguel', 4500.00, 12);

        ContaPagar::whereIn('id', [$uma->id, $outra->id, $intocada->id])
            ->update(['conta_financeira_id' => $caixa->id]);

        $operador = $this->operador();

        $resposta = $this->actingAs($operador)->get(route('contas-pagar.index'));
        $resposta->assertOk();

        // A barra existe, mostra contagem E soma — dar baixa é irreversível, e
        // o valor é a conferência antes do clique.
        $html = $resposta->getContent();
        $this->assertStringContainsString('selecionados.length', $html);
        $this->assertStringContainsString('selecionados.reduce(', $html);
        $this->assertStringContainsString('Dar baixa nas despesas', $html);
        $this->assertMatchesRegularExpression(
            '/valores\['.$uma->id.'\] = 1800/',
            $html,
            'Cada linha precisa declarar o próprio valor para a barra somar.'
        );

        // E a baixa em massa conclui exatamente os marcados.
        $this->actingAs($operador)
            ->post(route('contas-pagar.baixarEmMassa'), ['ids' => [$uma->id, $outra->id]])
            ->assertRedirect();

        $this->assertSame('pago', $uma->fresh()->status);
        $this->assertSame('pago', $outra->fresh()->status);
        $this->assertSame('em_aberto', $intocada->fresh()->status, 'Quem não foi marcado não pode ser baixado.');
    }

    /**
     * @spec:AC-055 Atrasado e a vencer se distinguem à primeira vista, cada um
     * mostrando o prazo real junto da data.
     */
    public function test_atrasado_e_a_vencer_se_distinguem_a_primeira_vista(): void
    {
        $this->despesa('Licenças vencidas', 1240.00, -26);
        $this->despesa('Servidores', 1800.00, 2);
        $this->despesa('Aluguel', 4500.00, 20);

        // `periodo=todos`: o teste verifica a distinção visual atrasado/a
        // vencer, não o filtro de período — sem isto, os vencimentos de
        // -26/+20 dias podem cair fora do mês corrente (16/08/2026) e
        // desaparecer da tabela antes mesmo da asserção rodar.
        $resposta = $this->actingAs($this->operador())->get(route('contas-pagar.index', ['periodo' => 'todos']));
        $resposta->assertOk();

        $html = $resposta->getContent();

        // O prazo real vem escrito ao lado da data — "vence dia 12" não diz se
        // já passou.
        $this->assertStringContainsString('atraso 26d', $html);
        $this->assertStringContainsString('em 2d', $html);
        $this->assertStringContainsString('em 20d', $html);

        // A linha atrasada é vermelha; a que vence em poucos dias, âmbar.
        $this->assertMatchesRegularExpression(
            '/border-left: 2px solid rgb\(var\(--crit\)\)/',
            $html,
            'A despesa vencida precisa da marca vermelha na lateral.'
        );
        $this->assertMatchesRegularExpression(
            '/border-left: 2px solid rgb\(var\(--warn\)\)/',
            $html,
            'A despesa que vence em até 3 dias precisa da marca âmbar.'
        );

        // Recorrente e pontual convivem na mesma lista, distinguidas pelo
        // subtítulo — não por abas separadas.
        ContaFixaPagar::create([
            'descricao' => 'Internet', 'valor' => 300.00, 'dia_vencimento' => 5,
            'data_inicio' => now()->subYear()->toDateString(), 'ativo' => true,
        ]);
        $recorrente = $this->despesa('Internet', 300.00, 5);
        $recorrente->update(['tipo' => 'fixa']);

        $resposta = $this->actingAs($this->operador())->get(route('contas-pagar.index'));
        $this->assertStringContainsString('recorrente · todo dia', $resposta->getContent());
        $this->assertStringContainsString('pontual', $resposta->getContent());
    }
}
