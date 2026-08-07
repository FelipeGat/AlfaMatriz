<?php

namespace Tests\Feature\Infra;

use Symfony\Component\Process\Process;
use Tests\Support\RodaOProvisionamento;
use Tests\TestCase;

class ExecutorDeFilaTest extends TestCase
{
    use RodaOProvisionamento;

    private string $unidade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->unidade = base_path('deploy/alfamatriz-queue.service');
        $this->prepararProvisionamentoFalso();
    }

    protected function tearDown(): void
    {
        $this->limparProvisionamentoFalso();
        parent::tearDown();
    }

    /**
     * @spec:AC-073 Depois do provisionamento existe um serviço permanente
     * consumindo a fila. A fila já estava configurada, mas ninguém a consumia:
     * trabalho enfileirado ficaria parado para sempre.
     */
    public function test_o_servidor_ganha_um_servico_permanente_consumindo_a_fila(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString(
            '/etc/systemd/system/alfamatriz-queue.service',
            $log,
            'A unidade do executor precisa ser enviada para dentro do container.'
        );
        $this->assertStringContainsString('systemctl daemon-reload', $log);
        $this->assertStringContainsString('systemctl enable alfamatriz-queue', $log);
    }

    /**
     * @spec:AC-073 O executor sobe junto com o servidor e se reergue sozinho
     * ao cair — senão, uma queda no meio da madrugada deixa a fila parada até
     * alguém reparar.
     */
    public function test_o_executor_sobe_com_o_servidor_e_se_reergue_sozinho(): void
    {
        $unidade = file_get_contents($this->unidade);

        $this->assertStringContainsString('WantedBy=multi-user.target', $unidade, 'Precisa subir com o servidor.');
        $this->assertStringContainsString('Restart=always', $unidade, 'Precisa se reerguer ao cair.');
        $this->assertMatchesRegularExpression('/RestartSec=\d+/', $unidade);
    }

    /**
     * @spec:AC-073 O executor se recicla de tempos em tempos. Sem isso, um
     * processo que subiu antes de uma publicação continuaria rodando o código
     * velho indefinidamente.
     */
    public function test_o_executor_se_recicla_de_tempos_em_tempos(): void
    {
        $unidade = file_get_contents($this->unidade);

        $this->assertMatchesRegularExpression(
            '/--max-time=\d+/',
            $unidade,
            'O executor precisa ter um tempo de vida máximo, para não perpetuar código velho.'
        );
    }

    /**
     * @spec:AC-073 O executor roda com o mesmo usuário do servidor web. Se
     * rodasse como administrador, deixaria em storage/ arquivos que o painel
     * não conseguiria reabrir.
     */
    public function test_o_executor_roda_com_o_mesmo_usuario_do_painel(): void
    {
        $unidade = file_get_contents($this->unidade);

        $this->assertStringContainsString('User=www-data', $unidade);
        $this->assertStringContainsString('Group=www-data', $unidade);
        $this->assertStringNotContainsString('User=root', $unidade);
        $this->assertStringContainsString(
            'WorkingDirectory=/var/www/alfamatriz',
            $unidade,
            'O executor precisa rodar dentro do diretório do painel.'
        );
        $this->assertStringContainsString(
            '/usr/bin/php',
            $unidade,
            'O interpretador vai pelo caminho completo: o serviço não herda o caminho de busca de um login.'
        );
    }

    /**
     * @spec:AC-073 O destino do log do executor nasce pertencendo a quem
     * escreve nele, pelo mesmo motivo do log do agendamento.
     */
    public function test_o_log_do_executor_pertence_a_quem_escreve_nele(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString('touch /var/log/alfamatriz-queue.log', $log);
        $this->assertStringContainsString('chown www-data:www-data /var/log/alfamatriz-queue.log', $log);
    }

    /**
     * @spec:AC-073 Provisionar um container onde o painel ainda não foi
     * publicado não pode falhar por causa do executor: ele ainda não tem o que
     * executar. O script avisa e segue.
     */
    public function test_container_sem_painel_publicado_nao_derruba_o_provisionamento(): void
    {
        $saida = $this->provisionar();

        $this->assertStringContainsString('instalando o executor da fila', $saida);
        $this->assertStringContainsString(
            'deploy/publicar.sh',
            $this->logDoProvisionamento(),
            'O aviso precisa dizer o que fazer quando o executor não sobe.'
        );
    }

    /**
     * @spec:AC-073 A instalação do executor não exige nenhuma ferramenta nova
     * do host Proxmox: usa a mesma mecânica já usada pela configuração do
     * Nginx. É o que mantém o provisionamento rodando onde ele já rodava.
     */
    public function test_a_instalacao_nao_exige_ferramenta_nova_do_host(): void
    {
        $script = file_get_contents(base_path('deploy/provisionar.sh'));
        $trecho = substr($script, (int) strpos($script, 'instalando o executor da fila'));

        $this->assertStringContainsString('pct push', $trecho);
        foreach (['install ', 'envsubst', 'tee '] as $ferramentaNova) {
            $this->assertStringNotContainsString(
                $ferramentaNova,
                $trecho,
                "O provisionamento não pode passar a exigir \"{$ferramentaNova}\" do host."
            );
        }
    }

    /** @spec:AC-073 A unidade é um arquivo válido para o systemd. */
    public function test_a_unidade_declara_as_secoes_que_o_systemd_exige(): void
    {
        $unidade = file_get_contents($this->unidade);

        foreach (['[Unit]', '[Service]', '[Install]'] as $secao) {
            $this->assertStringContainsString($secao, $unidade);
        }

        $this->assertStringContainsString('Description=', $unidade);

        // A quebra de linha com barra invertida no ExecStart é aceita pelo
        // systemd, mas só se a continuação vier indentada e sem linha em
        // branco no meio — um erro aqui deixa o serviço sem subir.
        $sintaxe = new Process(['bash', '-c', 'grep -c "^ExecStart=" '.escapeshellarg($this->unidade)]);
        $sintaxe->run();
        $this->assertSame('1', trim($sintaxe->getOutput()), 'A unidade precisa ter exatamente um ExecStart.');
    }
}
