<?php

namespace Tests\Feature\SistemasInternos;

use App\Models\Perfil;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O que cada tela MOSTRA depois da separação.
 *
 * Os testes de população provam que o interno não entra nas contas; estes
 * provam a outra metade, que é de leitura: a tela do interno não pode oferecer
 * o que não se aplica a ele, e a que oferece as duas famílias tem de dizer qual
 * é qual. As duas falhas são invisíveis para um teste de `viewData`.
 */
class TelasDoCatalogoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_acao_do_topo_segue_a_aba(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('produtos.index'))
            ->assertSee('Novo produto');

        // Botão fixo em "Novo produto" levaria quem está na lista de internos a
        // um formulário com os campos comerciais já abertos.
        $this->actingAs($usuario)->get(route('produtos.index', ['aba' => 'internos']))
            ->assertSee('Novo sistema interno');
    }

    /** Quem só lê o catálogo não recebe um botão que termina em 403. */
    public function test_quem_nao_cadastra_nao_ve_o_botao(): void
    {
        (new PerfilPermissaoSeeder)->run();

        $financeiro = User::factory()->semPerfil()->create();
        $financeiro->perfis()->attach(Perfil::where('slug', 'financeiro')->value('id'));

        $this->actingAs($financeiro)->get(route('produtos.index'))
            ->assertOk()
            ->assertDontSee('Novo produto');
    }

    public function test_a_lista_de_internos_mostra_responsavel_e_versao(): void
    {
        Sistema::factory()->interno()->create([
            'nome' => 'Infra e Deploy', 'responsavel' => 'Marina Alves', 'versao' => 'v2.1.0',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('produtos.index', ['aba' => 'internos']))
            ->assertOk()
            ->assertSee('Infra e Deploy')
            ->assertSee('Marina Alves')
            ->assertSee('v2.1.0');
    }

    /**
     * Tier é preço cobrado da revenda. Numa tela de sistema interno, a tabela
     * de preços vazia convida a configurar o que nunca vai ser faturado.
     */
    public function test_a_tela_do_interno_nao_oferece_tier_de_atacado(): void
    {
        $usuario = User::factory()->create();
        $produto = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $interno = Sistema::factory()->interno()->create(['nome' => 'Infra e Deploy']);

        $this->actingAs($usuario)->get(route('sistemas.edit', $produto))
            ->assertOk()->assertSee('Tiers de atacado', false);

        $this->actingAs($usuario)->get(route('sistemas.edit', $interno))
            ->assertOk()->assertDontSee('Tiers de atacado', false);
    }

    /**
     * O select do quadro agrupa as duas famílias — numa lista corrida, o
     * sistema interno apareceria entre dois produtos como se também fosse
     * vendido.
     */
    public function test_o_quadro_separa_as_duas_familias_no_select(): void
    {
        Sistema::factory()->create(['nome' => 'AlfaGym']);
        Sistema::factory()->interno()->create(['nome' => 'Infra e Deploy']);

        $this->actingAs(User::factory()->create())->get(route('tarefas.index'))
            ->assertOk()
            ->assertSee('<optgroup label="Produto">', false)
            ->assertSee('<optgroup label="Sistema interno">', false);
    }

    /**
     * Rótulo de grupo sobre um grupo só é moldura: a casa que ainda não tem
     * sistema interno veria "Produto" acima da lista de sempre, sem nada a
     * distinguir.
     */
    public function test_sem_interno_cadastrado_o_select_nao_agrupa(): void
    {
        Sistema::factory()->create(['nome' => 'AlfaGym']);

        $this->actingAs(User::factory()->create())->get(route('tarefas.index'))
            ->assertOk()
            ->assertDontSee('<optgroup', false);
    }
}
