<?php

namespace Tests\Feature\Deploy;

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
}
