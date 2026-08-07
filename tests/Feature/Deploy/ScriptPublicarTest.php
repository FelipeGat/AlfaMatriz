<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptPublicarTest extends TestCase
{
    private string $fakeBin;

    private string $appDir;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeBin = $this->criarDiretorioTemporario('publicar-bin-');
        $this->appDir = $this->criarDiretorioTemporario('publicar-app-');
        $this->log = tempnam(sys_get_temp_dir(), 'publicar-log-');
        unlink($this->log);

        foreach (['git', 'composer', 'npm', 'php', 'sudo'] as $ferramenta) {
            $this->criarFerramentaFalsa($ferramenta);
        }
    }

    protected function tearDown(): void
    {
        $this->removerDiretorio($this->fakeBin);
        $this->removerDiretorio($this->appDir);
        if (is_file($this->log)) {
            unlink($this->log);
        }

        parent::tearDown();
    }

    /**
     * @spec:AC-009 Rodar o script de publicação, com tudo saudável, executa as
     * cinco etapas (buscar código, instalar dependências de produção,
     * compilar o front-end, aplicar migrações e recarregar os caches) em
     * ordem e termina com sucesso.
     */
    public function test_publicar_executa_todas_as_etapas_em_ordem_e_sai_com_sucesso(): void
    {
        $processo = $this->rodarScript();

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        $chamadas = $this->lerChamadas();

        $indiceGit = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'git pull');
        $indiceComposer = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'composer install');
        $indiceNpmCi = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'npm ci');
        $indiceNpmBuild = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'npm run build');
        $indiceMigrate = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'php artisan migrate --force');
        $indiceConfigCache = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'php artisan config:cache');
        $indiceRouteCache = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'php artisan route:cache');
        $indiceViewCache = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'php artisan view:cache');
        $indiceReload = $this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'sudo systemctl reload');

        foreach ([
            'git pull' => $indiceGit,
            'composer install' => $indiceComposer,
            'npm ci' => $indiceNpmCi,
            'npm run build' => $indiceNpmBuild,
            'php artisan migrate --force' => $indiceMigrate,
            'php artisan config:cache' => $indiceConfigCache,
            'php artisan route:cache' => $indiceRouteCache,
            'php artisan view:cache' => $indiceViewCache,
            'sudo systemctl reload' => $indiceReload,
        ] as $etapa => $indice) {
            $this->assertNotNull($indice, "etapa esperada não rodou: {$etapa}");
        }

        $this->assertTrue($indiceGit < $indiceComposer);
        $this->assertTrue($indiceComposer < $indiceNpmCi);
        $this->assertTrue($indiceNpmCi < $indiceNpmBuild);
        $this->assertTrue($indiceNpmBuild < $indiceMigrate);
        $this->assertTrue($indiceMigrate < $indiceConfigCache);
        $this->assertTrue($indiceConfigCache < $indiceRouteCache);
        $this->assertTrue($indiceRouteCache < $indiceViewCache);
        $this->assertTrue($indiceViewCache < $indiceReload);
    }

    /**
     * @spec:AC-009 Se uma etapa falhar (aqui, a instalação de dependências
     * PHP), o script para imediatamente, avisa qual etapa falhou e não chega
     * a rodar as etapas seguintes (front-end, migrações, caches).
     */
    public function test_publicar_para_na_primeira_etapa_que_falhar_com_mensagem_clara(): void
    {
        $processo = $this->rodarScript(['FALHAR_EM' => 'composer']);

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertStringContainsString('composer', $processo->getErrorOutput());

        $chamadas = $this->lerChamadas();

        $this->assertNotNull($this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'git pull'));
        $this->assertNotNull($this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'composer install'));

        $this->assertNull($this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'npm'));
        $this->assertNull($this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'php artisan migrate'));
        $this->assertNull($this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'php artisan config:cache'));
        $this->assertNull($this->indiceDaPrimeiraChamadaComPrefixo($chamadas, 'sudo systemctl reload'));
    }

    private function rodarScript(array $envExtra = []): Process
    {
        $raiz = dirname(__DIR__, 3);
        $script = $raiz.'/deploy/publicar.sh';

        $processo = new Process(
            [$script, '--dir', $this->appDir],
            null,
            array_merge([
                'PATH' => $this->fakeBin.':'.getenv('PATH'),
                'LOG_CHAMADAS' => $this->log,
            ], $envExtra)
        );
        $processo->run();

        return $processo;
    }

    private function criarFerramentaFalsa(string $nome): void
    {
        $conteudo = <<<'SH'
#!/usr/bin/env bash
echo "%s $*" >> "$LOG_CHAMADAS"
if [[ "${FALHAR_EM:-}" == "%s" ]]; then
    echo "%s: falha simulada para teste" >&2
    exit 1
fi
exit 0
SH;

        $caminho = $this->fakeBin.'/'.$nome;
        file_put_contents($caminho, sprintf($conteudo, $nome, $nome, $nome));
        chmod($caminho, 0755);
    }

    private function lerChamadas(): array
    {
        if (! is_file($this->log)) {
            return [];
        }

        return array_values(array_filter(explode("\n", file_get_contents($this->log))));
    }

    private function indiceDaPrimeiraChamadaComPrefixo(array $chamadas, string $prefixo): ?int
    {
        foreach ($chamadas as $indice => $chamada) {
            if (str_starts_with($chamada, $prefixo)) {
                return $indice;
            }
        }

        return null;
    }

    private function criarDiretorioTemporario(string $prefixo): string
    {
        $caminho = sys_get_temp_dir().'/'.$prefixo.uniqid();
        mkdir($caminho, 0755, true);

        return $caminho;
    }

    private function removerDiretorio(string $caminho): void
    {
        if (! is_dir($caminho)) {
            return;
        }

        $itens = scandir($caminho);
        foreach ($itens as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemCaminho = $caminho.'/'.$item;
            if (is_dir($itemCaminho)) {
                $this->removerDiretorio($itemCaminho);
            } else {
                unlink($itemCaminho);
            }
        }

        rmdir($caminho);
    }
}
