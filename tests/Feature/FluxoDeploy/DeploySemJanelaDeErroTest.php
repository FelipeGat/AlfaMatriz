<?php

namespace Tests\Feature\FluxoDeploy;

use Tests\TestCase;

class DeploySemJanelaDeErroTest extends TestCase
{
    private const SCRIPTS = [
        'deploy/deploy-staging-alfamatriz.sh',
        'deploy/deploy-tag-watcher-alfamatriz.sh',
        'deploy/publicar.sh',
    ];

    /**
     * @spec:AC-037 Nenhum script apaga o cache de configuração antes de
     * reconstruí-lo.
     *
     * O `.env` do servidor é 600/root, então o www-data não consegue lê-lo: o
     * app depende inteiramente do cache. Entre um `config:clear` e o
     * `config:cache` seguinte existe uma janela de segundos sem nenhuma das
     * duas fontes, e toda requisição nela responde 500 — foi observado no
     * staging. O `config:cache` sozinho reescreve o arquivo de uma vez.
     */
    public function test_nenhum_script_apaga_a_configuracao_antes_de_reconstruir(): void
    {
        foreach (self::SCRIPTS as $script) {
            $conteudo = file_get_contents(base_path($script));

            foreach (preg_split('/\R/', $conteudo) as $numero => $linha) {
                // Comentários explicando a decisão são permitidos.
                if (preg_match('/^\s*#/', $linha)) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    'config:clear',
                    $linha,
                    "{$script}:".($numero + 1)." apaga a configuração e abre uma janela de 500 para quem estiver usando."
                );
            }

            $this->assertStringContainsString(
                'config:cache',
                $conteudo,
                "{$script} precisa reconstruir o cache de configuração."
            );
        }
    }
}
