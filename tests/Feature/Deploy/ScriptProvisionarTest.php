<?php

namespace Tests\Feature\Deploy;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ScriptProvisionarTest extends TestCase
{
    private string $script;

    private string $fakeBin;

    private string $estado;

    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->script = base_path('deploy/provisionar.sh');
        $this->fakeBin = $this->criarDiretorioTemporario('provisionar-bin-');
        $this->estado = $this->criarDiretorioTemporario('provisionar-estado-');
        $this->log = $this->estado.'/chamadas.log';

        $this->criarProxmoxFalso();
    }

    protected function tearDown(): void
    {
        $this->removerDiretorio($this->fakeBin);
        $this->removerDiretorio($this->estado);

        parent::tearDown();
    }

    /**
     * @spec:AC-008 Rodar o provisionamento uma segunda vez sobre um servidor
     * já provisionado termina com sucesso e não recria o container nem apaga
     * o banco — é o que permite consertar um ambiente torto sem perder dados.
     */
    public function test_provisionar_duas_vezes_nao_recria_container_nem_apaga_banco(): void
    {
        $primeira = $this->rodar();
        $this->assertSame(0, $primeira->getExitCode(), $primeira->getErrorOutput().$primeira->getOutput());

        $chamadasPrimeira = $this->lerChamadas();
        $this->assertNotEmpty(
            $this->filtrar($chamadasPrimeira, 'pct create'),
            'Na primeira execução o container precisa ser criado.'
        );

        // Segunda execução: o container agora "existe" (o Proxmox falso guarda estado).
        file_put_contents($this->log, '');
        $segunda = $this->rodar();

        $this->assertSame(
            0,
            $segunda->getExitCode(),
            "Rodar de novo precisa terminar com sucesso.\n".$segunda->getErrorOutput().$segunda->getOutput()
        );

        $chamadasSegunda = $this->lerChamadas();

        $this->assertEmpty(
            $this->filtrar($chamadasSegunda, 'pct create'),
            'O container já existe: recriar apagaria tudo.'
        );
        $this->assertStringContainsString('já existe', $segunda->getOutput());

        // Nada que destrua dados pode aparecer em nenhuma das execuções.
        foreach ([$chamadasPrimeira, $chamadasSegunda] as $chamadas) {
            $texto = implode("\n", $chamadas);
            foreach (['pct destroy', 'DROP DATABASE', 'DROP USER', 'rm -rf /var/www'] as $destrutivo) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $destrutivo,
                    $texto,
                    "O provisionamento nunca pode executar \"{$destrutivo}\"."
                );
            }
        }

        // O banco é criado sem destruir o que existe.
        $this->assertNotEmpty(
            $this->filtrar($chamadasSegunda, 'CREATE DATABASE IF NOT EXISTS'),
            'O banco precisa ser garantido de forma não destrutiva.'
        );
    }

    /**
     * @spec:AC-014 O provisionamento instala o cron do `schedule:run`. Sem ele
     * o `Schedule::` de routes/console.php nunca dispara: o retrato horário dos
     * sistemas integrados e o fechamento mensal de competência ficam parados
     * sem que nada acuse.
     */
    public function test_provisionamento_instala_o_cron_do_agendador(): void
    {
        $processo = $this->rodar();
        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        $agendador = $this->filtrar($this->lerChamadas(), 'schedule:run');

        $this->assertNotEmpty($agendador, 'O provisionamento precisa instalar o cron do schedule:run.');

        $comando = implode("\n", $agendador);
        $this->assertStringContainsString('* * * * *', $comando, 'O executor roda a cada minuto — quem decide a hora é o Laravel.');
        $this->assertStringContainsString('crontab -l', $comando, 'Instalar tem de preservar os crons que já existem (o do backup, por exemplo).');
        $this->assertStringContainsString('grep -q', $comando, 'Rodar o provisionamento de novo não pode duplicar a linha do cron.');
    }

    /**
     * @spec:AC-013 O provisionamento instala em /usr/local/bin os scripts que
     * o cron executa. Eram cópias manuais: uma etapa acrescentada ao vigia de
     * tags ficou publicada no repositório e ausente do arquivo que roda, sem
     * erro nenhum — o deploy passava e simplesmente não fazia o que o código
     * dizia.
     */
    public function test_provisionamento_instala_os_scripts_que_o_cron_executa(): void
    {
        $processo = $this->rodar();
        $this->assertSame(0, $processo->getExitCode(), $processo->getErrorOutput().$processo->getOutput());

        $chamadas = $this->lerChamadas();

        foreach ([
            'alfamatriz-backup.sh' => 'backup.sh',
            'deploy-tag-watcher-alfamatriz.sh' => 'deploy-tag-watcher-alfamatriz.sh',
        ] as $destino => $origem) {
            $instalacao = $this->filtrar($chamadas, $destino);

            $this->assertNotEmpty($instalacao, "O provisionamento precisa instalar {$destino}.");

            $comando = implode("\n", $instalacao);
            $this->assertStringContainsString(
                "deploy/{$origem}",
                $comando,
                "O {$destino} instalado tem de vir do repositório, não de uma cópia solta."
            );
        }
    }

    /**
     * @spec:AC-013 A instalação sobrescreve por renomeação, não por escrita
     * direta: o vigia roda a cada 5 minutos e o bash lê o script sob demanda,
     * então trocar o conteúdo embaixo de uma execução em curso a corromperia.
     */
    public function test_instalacao_dos_scripts_troca_o_arquivo_por_renomeacao(): void
    {
        $this->rodar();

        $instalacao = implode("\n", $this->filtrar($this->lerChamadas(), 'deploy-tag-watcher-alfamatriz.sh'));

        $this->assertStringContainsString('.novo', $instalacao, 'Escreve num temporário antes de assumir o lugar.');
        $this->assertStringContainsString('mv -f', $instalacao, 'A troca é por rename, que é atômico e preserva o inode em uso.');
    }

    /**
     * @spec:AC-013 O provisionamento instala o cron do vigia de tags. Sem ele
     * nenhuma versão nova chega à produção sozinha, e o `publicar.sh` vira
     * passo manual obrigatório.
     */
    public function test_provisionamento_instala_o_cron_do_vigia_de_tags(): void
    {
        $this->rodar();

        $vigia = $this->filtrar($this->lerChamadas(), 'deploy-tag-watcher-alfamatriz.sh >/dev/null');

        $this->assertNotEmpty($vigia, 'O provisionamento precisa agendar o vigia de tags.');

        $comando = implode("\n", $vigia);
        $this->assertStringContainsString('*/5 * * * *', $comando);
        $this->assertStringContainsString('grep -q', $comando, 'Provisionar de novo não pode duplicar a linha do cron.');
    }

    /** @spec:AC-008 O script é sintaticamente válido e para no primeiro erro. */
    public function test_script_tem_sintaxe_valida_e_para_no_primeiro_erro(): void
    {
        $sintaxe = new Process(['bash', '-n', $this->script]);
        $sintaxe->run();

        $this->assertSame(0, $sintaxe->getExitCode(), $sintaxe->getErrorOutput());
        $this->assertStringContainsString('set -euo pipefail', file_get_contents($this->script));
    }

    private function rodar(): Process
    {
        $processo = new Process(
            ['bash', $this->script, '--local'],
            base_path(),
            [
                'PATH' => $this->fakeBin.':'.getenv('PATH'),
                'ALFA_LOG' => $this->log,
                'ALFA_ESTADO' => $this->estado,
            ]
        );
        $processo->run();

        return $processo;
    }

    /**
     * Proxmox de mentira: registra cada chamada e lembra se o container já
     * foi criado, para a segunda execução enxergar um servidor provisionado.
     */
    private function criarProxmoxFalso(): void
    {
        $pct = <<<'BASH'
#!/usr/bin/env bash
echo "pct $*" >> "$ALFA_LOG"
case "$1" in
    config)
        [ -f "$ALFA_ESTADO/criado" ] && exit 0
        exit 1
        ;;
    create)
        touch "$ALFA_ESTADO/criado"
        exit 0
        ;;
    status)
        [ -f "$ALFA_ESTADO/rodando" ] && echo "status: running" || echo "status: stopped"
        exit 0
        ;;
    start)
        touch "$ALFA_ESTADO/rodando"
        exit 0
        ;;
    exec)
        shift 3
        echo "container: $*" >> "$ALFA_LOG"
        exit 0
        ;;
    push) exit 0 ;;
