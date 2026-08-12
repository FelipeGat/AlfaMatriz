<?php

namespace Tests\Feature\FluxoDeploy;

use Tests\TestCase;

class DeploySemJanelaDeErroTest extends TestCase
{
    private const SCRIPTS = [
        'deploy/deploy-staging-alfamatriz.sh',
        'deploy/deploy-tag-watcher-alfamatriz.sh',
        'deploy/publicar.sh',
        'deploy/voltar.sh',
        'deploy/converter-para-azul-verde.sh',
    ];

    /**
     * Quem reconstrói os caches é o motor da publicação — os outros scripts o
     * chamam. Enquanto cada um tinha a sua cópia das etapas, elas derivaram.
     */
    private const RECONSTROI_O_CACHE = [
        'deploy/publicar.sh',
        'deploy/converter-para-azul-verde.sh',
    ];

    /**
     * @spec:AC-068 Nenhum script apaga o cache de configuração antes de
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
                    "{$script}:".($numero + 1).' apaga a configuração e abre uma janela de 500 para quem estiver usando.'
                );
            }

            if (in_array($script, self::RECONSTROI_O_CACHE, true)) {
                $this->assertStringContainsString(
                    'config:cache',
                    $conteudo,
                    "{$script} precisa reconstruir o cache de configuração."
                );
            }
        }
    }

    /**
     * @spec:AC-167 Com azul/verde a reconstrução do cache deixa de acontecer
     * em cima do que está no ar: ela roda dentro da cópia em preparo.
     *
     * A janela de 500 que a asserção acima persegue nasceu de um `config:clear`
     * no diretório servido. O azul/verde tira a causa da mesa — mas só
     * enquanto as etapas continuarem entrando por um `cd` na cópia alvo, e não
     * na raiz da instalação.
     */
    public function test_os_caches_sao_reconstruidos_dentro_da_copia_em_preparo(): void
    {
        $publicar = file_get_contents(base_path('deploy/publicar.sh'));

        foreach (['config:cache', 'route:cache', 'view:cache'] as $comando) {
            $this->assertMatchesRegularExpression(
                '/\(\s*cd "\$ALVO" && php artisan '.preg_quote($comando, '/').'/',
                $publicar,
                "O {$comando} precisa rodar dentro da cópia em preparo (\$ALVO)."
            );
        }
    }
}
