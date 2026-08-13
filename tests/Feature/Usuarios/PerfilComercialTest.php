<?php

namespace Tests\Feature\Usuarios;

use App\Models\Perfil;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O perfil de quem vende.
 *
 * Curto de propósito: funil, clientes e revendas. O perfil mais próximo era
 * `operacao`, que traz junto sistemas, preço de atacado, faturamento, receitas,
 * despesas e os painéis — e o Centro de Controle abre com "Saldo em caixa" na
 * primeira fileira.
 */
class PerfilComercialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        (new PerfilPermissaoSeeder)->run();
    }

    private function vendedor(): User
    {
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach(Perfil::where('slug', 'comercial')->value('id'));

        return $usuario;
    }

    public function test_alcanca_funil_clientes_e_revendas(): void
    {
        $vendedor = $this->vendedor();

        foreach (['leads.index', 'clientes.index', 'revendas.index'] as $rota) {
            $this->actingAs($vendedor)->get(route($rota))->assertOk();
        }
    }

    public function test_nao_alcanca_o_dinheiro_da_casa(): void
    {
        $vendedor = $this->vendedor();

        // O Centro de Controle é o motivo de este perfil não ter painel: ele
        // abre com saldo em caixa e receita recorrente.
        foreach ([
            'centro-controle', 'dashboard', 'comercial',
            'cobrancas.index', 'contas-pagar.index', 'contas-financeiras.index',
            'faturamento.index', 'produtos.index', 'tarefas.index', 'usuarios.index',
        ] as $rota) {
            $this->actingAs($vendedor)->get(route($rota))->assertForbidden();
        }
    }

    public function test_registra_e_corrige_mas_nao_apaga(): void
    {
        $vendedor = $this->vendedor();

        // Apagar cliente, revenda ou lead desfaz histórico de faturamento e de
        // funil: quem vende registra e corrige, quem apaga é o administrador.
        $this->assertTrue($vendedor->canPermissao('leads', 'incluir'));
        $this->assertFalse($vendedor->canPermissao('leads', 'excluir'));
        $this->assertFalse($vendedor->canPermissao('clientes', 'excluir'));
        $this->assertFalse($vendedor->canPermissao('revendas', 'excluir'));
    }

    public function test_a_casa_dele_e_o_funil_e_nao_a_carteira_ja_formada(): void
    {
        $this->assertSame(route('leads.index', absolute: false), $this->vendedor()->telaInicial());
    }

    public function test_o_menu_dele_nao_oferece_porta_que_nao_abre(): void
    {
        $resposta = $this->actingAs($this->vendedor())->get(route('leads.index'))->assertOk();

        foreach (['centro-controle', 'dashboard', 'comercial', 'produtos.index',
            'cobrancas.index', 'contas-pagar.index', 'contas-financeiras.index',
            'faturamento.index', 'tarefas.index', 'usuarios.index'] as $rota) {
            $resposta->assertDontSee(route($rota), escape: false);
        }

        // Clientes não tem item próprio: eles moram na aba de "Revendas e
        // clientes". O que o menu tem de oferecer são estes dois.
        foreach (['leads.index', 'revendas.index'] as $rota) {
            $resposta->assertSee(route($rota), escape: false);
        }
    }

    public function test_a_raiz_nao_o_joga_no_centro_de_controle(): void
    {
        // Digitar o endereço do painel — o caminho mais natural de todos —
        // levava a raiz a redirecioná-lo para o Centro de Controle, e ele
        // batia num 403 sem nunca ter clicado em nada.
        $this->actingAs($this->vendedor())
            ->get('/')
            ->assertRedirect(route('leads.index', absolute: false));
    }

    public function test_a_marca_no_topo_leva_para_casa_e_nao_para_um_403(): void
    {
        // O logo apontava para o Centro de Controle para todo mundo que não
        // fosse revenda — clicar nele era o caminho mais curto para um 403.
        $resposta = $this->actingAs($this->vendedor())->get(route('leads.index'))->assertOk();

        $resposta->assertSee('href="'.route('leads.index', absolute: false).'"', escape: false);
    }

    public function test_a_migracao_leva_o_perfil_a_producao_e_o_seeder_nao_o_duplica(): void
    {
        // O deploy roda só `migrate --force`: perfil que existe apenas no
        // seeder não chega a produção. Os dois caminhos têm de convergir.
        $this->assertSame(1, Perfil::where('slug', 'comercial')->count());

        (new PerfilPermissaoSeeder)->run();

        $this->assertSame(1, Perfil::where('slug', 'comercial')->count());
        $this->assertSame(3, Perfil::where('slug', 'comercial')->firstOrFail()->permissoes()->count());
    }

    public function test_a_tela_de_usuarios_oferece_o_perfil_novo(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('usuarios.index'))
            ->assertOk()
            ->assertSee('Comercial');
    }
}
