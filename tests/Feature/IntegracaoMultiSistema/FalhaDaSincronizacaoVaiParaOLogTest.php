<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Sistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * O motivo da falha de cada sistema fica no log, e não só na tela.
 *
 * O agendador guarda apenas o código de saída do comando: a queda de 17/10
 * (T-397), um 522 do AlfaControl e do AlfaJornada, deixou no log só um
 * "exit code 1", e a causa teve de ser reconstruída por fora.
 *
 * A primeira hora fora vai como WARNING, e só a queda que dura vira ERROR: o
 * vigia de logs transforma ERROR em Bug, e um 522 que passou sozinho (T-497)
 * abria tarefa sem nada a consertar.
 */
class FalhaDaSincronizacaoVaiParaOLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_primeira_falha_vai_para_o_log_como_aviso(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/*' => Http::response(['contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => []]),
            '*control.alfasolucoes.cloud/*' => Http::response('', 522),
        ]);

        Log::spy();

        $this->artisan('alfa:sincronizar-sistemas')->assertFailed();

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $mensagem, array $contexto) => $mensagem === 'Sincronização: AlfaControl falhou.'
                && $contexto['sistema'] === 'alfacontrol'
                && str_contains($contexto['motivo'], 'AlfaControl respondeu 522.')
                && $contexto['ensaio'] === false);
    }

    public function test_a_queda_que_continua_vai_para_o_log_como_erro(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        Sistema::factory()->alfacontrol()->create([
            'token' => 'chave-control',
            'sincronizacao_caiu_em' => now()->subHour(),
            'sincronizacao_motivo' => 'AlfaControl respondeu 522.',
        ]);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/*' => Http::response(['contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => []]),
            '*control.alfasolucoes.cloud/*' => Http::response('', 522),
        ]);

        Log::spy();

        $this->artisan('alfa:sincronizar-sistemas')->assertFailed();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $mensagem, array $contexto) => $mensagem === 'Sincronização: AlfaControl falhou.'
                && $contexto['sistema'] === 'alfacontrol'
                && str_contains($contexto['motivo'], 'AlfaControl respondeu 522.')
                && $contexto['ensaio'] === false);
    }

    public function test_sistema_que_sincronizou_nao_vai_para_o_log_de_erro(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/*' => Http::response(['contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => []]),
        ]);

        Log::spy();

        $this->artisan('alfa:sincronizar-sistemas')->assertSuccessful();

        Log::shouldNotHaveReceived('error');
    }

    public function test_o_ensaio_tambem_registra_e_diz_que_era_ensaio(): void
    {
        Sistema::factory()->alfacontrol()->create([
            'token' => 'chave-control',
            'sincronizacao_caiu_em' => now()->subHour(),
        ]);

        Http::preventStrayRequests();
        Http::fake(['*control.alfasolucoes.cloud/*' => Http::response('', 503)]);

        Log::spy();

        $this->artisan('alfa:sincronizar-sistemas', ['--simular' => true])->assertFailed();

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $mensagem, array $contexto) => $mensagem === 'Sincronização: AlfaControl falhou.'
                && $contexto['ensaio'] === true);
    }
}
