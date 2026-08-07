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
