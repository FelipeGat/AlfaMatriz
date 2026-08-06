<?php

namespace Tests\Feature\Redesign;

use App\Models\Categoria;
use App\Models\CentroCusto;
use App\Models\Conta;
use App\Models\ContaPagar;
use App\Models\Fornecedor;
use App\Models\Subcategoria;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cadastros auxiliares: a contagem de lançamentos ao lado de cada item — o
 * dado que decide se remover é seguro — e o plano de contas lido na
 * horizontal, sem descer quatro níveis de indentação.
 */
class CadastrosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function contaPagar(array $atributos = []): ContaPagar
    {
        return ContaPagar::create(array_merge([
            'descricao' => 'Lançamento de teste',
            'valor' => 100,
            'data_vencimento' => now()->addDays(10)->toDateString(),
            'status' => 'em_aberto',
            'tipo' => 'avulsa',
        ], $atributos));
    }

    /**
     * @spec:AC-058 Cada item mostra quantos lançamentos dependem dele ao
     * lado do botão de remover — centro de custo e fornecedor.
     */
    public function test_cada_centro_de_custo_e_fornecedor_mostra_quantos_lancamentos_usam(): void
    {
        $centroUsado = CentroCusto::create(['nome' => 'Infraestrutura']);
        $centroLivre = CentroCusto::create(['nome' => 'Marketing']);
        $this->contaPagar(['centro_custo_id' => $centroUsado->id]);
        $this->contaPagar(['centro_custo_id' => $centroUsado->id]);

        $fornecedorUsado = Fornecedor::create(['razao_social' => 'Provedor de Nuvem Ltda']);
        $fornecedorLivre = Fornecedor::create(['razao_social' => 'Escritório de Contabilidade Ltda']);
        $this->contaPagar(['fornecedor_id' => $fornecedorUsado->id]);

        $resposta = $this->actingAs($this->operador())->get(route('cadastros-auxiliares.index'));

        $resposta->assertOk();
        $resposta->assertSeeInOrder(['Infraestrutura', '2 lançamento(s)']);
        $resposta->assertSeeInOrder(['Marketing', '0 lançamento(s)']);
        $resposta->assertSeeInOrder(['Provedor de Nuvem Ltda', '1 lançamento(s)']);
        $resposta->assertSeeInOrder(['Escritório de Contabilidade Ltda', '0 lançamento(s)']);
    }

    /**
     * @spec:AC-059 O plano de contas lê-se na horizontal: cada categoria é
     * um bloco marcado pelo tipo, cada subcategoria uma linha com as contas
     * como etiquetas ao lado — não quatro níveis de indentação descendo a
     * tela.
     */
    public function test_o_plano_de_contas_le_se_na_horizontal_por_bloco_de_categoria(): void
    {
        $receita = Categoria::create(['nome' => 'Receitas de software', 'tipo' => 'receita']);
        $subReceita = Subcategoria::create(['categoria_id' => $receita->id, 'nome' => 'Licenciamento']);
        Conta::create(['subcategoria_id' => $subReceita->id, 'nome' => 'Atacado de revenda']);
        Conta::create(['subcategoria_id' => $subReceita->id, 'nome' => 'Venda direta']);

        $despesa = Categoria::create(['nome' => 'Administrativo', 'tipo' => 'despesa']);
        $subDespesa = Subcategoria::create(['categoria_id' => $despesa->id, 'nome' => 'Escritório']);
        Conta::create(['subcategoria_id' => $subDespesa->id, 'nome' => 'Aluguel']);

        $resposta = $this->actingAs($this->operador())->get(route('cadastros-auxiliares.index'));
        $html = $resposta->getContent();

        $resposta->assertOk();

        // Cada categoria é um bloco só, sem indentação em cascata: o nome da
        // categoria, o badge de tipo e o resumo aparecem juntos no mesmo
        // trecho, e não em linhas soltas empilhadas.
        $blocoReceita = substr($html, strpos($html, 'Receitas de software'), 800);
        $this->assertStringContainsString('Receita', $blocoReceita);
        $this->assertStringContainsString('1 sub', $blocoReceita);
        $this->assertStringContainsString('2 contas', $blocoReceita);

        $blocoDespesa = substr($html, strpos($html, 'Administrativo'), 800);
        $this->assertStringContainsString('Despesa', $blocoDespesa);

        // A subcategoria é uma linha com as contas como etiquetas ao lado —
        // nome da subcategoria e as duas contas aparecem próximos, na mesma
        // linha horizontal, não em blocos verticais separados.
        $trechoSub = substr($html, strpos($html, 'Licenciamento'), 2500);
        $this->assertStringContainsString('Atacado de revenda', $trechoSub);
        $this->assertStringContainsString('Venda direta', $trechoSub);

        $resposta->assertSeeInOrder(['Receitas de software', 'Licenciamento', 'Atacado de revenda', 'Venda direta']);
        $resposta->assertSeeInOrder(['Administrativo', 'Escritório', 'Aluguel']);
    }
}
