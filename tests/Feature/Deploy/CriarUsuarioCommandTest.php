<?php

namespace Tests\Feature\Deploy;

use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CriarUsuarioCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-002 O operador cria uma conta pelo comando administrativo e a
     * pessoa consegue entrar no painel; se o e-mail já existir, o comando
     * recusa e não sobrescreve a senha de quem já está lá.
     */
    public function test_comando_cria_usuario_que_consegue_entrar_e_recusa_email_repetido(): void
    {
        $this->artisan('alfa:criar-usuario', [
            'nome' => 'Rossini Santos',
            'email' => 'rossini@alfatecnologia.com.br',
            '--senha' => 'SenhaInicial@2026',
        ])->assertSuccessful();

        $usuario = User::where('email', 'rossini@alfatecnologia.com.br')->first();
        $this->assertNotNull($usuario, 'O comando deveria ter criado o usuário.');
        $this->assertNotNull($usuario->email_verified_at, 'O painel exige e-mail verificado.');

        $this->post('/login', [
            'email' => 'rossini@alfatecnologia.com.br',
            'password' => 'SenhaInicial@2026',
        ]);
        $this->assertAuthenticatedAs($usuario);
        $this->post('/logout');

        // Mesmo e-mail, senha diferente: precisa recusar sem tocar no que existe.
        $this->artisan('alfa:criar-usuario', [
            'nome' => 'Outra Pessoa',
            'email' => 'rossini@alfatecnologia.com.br',
            '--senha' => 'SenhaDoInvasor@2026',
        ])->assertFailed();

        $this->assertSame(1, User::where('email', 'rossini@alfatecnologia.com.br')->count());
        $this->assertTrue(
            Hash::check('SenhaInicial@2026', $usuario->fresh()->password),
            'A senha de quem já existia não pode ser sobrescrita.'
        );
    }

    /**
     * @spec:AC-002 Usuário com perfil de revenda nasce com o escopo restrito:
     * o `--revenda` grava o vínculo e o perfil determina o que ele pode abrir.
     */
    public function test_comando_vincular_perfil_e_revenda(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);

        $this->artisan('alfa:criar-usuario', [
            'nome' => 'Gerente Alpha',
            'email' => 'gerente@alpha.com.br',
            '--senha' => 'SenhaInicial@2026',
            '--perfil' => 'operacao',
            '--revenda' => $revenda->id,
        ])->assertSuccessful();

        $usuario = User::where('email', 'gerente@alpha.com.br')->first();
        $this->assertNotNull($usuario);
        $this->assertSame((int) $revenda->id, (int) $usuario->revenda_id, 'O vínculo com a revenda precisa ser gravado.');
        $this->assertTrue($usuario->temEscopoDeRevenda(), 'Com revenda_id o usuário passa a ter escopo restrito.');
        $this->assertTrue(
            $usuario->perfis()->where('slug', 'operacao')->exists(),
            'O perfil informado precisa ser atribuído.'
        );
    }

    /**
     * @spec:AC-002 Revenda inexistente é recusada: criar uma conta vinculada a
     * um escopo que não existe só causaria confusão.
     */
    public function test_comando_recusa_revenda_inexistente(): void
    {
        $this->artisan('alfa:criar-usuario', [
            'nome' => 'Sem Dono',
            'email' => 'sem@dono.com.br',
            '--senha' => 'SenhaInicial@2026',
            '--revenda' => 9999,
        ])->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'sem@dono.com.br']);
    }
}
