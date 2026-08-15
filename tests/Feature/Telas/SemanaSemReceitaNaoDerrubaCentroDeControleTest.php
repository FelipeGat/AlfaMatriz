<?php

namespace Tests\Feature\Telas;

use App\Models\ContaPagar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Semana sem receita a vencer não pode derrubar o Centro de Controle.
 *
 * O "Próximos 7 dias" junta receitas e despesas com `merge()`. A lista de
 * receitas nasce de um `get()` do Eloquent, e o `map()` para array só rebaixa
 * a collection para a comum quando HÁ itens — vazia, ela continua Eloquent, e
 * o `merge()` dessa classe chama `getKey()` em cada despesa, que é array.
 *
 * O cenário é o de produção em 14/08/2026: nenhuma cobrança a vencer na
 * janela, quatro despesas dentro dela — e a tela inteira respondia 500. Com
 * as DUAS listas vazias o merge não itera nada, então o teste precisa da
 * despesa: sem ela, ele passa com o defeito de volta.
 */
class SemanaSemReceitaNaoDerrubaCentroDeControleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tela_abre_sem_receita_a_vencer_e_com_despesa_na_janela(): void
    {
        ContaPagar::create([
            'descricao' => 'Aluguel', 'valor' => 1200.00,
            'data_vencimento' => now()->addDays(3)->toDateString(),
            'status' => 'em_aberto', 'tipo' => 'fixa',
        ]);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('centro-controle'));

        $resposta->assertOk();

        // Não basta não cair: a despesa precisa estar na lista dos 7 dias.
        $this->assertCount(1, $resposta->viewData('proximos'));
    }
}
