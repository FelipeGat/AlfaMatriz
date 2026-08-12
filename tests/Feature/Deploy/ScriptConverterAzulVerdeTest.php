<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * A conversão roda uma vez sobre um servidor com dados reais em cima. Este
 * teste monta uma instalação no formato antigo — com segredo, anexo de cliente
 * e dependências instaladas — e confere que nada disso se perde no caminho.
 */
class ScriptConverterAzulVerdeTest extends TestCase
{
    private string $instalacao;

    private string $bin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->instalacao = $this->tmp('converter-app-');
        $this->bin = $this->tmp('converter-bin-');

        $this->montarInstalacaoAntiga();
    }

    protected function tearDown(): void
    {
        $this->apagar($this->instalacao);
        $this->apagar($this->bin);

        parent::tearDown();
    }

    /**
     * @spec:AC-174 A conversão preserva o segredo, os anexos e a versão que
     * estava no ar, e deixa a instalação servindo pela cópia azul.
     */
    public function test_conversao_preserva_segredo_anexos_e_versao(): void
    {
        $processo = $this->converter();
        $this->assertSame(0, $processo->getExitCode(), $processo->getOutput().$processo->getErrorOutput());

        // O segredo passa a ser da instalação: uma cópia só, apontada por todo
        // mundo. A raiz continua tendo um `.env` legível — há scripts que leem
        // o APP_ENV de lá antes de qualquer outra coisa.
        $this->assertFileExists($this->instalacao.'/compartilhado/.env');
        $this->assertStringContainsString('APP_ENV=production', file_get_contents($this->instalacao.'/compartilhado/.env'));
        $this->assertTrue(is_link($this->instalacao.'/.env'));

        // O anexo de cliente sai de dentro da versão.
        $this->assertFileExists($this->instalacao.'/compartilhado/anexos/boleto.pdf');
        $this->assertSame('conteudo do anexo', file_get_contents($this->instalacao.'/compartilhado/anexos/boleto.pdf'));

        // E o app passa a gravar lá.
        $this->assertStringContainsString(
            'FILESYSTEM_PUBLIC_ROOT='.$this->instalacao.'/compartilhado/anexos',
            file_get_contents($this->instalacao.'/compartilhado/.env')
        );

        // As duas cópias existem, no commit que estava publicado, e a azul
        // recebeu o que já estava pronto — reinstalar poderia publicar algo
        // diferente do que estava no ar.
        $this->assertDirectoryExists($this->instalacao.'/versoes/azul');
        $this->assertDirectoryExists($this->instalacao.'/versoes/verde');
        $this->assertDirectoryExists($this->instalacao.'/versoes/azul/vendor');
        $this->assertDirectoryExists($this->instalacao.'/versoes/azul/node_modules');
        $this->assertFileExists($this->instalacao.'/versoes/azul/public/build/app.js');

        $this->assertSame($this->instalacao.'/versoes/azul', readlink($this->instalacao.'/atual'));
        $this->assertSame($this->instalacao.'/versoes/verde', readlink($this->instalacao.'/preparo'));
    }

    /**
     * @spec:AC-174 Rodar a conversão de novo não faz nada. É o que permite ao
     * provisionamento chamá-la sempre, sem condição por fora — o mesmo
     * critério que já vale para o resto do provisionar.sh.
     */
    public function test_converter_de_novo_nao_faz_nada(): void
    {
        $this->converter();

        $antes = filemtime($this->instalacao.'/compartilhado/.env');

        $segunda = $this->converter();

        $this->assertSame(0, $segunda->getExitCode());
        $this->assertStringContainsString('nada a fazer', $segunda->getOutput());
        $this->assertSame($antes, filemtime($this->instalacao.'/compartilhado/.env'));
        $this->assertSame(
            'FILESYSTEM_PUBLIC_ROOT',
            $this->primeiraOcorrenciaDe('FILESYSTEM_PUBLIC_ROOT', $this->instalacao.'/compartilhado/.env'),
            'A linha do disco de anexos não pode ser acrescentada duas vezes.'
        );
    }

    /**
     * @spec:AC-174 A instalação antiga continua inteira: as dependências são
     * COPIADAS, não movidas. Interromper a conversão no meio não pode deixar o
     * servidor sem o que ele estava servindo até então.
     */
    public function test_a_instalacao_antiga_nao_e_desmontada(): void
    {
        $this->converter();

        $this->assertDirectoryExists($this->instalacao.'/vendor');
        $this->assertDirectoryExists($this->instalacao.'/node_modules');
        $this->assertFileExists($this->instalacao.'/storage/app/public/boleto.pdf');
    }

    // ------------------------------------------------------------- apoio

    private function converter(): Process
    {
        $processo = new Process(
            ['bash', base_path('deploy/converter-para-azul-verde.sh'), '--dir', $this->instalacao],
            $this->instalacao,
            [
                'PATH' => $this->bin.':'.getenv('PATH'),
                'HOME' => $this->instalacao,
            ]
        );
        $processo->run();

        return $processo;
    }

    private function montarInstalacaoAntiga(): void
    {
        $this->executar(['git', 'init', '--quiet', $this->instalacao]);
        $this->executar(['git', 'config', 'user.email', 'teste@exemplo.com'], $this->instalacao);
        $this->executar(['git', 'config', 'user.name', 'Teste'], $this->instalacao);

        mkdir($this->instalacao.'/public', 0755, true);
        file_put_contents($this->instalacao.'/versao.txt', "v1\n");

        $this->executar(['git', 'add', '-A'], $this->instalacao);
        $this->executar(['git', 'commit', '--quiet', '-m', 'inicial'], $this->instalacao);

        // O que uma instalação no formato antigo tem em cima: segredo, anexo
        // de cliente, dependências e front-end compilado.
        file_put_contents($this->instalacao.'/.env', "APP_ENV=production\nAPP_KEY=base64:xxx\n");

        mkdir($this->instalacao.'/storage/app/public', 0755, true);
        file_put_contents($this->instalacao.'/storage/app/public/boleto.pdf', 'conteudo do anexo');

        mkdir($this->instalacao.'/vendor', 0755, true);
        file_put_contents($this->instalacao.'/vendor/autoload.php', '<?php');
        mkdir($this->instalacao.'/node_modules', 0755, true);
        mkdir($this->instalacao.'/public/build', 0755, true);
        file_put_contents($this->instalacao.'/public/build/app.js', '// build');

        $php = $this->bin.'/php';
        file_put_contents($php, "#!/usr/bin/env bash\nexit 0\n");
        chmod($php, 0755);
    }

    private function primeiraOcorrenciaDe(string $chave, string $arquivo): string
    {
        $vezes = substr_count(file_get_contents($arquivo), $chave);

        return $vezes === 1 ? $chave : "{$chave} × {$vezes}";
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
