<?php

namespace Tests\Feature\Acessos;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AlterarAcessoCommandTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-029 O comando troca e-mail e senha da conta existente: a pessoa
     * passa a entrar com os dados novos, a senha antiga para de funcionar e a
     * conta continua a mesma, com perfis e permissões preservados.
     */
    public function test_troca_email_e_senha_mantendo_a_mesma_conta(): void
    {
        $usuario = User::factory()->create([
            'email' => 'admin@alfatecnologia.com.br',
        ]);
        $idOriginal = $usuario->id;

        // O factory atribui o perfil admin; o teste o reaproveita e garante o
        // contrato de que os perfis sobrevivem à troca de credenciais.
        $usuario->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);

        $this->artisan('alfa:alterar-acesso', [
            'email' => 'admin@alfatecnologia.com.br',
            '--novo-email' => 'financeiro@alfatecnologia.com.br',
            '--senha' => 'NovaSenhaForte@2026',
        ])->assertSuccessful();

        $atualizado = User::find($idOriginal);
        $this->assertNotNull($atualizado, 'Precisa ser a MESMA conta, não uma nova.');
        $this->assertSame('financeiro@alfatecnologia.com.br', $atualizado->email);
        $this->assertTrue(Hash::check('NovaSenhaForte@2026', $atualizado->password));
        $this->assertSame(1, $atualizado->perfis()->count(), 'Os perfis não podem se perder na troca.');

        // Entra com o novo e-mail...
        $this->post('/login', [
            'email' => 'financeiro@alfatecnologia.com.br',
            'password' => 'NovaSenhaForte@2026',
        ]);
        $this->assertAuthenticatedAs($atualizado);
        $this->post('/logout');

        // ...e o e-mail antigo não existe mais.
        $this->assertDatabaseMissing('users', ['email' => 'admin@alfatecnologia.com.br']);
    }

    /**
     * @spec:AC-029 Trocar só o e-mail é possível: o comando pergunta a senha e
     * uma resposta em branco mantém a que já existe.
     */
    public function test_senha_em_branco_mantem_a_atual(): void
    {
        $usuario = User::factory()->create(['email' => 'admin@alfatecnologia.com.br']);
        $hashOriginal = $usuario->password;

        $this->artisan('alfa:alterar-acesso', ['email' => 'admin@alfatecnologia.com.br'])
            ->expectsQuestion('Nova senha (em branco mantém a atual)', '')
            ->assertSuccessful();

        $this->assertSame($hashOriginal, $usuario->fresh()->password);
    }

    /**
     * @spec:AC-030 Pedido inválido é recusado sem alterar nada: conta que não
     * existe, e-mail que já é de outra pessoa, ou senha curta demais — uma
     * alteração pela metade trancaria todo mundo para fora.
     */
    public function test_recusa_alteracao_invalida_sem_tocar_na_conta(): void
    {
        $usuario = User::factory()->create(['email' => 'admin@alfatecnologia.com.br']);
        $hashOriginal = $usuario->password;
        User::factory()->create(['email' => 'outra@alfatecnologia.com.br']);

        // Conta que não existe.
        $this->artisan('alfa:alterar-acesso', [
            'email' => 'ninguem@alfatecnologia.com.br',
            '--senha' => 'SenhaQualquer@2026',
        ])->assertFailed();

        // E-mail que já pertence a outra pessoa.
        $this->artisan('alfa:alterar-acesso', [
            'email' => 'admin@alfatecnologia.com.br',
            '--novo-email' => 'outra@alfatecnologia.com.br',
            '--senha' => 'SenhaQualquer@2026',
        ])->assertFailed();

        // Senha curta demais — e o e-mail novo não pode ser aplicado mesmo assim.
        $this->artisan('alfa:alterar-acesso', [
            'email' => 'admin@alfatecnologia.com.br',
            '--novo-email' => 'novo@alfatecnologia.com.br',
            '--senha' => 'curta',
        ])->assertFailed();

        $intacto = $usuario->fresh();
        $this->assertSame('admin@alfatecnologia.com.br', $intacto->email, 'Nenhuma recusa pode ter mexido no e-mail.');
        $this->assertSame($hashOriginal, $intacto->password, 'Nenhuma recusa pode ter mexido na senha.');
    }
}
