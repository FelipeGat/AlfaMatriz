<?php

namespace Tests\Feature\Seguranca;

use Tests\TestCase;

/**
 * O `deploy/nginx-alfamatriz.conf` é a única barreira dos anexos de tarefa, de
 * cobrança e de conta a pagar — a suíte nunca abriu esse arquivo antes. Uma
 * edição de configuração que reabra `/storage/` ou troque o `^~` por um prefixo
 * comum reabriria a leitura direta e a execução de PHP no disco de upload sem
 * derrubar nenhum teste de aplicação, porque nenhum deles passa pelo nginx.
 */
class DiscoDeAnexosFechadoTest extends TestCase
{
    private const CAMINHO_CONF = 'deploy/nginx-alfamatriz.conf';

    /**
     * @spec:AC-271 `/storage/` está fechado com `deny all`, e só
     * `/storage/marcas/` é servido. Afirma o `^~` nas duas locations: é o que
     * vence a regex do `.php` por precedência do nginx — trocá-lo por
     * `/storage/` puro reabriria a execução de PHP no disco de upload sem
     * mudar nada visível na tela.
     */
    public function test_storage_continua_fechado_e_so_marcas_e_servido(): void
    {
        $caminho = base_path(self::CAMINHO_CONF);
        $this->assertFileExists($caminho, 'deploy/nginx-alfamatriz.conf sumiu — é a única barreira dos anexos.');

        $conf = file_get_contents($caminho);

        $this->assertMatchesRegularExpression(
            '/location\s+\^~\s+\/storage\/marcas\/\s*\{[^}]*try_files/s',
            $conf,
            'A exceção de /storage/marcas/ precisa usar `^~` — sem isso a regex do .php pode vencer por ordem.'
        );

        $this->assertMatchesRegularExpression(
            '/location\s+\^~\s+\/storage\/\s*\{[^}]*deny\s+all;/s',
            $conf,
            '/storage/ precisa continuar fechado com `deny all` sob `^~` — é a única proteção dos anexos hoje.'
        );

        $this->assertDoesNotMatchRegularExpression(
            '/location\s+\/storage\/\s*\{/',
            $conf,
            'location /storage/ sem `^~` perde a precedência sobre a regex do .php e reabre a execução de PHP no disco de upload.'
        );
    }
}
