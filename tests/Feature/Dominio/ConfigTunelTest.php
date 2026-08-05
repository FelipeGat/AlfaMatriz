<?php

namespace Tests\Feature\Dominio;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ConfigTunelTest extends TestCase
{
    private const MODELO = 'deploy/cloudflared-alfamatriz.yml';

    /**
     * @spec:AC-024 O túnel entrega `matriz.alfasolucoes.cloud` no Nginx local
     * do container e recusa qualquer outro hostname — é o que faz o domínio da
     * empresa responder sem abrir porta no roteador.
     */
    public function test_tunel_mapeia_o_dominio_para_o_nginx_local(): void
    {
        $caminho = base_path(self::MODELO);
        $this->assertFileExists($caminho, 'O provisionamento depende deste modelo de túnel.');

        $config = Yaml::parseFile($caminho);

        $this->assertArrayHasKey('ingress', $config);
        $ingress = $config['ingress'];

        $this->assertSame(
            'matriz.alfasolucoes.cloud',
            $ingress[0]['hostname'] ?? null,
            'A primeira regra precisa ser o domínio do painel.'
        );
        $this->assertSame(
            'http://localhost:80',
            $ingress[0]['service'] ?? null,
            'O destino é o Nginx local do container, que atende em HTTP.'
        );

        // A última regra tem de ser o descarte: sem ela o cloudflared recusa a
        // configuração inteira e o túnel não sobe.
        $ultima = end($ingress);
        $this->assertSame('http_status:404', $ultima['service'] ?? null);
        $this->assertArrayNotHasKey('hostname', $ultima);

        // A credencial nunca pode ser versionada — só o modelo.
        $conteudo = file_get_contents($caminho);
        $this->assertStringContainsString('<UUID-DO-TUNEL>', $conteudo);
        $this->assertDoesNotMatchRegularExpression(
            '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/',
            $conteudo,
            'O modelo versionado não pode conter o identificador real do túnel.'
        );
    }
}
