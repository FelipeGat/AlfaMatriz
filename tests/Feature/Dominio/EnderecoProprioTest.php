<?php

namespace Tests\Feature\Dominio;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnderecoProprioTest extends TestCase
{
    use RefreshDatabase;

    private const DOMINIO = 'https://matriz.alfasolucoes.cloud';

    protected function tearDown(): void
    {
        putenv('APP_URL');
        unset($_ENV['APP_URL'], $_SERVER['APP_URL']);

        parent::tearDown();
    }

    /**
     * @spec:AC-025 O ambiente publicado aponta para o domínio da empresa, e os
     * links e redirecionamentos que o painel gera saem nesse endereço — não no
     * endereço técnico do Tailscale.
     */
    public function test_ambiente_e_links_usam_o_dominio_da_empresa(): void
    {
        $modelo = base_path('deploy/.env.producao.exemplo');
        $this->assertFileExists($modelo);

        $conteudo = file_get_contents($modelo);

        $this->assertStringContainsString(
            'APP_URL='.self::DOMINIO,
            $conteudo,
            'O modelo de produção precisa apontar para o domínio da empresa.'
        );
        $this->assertStringNotContainsString(
            'APP_URL=https://alfamatriz.tail0939dd.ts.net',
            $conteudo,
            'O endereço antigo do Tailscale não pode continuar como APP_URL.'
        );

        // O endereço do painel sai do ambiente, não de algo fixo no código —
        // é isso que faz o modelo acima valer em produção.
        $this->assertStringContainsString(
            "'url' => env('APP_URL'",
            file_get_contents(config_path('app.php')),
            'O endereço da aplicação precisa vir de APP_URL.'
        );

        // Os artefatos que definem para onde o deploy aponta não podem mais
        // conter o endereço do Tailscale.
        foreach (['deploy/smoke.sh', 'deploy/.env.producao.exemplo'] as $artefato) {
            $this->assertStringNotContainsString(
                'alfamatriz.tail0939dd.ts.net',
                file_get_contents(base_path($artefato)),
                "{$artefato} ainda aponta para o endereço antigo do Tailscale."
            );
        }

        // O README pode (e deve) citar o endereço do Tailscale como acesso de
        // emergência interno — o que ele não pode é anunciá-lo como a URL
        // pública do painel.
        $readme = file_get_contents(base_path('README.md'));
        $this->assertStringContainsString(
            'URL pública: **'.self::DOMINIO.'**',
            $readme,
            'O README precisa anunciar o domínio da empresa como endereço público.'
        );
        $this->assertStringNotContainsString(
            'URL pública (Tailscale Funnel)',
            $readme,
            'O README não pode mais apresentar o endereço do Tailscale como público.'
        );
    }
}
