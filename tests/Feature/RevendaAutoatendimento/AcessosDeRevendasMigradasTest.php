<?php

namespace Tests\Feature\RevendaAutoatendimento;

use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * O acesso das revendas que vieram do AlfaGym pelo sincronizador.
 *
 * Revenda provisionada pela Matriz já nasce com acesso; a migrada não tem
 * usuário nenhum e não conseguiria entrar para cadastrar cliente.
 */
class AcessosDeRevendasMigradasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        (new PerfilPermissaoSeeder)->run();
    }

    /** @spec:AC-107 As revendas migradas ganham acesso ao painel. */
    public function test_cria_acesso_para_revenda_migrada(): void
    {
        $revenda = Revenda::create([
            'nome' => 'Invest Soluções',
            'contato_nome' => 'Fulano da Silva',
            'contato_email' => 'contato@invest.com.br',
            'ativo' => true,
        ]);

        $this->artisan('alfa:criar-acessos-revendas')
            ->expectsOutputToContain('Acessos criados (1)')
            ->assertSuccessful();

        $usuario = User::where('revenda_id', $revenda->id)->first();

        $this->assertNotNull($usuario, 'A revenda migrada continuou sem acesso ao painel.');
        $this->assertSame('contato@invest.com.br', $usuario->email);
        $this->assertSame('Fulano da Silva', $usuario->name);
        $this->assertContains('revenda', $usuario->perfis->pluck('slug')->all());
        $this->assertTrue($usuario->temEscopoDeRevenda());
    }

    /** @spec:AC-107 Cada revenda recebe a própria senha, nunca uma compartilhada. */
    public function test_cada_revenda_recebe_senha_propria(): void
    {
        foreach (['Invest Soluções' => 'a@invest.com.br', 'Concorrente Ltda' => 'b@concorrente.com.br'] as $nome => $email) {
            Revenda::create(['nome' => $nome, 'contato_email' => $email, 'ativo' => true]);
        }

        $this->artisan('alfa:criar-acessos-revendas')->assertSuccessful();

        $primeiro = User::where('email', 'a@invest.com.br')->firstOrFail();
        $segundo = User::where('email', 'b@concorrente.com.br')->firstOrFail();

        // Senhas diferentes: uma senha só entre revendas concorrentes seria
        // acesso cruzado esperando acontecer.
        $this->assertNotSame($primeiro->password, $segundo->password);
        $this->assertNotSame($primeiro->revenda_id, $segundo->revenda_id);
    }

    /** @spec:AC-107 Rodar de novo não duplica nem redefine a senha de quem já entra. */
    public function test_rodar_de_novo_nao_duplica_nem_troca_senha(): void
    {
        $revenda = Revenda::create([
            'nome' => 'Invest Soluções', 'contato_email' => 'contato@invest.com.br', 'ativo' => true,
        ]);

        $this->artisan('alfa:criar-acessos-revendas')->assertSuccessful();

        $senhaOriginal = User::where('revenda_id', $revenda->id)->firstOrFail()->password;

        $this->artisan('alfa:criar-acessos-revendas')
            ->expectsOutputToContain('Já tinham acesso (1)')
            ->assertSuccessful();

        $this->assertSame(1, User::where('revenda_id', $revenda->id)->count());
        // Derrubar o acesso de quem já usa o painel seria pior que não rodar.
        $this->assertSame($senhaOriginal, User::where('revenda_id', $revenda->id)->firstOrFail()->password);
    }

    /** @spec:AC-107 Revenda sem e-mail de contato vira pendência, não acesso inventado. */
    public function test_revenda_sem_email_vira_pendencia(): void
    {
        Revenda::create(['nome' => 'Revenda Sem Contato', 'ativo' => true]);

        $this->artisan('alfa:criar-acessos-revendas')
            ->expectsOutputToContain('Revenda Sem Contato — sem e-mail de contato')
            ->assertSuccessful();

        $this->assertSame(0, User::whereNotNull('revenda_id')->count());
    }

    /** @spec:AC-107 A senha criada serve para entrar de verdade. */
    public function test_a_senha_impressa_e_a_senha_que_entra(): void
    {
        Revenda::create(['nome' => 'Invest Soluções', 'contato_email' => 'contato@invest.com.br', 'ativo' => true]);

        $this->withoutMockingConsoleOutput();
        Artisan::call('alfa:criar-acessos-revendas');

        // A senha sai no relatório; é a que o admin repassa para a revenda.
        preg_match('/contato@invest\.com\.br · senha: (\S+)/u', Artisan::output(), $m);

        $this->assertNotEmpty($m[1] ?? null, 'A senha não apareceu no relatório do comando.');
        $this->assertTrue(
            Hash::check($m[1], User::where('email', 'contato@invest.com.br')->firstOrFail()->password),
            'A senha impressa não é a senha gravada — o admin repassaria uma senha que não entra.'
        );
    }

    /** @spec:AC-107 O comando exige o perfil de revenda antes de criar qualquer acesso. */
    public function test_sem_o_perfil_revenda_o_comando_para(): void
    {
        Perfil::where('slug', 'revenda')->delete();

        Revenda::create(['nome' => 'Invest Soluções', 'contato_email' => 'contato@invest.com.br', 'ativo' => true]);

        $this->artisan('alfa:criar-acessos-revendas')->assertFailed();

        // Acesso sem perfil entraria no painel e tomaria 403 em toda tela.
        $this->assertSame(0, User::whereNotNull('revenda_id')->count());
    }
}
