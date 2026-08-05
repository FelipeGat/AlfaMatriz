<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptBackupTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/backup-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);
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
     * @spec:AC-011 A cópia diária mantém as sete mais recentes e apaga as
     * mais antigas — o teste cria dez cópias com datas diferentes e confere
     * quais sobreviveram.
     */
    public function test_retencao_mantem_as_sete_copias_mais_recentes(): void
    {
        // Dez cópias, da mais antiga para a mais nova.
        $todas = [];
        for ($dia = 1; $dia <= 10; $dia++) {
            $nome = sprintf('%s/alfamatriz-2026-07-%02d.sql.gz', $this->dir, $dia);
            file_put_contents($nome, "dump falso do dia {$dia}");
            // ls -1t ordena por data de modificação: escalona para não empatar.
            touch($nome, time() - ((10 - $dia) * 3600));
            $todas[$dia] = $nome;
        }

        $processo = new Process(['bash', base_path('deploy/backup.sh'), '--dir', $this->dir, '--sem-dump']);
        $processo->run();

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        $restantes = glob($this->dir.'/alfamatriz-*.sql.gz') ?: [];
        $this->assertCount(7, $restantes, 'Devem sobrar exatamente sete cópias.');

        // As sete mais recentes são os dias 4 a 10.
        for ($dia = 4; $dia <= 10; $dia++) {
            $this->assertFileExists($todas[$dia], "A cópia do dia {$dia} é recente e deveria ter ficado.");
        }

        for ($dia = 1; $dia <= 3; $dia++) {
            $this->assertFileDoesNotExist($todas[$dia], "A cópia do dia {$dia} passou de sete dias e deveria ter saído.");
        }
    }

    /** @spec:AC-011 Com menos de sete cópias, nenhuma é apagada. */
    public function test_com_poucas_copias_nada_e_apagado(): void
    {
        for ($dia = 1; $dia <= 3; $dia++) {
            file_put_contents(sprintf('%s/alfamatriz-2026-07-%02d.sql.gz', $this->dir, $dia), 'dump');
        }

        $processo = new Process(['bash', base_path('deploy/backup.sh'), '--dir', $this->dir, '--sem-dump']);
        $processo->run();

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        $this->assertCount(3, glob($this->dir.'/alfamatriz-*.sql.gz') ?: []);
    }
}
