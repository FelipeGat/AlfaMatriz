<?php

namespace Tests\Feature\Monitor;

use App\Models\MonitorAlerta;
use App\Models\MonitorEvento;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A aba Monitor da tela de Manutenção (#394): o que está fora agora e o
 * histórico, a partir do que o webhook do AlfaMonitor guardou.
 */
class TelaDoMonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 15:30:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function alerta(array $extra = []): MonitorAlerta
    {
        static $n = 0;
        $n++;

        return MonitorAlerta::create(array_merge([
            'kind' => 'monitor',
            'entity' => 'ENT-'.$n,
            'severity' => 'critical',
            'title' => 'Título '.$n,
            'body' => 'Detalhe '.$n,
            'aberto' => true,
            'caiu_em' => '2026-10-03 15:00:00',
            'quedas' => 1,
            'ultimo_evento_em' => '2026-10-03 15:00:00',
            'ultimo_aviso_em' => '2026-10-03 15:00:00',
        ], $extra));
    }

    private function evento(MonitorAlerta $alerta, array $extra = []): MonitorEvento
    {
        static $id = 1000;
        $id++;

        return MonitorEvento::create(array_merge([
            'monitor_alerta_id' => $alerta->id,
            'event_id' => $id,
            'incident_id' => $id,
            'severity' => $alerta->severity,
            'resolved' => false,
            'derived' => false,
            'title' => $alerta->title,
            'body' => $alerta->body,
            'ocorreu_em' => '2026-10-03 15:00:00',
        ], $extra));
    }

    private function aba(array $query = [])
    {
        return $this->actingAs(User::factory()->create())
            ->get(route('manutencao.index', ['aba' => 'monitor'] + $query));
    }

    public function test_vazia_diz_de_onde_o_conteudo_vem(): void
    {
        $this->aba()->assertOk()
            ->assertSee('O AlfaMonitor ainda não mandou nenhum alerta.')
            ->assertSee('alfa:monitor-token');

        Sistema::factory()->create(['slug' => 'alfamonitor'])->forceFill(['monitor_token_hash' => str_repeat('a', 64)])->save();

        $this->aba()->assertSee('O token já foi emitido');
    }

    public function test_fora_agora_vem_por_gravidade_e_o_que_voltou_nao_aparece(): void
    {
        $aviso = $this->alerta(['severity' => 'warning', 'entity_name' => 'GYM VPS', 'entity' => 'GYM VPS:cpu', 'kind' => 'metric', 'body' => 'CPU em 97%']);
        $critico = $this->alerta(['entity_name' => 'Catraca 2', 'entity' => 'AlfaControl:123', 'kind' => 'device', 'cliente' => 'Academia Centro', 'caiu_em' => '2026-10-03 13:30:00']);
        $voltou = $this->alerta(['entity_name' => 'ALFA SITE', 'aberto' => false, 'voltou_em' => '2026-10-03 15:10:00']);
        $this->evento($critico);

        $resposta = $this->aba();

        $resposta->assertOk()
            ->assertSeeInOrder(['Fora agora', 'Catraca 2', 'Academia Centro', 'Crítico', 'GYM VPS', 'Atenção', 'CPU em 97%', 'Histórico'])
            ->assertSee('AlfaControl:123')
            ->assertSee('Dispositivo de acesso')
            ->assertSee('há 2 horas')
            ->assertSee('2 itens fora do ar agora');

        $this->assertStringNotContainsString('ALFA SITE', explode('Histórico', $resposta->getContent())[0]);
    }

    public function test_o_cliente_que_casou_aparece_com_o_nome_do_cadastro(): void
    {
        $cliente = \App\Models\Cliente::create(['nome' => 'Academia Força Total', 'ativo' => true]);
        $this->evento($this->alerta(['kind' => 'device', 'cliente' => 'academia forca total', 'cliente_id' => $cliente->id]));
        $this->evento($this->alerta(['kind' => 'device', 'cliente' => 'Academia Sem Cadastro']));

        $this->aba()->assertSee('Academia Força Total')->assertSee('Academia Sem Cadastro')->assertDontSee('academia forca total');
    }

    public function test_o_historico_mostra_queda_volta_e_quanto_ficou_fora(): void
    {
        $alerta = $this->alerta(['entity_name' => 'GYM', 'aberto' => false, 'voltou_em' => '2026-10-03 15:12:00']);
        $queda = $this->evento($alerta, ['ocorreu_em' => '2026-10-03 15:00:00']);
        $this->evento($alerta, ['resolved' => true, 'severity' => 'info', 'incident_id' => $queda->event_id, 'ocorreu_em' => '2026-10-03 15:12:00', 'body' => 'GYM voltou']);

        $this->aba()->assertOk()
            ->assertSeeInOrder(['Histórico', '03/10/2026 15:12', 'Voltou', 'ficou fora 12 minutos', '03/10/2026 15:00', 'Caiu']);
    }

    public function test_o_voltou_sem_queda_e_o_fora_de_ordem_aparecem(): void
    {
        $alerta = $this->alerta(['aberto' => false, 'voltou_sem_queda' => true]);
        $this->evento($alerta, ['resolved' => true, 'incident_id' => 999999]);
        $this->evento($alerta, ['aplicado' => false, 'ocorreu_em' => '2026-10-03 14:00:00']);

        $this->aba()->assertSee('sem queda registrada')->assertSee('fora de ordem');
    }

    public function test_lembretes_ficam_escondidos_ate_pedir(): void
    {
        $alerta = $this->alerta();
        $this->evento($alerta, ['derived' => true, 'aplicado' => false, 'body' => 'ainda fora há 4h']);

        $this->aba()->assertDontSee('ainda fora há 4h');
        $this->aba(['lembretes' => 1])->assertSee('ainda fora há 4h')->assertSee('Lembrete');
    }

    public function test_filtra_por_tipo_e_por_periodo(): void
    {
        $site = $this->alerta(['entity_name' => 'ALFA SITE']);
        $vps = $this->alerta(['entity_name' => 'VPS 1', 'kind' => 'hostinger', 'entity' => 'vps:1']);
        $this->evento($site, ['body' => 'site caiu']);
        $this->evento($vps, ['body' => 'vps antiga', 'ocorreu_em' => '2026-09-20 10:00:00']);

        $this->aba(['kind' => 'hostinger'])->assertSee('VPS 1')->assertDontSee('ALFA SITE');
        $this->aba()->assertSee('site caiu')->assertDontSee('vps antiga');
        $this->aba(['periodo' => 30])->assertSee('vps antiga');
    }

    public function test_o_link_abre_no_monitor_so_com_a_base_configurada(): void
    {
        $this->evento($this->alerta(['entity_name' => 'GYM', 'link' => '/monitor/GYM']));

        $this->aba()->assertDontSee('/monitor/GYM');

        config(['services.alfamonitor.url' => 'https://monitor.exemplo/']);
        $this->aba()->assertSee('https://monitor.exemplo/monitor/GYM', false);
    }

    public function test_se_atualiza_sozinha_como_a_aba_erros(): void
    {
        $this->aba()->assertSee('manutencaoTela({ automatico: true })', false);
    }

    public function test_perfil_de_exibicao_e_revenda_nao_entram(): void
    {
        $this->seed(PerfilPermissaoSeeder::class);

        $exibicao = User::factory()->semPerfil()->create();
        $exibicao->perfis()->attach(Perfil::where('slug', 'exibicao')->value('id'));

        $this->actingAs($exibicao)->get(route('manutencao.index', ['aba' => 'monitor']))->assertForbidden();

        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $this->actingAs(User::factory()->create(['revenda_id' => $revenda->id]))
            ->get(route('manutencao.index', ['aba' => 'monitor']))->assertForbidden();
    }
}
