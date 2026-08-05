<?php

namespace Tests\Feature\Acessos;

use App\Models\User;
use Database\Seeders\DadosIniciaisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederEmailAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O ambiente publicado define estas variáveis no .env. Sem limpar
        // aqui, o teste passaria só onde elas não existem — e reprovaria o
        // portão de deploy sem haver defeito nenhum no código.
        $this->limparVariaveis();
    }

    protected function tearDown(): void
    {
        $this->limparVariaveis();

        parent::tearDown();
    }

    private function limparVariaveis(): void
    {
        foreach (['ADMIN_EMAIL', 'ADMIN_PASSWORD'] as $variavel) {
            putenv($variavel);
            unset($_ENV[$variavel], $_SERVER[$variavel]);
        }
    }

    /**
     * @spec:AC-031 A carga inicial cria o administrador no e-mail configurado
     * no ambiente — e não no endereço que estava fixo no código, que fazia a
     * conta antiga voltar depois de alguém trocar o acesso.
     */
    public function test_carga_inicial_usa_o_email_do_ambiente(): void
    {
        putenv('ADMIN_EMAIL=financeiro@alfatecnologia.com.br');
        $_ENV['ADMIN_EMAIL'] = 'financeiro@alfatecnologia.com.br';

        $this->app->make(DadosIniciaisSeeder::class)->run();

        $this->assertDatabaseHas('users', ['email' => 'financeiro@alfatecnologia.com.br']);
        $this->assertDatabaseMissing('users', ['email' => 'admin@alfatecnologia.com.br']);
        $this->assertSame(1, User::count(), 'A carga não pode deixar duas contas de administrador.');
    }

    /**
     * @spec:AC-031 Sem a variável definida, o endereço histórico continua
     * valendo — para não quebrar o ambiente local de quem já usa o padrão.
     */
    public function test_sem_a_variavel_mantem_o_endereco_padrao(): void
    {
        putenv('ADMIN_EMAIL');
        unset($_ENV['ADMIN_EMAIL'], $_SERVER['ADMIN_EMAIL']);

        $this->app->make(DadosIniciaisSeeder::class)->run();

        $this->assertDatabaseHas('users', ['email' => 'admin@alfatecnologia.com.br']);
    }
}
