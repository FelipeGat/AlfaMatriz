<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * De onde o `publicar-changelog.sh` tira as tarefas da versão (#252). Ele
 * publica o changelog de todos os sistemas, e a tag é do repositório do
 * sistema: o AlfaControl v2026.10.05 entrou na aba Atualizações sem tarefas
 * porque o script procurava a tag no git do AlfaMatriz. O `curl` é falso —
 * nada sai da máquina; só o registro (`--so-registrar`) é exercitado.
 */
class ScriptPublicarChangelogTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/changelog-teste-'.bin2hex(random_bytes(4));
        mkdir($this->dir.'/bin', 0777, true);

        file_put_contents($this->dir.'/bin/curl', <<<'SH'
#!/usr/bin/env bash
printf '%s\n' "$@" > "$DIR_TESTE/curl-args"
printf '{"message":"registrada"}\n201'
SH);
        chmod($this->dir.'/bin/curl', 0755);

        $this->changelog('alfacontrol.txt', 'AlfaControl');
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();

        parent::tearDown();
    }

    private function changelog(string $nome, string $sistema): string
    {
        file_put_contents($this->dir.'/'.$nome, "<b>📋 {$sistema} — Changelog 05/10/2026</b>\n\n• Novidade.\n");

        return $this->dir.'/'.$nome;
    }

    private function rodar(array $argumentos): Process
    {
        $processo = new Process(
            array_merge(['bash', dirname(__DIR__, 3).'/deploy/publicar-changelog.sh', '--so-registrar'], $argumentos),
            $this->dir,
            [
                'PATH' => $this->dir.'/bin:'.getenv('PATH'),
                'DIR_TESTE' => $this->dir,
                'ALFAMATRIZ_CHANGELOG_TOKEN' => '1|token-do-teste',
                'ALFAMATRIZ_URL' => 'https://alfamatriz.exemplo',
            ],
        );
        $processo->run();

        return $processo;
    }

    /** O que o curl falso mandou no campo `tarefas`. */
    private function tarefasEnviadas(): string
    {
        foreach (file($this->dir.'/curl-args', FILE_IGNORE_NEW_LINES) as $argumento) {
            if (str_starts_with($argumento, 'tarefas=')) {
                return trim(substr($argumento, strlen('tarefas=')));
            }
        }

        $this->fail('O registro não mandou o campo tarefas.');
    }

    private function git(string $repo, string ...$argumentos): void
    {
        $processo = new Process(array_merge(['git', '-C', $repo, '-c', 'user.name=Teste', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', '-c', 'tag.gpgsign=false'], $argumentos));
        $processo->mustRun();
    }

    public function test_tarefas_explicitas_vencem_e_aceitam_qualquer_grafia(): void
    {
        $processo = $this->rodar(['--versao=v2026.10.05', '--tarefas=T-248, #226 226', $this->dir.'/alfacontrol.txt']);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        $this->assertSame('226 248', $this->tarefasEnviadas());
    }

    public function test_repo_le_a_tag_e_os_commits_do_sistema_certo(): void
    {
        $repo = $this->dir.'/alfacontrol';
        mkdir($repo);
        $this->git($repo, 'init', '-q');
        $this->git($repo, 'commit', '-q', '--allow-empty', '-m', 'Antes (T-10)');
        $this->git($repo, 'tag', 'v2026.10.04');
        $this->git($repo, 'commit', '-q', '--allow-empty', '-m', 'Vigia (T-226)');
        $this->git($repo, 'commit', '-q', '--allow-empty', '-m', 'Outra coisa t-248, sem #999');
        $this->git($repo, 'tag', 'v2026.10.05');

        $processo = $this->rodar(['--versao=v2026.10.05', '--repo='.$repo, $this->dir.'/alfacontrol.txt']);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        $this->assertSame('226 248', $this->tarefasEnviadas());
    }

    /**
     * Sem --repo nem --tarefas, o git do AlfaMatriz não serve para outro
     * sistema: uma tag de mesmo nome daria as tarefas erradas.
     */
    public function test_changelog_de_outro_sistema_sem_repo_registra_sem_tarefas_e_diz_por_que(): void
    {
        $processo = $this->rodar(['--versao=v2026.10.05', $this->dir.'/alfacontrol.txt']);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        $this->assertSame('', $this->tarefasEnviadas());
        $this->assertStringContainsString('o changelog é de alfacontrol', $processo->getErrorOutput());
        $this->assertStringContainsString('--repo=', $processo->getErrorOutput());
    }

    public function test_repo_que_nao_e_git_e_recusado_antes_de_registrar(): void
    {
        $processo = $this->rodar(['--versao=v1', '--repo='.$this->dir.'/bin', $this->dir.'/alfacontrol.txt']);

        $this->assertSame(2, $processo->getExitCode());
        $this->assertFileDoesNotExist($this->dir.'/curl-args');
    }
}
