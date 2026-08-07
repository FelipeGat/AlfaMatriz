<?php

namespace Tests\Feature\Infra;

use Tests\Support\RodaOProvisionamento;
use Tests\TestCase;

class PermissoesDoAplicativoTest extends TestCase
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
     * @spec:AC-072 A configuração fica legível para o usuário do aplicativo,
     * que é quem executa as rotinas de fundo. Enquanto era exclusiva do
     * administrador, elas só funcionavam por causa do cache de configuração e
     * morriam na primeira limpeza.
     */
    public function test_a_configuracao_fica_legivel_para_o_usuario_do_aplicativo(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString('chown root:www-data /var/www/alfamatriz/.env', $log);
        $this->assertStringContainsString('chmod 640 /var/www/alfamatriz/.env', $log);
    }

    /**
     * @spec:AC-072 Legível para o aplicativo não é legível para todo mundo: o
     * dono continua sendo o administrador e nenhum outro usuário do sistema
     * ganha leitura de um arquivo que guarda senha de banco e chave da aplicação.
     */
    public function test_a_configuracao_nao_fica_legivel_para_o_resto_do_sistema(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        foreach (['chmod 644 /var/www/alfamatriz/.env', 'chmod 664 /var/www/alfamatriz/.env', 'chmod 777'] as $aberto) {
            $this->assertStringNotContainsString(
                $aberto,
                $log,
                "A configuração nunca pode receber \"{$aberto}\" — ela guarda a senha do banco."
            );
        }

        $this->assertStringNotContainsString(
            'chown www-data:www-data /var/www/alfamatriz/.env',
            $log,
            'O dono da configuração continua sendo o administrador; o aplicativo entra pelo grupo.'
        );
    }

    /**
     * @spec:AC-072 As pastas de trabalho e de cache pertencem ao usuário do
     * aplicativo. Se as rotinas de fundo rodassem como administrador, elas
     * deixariam ali arquivos que o servidor web não consegue reabrir depois.
     */
    public function test_as_pastas_de_trabalho_pertencem_ao_usuario_do_aplicativo(): void
    {
        $this->provisionar();
        $log = $this->logDoProvisionamento();

        $this->assertStringContainsString(
            'chown -R www-data:www-data /var/www/alfamatriz/storage /var/www/alfamatriz/bootstrap/cache',
            $log
        );
    }

    /**
     * @spec:AC-072 Provisionar um servidor que ainda não tem configuração não
     * pode quebrar: o script avisa e segue, em vez de derrubar um
     * provisionamento que no resto está correto.
     */
    public function test_servidor_sem_configuracao_ainda_nao_criada_nao_quebra_o_provisionamento(): void
    {
        $saida = $this->provisionar();

        $this->assertStringContainsString(
            'garantindo que o painel lê a configuração',
            $saida,
            'A etapa precisa aparecer no roteiro.'
        );
        $this->assertStringContainsString(
            'if [ -f /var/www/alfamatriz/.env ]',
            $this->logDoProvisionamento(),
            'A permissão só é aplicada quando o arquivo existe.'
        );
        $this->assertStringContainsString(
            'ainda não existe',
            $this->logDoProvisionamento(),
            'Sem o arquivo, o script avisa em vez de derrubar o provisionamento.'
        );
    }

    /**
     * @spec:AC-072 Provisionar de novo aplica as mesmas permissões, sem
     * quebrar nada — é o que permite usar o script para consertar um servidor
     * cujo dono de arquivo saiu do lugar.
     */
    public function test_provisionar_de_novo_reaplica_as_mesmas_permissoes(): void
    {
        $this->provisionar();
        $primeira = $this->logDoProvisionamento();

        $this->esquecerChamadas();
        $this->provisionar();
        $segunda = $this->logDoProvisionamento();

        foreach (['chmod 640 /var/www/alfamatriz/.env', 'chown -R www-data:www-data /var/www/alfamatriz/storage'] as $comando) {
            $this->assertSame(
                substr_count($primeira, $comando),
                substr_count($segunda, $comando),
                "A segunda execução precisa aplicar \"{$comando}\" igual à primeira."
            );
        }
    }
}
