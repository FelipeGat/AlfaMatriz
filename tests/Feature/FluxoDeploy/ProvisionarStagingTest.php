<?php

namespace Tests\Feature\FluxoDeploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProvisionarStagingTest extends TestCase
{
    private string $script;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = base_path('deploy/provisionar.sh');
    }

    /**
     * @spec:AC-034 O provisionamento sabe criar o ambiente de staging: outro
     * container, outro endereço e — o ponto importante — sem túnel Cloudflare
     * e sem porta pública. O staging vive só no tailnet.
     */
    public function test_staging_usa_outro_container_e_nao_abre_porta_publica(): void
    {
        $saida = $this->rodar('staging');

        $this->assertStringContainsString('LXC 116', $saida, 'O staging precisa de container próprio.');
        $this->assertStringContainsString('10.0.3.116', $saida);
        $this->assertStringContainsString('sem túnel Cloudflare', $saida);
        $this->assertStringNotContainsString(
            'instalando o túnel Cloudflare',
            $saida,
            'O staging não pode ganhar o túnel que publica o domínio da empresa.'
        );
    }

    /**
     * @spec:AC-034 Produção continua exatamente como estava: mesmo container,
     * mesmo endereço, com o túnel do domínio da empresa.
     */
    public function test_producao_permanece_no_container_e_no_tunel_de_sempre(): void
    {
        $saida = $this->rodar('producao');

        $this->assertStringContainsString('LXC 115', $saida);
        $this->assertStringContainsString('10.0.3.115', $saida);
        $this->assertStringContainsString('instalando o túnel Cloudflare', $saida);
    }

    /** @spec:AC-034 Ambiente desconhecido é recusado, em vez de virar produção por engano. */
    public function test_ambiente_invalido_e_recusado(): void
    {
        $processo = new Process(['bash', $this->script, '--local', '--ambiente', 'homologacao']);
        $processo->run();

        $this->assertNotSame(0, $processo->getExitCode());
        $this->assertStringContainsString('ambiente inválido', $processo->getErrorOutput());
    }

    /**
     * Roda o provisionamento com um Proxmox falso: interessa o CAMINHO que ele
     * escolhe por ambiente, não criar container de verdade.
     */
    private function rodar(string $ambiente): string
    {
        $bin = sys_get_temp_dir().'/prov-bin-'.bin2hex(random_bytes(6));
        mkdir($bin, 0755, true);

        foreach (['pct', 'ssh', 'scp', 'sleep', 'cp', 'grep'] as $ferramenta) {
            file_put_contents($bin.'/'.$ferramenta, "#!/usr/bin/env bash\nexit 0\n");
            chmod($bin.'/'.$ferramenta, 0755);
        }

        $processo = new Process(
            ['bash', $this->script, '--local', '--ambiente', $ambiente],
            base_path(),
            ['PATH' => $bin.':'.getenv('PATH')]
        );
        $processo->run();

        (new Process(['rm', '-rf', $bin]))->run();

        return $processo->getOutput().$processo->getErrorOutput();
    }
}
