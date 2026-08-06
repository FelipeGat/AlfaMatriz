<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tela de Produtos.
 *
 * A mudança estrutural aqui é o motivo do teste existir: sete cartões numa
 * grade não deixavam comparar nada — cada um repetia a mesma grade de
 * métricas dentro da própria moldura. Virou lista ordenada por receita, e o
 * que se prova é que a ordem, a participação e a unidade de cobrança dizem a
 * verdade.
 */
class ProdutosTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function sistema(string $nome, string $unidade, bool $ativo = true): Sistema
    {
        return Sistema::create([
            'nome' => $nome,
            'slug' => \Illuminate\Support\Str::slug($nome),
            'unidade_cobranca' => $unidade,
            'ativo' => $ativo,
        ]);
    }

    /**
     * Liga N clientes ativos ao sistema e dá a ele um tier de atacado, para o
     * MRR estimado ter de onde sair.
     */
    private function comBase(Sistema $sistema, int $quantos, float $precoUnitario): void
    {
        // Tier metrado puro: nada incluso, tudo cobrado por unidade — é o
        // formato que deixa a conta do teste ser unidades × preço.
        PrecoAtacado::create([
            'sistema_id' => $sistema->id,
            'nome' => 'Metrado',
            'preco_base' => 0,
            'unidades_inclusas' => 0,
            'valor_excedente_unidade' => $precoUnitario,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);

        for ($i = 0; $i < $quantos; $i++) {
            $cliente = Cliente::create(['nome' => $sistema->nome." cliente {$i}", 'ativo' => true]);
            $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);
        }
    }

    /**
     * @spec:AC-049 Produtos abre como lista comparável ordenada por receita, e
     * o modo escolhido persiste.
     */
    public function test_produtos_abre_em_lista_ordenada_por_receita(): void
    {
        $lider = $this->sistema('AlfaGym', 'academia ativa');
        $segundo = $this->sistema('AlfaHome', 'condomínio ativo');
        $semReceita = $this->sistema('AlfaSchool', 'escola', ativo: false);

        $this->comBase($lider, 10, 30.00);   // 300
        $this->comBase($segundo, 5, 20.00);  // 100
        // AlfaSchool fica sem tier de propósito.

        $resposta = $this->actingAs($this->operador())->get(route('produtos.index'));
        $resposta->assertOk();

        $produtos = $resposta->viewData('produtos');

        // Ordenado por receita: quem sustenta a casa vem primeiro.
        $this->assertSame('AlfaGym', $produtos->first()['sistema']->nome);
        $this->assertSame('AlfaHome', $produtos->get(1)['sistema']->nome);

        // Participação e barra dizem a mesma verdade que os números.
        $this->assertEqualsWithDelta(300.0, $produtos->first()['mrr'], 0.01);
        $this->assertEqualsWithDelta(0.75, $produtos->first()['share'], 0.001, '300 de 400 é 75% do total.');
        $this->assertEqualsWithDelta(1.0, $produtos->first()['largura'], 0.001, 'O líder ocupa a pista inteira.');
        $this->assertEqualsWithDelta(1 / 3, $produtos->get(1)['largura'], 0.001, '100 sobre 300 é um terço da pista.');

        // ARR é derivado, e o ticket médio é por unidade ativa.
        $this->assertEqualsWithDelta(3600.0, $produtos->first()['arr'], 0.01);
        $this->assertEqualsWithDelta(30.0, $produtos->first()['ticket_medio'], 0.01);

        // A unidade de cobrança REAL aparece — "clientes" esconderia o que se cobra.
        $resposta->assertSee('academia ativa', escape: false);
        $resposta->assertSee('condomínio ativo', escape: false);

        // O sistema sem tier é sinalizado: ele fica fora do faturamento.
        $school = $produtos->firstWhere('sistema.nome', 'AlfaSchool');
        $this->assertTrue($school['sem_tier']);
        $this->assertFalse($produtos->first()['sem_tier']);
        $resposta->assertSee('sem tier de atacado', escape: false);

        // Contagens do rodapé.
        $contagens = $resposta->viewData('contagens');
        $this->assertSame(3, $contagens['sistemas']);
        $this->assertSame(2, $contagens['ativos']);
        $this->assertSame(1, $contagens['sem_tier']);

        // Totais somados das linhas.
        $totais = $resposta->viewData('totais');
        $this->assertEqualsWithDelta(400.0, $totais['mrr'], 0.01);
        $this->assertEqualsWithDelta(4800.0, $totais['arr'], 0.01);
        $this->assertSame(15, $totais['ativos']);

        // ── O modo: lista é o padrão, e a escolha sobrevive à visita seguinte.
        $html = $resposta->getContent();
        $this->assertMatchesRegularExpression(
            "/return localStorage\.getItem\('alfamatriz:produtos-modo'\) === 'cartoes' \? 'cartoes' : 'lista'/",
            $html,
            'Sem preferência guardada, o padrão precisa ser a lista.'
        );
        $this->assertStringContainsString("localStorage.setItem('alfamatriz:produtos-modo'", $html);
        $this->assertStringContainsString("x-show=\"modo === 'lista'\"", $html);
        $this->assertStringContainsString("x-show=\"modo === 'cartoes'\"", $html);
    }

    /**
     * @spec:AC-048 Produtos traz resumo, tabela e contagem — e o churn alto
     * marca a linha, porque é ele que decide onde olhar primeiro.
     */
    public function test_produtos_traz_resumo_tabela_e_marca_o_churn_alto(): void
    {
        $saudavel = $this->sistema('AlfaGym', 'academia ativa');
        $sangrando = $this->sistema('AlfaMed', 'vida agregada');

        $this->comBase($saudavel, 10, 30.00);
        $this->comBase($sangrando, 4, 25.00);

        // Seis cancelados contra quatro ativos: 60% de churn.
        for ($i = 0; $i < 6; $i++) {
            $cliente = Cliente::create(['nome' => "Cancelado {$i}", 'ativo' => true]);
            $cliente->sistemas()->attach($sangrando->id, ['ativo' => false, 'cancelado_em' => now()->subMonth()]);
        }

        $resposta = $this->actingAs($this->operador())->get(route('produtos.index'));
        $resposta->assertOk();

        $produtos = $resposta->viewData('produtos');
        $med = $produtos->firstWhere('sistema.nome', 'AlfaMed');
        $gym = $produtos->firstWhere('sistema.nome', 'AlfaGym');

        $this->assertEqualsWithDelta(60.0, $med['taxa_cancelamento'], 0.1, '6 cancelados de 10 é 60%.');
        $this->assertEqualsWithDelta(0.0, $gym['taxa_cancelamento'], 0.1);
        $this->assertSame(6, $med['clientes_cancelados']);

        // O MRR total é o resumo do topo e bate com a soma das linhas.
        $this->assertEqualsWithDelta(
            $produtos->sum('mrr'),
            $resposta->viewData('mrrTotal'),
            0.01,
            'O MRR do topo precisa ser a soma das linhas, não um número à parte.'
        );

        $resposta->assertSee('MRR total · todos os produtos', escape: false);
        $resposta->assertSee('cancel.', escape: false);

        $this->assertStringContainsString(
            '<x-linha-total>',
            file_get_contents(base_path('resources/views/produtos/index.blade.php'))
        );
    }
}
