<?php

namespace Tests\Feature\Deploy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaudeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-005 A checagem de saúde responde 200 com o estado do app e do
     * banco, sem exigir login e sem devolver nada do negócio — é ela que o
     * script de conferência consulta depois do deploy.
     */
    public function test_healthz_responde_sem_login_e_nao_vaza_dado_de_negocio(): void
    {
        $resposta = $this->get('/healthz');

        $resposta->assertOk();
        $resposta->assertExactJson([
            'app' => 'ok',
            'banco' => 'ok',
        ]);

        $this->assertGuest();

        // Nada de versão, caminho de arquivo ou credencial na resposta.
        $corpo = $resposta->getContent();
        foreach (['/var/www', 'APP_KEY', 'password', 'Laravel', base_path()] as $vazamento) {
            $this->assertStringNotContainsStringIgnoringCase(
                $vazamento,
                $corpo,
                "A checagem de saúde não pode expor \"{$vazamento}\"."
            );
        }
    }
}
