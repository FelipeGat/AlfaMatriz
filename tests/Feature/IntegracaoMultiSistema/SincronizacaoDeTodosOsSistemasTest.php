<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Sistema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Um comando para todos os sistemas integrados.
 *
 * O risco que estes testes cobrem é o da implantação: enquanto o AlfaControl
 * está entrando, o AlfaGym continua em produção. Uma indisponibilidade do
 * novato não pode parar o retrato do que já funciona.
 */
class SincronizacaoDeTodosOsSistemasTest extends TestCase
{
    use RefreshDatabase;

    private function respostaVazia(string $sistema): mixed
    {
        return Http::response(['contrato' => '1.0', 'sistema' => $sistema, 'dados' => []]);
    }

    /**
     * @spec:AC-134 Sem `--sistema`, o comando percorre todos os integráveis —
     * não só o AlfaGym. Um sistema novo entra sozinho ao ser configurado.
     */
    public function test_sincroniza_todos_os_sistemas_configurados(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/*' => $this->respostaVazia('alfagym'),
            '*control.alfasolucoes.cloud/*' => $this->respostaVazia('alfacontrol'),
        ]);

        $this->artisan('alfa:sincronizar-sistemas')->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'gym.alfasolucoes.cloud')
            && $r->hasHeader('X-Matriz-Key', 'chave-gym'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'control.alfasolucoes.cloud')
            && $r->hasHeader('X-Matriz-Key', 'chave-control'));
    }

    /**
     * @spec:AC-135 Um sistema fora do ar não interrompe os outros: o que
     * responde é sincronizado, o comando sai com erro, e o relatório nomeia
     * quem falhou.
     */
    public function test_falha_de_um_sistema_nao_para_os_outros(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/*' => $this->respostaVazia('alfagym'),
            '*control.alfasolucoes.cloud/*' => Http::response('', 500),
        ]);

        $this->artisan('alfa:sincronizar-sistemas')
            ->expectsOutputToContain('AlfaControl respondeu 500.')
            ->assertFailed();

        // O gym foi sincronizado apesar da falha do outro.
        Http::assertSent(fn ($r) => str_contains($r->url(), 'gym.alfasolucoes.cloud/api/matriz/v1/licencas'));
    }

    /**
     * @spec:AC-136 A recusa nomeia o sistema certo. Antes, toda mensagem dizia
     * "AlfaGym" — com dois integrados, o suporte investigaria o servidor errado.
     */
    public function test_a_recusa_nomeia_o_sistema_certo(): void
    {
        Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        Http::preventStrayRequests();
        Http::fake(['*control.alfasolucoes.cloud/*' => Http::response('', 503)]);

        $this->artisan('alfa:sincronizar-sistemas')
            ->expectsOutputToContain('AlfaControl respondeu 503.')
            ->assertFailed();
    }

    /**
     * @spec:AC-134 Sistema sem endereço ou sem chave é ignorado em silêncio —
     * é o estado normal entre publicar a integração e configurá-la, e é
     * exatamente como o AlfaControl sobe em produção.
     */
    public function test_sistema_ainda_nao_configurado_e_ignorado(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        Sistema::factory()->alfacontrol()->create(['token' => null]);

        Http::preventStrayRequests();
        Http::fake(['*gym.alfasolucoes.cloud/*' => $this->respostaVazia('alfagym')]);

        $this->artisan('alfa:sincronizar-sistemas')->assertSuccessful();

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'control.alfasolucoes.cloud'));
    }

    /**
     * @spec:AC-134 Sistema sem a capacidade `sincroniza` não é consultado, nem
     * quando tem endereço e chave preenchidos.
     */
    public function test_sistema_sem_a_capacidade_nao_e_consultado(): void
    {
        Sistema::factory()->create([
            'nome' => 'AlfaMed',
            'base_url' => 'https://med.alfasolucoes.cloud',
            'token' => 'chave-med',
        ]);

        Http::preventStrayRequests();
        Http::fake();

        $this->artisan('alfa:sincronizar-sistemas')->assertFailed();

        Http::assertNothingSent();
    }

    /**
     * @spec:AC-134 O nome antigo do comando continua respondendo: cron manual
     * e runbook escritos antes da generalização não quebram.
     */
    public function test_o_nome_antigo_do_comando_continua_valendo(): void
    {
        Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);

        Http::preventStrayRequests();
        Http::fake(['*gym.alfasolucoes.cloud/*' => $this->respostaVazia('alfagym')]);

        $this->artisan('app:sincronizar-alfagym')->assertSuccessful();
    }

    /**
     * @spec:AC-164 Sistema sem `sincroniza_licencas` tem revenda e cliente
     * sincronizados, mas a licença não é lida.
     *
     * O AlfaControl está nesse estado: renovar lá encadeia licenças ativas em
     * vez de substituir a anterior. Espelhar esse retrato faria a Matriz herdar
     * o defeito e — pior — faturar em cima dele. O cliente aparece na lista sem
     * estado de licença, que é honesto, em vez de aparecer com um estado errado.
     */
    public function test_licenca_nao_e_lida_sem_a_capacidade(): void
    {
        $control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        $this->assertFalse($control->suporta('sincroniza_licencas'));

        Http::preventStrayRequests();
        Http::fake(['*control.alfasolucoes.cloud/*' => $this->respostaVazia('alfacontrol')]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfacontrol'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/clientes'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/licencas'));
    }

    /**
     * @spec:AC-164 O AlfaGym continua lendo licença — é o que ele já fazia, e
     * a separação não pode tirar isso dele.
     */
    public function test_o_alfagym_continua_lendo_licenca(): void
    {
        $gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);

        $this->assertTrue($gym->suporta('sincroniza_licencas'));

        Http::preventStrayRequests();
        Http::fake(['*gym.alfasolucoes.cloud/*' => $this->respostaVazia('alfagym')]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfagym'])->assertSuccessful();

        Http::assertSent(fn ($r) => str_contains($r->url(), '/licencas'));
    }
}
