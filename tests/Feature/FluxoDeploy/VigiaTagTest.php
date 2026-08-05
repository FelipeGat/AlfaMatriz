<?php

namespace Tests\Feature\FluxoDeploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Exercita o vigia de tag de verdade, num repositório descartável com
 * binários falsos — inclusive o `curl` do health-check.
 */
class VigiaTagTest extends TestCase
{
    private string $repo;

    private string $bin;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->tmp('vigia-repo-');
        $this->bin = $this->tmp('vigia-bin-');
        $this->log = $this->repo.'/chamadas.log';

        $this->montarRepositorio();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->repo);
        $this->apagar($this->bin);

        parent::tearDown();
    }

    /**
     * @spec:AC-036 Havendo tag nova, o vigia aplica ela em produção e registra
     * a versão. Sem tag nova, ele não faz nada — alteração na main sozinha
     * nunca chega ao faturamento.
     */
    public function test_aplica_a_tag_nova_e_ignora_alteracao_sem_tag(): void
    {
        $this->criarFerramentas(saude: '200');

        $processo = $this->rodar();
        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        $chamadas = $this->chamadas();
        $this->assertStringContainsString('git checkout', $chamadas);
        $this->assertStringContainsString('php artisan migrate --force', $chamadas);
        $this->assertSame('v1.0.0', trim(file_get_contents($this->repo.'/.deploy-tag-state')));

        // Rodar de novo, sem tag nova, não pode aplicar coisa alguma.
        file_put_contents($this->log, '');
        $segunda = $this->rodar();

        $this->assertSame(0, $segunda->getExitCode());
        $this->assertStringContainsString('UPTODATE', $segunda->getOutput());
        $this->assertStringNotContainsString('php artisan migrate', $this->chamadas());
    }

    /**
     * @spec:AC-037 O banco é copiado ANTES das migrações, e a ordem importa:
     * uma migração ruim em cima de faturamento real precisa ter volta.
     */
    public function test_faz_backup_antes_de_migrar(): void
    {
        $this->criarFerramentas(saude: '200');

        $this->rodar();

        $chamadas = $this->chamadas();
        $posBackup = strpos($chamadas, 'BACKUP');
        $posMigrate = strpos($chamadas, 'php artisan migrate');

        $this->assertNotFalse($posBackup, 'O backup precisa ser chamado.');
        $this->assertNotFalse($posMigrate);
        $this->assertLessThan($posMigrate, $posBackup, 'O backup tem de vir ANTES da migração.');
    }

    /**
     * @spec:AC-037 Saúde ruim depois de aplicar: o vigia marca a falha e para.
     * Na execução seguinte ele não tenta de novo — insistir em cima de um
     * sistema quebrado só piora.
     */
    public function test_saude_ruim_marca_falha_e_nao_tenta_de_novo(): void
    {
        $this->criarFerramentas(saude: '500');

        $processo = $this->rodar();

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertFileExists($this->repo.'/.deploy-tag-failed');
        $this->assertFileDoesNotExist($this->repo.'/.deploy-tag-state', 'Versão quebrada não pode ser registrada como aplicada.');

        $estado = json_decode(file_get_contents($this->repo.'/public/deploy-status.json'), true);
        $this->assertSame('falha', $estado['estado'] ?? null, 'O painel precisa enxergar a falha.');

        // Segunda execução: bloqueada pelo marcador.
        file_put_contents($this->log, '');
        $segunda = $this->rodar();

        $this->assertSame(0, $segunda->getExitCode());
        $this->assertStringContainsString('BLOQUEADO', $segunda->getOutput());
        $this->assertStringNotContainsString('php artisan migrate', $this->chamadas());
    }

    // ------------------------------------------------------------- apoio

    private function rodar(): Process
    {
        $processo = new Process(
            ['bash', base_path('deploy/deploy-tag-watcher-alfamatriz.sh'), '--dir', $this->repo],
            $this->repo,
            [
                'PATH' => $this->bin.':'.getenv('PATH'),
                'ALFA_LOG' => $this->log,
                'LOG' => $this->repo.'/deploy-tag.log',
                'HEALTH_URL' => 'https://exemplo.invalido/healthz',
                'HOME' => $this->repo,
            ]
        );
        $processo->run();

        return $processo;
    }

    private function montarRepositorio(): void
    {
        $this->executar(['git', 'init', '--quiet', $this->repo]);
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $this->repo);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $this->repo);

        mkdir($this->repo.'/deploy', 0755, true);
        mkdir($this->repo.'/public', 0755, true);

        // backup.sh de mentira: registra que foi chamado, para o teste poder
        // conferir a ORDEM entre backup e migração.
        file_put_contents(
            $this->repo.'/deploy/backup.sh',
            "#!/usr/bin/env bash\necho BACKUP >> \"\$ALFA_LOG\"\nexit 0\n"
        );

        file_put_contents($this->repo.'/versao.txt', 'v1');
        $this->executar(['git', 'add', '-A'], $this->repo);
        $this->executar(['git', 'commit', '--quiet', '-m', 'inicial'], $this->repo);
        $this->executar(['git', 'tag', 'v1.0.0'], $this->repo);
    }

    private function criarFerramentas(string $saude): void
    {
        // `git` real, menos o fetch (não há remoto neste repositório de teste).
        $this->binario('git', <<<'BASH'
#!/usr/bin/env bash
echo "git $*" >> "$ALFA_LOG"
if [ "$1" = "fetch" ]; then exit 0; fi
exec /usr/bin/git "$@"
BASH);

        $this->binario('curl', "#!/usr/bin/env bash\necho \"curl \$*\" >> \"\$ALFA_LOG\"\nprintf '{$saude}'\nexit 0\n");

        foreach (['composer', 'npm', 'php', 'systemctl'] as $ferramenta) {
            $this->binario($ferramenta, "#!/usr/bin/env bash\necho \"{$ferramenta} \$*\" >> \"\$ALFA_LOG\"\nexit 0\n");
        }
    }

    private function binario(string $nome, string $conteudo): void
    {
        $caminho = $this->bin.'/'.$nome;
        file_put_contents($caminho, $conteudo);
        chmod($caminho, 0755);
    }

    private function chamadas(): string
    {
        return is_file($this->log) ? file_get_contents($this->log) : '';
    }

    /** @param array<int, string> $comando */
    private function executar(array $comando, ?string $cwd = null): void
    {
        $processo = new Process($comando, $cwd);
        $processo->run();

        $this->assertSame(0, $processo->getExitCode(), implode(' ', $comando).': '.$processo->getErrorOutput());
    }

    private function tmp(string $prefixo): string
    {
        $caminho = sys_get_temp_dir().'/'.$prefixo.bin2hex(random_bytes(6));
        mkdir($caminho, 0755, true);

        return $caminho;
    }

    private function apagar(string $caminho): void
    {
        if (is_dir($caminho)) {
            (new Process(['rm', '-rf', $caminho]))->run();
        }
    }
}
