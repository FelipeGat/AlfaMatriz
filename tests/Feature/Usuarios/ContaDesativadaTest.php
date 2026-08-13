<?php

namespace Tests\Feature\Usuarios;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A coluna `ativo` existia desde a primeira migração de SaaS e nunca era
 * lida: o `LoginRequest` usava `Auth::attempt` puro. Um botão "Desativar" em
 * cima disso só trocaria a cor de um badge, e quem clicasse acreditaria ter
 * fechado o acesso.
 */
class ContaDesativadaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_conta_desativada_nao_entra_nem_com_a_senha_certa(): void
    {
        $usuario = User::factory()->desativado()->create();

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_recusa_diz_o_motivo_em_vez_de_fingir_senha_errada(): void
    {
        $usuario = User::factory()->desativado()->create();

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password']);

        // Quem chega aqui já provou a senha: dizer o motivo não entrega nada a
        // quem não a tinha, e poupa um chamado de "o painel quebrou".
        $this->assertStringContainsString(
            'desativada',
            session('errors')->first('email')
        );
    }

    public function test_conta_ativa_continua_entrando(): void
    {
        $usuario = User::factory()->create();

        $this->post(route('login'), ['email' => $usuario->email, 'password' => 'password']);

        $this->assertAuthenticatedAs($usuario);
    }

    public function test_sessao_ja_aberta_cai_no_clique_seguinte(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('centro-controle'))->assertOk();

        $usuario->update(['ativo' => false]);

        // Sem isto, desativar só valeria no próximo login — e desativar existe
        // justamente para os casos em que esperar não é opção.
        $this->actingAs($usuario)->get(route('centro-controle'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_quem_foi_desativado_sai_da_lista_de_responsaveis(): void
    {
        $admin = User::factory()->create();
        $saiu = User::factory()->desativado()->create(['name' => 'Quem Saiu']);

        $this->actingAs($admin)->get(route('tarefas.index'))->assertDontSee('Quem Saiu');
    }

    public function test_mas_continua_na_lista_enquanto_responder_por_alguma_tarefa(): void
    {
        $admin = User::factory()->create();
        $saiu = User::factory()->create(['name' => 'Quem Saiu']);

        Tarefa::factory()->create(['criado_por_id' => $admin->id, 'responsavel_id' => $saiu->id]);
        $saiu->update(['ativo' => false]);

        // Fora da lista, o `select` da tarefa perderia o valor escolhido — e
        // salvá-la depois a esvaziaria sem ninguém ter pedido isso.
        $this->actingAs($admin)->get(route('tarefas.index'))->assertSee('Quem Saiu');
    }
}
