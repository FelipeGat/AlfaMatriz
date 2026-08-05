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

    protected function tearDown(): void
    {
        putenv('ADMIN_PASSWORD');
        unset($_ENV['ADMIN_PASSWORD'], $_SERVER['ADMIN_PASSWORD']);

        parent::tearDown();
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
