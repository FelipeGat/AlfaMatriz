<?php

namespace Tests\Feature\Infra;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class TesteDeEmailTest extends TestCase
{
    /**
     * A suíte usa o transporte "array" (phpunit.xml), então a mensagem fica
     * retida em memória e dá para conferir o que iria para o fio.
     *
     * Mail::fake() NÃO serve aqui: o `raw()` do dublê do framework é um método
     * vazio, e um teste montado sobre ele passaria sem provar nada.
     *
     * @return \Illuminate\Support\Collection<int, \Symfony\Component\Mailer\SentMessage>
     */
    private function mensagensEnviadas(): Collection
    {
        return Mail::mailer()->getSymfonyTransport()->messages();
    }

    /** Configura um meio que reporta dados de SMTP mas retém a mensagem. */
    private function comMeio(string $meio, array $dados = []): void
    {
        config([
            'mail.default' => $meio,
            'mail.mailers.'.$meio => array_merge(['transport' => 'array'], $dados),
        ]);
    }

    /**
     * @spec:AC-077 O comando informa por qual meio, servidor e remetente o
     * envio será feito ANTES de tentar. É o que faz um envio apontado para o
     * lugar errado aparecer na hora, em vez de sumir junto com o e-mail.
     */
    public function test_o_comando_mostra_por_onde_o_envio_vai_sair_antes_de_tentar(): void
    {
        $this->comMeio('smtp', [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'username' => 'painel@alfatecnologia.com.br',
        ]);
        config(['mail.from.address' => 'painel@alfatecnologia.com.br']);

        $this->artisan('app:testar-email', ['destino' => 'rossini@alfatecnologia.com.br'])
            ->expectsOutputToContain('smtp.gmail.com')
            ->expectsOutputToContain('587')
            ->expectsOutputToContain('painel@alfatecnologia.com.br')
            ->expectsOutputToContain('rossini@alfatecnologia.com.br')
            ->assertSuccessful();
    }

    /**
     * @spec:AC-077 A senha nunca é impressa — o comando roda num terminal que
     * costuma ficar em histórico. Ele diz apenas se ela está definida.
     */
    public function test_o_comando_nunca_imprime_a_senha(): void
    {
        $this->comMeio('smtp', ['password' => 'senha-de-aplicativo-do-google']);
        config(['mail.from.address' => 'painel@alfatecnologia.com.br']);

        $this->artisan('app:testar-email')
            ->doesntExpectOutputToContain('senha-de-aplicativo-do-google')
            ->expectsOutputToContain('definida')
            ->assertSuccessful();
    }

    /**
     * @spec:AC-077 Envio engavetado no arquivo de log é avisado com todas as
     * letras. Era o estado do ambiente publicado, e ninguém percebia porque o
     * comando terminaria com sucesso do mesmo jeito.
     */
    public function test_o_comando_avisa_quando_o_envio_esta_apontado_para_o_log(): void
    {
        $this->comMeio('log');
        config(['mail.from.address' => 'painel@alfatecnologia.com.br']);

        $this->artisan('app:testar-email')
            ->expectsOutputToContain('nada sai para a internet')
            ->assertSuccessful();
    }

    /**
     * @spec:AC-077 O comando envia de verdade para o destino informado — não
     * apenas relata a configuração.
     */
    public function test_o_comando_envia_a_mensagem_para_o_destino_informado(): void
    {
        config(['mail.from.address' => 'painel@alfatecnologia.com.br']);

        $this->artisan('app:testar-email', ['destino' => 'rossini@alfatecnologia.com.br'])
            ->assertSuccessful();

        $mensagens = $this->mensagensEnviadas();
        $this->assertCount(1, $mensagens, 'O comando precisa enviar exatamente uma mensagem.');

        $enviada = $mensagens->first()->getOriginalMessage();
        $this->assertSame('rossini@alfatecnologia.com.br', $enviada->getTo()[0]->getAddress());
        $this->assertStringContainsString('AlfaMatriz', $enviada->getSubject());
    }

    /**
     * @spec:AC-077 Sem destino informado, o comando manda para o próprio
     * remetente configurado — é o teste que se quer fazer logo depois de
     * publicar, sem precisar lembrar de nenhum endereço.
     */
    public function test_sem_destino_o_comando_manda_para_o_proprio_remetente(): void
    {
        config(['mail.from.address' => 'painel@alfatecnologia.com.br']);

        $this->artisan('app:testar-email')->assertSuccessful();

        $enviada = $this->mensagensEnviadas()->first()->getOriginalMessage();
        $this->assertSame('painel@alfatecnologia.com.br', $enviada->getTo()[0]->getAddress());
    }

    /**
     * @spec:AC-077 Se o envio não completar, o comando falha com a mensagem do
     * erro e sugere a saída alternativa — um erro silencioso aqui devolveria o
     * painel ao estado em que ninguém sabia que o e-mail não chegava.
     */
    public function test_envio_que_nao_completa_falha_com_a_mensagem_do_erro(): void
    {
        config(['mail.from.address' => 'painel@alfatecnologia.com.br']);

        Mail::shouldReceive('raw')
            ->once()
            ->andThrow(new TransportException('Connection could not be established with host "smtp.gmail.com"'));

        $this->artisan('app:testar-email')
            ->expectsOutputToContain('O envio falhou')
            ->expectsOutputToContain('smtp.gmail.com')
            ->expectsOutputToContain('465')
            ->assertFailed();
    }

    /**
     * @spec:AC-077 Sem remetente configurado e sem destino informado, o comando
     * recusa em vez de tentar enviar para lugar nenhum.
     */
    public function test_sem_remetente_e_sem_destino_o_comando_recusa(): void
    {
        config(['mail.from.address' => null]);

        $this->artisan('app:testar-email')
            ->expectsOutputToContain('Não há para quem enviar')
            ->assertFailed();

        $this->assertTrue($this->mensagensEnviadas()->isEmpty(), 'Nada pode ser enviado quando não há destino.');
    }
}
