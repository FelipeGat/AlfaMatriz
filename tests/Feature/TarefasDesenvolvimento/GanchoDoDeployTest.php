<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use Tests\TestCase;

/**
 * O contrato entre o deploy do staging e o quadro (US-086).
 *
 * O script roda no host do Proxmox e o quadro vive na produção — nenhum teste
 * daqui alcança os dois de verdade. O que dá para segurar por teste é o
 * CONTRATO: o script chama o comando do portão nos dois vereditos, e chama
 * como espelho (best-effort), nunca como portão. É o teste que acusa quando
 * alguém renomear o comando e esquecer o script — o erro que em produção
 * falharia calado, dentro de um `|| log`.
 */
class GanchoDoDeployTest extends TestCase
{
    /**
     * @spec:AC-314 O deploy chama o comando do portão nos dois vereditos — reprovou
     * bloqueia o quadro, passou destrava — sem derrubar o deploy se a chamada falhar.
     */
    public function test_o_script_chama_o_portao_nos_dois_vereditos_sem_derrubar_o_deploy(): void
    {
        $script = file_get_contents(base_path('deploy/deploy-staging-alfamatriz.sh'));

        // Os dois ramos, pelo nome completo do comando: renomeá-lo sem mexer
        // aqui é exatamente o que este teste existe para acusar.
        $this->assertStringContainsString('alfa:portao-staging reprovou', $script);
        $this->assertStringContainsString('alfa:portao-staging passou', $script);

        // Espelho, não portão: cada chamada engole a própria falha com um
        // `|| log`. Sem isso, painel fora do ar derrubaria deploy de staging.
        foreach (['reprovou', 'passou'] as $veredito) {
            $linha = $this->linhaQueChama($script, $veredito);

            $this->assertStringContainsString('|| log', $linha,
                "A chamada de '{$veredito}' precisa ser best-effort — o quadro não pode segurar o deploy.");
        }

        // E fala com o quadro de PRODUÇÃO, onde o time trabalha — não com o
        // banco do staging, onde ninguém veria o bloqueio.
        $this->assertStringContainsString('QUADRO_LXC=${QUADRO_LXC:-115}', $script);
        $this->assertStringContainsString('QUADRO_DIR=${QUADRO_DIR:-/var/www/alfamatriz/atual}', $script);
    }

    /** A(s) linha(s) da chamada de um veredito, com a continuação `\` colada. */
    private function linhaQueChama(string $script, string $veredito): string
    {
        $emenda = preg_replace('/\\\\\n/', ' ', $script);

        foreach (explode("\n", $emenda) as $linha) {
            if (str_contains($linha, 'alfa:portao-staging '.$veredito)) {
                return $linha;
            }
        }

        $this->fail("Nenhuma linha do script chama 'alfa:portao-staging {$veredito}'.");
    }
}
