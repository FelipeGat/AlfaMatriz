<?php

namespace Tests\Feature\Deploy;

use App\Models\User;
use Database\Seeders\DadosIniciaisSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SeederSenhaAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // O ambiente publicado define ADMIN_PASSWORD no .env. Sem limpar aqui,
        // o cenário "sem senha definida" nunca acontece no servidor e o teste
        // reprova o portão de deploy sem haver defeito no código.
        $this->limparSenha();
    }

    protected function tearDown(): void
    {
        $this->limparSenha();

        parent::tearDown();
    }

    private function limparSenha(): void
    {
        // ADMIN_EMAIL também: o seeder passou a lê-lo do ambiente, e onde ele
        // estiver definido o admin nasce com outro endereço — quebrando a
        // busca abaixo por um motivo que não é o objeto deste teste.
        foreach (['ADMIN_PASSWORD', 'ADMIN_EMAIL'] as $variavel) {
            putenv($variavel);
            unset($_ENV[$variavel], $_SERVER[$variavel]);
        }
    }

    /**
     * @spec:AC-006 Em produção, a carga inicial sem ADMIN_PASSWORD definida
     * falha avisando que a senha é obrigatória — em vez de criar o admin com
     * a senha de exemplo que está publicada no README. Com a variável
     * definida, é ela que vale.
     */
    public function test_carga_inicial_em_producao_exige_senha_do_ambiente(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue($this->app->environment('production'));

        try {
            $this->rodarCargaInicial();
            $this->fail('A carga inicial deveria ter falhado sem ADMIN_PASSWORD em produção.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ADMIN_PASSWORD', $e->getMessage());
        }

        $this->assertDatabaseMissing('users', ['email' => 'admin@alfatecnologia.com.br']);

        // Com a senha vinda do ambiente, a carga passa e é ela que vale.
        putenv('ADMIN_PASSWORD=SenhaDeProducao@2026');
        $_ENV['ADMIN_PASSWORD'] = 'SenhaDeProducao@2026';

        $this->rodarCargaInicial();

        $admin = User::where('email', 'admin@alfatecnologia.com.br')->firstOrFail();
        $this->assertTrue(Hash::check('SenhaDeProducao@2026', $admin->password));
        $this->assertFalse(
            Hash::check('AlfaTecnologia@2026', $admin->password),
            'A senha de exemplo do README nunca pode valer em produção.'
        );
    }

    /**
     * Chama o seeder direto: em produção o `db:seed` pede confirmação
     * interativa, e o que está sob teste é a carga, não o prompt.
     */
    private function rodarCargaInicial(): void
    {
        $this->app->make(DadosIniciaisSeeder::class)->run();
    }
}
