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
        foreach (glob($this->dir.'/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        if (is_dir($this->dir)) {
            rmdir($this->dir);
        }

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
        $this->assertStringContainsString('NÃO foi alterado', $semConfirmar->getErrorOutput());

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
     * @param  array<int, string>  $argumentos
     */
    private function rodar(array $argumentos): Process
    {
        $processo = new Process(array_merge(['bash', base_path('deploy/restaurar.sh')], $argumentos));
        $processo->run();

        return $processo;
    }
}
