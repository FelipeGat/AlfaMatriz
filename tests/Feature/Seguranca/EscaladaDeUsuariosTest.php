<?php

namespace Tests\Feature\Seguranca;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * `usuarios` é a permissão que cadastra contas — não a que faz alguém
 * administrador. Estes testes guardam a fronteira entre as duas: só quem já
 * é admin promove outro a admin, e só admin repõe a senha de um admin.
 */
class EscaladaDeUsuariosTest extends TestCase
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

    /**
     * Conta com a permissão `usuarios` (todos os verbos) e sem o perfil
     * Administrador — o cenário que as duas ACs desta história descrevem.
     */
    private function gestorDeContasSemAdmin(): User
    {
        // `semPerfil()` pula o `PerfilPermissaoSeeder` que o factory normal
        // dispara — sem chamá-lo à mão aqui, nem o perfil `operacao` existe.
        (new PerfilPermissaoSeeder)->run();

        $gestor = User::factory()->semPerfil()->create();
        $gestor->perfis()->attach($this->idDoPerfil('operacao'));

        Perfil::where('slug', 'operacao')->first()->permissoes()
            ->syncWithoutDetaching([
                Permissao::where('recurso', 'usuarios')->value('id') => [
                    'ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => true,
                ],
            ]);

        return $gestor;
    }

    /** @spec:AC-264 Só administrador concede o perfil Administrador (criação). */
    public function test_gestor_sem_admin_nao_cria_conta_administradora(): void
    {
        $gestor = $this->gestorDeContasSemAdmin();

        $this->actingAs($gestor)
            ->post(route('usuarios.store'), [
                'name' => 'Nova Conta',
                'email' => 'nova@alfatecnologia.com.br',
                'perfis' => [$this->idDoPerfil('admin')],
            ])
            ->assertSessionHas('erro');

        $this->assertDatabaseMissing('users', ['email' => 'nova@alfatecnologia.com.br']);
    }

    /** @spec:AC-264 Só administrador concede o perfil Administrador (edição, inclusive na própria conta). */
    public function test_gestor_sem_admin_nao_promove_a_propria_conta_nem_a_de_outro(): void
    {
        $gestor = $this->gestorDeContasSemAdmin();
        $perfisAntes = $gestor->perfis()->orderBy('nome')->pluck('slug')->all();

        $this->actingAs($gestor)
            ->put(route('usuarios.update', $gestor), [
                'name' => $gestor->name,
                'email' => $gestor->email,
                'perfis' => [$this->idDoPerfil('operacao'), $this->idDoPerfil('admin')],
            ])
            ->assertSessionHas('erro');

        $gestor->refresh();
        $this->assertSame($perfisAntes, $gestor->perfis()->orderBy('nome')->pluck('slug')->all());

        $outraConta = User::factory()->semPerfil()->create();
        $outraConta->perfis()->attach($this->idDoPerfil('operacao'));

        $this->actingAs($gestor)
            ->put(route('usuarios.update', $outraConta), [
                'name' => $outraConta->name,
                'email' => $outraConta->email,
                'perfis' => [$this->idDoPerfil('admin')],
            ])
            ->assertSessionHas('erro');

        $this->assertSame(['operacao'], $outraConta->fresh()->perfis->pluck('slug')->all());
    }

    /** @spec:AC-264 Administrador continua livre para conceder o próprio perfil. */
    public function test_administrador_continua_promovendo_outra_conta(): void
    {
        $admin = $this->admin();
        $outraConta = User::factory()->semPerfil()->create();
        $outraConta->perfis()->attach($this->idDoPerfil('operacao'));

        $this->actingAs($admin)
            ->put(route('usuarios.update', $outraConta), [
                'name' => $outraConta->name,
                'email' => $outraConta->email,
                'perfis' => [$this->idDoPerfil('admin')],
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($outraConta->fresh()->ehAdmin());
    }

    /** @spec:AC-265 Só administrador repõe a senha de um administrador. */
    public function test_gestor_sem_admin_nao_redefine_a_senha_de_um_administrador(): void
    {
        $gestor = $this->gestorDeContasSemAdmin();
        $outroAdmin = $this->admin();
        $senhaAntes = $outroAdmin->password;

        $this->actingAs($gestor)
            ->post(route('usuarios.senha', $outroAdmin))
            ->assertSessionHas('erro');

        $this->assertSame($senhaAntes, $outroAdmin->fresh()->password);
    }

    /** @spec:AC-265 Administrador continua repondo a senha de outro administrador. */
    public function test_administrador_continua_redefinindo_a_senha_de_outro_administrador(): void
    {
        $admin = $this->admin();
        $outroAdmin = $this->admin();
        $senhaAntes = $outroAdmin->password;

        $this->actingAs($admin)
            ->post(route('usuarios.senha', $outroAdmin))
            ->assertSessionHas('senha_gerada');

        $outroAdmin->refresh();
        $this->assertNotSame($senhaAntes, $outroAdmin->password);
        $this->assertTrue(Hash::check(session('senha_gerada')['senha'], $outroAdmin->password));
    }

    /** @spec:AC-265 Gestor sem admin continua repondo a senha de quem não é administrador. */
    public function test_gestor_sem_admin_continua_redefinindo_senha_de_conta_comum(): void
    {
        $gestor = $this->gestorDeContasSemAdmin();
        $comum = User::factory()->semPerfil()->create();
        $comum->perfis()->attach($this->idDoPerfil('operacao'));

        $this->actingAs($gestor)
            ->post(route('usuarios.senha', $comum))
            ->assertSessionHas('senha_gerada');
    }
}
