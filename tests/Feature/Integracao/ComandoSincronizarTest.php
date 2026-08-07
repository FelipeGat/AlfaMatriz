<?php

namespace Tests\Feature\Integracao;

use App\Models\Sincronizacao;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\FabricaDeConector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

class ComandoSincronizarTest extends TestCase
{
    use RefreshDatabase;

    /** @spec:AC-084 O comando sincroniza todos os produtos vendidos como serviço, e só eles. */
    public function test_o_comando_sincroniza_todos_os_sistemas_saas(): void
    {
        $gym = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $control = Sistema::factory()->integrado()->create(['nome' => 'AlfaControl']);
        Sistema::factory()->create(['nome' => 'AlfaGestor', 'categoria' => 'crm']);

        $this->app->instance(
            FabricaDeConector::class,
            (new FabricaFalsa)
                ->registrar($gym, Amostras::conector(sistema: 'alfagym'))
                ->registrar($control, Amostras::conector(sistema: 'alfacontrol'))
        );

        $this->artisan('app:sincronizar-sistemas')->assertSuccessful();

        $this->assertSame(2, Sincronizacao::where('status', 'sucesso')->distinct()->pluck('sistema_id')->count());
        $this->assertSame(0, Sincronizacao::whereHas('sistema', fn ($q) => $q->where('categoria', 'crm'))->count(), 'O Gestor fica de fora.');
        $this->assertSame(4, SistemaCliente::doSistema($gym)->count());
        $this->assertSame(4, SistemaCliente::doSistema($control)->count());
    }

    /** @spec:AC-084 O comando aceita um sistema específico pelo slug. */
    public function test_o_comando_aceita_um_sistema_especifico(): void
    {
        $gym = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $control = Sistema::factory()->integrado()->create(['nome' => 'AlfaControl']);

        $this->app->instance(
            FabricaDeConector::class,
            (new FabricaFalsa)
                ->registrar($gym, Amostras::conector(sistema: 'alfagym'))
                ->registrar($control, Amostras::conector(sistema: 'alfacontrol'))
        );

        $this->artisan('app:sincronizar-sistemas', ['--sistema' => $gym->slug])->assertSuccessful();

        $this->assertGreaterThan(0, SistemaCliente::doSistema($gym)->count(), 'O AlfaGym foi sincronizado.');
        $this->assertSame(0, Sincronizacao::where('sistema_id', $control->id)->count(), 'O AlfaControl ficou intocado.');
        $this->assertGreaterThan(0, Sincronizacao::where('sistema_id', $gym->id)->count());
    }

    /**
     * @spec:AC-084 Sistema mal configurado não derruba o comando inteiro: a
     * execução dele é gravada com o motivo e os demais sistemas seguem — mas o
     * comando termina em falha, para o agendador perceber que algo ficou para
     * trás.
     */
    public function test_sistema_mal_configurado_nao_derruba_o_comando(): void
    {
        $gym = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $semChave = Sistema::factory()->create(['nome' => 'AlfaHome', 'base_url' => 'https://home.alfa', 'token' => null]);

        $this->app->instance(
            FabricaDeConector::class,
            (new FabricaFalsa)->registrar($gym, Amostras::conector(sistema: 'alfagym'))
        );

        $this->artisan('app:sincronizar-sistemas')->assertFailed();

        $this->assertSame(0, Sincronizacao::where('sistema_id', $gym->id)->where('status', 'falha')->count(), 'O que está no ar sincroniza.');
        $this->assertSame('sem_chave', Sincronizacao::where('sistema_id', $semChave->id)->value('erro_codigo'));
    }

    /** @spec:AC-084 O comando registra a origem da execução para o painel distinguir o que foi agendado do que foi manual. */
    public function test_o_comando_registra_a_origem_da_execucao(): void
    {
        $gym = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);

        $this->app->instance(
            FabricaDeConector::class,
            (new FabricaFalsa)->registrar($gym, Amostras::conector(sistema: 'alfagym'))
        );

        Artisan::call('app:sincronizar-sistemas', ['--escopo' => 'clientes']);

        $execucao = Sincronizacao::where('sistema_id', $gym->id)->first();
        $this->assertSame('clientes', $execucao->escopo);
        $this->assertSame('comando', $execucao->origem);
    }
}
