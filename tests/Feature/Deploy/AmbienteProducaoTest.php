<?php

namespace Tests\Feature\Deploy;

use Tests\TestCase;

class AmbienteProducaoTest extends TestCase
{
    private const MODELO = 'deploy/.env.producao.exemplo';

    /**
     * @spec:AC-007 O modelo de ambiente usado no deploy já nasce endurecido:
     * sem modo de depuração, ambiente production, endereço em https e cookie
     * de sessão restrito a HTTPS — e sem nenhum segredo real dentro.
     */
    public function test_modelo_de_producao_nasce_endurecido_e_sem_segredo(): void
    {
        $caminho = base_path(self::MODELO);
        $this->assertFileExists($caminho, 'O deploy depende deste modelo de ambiente.');

        $valores = $this->lerVariaveis($caminho);

        $this->assertSame('production', $valores['APP_ENV'] ?? null);
        $this->assertSame('false', $valores['APP_DEBUG'] ?? null, 'Depuração ligada expõe stack trace com dado real.');
        $this->assertSame('true', $valores['SESSION_SECURE_COOKIE'] ?? null, 'O cookie de sessão não pode trafegar em claro.');
        $this->assertArrayHasKey('APP_URL', $valores);
        $this->assertStringStartsWith('https://', $valores['APP_URL']);
        $this->assertSame('warning', $valores['LOG_LEVEL'] ?? null, 'Log em debug guarda dado do negócio.');

        // Os segredos ficam de fora: o arquivo é versionado.
        foreach (['APP_KEY', 'DB_PASSWORD', 'ADMIN_PASSWORD'] as $segredo) {
            $this->assertArrayHasKey($segredo, $valores, "O modelo precisa listar {$segredo}.");
            $this->assertSame(
                'PREENCHER',
                $valores[$segredo],
                "{$segredo} tem valor real no modelo versionado — deve ficar como PREENCHER."
            );
        }

        // A senha de exemplo publicada no README não pode aparecer aqui.
        $this->assertStringNotContainsString('AlfaTecnologia@2026', file_get_contents($caminho));
    }

    /**
     * @spec:AC-075 O ambiente publicado nasce configurado para enviar e-mail
     * por um serviço real, não para engavetar no arquivo de log. Enquanto
     * estava apontado para o log, nenhum aviso do painel chegava a ninguém —
     * e nada acusava isso.
     */
    public function test_o_ambiente_publicado_nasce_configurado_para_enviar(): void
    {
        $valores = $this->lerVariaveis(base_path(self::MODELO));

        $this->assertSame(
            'smtp',
            $valores['MAIL_MAILER'] ?? null,
            'Apontado para o arquivo de log, o painel não manda e-mail para ninguém.'
        );

        foreach (['MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_FROM_ADDRESS'] as $obrigatorio) {
            $this->assertArrayHasKey($obrigatorio, $valores, "O modelo precisa listar {$obrigatorio}.");
            $this->assertNotSame('', $valores[$obrigatorio], "{$obrigatorio} não pode ficar vazio.");
        }

        $this->assertSame('smtp.gmail.com', $valores['MAIL_HOST'], 'O envio sai pelo Google Workspace.');
        $this->assertContains(
            $valores['MAIL_PORT'],
            ['587', '465'],
            'A porta precisa ser uma das que o Google aceita (587 com STARTTLS ou 465 com TLS direto).'
        );
    }

    /**
     * @spec:AC-076 Os segredos novos entram no modelo como espaço a preencher.
     * O arquivo é versionado: uma senha de aplicativo ou um token de provedor
     * vazando aqui vaza para sempre, em todo clone do repositório.
     */
    public function test_os_segredos_novos_entram_como_espaco_a_preencher(): void
    {
        $caminho = base_path(self::MODELO);
        $valores = $this->lerVariaveis($caminho);

        foreach (['MAIL_PASSWORD', 'HOSTINGER_API_TOKEN'] as $segredo) {
            $this->assertArrayHasKey($segredo, $valores, "O modelo precisa listar {$segredo}.");
            $this->assertSame(
                'PREENCHER',
                $valores[$segredo],
                "{$segredo} tem valor real no modelo versionado — deve ficar como PREENCHER."
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function lerVariaveis(string $caminho): array
    {
        $valores = [];

        foreach (file($caminho, FILE_IGNORE_NEW_LINES) as $linha) {
            $linha = trim($linha);

            if ($linha === '' || str_starts_with($linha, '#') || ! str_contains($linha, '=')) {
                continue;
            }

            [$chave, $valor] = explode('=', $linha, 2);
            $valores[trim($chave)] = trim($valor, " \t\"'");
        }

        return $valores;
    }
}
