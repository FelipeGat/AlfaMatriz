<?php

namespace Tests\Feature\Usuarios;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A tela que tirou o cadastro de contas do SSH.
 *
 * O que estes testes guardam, além do CRUD: a senha nunca é escolhida por
 * quem administra, aparece uma única vez, e nenhuma ação da tela consegue
 * deixar o sistema sem quem a reabra.
 */
class GestaoDeUsuariosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function idDoPerfil(string $slug): int
    {
        return Perfil::where('slug', $slug)->value('id');
    }

    /** @return array<string, mixed> */
    private function dados(array $extras = []): array
    {
        return array_merge([
            'name' => 'Joana Pereira',
            'email' => 'joana@alfatecnologia.com.br',
            'perfis' => [$this->idDoPerfil('operacao')],
        ], $extras);
    }

    public function test_a_tela_lista_as_contas_com_perfil_e_situacao(): void
    {
        $admin = $this->admin();
        User::factory()->desativado()->create(['name' => 'Quem Saiu']);

        $resposta = $this->actingAs($admin)->get(route('usuarios.index'))->assertOk();

        $resposta->assertSee($admin->name);
        $resposta->assertSee('Quem Saiu');
        $resposta->assertSee('Desativada');
    }

    public function test_criar_conta_gera_a_senha_e_a_mostra_uma_unica_vez(): void
    {
        $admin = $this->admin();

        $resposta = $this->actingAs($admin)
            ->post(route('usuarios.store'), $this->dados())
            ->assertRedirect();

        $usuario = User::where('email', 'joana@alfatecnologia.com.br')->firstOrFail();

        $this->assertTrue($usuario->ativo);
        $this->assertTrue($usuario->primeiro_acesso, 'A senha veio de outra pessoa: a troca é obrigatória.');
        $this->assertNotNull($usuario->email_verified_at, 'Sem envio de e-mail, a conta nasce liberada.');

        $senha = session('senha_gerada')['senha'];
        $this->assertSame(20, strlen($senha));
        $this->assertTrue(Hash::check($senha, $usuario->password));

        // Uma vez, e só uma: a volta do cadastro mostra a senha; recarregar a
        // mesma tela já não mostra. Ela vive no flash da sessão e em nenhum
        // outro lugar — não há tela no sistema onde reencontrá-la.
        $resposta->assertSessionHas('senha_gerada');
        $this->actingAs($admin)->get(route('usuarios.index'))->assertSee($senha);
        $this->actingAs($admin)->get(route('usuarios.index'))->assertDontSee($senha);
    }

    public function test_o_formulario_nao_aceita_senha_escolhida_por_quem_administra(): void
    {
        $this->actingAs($this->admin())
            ->post(route('usuarios.store'), $this->dados(['password' => 'senha-do-time-2026']));

        $usuario = User::where('email', 'joana@alfatecnologia.com.br')->firstOrFail();

        $this->assertFalse(
            Hash::check('senha-do-time-2026', $usuario->password),
            'Campo de senha no envio não pode virar a senha da conta.'
        );
    }

    public function test_conta_sem_perfil_e_recusada(): void
    {
        $this->actingAs($this->admin())
            ->post(route('usuarios.store'), $this->dados(['perfis' => []]))
            ->assertSessionHasErrors('perfis');

        $this->assertDatabaseMissing('users', ['email' => 'joana@alfatecnologia.com.br']);
    }

    public function test_o_email_de_uma_conta_excluida_continua_reservado(): void
    {
        $admin = $this->admin();
        $excluida = User::factory()->create(['email' => 'quemsaiu@alfatecnologia.com.br']);
        $excluida->delete();

        $this->actingAs($admin)
            ->post(route('usuarios.store'), $this->dados(['email' => 'quemsaiu@alfatecnologia.com.br']))
            ->assertSessionHasErrors('email');
    }

    public function test_editar_troca_perfil_e_alcance_sem_mexer_na_senha(): void
    {
        $admin = $this->admin();
        $revenda = Revenda::create(['nome' => 'Revenda Sul']);
        $usuario = User::factory()->create();
        $senhaAntes = $usuario->password;

        $this->actingAs($admin)
            ->put(route('usuarios.update', $usuario), $this->dados([
                'name' => 'Nome Novo',
                'email' => $usuario->email,
                'perfis' => [$this->idDoPerfil('financeiro')],
                'revenda_id' => $revenda->id,
            ]))
            ->assertSessionHasNoErrors();

        $usuario->refresh();

        $this->assertSame('Nome Novo', $usuario->name);
        $this->assertSame($revenda->id, $usuario->revenda_id);
        $this->assertSame(['financeiro'], $usuario->perfis->pluck('slug')->all());
        $this->assertSame($senhaAntes, $usuario->password);
    }

    public function test_redefinir_senha_gera_outra_e_reativa_a_troca_obrigatoria(): void
    {
        $usuario = User::factory()->create();
        $senhaAntes = $usuario->password;

        $this->actingAs($this->admin())
            ->post(route('usuarios.senha', $usuario))
            ->assertSessionHas('senha_gerada');

        $usuario->refresh();

        $this->assertNotSame($senhaAntes, $usuario->password);
        $this->assertTrue($usuario->primeiro_acesso);
        $this->assertTrue(Hash::check(session('senha_gerada')['senha'], $usuario->password));
    }

    public function test_desativar_e_reativar_uma_conta(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($this->admin())->post(route('usuarios.ativo', $usuario));
        $this->assertFalse($usuario->fresh()->ativo);

        $this->actingAs($this->admin())->post(route('usuarios.ativo', $usuario));
        $this->assertTrue($usuario->fresh()->ativo);
    }

    public function test_excluir_e_soft_delete_e_some_da_lista(): void
    {
        $usuario = User::factory()->create(['name' => 'Quem Saiu']);

        $this->actingAs($this->admin())
            ->delete(route('usuarios.destroy', $usuario))
            ->assertSessionHasNoErrors();

        $this->assertSoftDeleted('users', ['id' => $usuario->id]);
        // Pelo e-mail, e não pelo nome: o nome ainda aparece uma vez, no aviso
        // "Conta de Quem Saiu excluída" que a própria exclusão deixou na sessão.
        $this->actingAs($this->admin())->get(route('usuarios.index'))->assertDontSee($usuario->email);
    }

    public function test_ninguem_tira_o_proprio_perfil_de_administrador(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put(route('usuarios.update', $admin), $this->dados([
                'name' => $admin->name,
                'email' => $admin->email,
                'perfis' => [$this->idDoPerfil('operacao')],
            ]))
            ->assertSessionHas('erro');

        $this->assertTrue($admin->fresh()->perfis->contains('slug', 'admin'));
    }

    public function test_ninguem_desativa_nem_exclui_a_propria_conta(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('usuarios.ativo', $admin))->assertSessionHas('erro');
        $this->assertTrue($admin->fresh()->ativo);

        $this->actingAs($admin)->delete(route('usuarios.destroy', $admin))->assertSessionHas('erro');
        $this->assertNotSoftDeleted('users', ['id' => $admin->id]);
    }

    public function test_a_ultima_conta_de_administrador_ativa_nao_cai(): void
    {
        // Quem age não é admin: o cenário só existe se outro perfil ganhar o
        // recurso `usuarios` um dia. É exatamente para esse dia que a regra vale.
        //
        // O admin nasce primeiro porque é a factory dele que semeia os perfis;
        // `semPerfil()` pula o seeder de propósito.
        $ultimoAdmin = $this->admin();

        $operador = User::factory()->semPerfil()->create();
        $operador->perfis()->attach($this->idDoPerfil('operacao'));
        Perfil::where('slug', 'operacao')->first()->permissoes()
            ->syncWithoutDetaching([
                Permissao::where('recurso', 'usuarios')->value('id') => [
                    'ler' => true, 'incluir' => true, 'imprimir' => true, 'excluir' => true,
                ],
            ]);

        $this->actingAs($operador)
            ->post(route('usuarios.ativo', $ultimoAdmin))
            ->assertSessionHas('erro');

        $this->assertTrue($ultimoAdmin->fresh()->ativo);
    }
}
