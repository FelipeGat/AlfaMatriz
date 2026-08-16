<?php

namespace Tests\Feature\Redesign;

use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Receitas / contas a receber: a faixa de atraso, a baixa em massa e a
 * distinção visual entre atrasado e a vencer.
 *
 * O dinheiro travado só é visível se o total em aberto for repartido por
 * vencimento — não basta somar tudo num KPI só. E a baixa em massa só prova
 * algo se a rota reaproveitada (`cobrancas.baixarEmMassa`) realmente
 * concluir os títulos marcados, não só mostrar a barra.
 */
class ReceitasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    /** Divide o HTML por início de linha de tabela, para inspecionar uma linha por vez. */
    private function linhasDaTabela(string $html): array
    {
        return preg_split('/(?=<tr)/', $html);
    }

    private function linhaContendo(string $html, string $agulha): ?string
    {
        foreach ($this->linhasDaTabela($html) as $linha) {
            if (str_contains($linha, $agulha)) {
                return $linha;
            }
        }

        return null;
    }

    /**
     * @spec:AC-053 A faixa de atraso distribui o que está em aberto por
     * vencimento — quatro faixas (a vencer, 1 a 15, 16 a 30, +30 dias), cada
     * uma com barra proporcional ao valor.
     */
    public function test_a_faixa_de_atraso_distribui_o_total_em_aberto_por_vencimento(): void
    {
        $hoje = now()->startOfDay();

        Cobranca::create([
            'descricao' => 'A vencer', 'valor' => 1000, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => $hoje->copy()->addDays(5)->toDateString(),
        ]);
        Cobranca::create([
            'descricao' => 'Atraso de 10 dias', 'valor' => 2000, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => $hoje->copy()->subDays(10)->toDateString(),
        ]);
        Cobranca::create([
            'descricao' => 'Atraso de 20 dias', 'valor' => 3000, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => $hoje->copy()->subDays(20)->toDateString(),
        ]);
        Cobranca::create([
            'descricao' => 'Atraso de 40 dias', 'valor' => 4000, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => $hoje->copy()->subDays(40)->toDateString(),
        ]);
        // Já paga: não conta para nenhuma faixa nem para o total em aberto.
        Cobranca::create([
            'descricao' => 'Já recebida', 'valor' => 9999, 'status' => 'pago', 'tipo' => 'avulsa',
            'data_vencimento' => $hoje->copy()->subDays(5)->toDateString(),
            'data_pagamento' => $hoje->toDateString(), 'valor_pago' => 9999,
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('cobrancas.index'));

        $resposta->assertOk();

        // As quatro faixas do desenho, cada uma com o valor que só ela contém.
        $resposta->assertSee('A vencer', escape: false);
        $resposta->assertSee('1 a 15 dias', escape: false);
        $resposta->assertSee('16 a 30 dias', escape: false);
        $resposta->assertSee('+30 dias', escape: false);
        $resposta->assertSee('R$ 1.000,00', escape: false);
        $resposta->assertSee('R$ 2.000,00', escape: false);
        $resposta->assertSee('R$ 3.000,00', escape: false);
        $resposta->assertSee('R$ 4.000,00', escape: false);

        // O total em aberto é a soma das quatro faixas — a paga fica de fora.
        $resposta->assertSee('R$ 10.000,00', escape: false);
        $resposta->assertDontSee('R$ 19.999,00', escape: false);

        // A barra é proporcional: o componente de faixa segmentada precisa
        // estar presente, e não uma lista solta sem largura calculada.
        $resposta->assertSee('rounded-badge', escape: false);
    }

    /**
     * @spec:AC-054 Selecionar títulos mostra o quanto e dá baixa de uma vez —
     * a barra de seleção soma os marcados, e a baixa em massa conclui todos
     * de uma tacada só, reaproveitando `cobrancas.baixarEmMassa`.
     */
    public function test_selecionar_titulos_mostra_a_barra_e_a_baixa_em_massa_conclui_todos(): void
    {
        $conta = ContaFinanceira::create(['nome' => 'Conta Teste']);

        $primeira = Cobranca::create([
            'descricao' => 'Primeira receita', 'valor' => 500, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => now()->addDays(3)->toDateString(), 'conta_financeira_id' => $conta->id,
        ]);
        $segunda = Cobranca::create([
            'descricao' => 'Segunda receita', 'valor' => 700, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => now()->addDays(4)->toDateString(), 'conta_financeira_id' => $conta->id,
        ]);

        $resposta = $this->actingAs($this->operador())->get(route('cobrancas.index'));
        $resposta->assertOk();

        $html = $resposta->getContent();

        // A barra de seleção existe, soma o que foi marcado e reaproveita a
        // rota de baixa em massa — não é um botão novo por fora dela.
        $this->assertStringContainsString('selecionados.length', $html);
        $this->assertStringContainsString('soma', $html);
        $this->assertStringContainsString('x-model="selecionados"', $html);
        $this->assertStringContainsString(route('cobrancas.baixarEmMassa'), $html);

        // A baixa em massa de verdade: marcar as duas e concluir as duas de uma vez.
        $resposta = $this->actingAs($this->operador())->post(route('cobrancas.baixarEmMassa'), [
            'ids' => [$primeira->id, $segunda->id],
        ]);

        $resposta->assertRedirect(route('cobrancas.index'));

        $this->assertSame('pago', $primeira->fresh()->status);
        $this->assertSame('pago', $segunda->fresh()->status);
    }

    /**
     * @spec:AC-055 Atrasado e a vencer se distinguem à primeira vista — o
     * atrasado em vermelho, o que está por vencer em âmbar, cada um com o
     * prazo real ao lado da data.
     */
    public function test_atrasado_e_a_vencer_se_distinguem_a_primeira_vista(): void
    {
        Cobranca::create([
            'descricao' => 'Cobranca atrasada 26 dias', 'valor' => 100, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => now()->subDays(26)->toDateString(),
        ]);
        Cobranca::create([
            'descricao' => 'Cobranca a vencer em 4 dias', 'valor' => 200, 'status' => 'pendente', 'tipo' => 'avulsa',
            'data_vencimento' => now()->addDays(4)->toDateString(),
        ]);

        // `periodo=todos` porque a tela passou a navegar por vencimento, com o
        // mês atual como padrão — e 26 dias de atraso caem sempre no mês
        // anterior. O que este teste guarda é a DISTINÇÃO VISUAL das linhas,
        // não o recorte padrão da tela; sem o parâmetro, ele quebraria de novo
        // a cada mudança de período, dizendo que a tarja sumiu quando o que
        // saiu de cena foi a linha.
        $resposta = $this->actingAs($this->operador())->get(route('cobrancas.index', ['periodo' => 'todos']));
        $resposta->assertOk();

        $html = $resposta->getContent();

        $linhaAtrasada = $this->linhaContendo($html, 'Cobranca atrasada 26 dias');
        $this->assertNotNull($linhaAtrasada, 'A linha da cobrança atrasada não foi encontrada na tabela.');
        $this->assertStringContainsString('border-l-crit', $linhaAtrasada, 'A linha atrasada precisa da marca vermelha.');
        $this->assertStringContainsString('atraso 26d', $linhaAtrasada, 'A linha atrasada precisa mostrar o prazo real.');

        $linhaAVencer = $this->linhaContendo($html, 'Cobranca a vencer em 4 dias');
        $this->assertNotNull($linhaAVencer, 'A linha a vencer não foi encontrada na tabela.');
        $this->assertStringContainsString('border-l-warn', $linhaAVencer, 'A linha a vencer precisa da marca âmbar.');
        $this->assertStringContainsString('em 4d', $linhaAVencer, 'A linha a vencer precisa mostrar o prazo real.');

        // As duas marcas são tokens diferentes — é isso que faz a distinção
        // acontecer à primeira vista, sem precisar ler a data.
        $this->assertStringNotContainsString('border-l-crit', $linhaAVencer);
        $this->assertStringNotContainsString('border-l-warn', $linhaAtrasada);
    }
}
