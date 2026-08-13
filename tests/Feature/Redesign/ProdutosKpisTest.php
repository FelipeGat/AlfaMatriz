<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O resumo do topo da tela de Produtos.
 *
 * Estes números moravam em `sistemas.index`, uma segunda tela de catálogo que
 * nenhuma view linkava — o menu sempre apontou para Produtos. Ela foi removida
 * e eles vieram junto, porque o preço médio por licença não existia em nenhum
 * outro lugar: o MRR total sozinho não distingue poucas licenças caras de
 * muitas baratas.
 *
 * O que o preço médio guarda é a coerência entre numerador e divisor. Produto
 * desativado fica fora do fechamento e tem MRR zero; somar as licenças dele
 * embaixo afundava o número na proporção do catálogo aposentado.
 */
class ProdutosKpisTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function sistemaComTier(Sistema $sistema, float $preco): Sistema
    {
        PrecoAtacado::create([
            'sistema_id' => $sistema->id,
            'revenda_id' => null,
            'nome' => 'Único',
            'preco_base' => $preco,
            'unidades_inclusas' => 100,
            'limite_unidades' => null,
            'ordem' => 1,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);

        return $sistema;
    }

    private function vincular(Sistema $sistema, int $quantos): void
    {
        foreach (range(1, $quantos) as $i) {
            $cliente = Cliente::create(['nome' => "Cliente {$sistema->id}-{$i}", 'ativo' => true]);
            $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);
        }
    }

    /**
     * @spec:AC-062 Os números do resumo chegam à TELA, não só ao controller.
     * Enquanto ficavam apenas no `viewData`, a tela pagava as consultas para
     * produzi-los e não mostrava nenhum.
     */
    public function test_o_resumo_do_topo_chega_ao_html(): void
    {
        $gym = $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 300.00);
        $this->vincular($gym, 2);

        $resposta = $this->actingAs($this->operador())->get(route('produtos.index'));
        $resposta->assertOk();

        $resposta->assertSee('MRR total', escape: false);
        $resposta->assertSee('Licenças ativas', escape: false);
        $resposta->assertSee('Preço médio por licença', escape: false);

        // E os valores, não só os rótulos. Os dois são distintivos o bastante
        // para provar que chegaram: a contagem de licenças seria só um "2" no
        // meio da página, então ela é conferida pelo `viewData`.
        $resposta->assertSee('300,00', escape: false);
        $resposta->assertSee('150,00', escape: false); // 300 / 2 licenças
        $this->assertSame(2, $resposta->viewData('vinculosAtivos'));
    }

    /**
     * @spec:AC-151 O preço médio divide pelo que o MRR conta. Produto
     * desativado fica fora do fechamento, então suas licenças não podem entrar
     * no divisor — quanto mais produto aposentado na base, mais o preço médio
     * afundava, sem nada ter mudado de preço.
     */
    public function test_o_preco_medio_ignora_licenca_de_sistema_desativado(): void
    {
        $vivo = $this->sistemaComTier(Sistema::factory()->alfagym()->create(['ativo' => true]), 100.00);
        $morto = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(['ativo' => false]), 900.00);

        $this->vincular($vivo, 1);
        $this->vincular($morto, 3);

        $resposta = $this->actingAs($this->operador())->get(route('produtos.index'));

        $this->assertEqualsWithDelta(100.0, (float) $resposta->viewData('mrrTotal'), 0.01);
        $this->assertSame(1, $resposta->viewData('vinculosAtivos'), 'As 3 licenças do produto desativado entraram no divisor.');

        // Antes: 100 / 4 = 25,00. Agora: 100 / 1 = 100,00.
        $this->assertEqualsWithDelta(100.0, (float) $resposta->viewData('precoMedio'), 0.01);
    }

    /**
     * @spec:AC-062 A definição se sustenta: preço médio é o MRR dividido pelas
     * licenças que o produziram, e não uma terceira conta paralela.
     */
    public function test_o_preco_medio_e_o_mrr_dividido_pelas_licencas(): void
    {
        $gym = $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 450.00);
        $this->vincular($gym, 3);

        $resposta = $this->actingAs($this->operador())->get(route('produtos.index'));

        $mrr = (float) $resposta->viewData('mrrTotal');
        $licencas = $resposta->viewData('vinculosAtivos');

        $this->assertSame(3, $licencas);
        $this->assertEqualsWithDelta($mrr / $licencas, (float) $resposta->viewData('precoMedio'), 0.01);
    }

    /**
     * @spec:AC-062 Sem licença nenhuma o preço médio é zero, e não uma divisão
     * por zero.
     */
    public function test_sem_licenca_o_preco_medio_e_zero(): void
    {
        $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 300.00);

        $resposta = $this->actingAs($this->operador())->get(route('produtos.index'));

        $resposta->assertOk();
        $this->assertSame(0, $resposta->viewData('vinculosAtivos'));
        $this->assertEqualsWithDelta(0.0, (float) $resposta->viewData('precoMedio'), 0.01);
    }
}
