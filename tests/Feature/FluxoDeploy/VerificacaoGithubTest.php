<?php

namespace Tests\Feature\FluxoDeploy;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class VerificacaoGithubTest extends TestCase
{
    private const WORKFLOW = '.github/workflows/testes.yml';

    /**
     * @spec:AC-035 A verificação automática roda a suíte na main e nas tags de
     * versão — é o sinal que os outros sistemas da casa têm antes de alguém
     * marcar uma versão para produção.
     */
    public function test_verificacao_roda_a_suite_na_main_e_nas_tags(): void
    {
        $caminho = base_path(self::WORKFLOW);
        $this->assertFileExists($caminho);

        $workflow = Yaml::parseFile($caminho);

        $this->assertSame('Testes', $workflow['name'] ?? null);

        // `on` em YAML vira booleano true sem aspas — daí a busca pelas duas chaves.
        $gatilhos = $workflow['on'] ?? $workflow[true] ?? [];

        $this->assertContains('main', $gatilhos['push']['branches'] ?? [], 'Precisa rodar a cada alteração na main.');
        $this->assertContains('v*', $gatilhos['push']['tags'] ?? [], 'Precisa rodar na tag que vai para produção.');

        $passos = $workflow['jobs']['testes']['steps'] ?? [];
        $comandos = implode("\n", array_map(
            fn (array $passo) => ($passo['run'] ?? '').' '.($passo['uses'] ?? ''),
            $passos
        ));

        $this->assertStringContainsString('php artisan test', $comandos, 'A verificação existe para rodar a suíte.');
        $this->assertStringContainsString('composer install', $comandos);

        // Sem compilar o front-end, os testes de tela quebram com
        // ViteManifestNotFoundException — foi o que aconteceu localmente.
        $this->assertStringContainsString('npm run build', $comandos);
    }
}
