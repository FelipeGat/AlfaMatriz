<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelasDeListaTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    private Revenda $revendaA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create();

        $this->revendaA = Revenda::create(['nome' => 'Invest', 'ativo' => true]);
        $revendaB = Revenda::create(['nome' => 'Outra', 'ativo' => true]);

        foreach (range(1, 3) as $i) {
            Cliente::create(['nome' => "Ativo A {$i}", 'revenda_id' => $this->revendaA->id, 'ativo' => true, 'valor_mensal' => 100]);
        }
        Cliente::create(['nome' => 'Inativo A', 'revenda_id' => $this->revendaA->id, 'ativo' => false, 'valor_mensal' => 100]);
        Cliente::create(['nome' => 'Ativo B', 'revenda_id' => $revendaB->id, 'ativo' => true, 'valor_mensal' => 50]);
    }

    /**
     * @spec:AC-044 Em Clientes, os cards do topo refletem o RECORTE dos
     * filtros, não a base inteira. Sem isso, o total do topo contradiz a
     * tabela logo abaixo — dois números diferentes na mesma tela.
     */
    public function test_resumo_de_clientes_acompanha_os_filtros(): void
    {
        // Sem filtro: 5 clientes, 4 ativos, 1 inativo, R$ 350 em contratos.
        $todos = $this->actingAs($this->usuario)->get(route('clientes.index'));
        $todos->assertOk();
        $this->assertSame(5, $todos->viewData('exibidos'));
        $this->assertSame(4, $todos->viewData('ativos'));
        $this->assertSame(1, $todos->viewData('inativos'));
        $this->assertEqualsWithDelta(350.0, $todos->viewData('somaContratos'), 0.01);

        // Filtrando por revenda: 4 clientes, 3 ativos, R$ 300.
        $porRevenda = $this->actingAs($this->usuario)
            ->get(route('clientes.index', ['revenda_id' => $this->revendaA->id]));
        $this->assertSame(4, $porRevenda->viewData('exibidos'));
        $this->assertSame(3, $porRevenda->viewData('ativos'));
        $this->assertEqualsWithDelta(300.0, $porRevenda->viewData('somaContratos'), 0.01);

        // Filtrando por situação: só os inativos.
        $inativos = $this->actingAs($this->usuario)->get(route('clientes.index', ['status' => 'inativos']));
        $this->assertSame(1, $inativos->viewData('exibidos'));
        $this->assertSame(0, $inativos->viewData('ativos'));

        // E por busca de nome.
        $busca = $this->actingAs($this->usuario)->get(route('clientes.index', ['busca' => 'Ativo B']));
        $this->assertSame(1, $busca->viewData('exibidos'));
    }

    /**
     * @spec:AC-044 O resumo bate com o que a tabela lista: o número do card
     * "Exibidos" é o mesmo total da paginação.
     */
    public function test_o_card_exibidos_bate_com_a_tabela(): void
    {
        foreach ([[], ['status' => 'ativos'], ['revenda_id' => $this->revendaA->id]] as $filtros) {
            $resposta = $this->actingAs($this->usuario)->get(route('clientes.index', $filtros));

            $this->assertSame(
                $resposta->viewData('clientes')->total(),
                $resposta->viewData('exibidos'),
                'O card do topo e a tabela precisam contar a mesma coisa, com filtros '.json_encode($filtros)
            );
        }
    }

    /**
     * @spec:AC-043 Nas telas de lista, toda coluna de dinheiro tem piso de
     * largura e valor sem quebra — é o que mantém a coluna legível quando a
     * janela encolhe.
     */
    public function test_colunas_de_dinheiro_tem_piso_e_nao_quebram(): void
    {
        foreach (['cobrancas.index', 'contas-pagar.index'] as $rota) {
            $html = $this->actingAs($this->usuario)->get(route($rota))->getContent();

            $this->assertMatchesRegularExpression(
                '/w-\[12[0-9]px\][^"]*text-right/',
                $html,
                "A coluna de valor de {$rota} precisa de largura mínima."
            );

            // O cabeçalho trunca em vez de invadir a coluna vizinha.
            $this->assertStringContainsString('truncate whitespace-nowrap border-b border-line', $html);
        }

        // E numa tela com linhas de verdade, a célula de dinheiro carrega a
        // classe que impede a quebra (Clientes tem o valor do contrato).
        $comLinhas = $this->actingAs($this->usuario)->get(route('clientes.index'))->getContent();
        $this->assertMatchesRegularExpression(
            '/class="valor[^"]*">\s*R\$/',
            $comLinhas,
            'O valor do contrato precisa ser marcado como monetário.'
        );
    }

    /**
     * @spec:AC-045 As telas de lista são neutras: o botão principal é
     * monocromático invertido e os links de ação não usam cor de marca.
     */
    public function test_telas_de_lista_sao_neutras(): void
    {
        foreach (['revendas.index', 'clientes.index', 'cobrancas.index', 'contas-pagar.index'] as $rota) {
            $html = $this->actingAs($this->usuario)->get(route($rota))->getContent();

            $this->assertStringContainsString('bg-ink px-3', $html, "O botão principal de {$rota} é monocromático invertido.");
            $this->assertStringNotContainsString('text-brand hover:text-brand-bright', $html, "Sobrou link em cor de marca em {$rota}.");
            $this->assertStringNotContainsString('bg-brand ', $html, "Sobrou fundo em cor de marca em {$rota}.");
        }
    }
}
