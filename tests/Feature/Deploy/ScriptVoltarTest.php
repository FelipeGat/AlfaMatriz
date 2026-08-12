<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptVoltarTest extends TestCase
{
    private string $instalacao;

    private string $bin;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instalacao = $this->tmp('voltar-app-');
        $this->bin = $this->tmp('voltar-bin-');
        $this->log = $this->instalacao.'/chamadas.log';

        $this->montarInstalacao();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->instalacao);
        $this->apagar($this->bin);

        parent::tearDown();
    }

    /**
     * @spec:AC-172 Voltar é trocar um symlink: a versão anterior continua
     * inteira no disco, com dependências instaladas e caches quentes. Por isso
     * a volta leva ~1 segundo, e não os ~2 minutos de reconstruir tudo.
     */
    public function test_voltar_troca_as_duas_copias_de_lugar(): void
    {
        $noAr = $this->paraOndeAponta('atual');
        $reserva = $this->paraOndeAponta('preparo');

        $processo = $this->voltar();

        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());
        $this->assertSame($reserva, $this->paraOndeAponta('atual'));
        $this->assertSame($noAr, $this->paraOndeAponta('preparo'));

        // Nada foi reconstruído — é esse o ponto do azul/verde.
        $this->assertStringNotContainsString('composer', $this->chamadas());
        $this->assertStringNotContainsString('npm', $this->chamadas());
    }

    /**
     * @spec:AC-171 Depois de voltar, o vigia precisa ficar BLOQUEADO. A tag da
     * qual se acabou de voltar continua sendo a mais recente do repositório:
     * sem o marcador, em cinco minutos ela seria aplicada de novo e o sistema
     * voltaria a quebrar sozinho — com quem voltou jurando ter resolvido.
     */
    public function test_voltar_bloqueia_a_esteira_e_avisa_o_painel(): void
    {
        $this->voltar();

        $this->assertFileExists($this->instalacao.'/.deploy-tag-failed');

        $status = json_decode(file_get_contents($this->instalacao.'/deploy-status.json'), true);
        $this->assertSame('falha', $status['estado'] ?? null, 'O painel precisa enxergar a falha.');
    }

    /**
     * @spec:AC-172 Cópia de reserva sem dependências instaladas não é versão
     * anterior: é uma pasta vazia. Voltar para ela trocaria uma falha por um
     * site fora do ar.
     */
    public function test_voltar_recusa_reserva_inutilizavel(): void
    {
        (new Process(['rm', '-rf', $this->instalacao.'/versoes/verde/vendor']))->run();

        $processo = $this->voltar();

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertStringContainsString('não tem dependências instaladas', $processo->getErrorOutput());
        $this->assertSame(
            $this->instalacao.'/versoes/azul',
            $this->paraOndeAponta('atual'),
            'Recusando, nada pode ter mudado.'
        );
    }

    /**
     * @spec:AC-172 Instalação que ainda não usa azul/verde recusa a volta com
     * uma mensagem que diz isso — e não com um erro de symlink ausente.
     */
    public function test_instalacao_antiga_recusa_com_mensagem_clara(): void
    {
        unlink($this->instalacao.'/atual');

        $processo = $this->voltar();

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertStringContainsString('ainda não usa azul/verde', $processo->getErrorOutput());
    }

    // ------------------------------------------------------------- apoio

    private function voltar(array $envExtra = []): Process
    {
        $processo = new Process(
            ['bash', base_path('deploy/voltar.sh'), '--dir', $this->instalacao, '--sim'],
            $this->instalacao,
            array_merge([
                'PATH' => $this->bin.':'.getenv('PATH'),
                'ALFA_LOG' => $this->log,
                'HOME' => $this->instalacao,
                'TENTATIVAS_SAUDE' => '2',
                'ESPERA_SAUDE' => '0',
            ], $envExtra)
        );
        $processo->run();

        return $processo;
    }

    /** Uma instalação já em azul/verde, com as duas cópias utilizáveis. */
    private function montarInstalacao(): void
    {
        $this->executar(['git', 'init', '--quiet', $this->instalacao]);
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $this->instalacao);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $this->instalacao);

        file_put_contents($this->instalacao.'/versao.txt', "v1\n");
        $this->executar(['git', 'add', '-A'], $this->instalacao);
        $this->executar(['git', 'commit', '--quiet', '-m', 'inicial'], $this->instalacao);
        $this->executar(['git', 'tag', 'v1.0.0'], $this->instalacao);

        foreach (['azul', 'verde'] as $cor) {
            $this->executar(
                ['git', 'worktree', 'add', '--detach', $this->instalacao.'/versoes/'.$cor, 'HEAD'],
                $this->instalacao
            );
            mkdir($this->instalacao.'/versoes/'.$cor.'/vendor', 0755, true);
        }

        symlink($this->instalacao.'/versoes/azul', $this->instalacao.'/atual');
        symlink($this->instalacao.'/versoes/verde', $this->instalacao.'/preparo');

        foreach (['sudo', 'systemctl', 'composer', 'npm', 'php'] as $ferramenta) {
            $caminho = $this->bin.'/'.$ferramenta;
            file_put_contents($caminho, "#!/usr/bin/env bash\necho \"{$ferramenta} \$*\" >> \"\$ALFA_LOG\"\nexit 0\n");
            chmod($caminho, 0755);
        }
    }

    private function chamadas(): string
    {
        return is_file($this->log) ? file_get_contents($this->log) : '';
    }

    private function paraOndeAponta(string $link): string
    {
        return (string) readlink($this->instalacao.'/'.$link);
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

        return realpath($caminho) ?: $caminho;
    }

    private function apagar(string $caminho): void
    {
        if (is_dir($caminho)) {
            (new Process(['rm', '-rf', $caminho]))->run();
        }
    }
}
