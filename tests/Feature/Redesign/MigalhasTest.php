<?php

namespace Tests\Feature\Redesign;

use App\Models\ContaFinanceira;
use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Migalhas de navegação nas telas filhas.
 *
 * O menu lateral diz em que seção a pessoa está; ele não diz onde dentro dela,
 * nem oferece o caminho de volta. Depois de salvar um formulário, o botão do
 * navegador não serve: voltar ali significa reenviar. A migalha é o caminho
 * limpo — e por isso ela precisa ser link de verdade, não texto.
 */
class MigalhasTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    /**
     * @spec:AC-038 Toda tela filha mostra o caminho de volta: a seção que a
     * contém, como link, e o nome do que está aberto.
     */
    public function test_a_tela_filha_mostra_a_secao_e_o_caminho_de_volta(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $cliente = Cliente::create(['nome' => 'Academia Corpo em Movimento', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $conta = ContaFinanceira::create([
            'nome' => 'Bradesco PJ', 'tipo' => 'corrente', 'saldo' => 100.00, 'ativo' => true,
        ]);

        $operador = $this->operador();

        $filhas = [
            [route('revendas.edit', $revenda), 'Revendas', route('revendas.index'), 'Invest Soluções'],
            [route('revendas.create'), 'Revendas', route('revendas.index'), 'Nova revenda'],
            [route('clientes.edit', $cliente), 'Clientes', route('clientes.index'), 'Academia Corpo em Movimento'],
            [route('contas-financeiras.extrato', $conta), 'Caixa', route('contas-financeiras.index'), 'Bradesco PJ'],
            [route('contas-financeiras.edit', $conta), 'Caixa', route('contas-financeiras.index'), 'Bradesco PJ'],
            [route('contas-pagar.create'), 'Despesas', route('contas-pagar.index'), 'Nova despesa'],
            [route('cobrancas.create'), 'Receitas', route('cobrancas.index'), 'Nova receita'],
        ];

        foreach ($filhas as [$url, $secao, $rotaPai, $atual]) {
            $resposta = $this->actingAs($operador)->get($url);
            $resposta->assertOk();

            $html = $resposta->getContent();

            // A migalha existe e é navegável — anunciada para quem usa leitor
            // de tela, e com a seção como LINK de verdade.
            $this->assertStringContainsString('aria-label="Você está em"', $html,
                "Falta a migalha em {$url}.");
            $this->assertMatchesRegularExpression(
                '/<a href="'.preg_quote($rotaPai, '/').'"[^>]*>\s*'.preg_quote($secao, '/').'/',
                $html,
                "Em {$url} a seção {$secao} precisa ser link de volta, não texto."
            );

            // E o último degrau é o título da tela, marcado como atual.
            $this->assertMatchesRegularExpression(
                '/aria-current="page">\s*'.preg_quote($atual, '/').'/',
                $html,
                "Em {$url} o título deveria ser \"{$atual}\"."
            );
        }
    }

    /**
     * @spec:AC-038 Tela de topo não ganha migalha: ali ela repetiria o que o
     * menu lateral já diz, e migalha de um degrau só é enfeite.
     */
    public function test_tela_de_topo_nao_tem_migalha(): void
    {
        foreach (['centro-controle', 'revendas.index', 'clientes.index', 'produtos.index'] as $rota) {
            $resposta = $this->actingAs($this->operador())->get(route($rota));

            $resposta->assertOk();
            $this->assertStringNotContainsString(
                'aria-label="Você está em"',
                $resposta->getContent(),
                "A tela {$rota} é de topo e não deveria ter migalha."
            );
        }
    }
}
