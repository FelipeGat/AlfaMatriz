<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptBackupTest extends TestCase
{
    private string $dir;

    private string $anexos;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/backup-'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0755, true);

        $this->anexos = sys_get_temp_dir().'/anexos-'.bin2hex(random_bytes(6));
        mkdir($this->anexos, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->apagarPasta($this->dir);
        $this->apagarPasta($this->anexos);

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

        $processo = $this->rodar(['--dir', $this->dir, '--sem-dump']);

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

        $processo = $this->rodar(['--dir', $this->dir, '--sem-dump']);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        $this->assertCount(3, glob($this->dir.'/alfamatriz-*.sql.gz') ?: []);
    }

    /**
     * @spec:AC-011 A cópia leva os anexos junto com o banco. O dump guarda só
     * o caminho do arquivo: sem esta metade, restaurar devolve a linha da
     * cobrança e não o PDF.
     */
    public function test_a_copia_leva_os_anexos_junto(): void
    {
        mkdir($this->anexos.'/cobrancas', 0755, true);
        file_put_contents($this->anexos.'/cobrancas/nota.pdf', 'conteúdo do PDF');
        // Arquivo oculto: a pasta de anexos tem um .gitignore, e ele precisa
        // entrar na cópia como qualquer outro.
        file_put_contents($this->anexos.'/.gitignore', "*\n");

        $processo = $this->rodar(['--dir', $this->dir, '--sem-dump', '--anexos', $this->anexos]);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        $copia = sprintf('%s/alfamatriz-anexos-%s.tar.gz', $this->dir, date('Y-m-d'));
        $this->assertFileExists($copia, 'A cópia dos anexos deveria ter sido gerada.');

        $listagem = new Process(['tar', '-tzf', $copia]);
        $listagem->run();
        $this->assertSame(0, $listagem->getExitCode(), $listagem->getErrorOutput());

        $this->assertStringContainsString('cobrancas/nota.pdf', $listagem->getOutput());
        $this->assertStringContainsString('.gitignore', $listagem->getOutput());
    }

    /**
     * @spec:AC-011 A retenção dos anexos é contada à parte, para que cada dump
     * do banco encontre os anexos da mesma data. Contadas juntas, a série mais
     * curta sumiria antes da outra.
     */
    public function test_retencao_conta_banco_e_anexos_em_series_separadas(): void
    {
        // Sete cópias do banco e sete dos anexos: catorze arquivos ao todo,
        // sete de cada régua. Nenhuma pode sair.
        for ($dia = 1; $dia <= 7; $dia++) {
            file_put_contents(sprintf('%s/alfamatriz-2026-07-%02d.sql.gz', $this->dir, $dia), 'dump');
            file_put_contents(sprintf('%s/alfamatriz-anexos-2026-07-%02d.tar.gz', $this->dir, $dia), 'tar');
        }

        $processo = $this->rodar(['--dir', $this->dir, '--sem-dump']);

        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput());
        $this->assertCount(7, glob($this->dir.'/alfamatriz-*.sql.gz') ?: [], 'As sete cópias do banco continuam.');
        $this->assertCount(7, glob($this->dir.'/alfamatriz-anexos-*.tar.gz') ?: [], 'As sete cópias dos anexos continuam.');
    }

    /**
     * @spec:AC-011 Pasta de anexos ausente avisa, mas não derruba a rodada: o
     * dump do banco já está pronto neste ponto, e perdê-lo por causa dos
     * anexos seria trocar a proteção maior pela menor.
     */
    public function test_pasta_de_anexos_ausente_avisa_sem_derrubar_a_rodada(): void
    {
        $processo = $this->rodar([
            '--dir', $this->dir,
            '--sem-dump',
            '--anexos', $this->anexos.'/nao-existe',
        ]);

        $this->assertSame(0, $processo->getExitCode(), 'A rodada não pode falhar por causa da pasta ausente.');
        $this->assertStringContainsString('AVISO', $processo->getOutput());
        $this->assertStringContainsString('NÃO foram copiados', $processo->getOutput());
        $this->assertCount(0, glob($this->dir.'/alfamatriz-anexos-*.tar.gz') ?: []);
    }

    /**
     * @param  array<int, string>  $argumentos
     */
    private function rodar(array $argumentos): Process
    {
        $processo = new Process(array_merge(['bash', base_path('deploy/backup.sh')], $argumentos));
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
