<?php

namespace Tests\Feature\Infra;

use Tests\Support\RodaOProvisionamento;
use Tests\TestCase;

class AgendamentoTest extends TestCase
{
    use RodaOProvisionamento;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararProvisionamentoFalso();
    }

    protected function tearDown(): void
    {
        $this->limparProvisionamentoFalso();
        parent::tearDown();
    }

    /**
     * @spec:AC-071 Depois do provisionamento o servidor executa sozinho as
     * rotinas do painel, a cada minuto. Sem isso nada do que está agendado
     * acontece — foi o estado do servidor até esta entrega.
     */
    public function test_o_servidor_passa_a_executar_as_rotinas_a_cada_minuto(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString(
            '/etc/cron.d/alfamatriz-schedule',
            $log,
            'O agendamento precisa ser instalado no servidor.'
        );
        $this->assertStringContainsString(
            '* * * * * www-data cd /var/www/alfamatriz && /usr/bin/php artisan schedule:run',
            $log,
            'A rotina precisa rodar a cada minuto, no diretório do painel.'
        );
    }

    /**
     * @spec:AC-071 O caminho de busca de programas vai declarado por extenso e
     * o interpretador é chamado pelo caminho completo: o cron roda com um
     * caminho mínimo, e `schedule:run` dispara subprocessos. Sem isso a rotina
     * falha em silêncio a cada minuto — foi o que aconteceu no AlfaDeploy.
     */
    public function test_o_agendamento_declara_o_caminho_de_busca_e_o_interpretador(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertMatchesRegularExpression(
            '#PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin#',
            $log,
            'O caminho de busca precisa estar declarado por extenso no arquivo do cron.'
        );

        // A linha PATH= tem de vir ANTES da linha da tarefa, senão não vale.
        $posicaoDoPath = strpos($log, 'PATH=/usr/local/sbin');
        $posicaoDaTarefa = strpos($log, '* * * * * www-data');
        $this->assertNotFalse($posicaoDoPath);
        $this->assertNotFalse($posicaoDaTarefa);
        $this->assertLessThan(
            $posicaoDaTarefa,
            $posicaoDoPath,
            'O caminho de busca precisa ser declarado antes da tarefa.'
        );

        $this->assertStringNotContainsString(
            '* * * * * www-data cd /var/www/alfamatriz && php artisan',
            $log,
            'O interpretador precisa ser chamado pelo caminho completo, nunca pelo nome solto.'
        );
    }

    /**
     * @spec:AC-071 O arquivo do cron nasce com o modo que o próprio cron exige
     * (0644) e com nome sem ponto — com qualquer um dos dois errado, o cron
     * ignora o arquivo sem reclamar, e ninguém descobre.
     */
    public function test_o_arquivo_do_agendamento_respeita_o_que_o_cron_exige(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString('chmod 0644 /etc/cron.d/alfamatriz-schedule', $log);
        $this->assertStringNotContainsString(
            '/etc/cron.d/alfamatriz.schedule',
            $log,
            'Nome com ponto é ignorado pelo cron.'
        );
        $this->assertStringContainsString(
            'systemctl restart cron',
            $log,
            'O cron precisa reler a configuração para o agendamento valer.'
        );
    }

    /**
     * @spec:AC-071 O destino do log do agendamento nasce pertencendo a quem
     * executa a rotina. Quem roda é o usuário do aplicativo, e ele não
     * consegue criar arquivo em /var/log — sem isso, todo redirecionamento
     * falharia e a rotina morreria antes de começar.
     */
    public function test_o_log_do_agendamento_pertence_a_quem_executa_a_rotina(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString('touch /var/log/alfamatriz-schedule.log', $log);
        $this->assertStringContainsString(
            'chown www-data:www-data /var/log/alfamatriz-schedule.log',
            $log
        );
    }

    /**
     * @spec:AC-071 Provisionar de novo não duplica nem quebra o agendamento:
     * o arquivo é sobrescrito, não acrescentado. É o que permite usar o script
     * para consertar um servidor torto.
     */
    public function test_provisionar_de_novo_nao_duplica_o_agendamento(): void
    {
        $this->provisionar();
        $primeira = $this->logDoProvisionamento();

        $this->esquecerChamadas();
        $this->provisionar();
        $segunda = $this->logDoProvisionamento();

        $tarefa = '* * * * * www-data cd /var/www/alfamatriz';

        $this->assertStringContainsString('/etc/cron.d/alfamatriz-schedule', $segunda);
        $this->assertSame(
            substr_count($primeira, $tarefa),
            substr_count($segunda, $tarefa),
            'A segunda execução precisa instalar exatamente o mesmo agendamento da primeira.'
        );

        // A instalação é uma sobrescrita de arquivo, não um acréscimo à tabela
        // do usuário: é isso que torna a idempotência estrutural, e não uma
        // consequência de o script conferir antes de escrever.
        $trechoDoAgendamento = substr($segunda, (int) strpos($segunda, 'alfamatriz-schedule'));
        $this->assertStringContainsString('cat > /etc/cron.d/alfamatriz-schedule', $trechoDoAgendamento);
        $this->assertStringNotContainsString('crontab -', $trechoDoAgendamento);
    }

    /**
     * @spec:AC-071 O staging também executa as rotinas: é lá que o
     * comportamento é conferido antes de a produção mudar.
     */
    public function test_o_staging_tambem_executa_as_rotinas(): void
    {
        $this->provisionar('staging');

        $this->assertStringContainsString(
            '/etc/cron.d/alfamatriz-schedule',
            $this->logDoProvisionamento()
        );
    }
}
