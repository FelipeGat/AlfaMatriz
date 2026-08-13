<?php

namespace Tests\Feature\Usuarios;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A troca obrigatória fecha o ciclo da senha gerada: ela é criada pelo
 * sistema, repassada por quem administra — ou seja, passa por um canal que
 * não é da pessoa — e vale só até a primeira entrada.
 */
class PrimeiroAcessoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_quem_tem_senha_emprestada_nao_alcanca_o_painel(): void
    {
        $usuario = User::factory()->primeiroAcesso()->create();

        $this->actingAs($usuario)->get(route('centro-controle'))
            ->assertRedirect(route('senha.primeiro-acesso'));

        $this->actingAs($usuario)->get(route('tarefas.index'))
            ->assertRedirect(route('senha.primeiro-acesso'));
    }

    public function test_a_propria_tela_de_troca_abre_sem_laco(): void
    {
        $usuario = User::factory()->primeiroAcesso()->create();

        $this->actingAs($usuario)->get(route('senha.primeiro-acesso'))->assertOk();
    }

    public function test_da_para_sair_sem_trocar(): void
    {
        // Quem abriu por engano na máquina de outra pessoa precisa de saída.
        $usuario = User::factory()->primeiroAcesso()->create();

        $this->actingAs($usuario)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_trocar_a_senha_libera_o_painel(): void
    {
        $usuario = User::factory()->primeiroAcesso()->create();

        $this->actingAs($usuario)
            ->put(route('senha.primeiro-acesso.update'), [
                'password' => 'minha-senha-longa-2026',
                'password_confirmation' => 'minha-senha-longa-2026',
            ])
            ->assertSessionHasNoErrors();

        $usuario->refresh();

        $this->assertFalse($usuario->primeiro_acesso);
        $this->assertTrue(Hash::check('minha-senha-longa-2026', $usuario->password));

        $this->actingAs($usuario)->get(route('centro-controle'))->assertOk();
    }

    public function test_a_troca_nao_pede_a_senha_atual(): void
    {
        // Exigi-la só repetiria o que a pessoa acabou de digitar para entrar,
        // sem provar nada: a sessão já é a prova.
        $usuario = User::factory()->primeiroAcesso()->create();

        $this->actingAs($usuario)
            ->put(route('senha.primeiro-acesso.update'), [
                'password' => 'outra-senha-bem-longa',
                'password_confirmation' => 'outra-senha-bem-longa',
            ])
            ->assertSessionHasNoErrors();
    }

    public function test_confirmacao_diferente_e_recusada(): void
    {
        $usuario = User::factory()->primeiroAcesso()->create();

        $this->actingAs($usuario)
            ->put(route('senha.primeiro-acesso.update'), [
                'password' => 'minha-senha-longa-2026',
                'password_confirmation' => 'digitei-diferente',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($usuario->fresh()->primeiro_acesso);
    }

    public function test_a_conta_criada_pela_tela_cai_direto_na_troca(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('usuarios.store'), [
            'name' => 'Joana Pereira',
            'email' => 'joana@alfatecnologia.com.br',
            'perfis' => [Perfil::where('slug', 'operacao')->value('id')],
        ]);

        $senha = session('senha_gerada')['senha'];

        $this->post(route('logout'));
        $this->post(route('login'), ['email' => 'joana@alfatecnologia.com.br', 'password' => $senha]);

        $this->get(route('centro-controle'))->assertRedirect(route('senha.primeiro-acesso'));
    }
}