esac
exit 0
BASH;

        $this->criarBinario('pct', $pct);

        foreach (['ssh', 'scp', 'sleep'] as $ferramenta) {
            $this->criarBinario($ferramenta, "#!/usr/bin/env bash\necho \"{$ferramenta} \$*\" >> \"\$ALFA_LOG\"\nexit 0\n");
        }

        // O `grep` do script consulta /etc/pve — que não existe aqui.
        $this->criarBinario('grep', "#!/usr/bin/env bash\nexit 0\n");
        $this->criarBinario('cp', "#!/usr/bin/env bash\nexit 0\n");
    }

    private function criarBinario(string $nome, string $conteudo): void
    {
        $caminho = $this->fakeBin.'/'.$nome;
        file_put_contents($caminho, $conteudo);
        chmod($caminho, 0755);
    }

    /** @return array<int, string> */
    private function lerChamadas(): array
    {
        if (! is_file($this->log)) {
            return [];
        }

        return array_values(array_filter(explode("\n", file_get_contents($this->log))));
    }

    /**
     * @param  array<int, string>  $chamadas
     * @return array<int, string>
     */
    private function filtrar(array $chamadas, string $trecho): array
    {
        return array_values(array_filter(
            $chamadas,
            fn (string $linha) => stripos($linha, $trecho) !== false
        ));
    }

    private function criarDiretorioTemporario(string $prefixo): string
    {
        $caminho = sys_get_temp_dir().'/'.$prefixo.bin2hex(random_bytes(6));
        mkdir($caminho, 0755, true);

        return $caminho;
    }

    private function removerDiretorio(string $caminho): void
    {
        if (! is_dir($caminho)) {
            return;
        }

        foreach (scandir($caminho) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $alvo = $caminho.'/'.$item;
            is_dir($alvo) ? $this->removerDiretorio($alvo) : unlink($alvo);
        }

        rmdir($caminho);
    }
}
