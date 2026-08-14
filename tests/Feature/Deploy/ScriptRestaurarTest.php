<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptRestaurarTest extends TestCase
{
    private string $dir;

    private string $copia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/restaurar-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);

        $this->copia = $this->dir.'/alfamatriz-2026-08-05.sql.gz';
        file_put_contents($this->copia, 'conteúdo de uma cópia válida');
    }

    protected function tearDown(): void
    {
        $this->apagarPasta($this->dir);

        parent::tearDown();
    }

    /**
     * @spec:AC-012 Chamar a restauração apontando um arquivo que não existe,
     * ou sem confirmar explicitamente, é recusado com erro e sem encostar no
     * banco de produção.
     */
    public function test_restaurar_recusa_sem_confirmacao_ou_com_arquivo_invalido(): void
    {
        // Arquivo existe, mas falta --confirmo.
        $semConfirmar = $this->rodar(['--arquivo', $this->copia]);
        $this->assertNotSame(0, $semConfirmar->getExitCode(), 'Sem --confirmo a restauração não pode rodar.');
        $this->assertStringContainsString('--confirmo', $semConfirmar->getErrorOutput());
        $this->assertStringContainsString('nada foi alterado', $semConfirmar->getErrorOutput());

        // Confirmou, mas o arquivo não existe.
        $inexistente = $this->rodar(['--arquivo', $this->dir.'/nao-existe.sql.gz', '--confirmo']);
        $this->assertNotSame(0, $inexistente->getExitCode(), 'Arquivo inexistente precisa ser recusado.');
        $this->assertStringContainsString('não existe', $inexistente->getErrorOutput());

        // Confirmou, mas nem passou arquivo.
        $semArquivo = $this->rodar(['--confirmo']);
        $this->assertNotSame(0, $semArquivo->getExitCode());
        $this->assertStringContainsString('--arquivo', $semArquivo->getErrorOutput());

        // Arquivo vazio também não vale como cópia.
        $vazio = $this->dir.'/vazio.sql.gz';
        touch($vazio);
        $copiaVazia = $this->rodar(['--arquivo', $vazio, '--confirmo']);
        $this->assertNotSame(0, $copiaVazia->getExitCode());
        $this->assertStringContainsString('vazio', $copiaVazia->getErrorOutput());

        // Em nenhum dos casos o script pode ter chamado o banco.
        foreach ([$semConfirmar, $inexistente, $semArquivo, $copiaVazia] as $processo) {
            $this->assertStringNotContainsString('restaurando', $processo->getOutput());
        }
    }

    /**
     * @spec:AC-012 A cópia dos anexos passa pela mesma régua da do banco:
     * arquivo que não existe, arquivo vazio e falta de --confirmo são
     * recusados antes de qualquer coisa ser escrita no destino.
     */
    public function test_restaurar_anexos_recusa_pelas_mesmas_razoes(): void
    {
        $destino = $this->dir.'/destino';
        $tar = $this->criarTarDeAnexos(['nota.pdf' => 'PDF de teste']);

        $semConfirmar = $this->rodar(['--anexos', $tar, '--destino', $destino]);
        $this->assertNotSame(0, $semConfirmar->getExitCode(), 'Sem --confirmo os anexos não podem voltar.');
        $this->assertStringContainsString('--confirmo', $semConfirmar->getErrorOutput());

        $inexistente = $this->rodar(['--anexos', $this->dir.'/nao-existe.tar.gz', '--destino', $destino, '--confirmo']);
        $this->assertNotSame(0, $inexistente->getExitCode());
        $this->assertStringContainsString('não existe', $inexistente->getErrorOutput());

        $vazio = $this->dir.'/vazio.tar.gz';
        touch($vazio);
        $copiaVazia = $this->rodar(['--anexos', $vazio, '--destino', $destino, '--confirmo']);
        $this->assertNotSame(0, $copiaVazia->getExitCode());
        $this->assertStringContainsString('vazio', $copiaVazia->getErrorOutput());

        // Recusado é recusado: o destino não pode nem ter sido criado.
        $this->assertDirectoryDoesNotExist($destino);
    }

    /**
     * @spec:AC-012 Restaurar anexos devolve o que se perdeu sem apagar o que
     * chegou depois da cópia — quem recupera um arquivo apagado por engano não
     * pode perder junto os anexos enviados desde então.
     */
    public function test_restaurar_anexos_devolve_o_perdido_sem_apagar_o_novo(): void
    {
        $destino = $this->dir.'/destino';
        mkdir($destino.'/cobrancas', 0755, true);
        // Chegou depois da cópia: não está no tar e precisa sobreviver.
        file_put_contents($destino.'/cobrancas/recente.pdf', 'enviado hoje');

        $tar = $this->criarTarDeAnexos(['cobrancas/antiga.pdf' => 'estava na cópia']);

        $processo = $this->rodar(['--anexos', $tar, '--destino', $destino, '--confirmo']);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        $this->assertFileExists($destino.'/cobrancas/antiga.pdf', 'O anexo da cópia deveria ter voltado.');
        $this->assertSame('estava na cópia', file_get_contents($destino.'/cobrancas/antiga.pdf'));

        $this->assertFileExists($destino.'/cobrancas/recente.pdf', 'O anexo enviado depois da cópia não pode ser apagado.');
        $this->assertSame('enviado hoje', file_get_contents($destino.'/cobrancas/recente.pdf'));

        // Sem --arquivo, o banco não pode ter sido tocado.
        $this->assertStringNotContainsString('restaurando '.$this->copia, $processo->getOutput());
    }

    /**
     * Monta um .tar.gz com os caminhos relativos à raiz dos anexos, do mesmo
     * jeito que o backup.sh grava (`tar -C "$ANEXOS" .`).
     *
     * @param  array<string, string>  $arquivos  caminho relativo => conteúdo
     */
    private function criarTarDeAnexos(array $arquivos): string
    {
        $origem = $this->dir.'/origem-'.bin2hex(random_bytes(4));
        mkdir($origem, 0755, true);

        foreach ($arquivos as $caminho => $conteudo) {
            $completo = $origem.'/'.$caminho;
            if (! is_dir(dirname($completo))) {
                mkdir(dirname($completo), 0755, true);
            }
            file_put_contents($completo, $conteudo);
        }

        $tar = $this->dir.'/alfamatriz-anexos-2026-08-05-'.bin2hex(random_bytes(4)).'.tar.gz';

        $processo = new Process(['tar', '-czf', $tar, '-C', $origem, '.']);
        $processo->mustRun();

        return $tar;
    }

    /**
     * @param  array<int, string>  $argumentos
     */
    private function rodar(array $argumentos): Process
    {
        $processo = new Process(array_merge(['bash', base_path('deploy/restaurar.sh')], $argumentos));
        $processo->run();

        return $processo;
    }

    private function apagarPasta(string $caminho): void
    {
        if (! is_dir($caminho)) {
            return;
        }

        foreach (glob($caminho.'/{,.}*', GLOB_BRACE) ?: [] as $item) {
            if (basename($item) === '.' || basename($item) === '..') {
                continue;
            }

            is_dir($item) ? $this->apagarPasta($item) : unlink($item);
        }

        rmdir($caminho);
    }
}
