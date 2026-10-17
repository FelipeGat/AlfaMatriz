<?php

namespace Tests\Feature\Monitor;

use App\Models\Cliente;
use App\Models\MonitorAlerta;
use App\Models\MonitorEvento;
use App\Models\Notificacao;
use App\Models\Sistema;
use App\Models\Tarefa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * O webhook do AlfaMonitor (#394): token, escopo, repetido, fora de ordem,
 * "voltou" sem queda, lembrete — e a regra de que o Matriz só guarda, sem
 * avisar ninguém.
 */
class RecebimentoDoMonitorTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $monitor;

    private string $token;

    /** 03/10/2026 15:00 em São Paulo, em segundos desde 1970 (UTC). */
    private const T0 = 1791050400;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 15:30:00'));
        Http::preventStrayRequests();

        $this->monitor = Sistema::factory()->create(['nome' => 'AlfaMonitor', 'slug' => 'alfamonitor']);

        $this->artisan('alfa:monitor-token', ['sistema' => 'alfamonitor'])->assertSuccessful();
        $this->monitor->forceFill(['monitor_token_hash' => hash('sha256', 'segredo')])->save();
        $this->token = $this->monitor->id.'|segredo';
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function evento(array $extra = []): array
    {
        static $id = 100;
        $id++;

        return array_merge([
            'v' => 1,
            'event_id' => $id,
            'incident_id' => $id,
            'severity' => 'critical',
            'kind' => 'monitor',
            'entity' => 'GYM',
            'entity_name' => 'AlfaGym produção',
            'cliente' => null,
            'title' => 'GYM',
            'body' => 'GYM fora do ar (HTTP 502)',
            'resolved' => false,
            'derived' => false,
            'link' => '/monitor/GYM',
            'at' => self::T0 + 5,
            'occurred_at' => self::T0,
        ], $extra);
    }

    private function enviar(array $evento, ?string $token = null): TestResponse
    {
        return $this->postJson(route('monitor.eventos'), $evento, ['X-AlfaMonitor-Token' => $token ?? $this->token]);
    }

    public function test_sem_token_ou_com_token_errado_recusa(): void
    {
        $this->postJson(route('monitor.eventos'), $this->evento())->assertStatus(401);
        $this->enviar($this->evento(), $this->monitor->id.'|outro')->assertStatus(401);
        $this->enviar($this->evento(), 'sem-id')->assertStatus(401);

        $this->monitor->update(['ativo' => false]);
        $this->enviar($this->evento())->assertStatus(401);

        $this->assertSame(0, MonitorEvento::count());
    }

    public function test_o_token_revogado_para_de_valer(): void
    {
        $this->artisan('alfa:monitor-token', ['sistema' => 'alfamonitor', '--revogar' => true])->assertSuccessful();

        $this->enviar($this->evento())->assertStatus(401);
    }

    public function test_o_token_do_vigia_nao_abre_a_porta_do_monitor(): void
    {
        $this->monitor->forceFill(['vigia_token_hash' => hash('sha256', 'do-vigia'), 'monitor_token_hash' => null])->save();

        $this->enviar($this->evento(), $this->monitor->id.'|do-vigia')->assertStatus(401);
    }

    public function test_a_queda_abre_o_alerta_e_a_volta_fecha(): void
    {
        $queda = $this->evento();
        $this->enviar($queda)->assertOk()->assertJson(['message' => 'gravado']);

        $alerta = MonitorAlerta::sole();
        $this->assertTrue($alerta->aberto);
        $this->assertSame('critical', $alerta->severity);
        $this->assertSame('AlfaGym produção', $alerta->nome());
        $this->assertSame('2026-10-03 15:00:00', $alerta->caiu_em->format('Y-m-d H:i:s'));
        $this->assertSame(1, $alerta->quedas);

        $this->enviar($this->evento([
            'severity' => 'info', 'resolved' => true, 'incident_id' => $queda['event_id'],
            'body' => 'GYM voltou', 'occurred_at' => self::T0 + 600, 'at' => self::T0 + 601,
        ]))->assertOk();

        $alerta->refresh();
        $this->assertFalse($alerta->aberto);
        $this->assertFalse($alerta->voltou_sem_queda);
        // A gravidade que fica é a da queda, não o "info" da volta.
        $this->assertSame('critical', $alerta->severity);
        $this->assertSame('2026-10-03 15:10:00', $alerta->voltou_em->format('Y-m-d H:i:s'));
        $this->assertSame(2, MonitorEvento::count());
    }

    public function test_a_mesma_notificacao_reenviada_nao_duplica(): void
    {
        $queda = $this->evento();

        $this->enviar($queda)->assertOk()->assertJson(['message' => 'gravado']);
        // A nova tentativa vem com outro `at` (a hora do envio), mesmo event_id.
        $this->enviar(['at' => self::T0 + 120] + $queda)->assertOk()->assertJson(['message' => 'repetido']);

        $this->assertSame(1, MonitorEvento::count());
        $this->assertSame(1, MonitorAlerta::sole()->quedas);
    }

    public function test_o_voltou_que_chega_antes_da_queda(): void
    {
        $queda = $this->evento(['occurred_at' => self::T0]);
        $volta = $this->evento(['resolved' => true, 'severity' => 'info', 'incident_id' => $queda['event_id'], 'occurred_at' => self::T0 + 300]);

        $this->enviar($volta)->assertOk();

        $alerta = MonitorAlerta::sole();
        $this->assertFalse($alerta->aberto);
        $this->assertTrue($alerta->voltou_sem_queda);
        $this->assertSame(0, $alerta->quedas);

        // A queda que estava na fila chega depois: não reabre, e encaixa.
        $this->enviar($queda)->assertOk();

        $alerta->refresh();
        $this->assertFalse($alerta->aberto);
        $this->assertFalse($alerta->voltou_sem_queda);
        $this->assertSame(1, $alerta->quedas);
        $this->assertSame('2026-10-03 15:00:00', $alerta->caiu_em->format('Y-m-d H:i:s'));
        $this->assertSame('critical', $alerta->severity);
        $this->assertFalse(MonitorEvento::where('event_id', $queda['event_id'])->value('aplicado'));
    }

    public function test_a_queda_atrasada_de_um_incidente_antigo_nao_reabre(): void
    {
        $this->enviar($this->evento(['occurred_at' => self::T0 + 1000]))->assertOk();
        $this->enviar($this->evento(['resolved' => true, 'severity' => 'info', 'occurred_at' => self::T0 + 2000]))->assertOk();

        // Uma queda de antes das duas, que ficou presa nas novas tentativas.
        $this->enviar($this->evento(['occurred_at' => self::T0]))->assertOk();

        $alerta = MonitorAlerta::sole();
        $this->assertFalse($alerta->aberto);
        $this->assertSame(1, $alerta->quedas);
        $this->assertSame(3, MonitorEvento::count());
    }

    public function test_o_empate_de_hora_se_decide_pelo_event_id(): void
    {
        $this->enviar($this->evento(['event_id' => 501, 'resolved' => true, 'severity' => 'info']))->assertOk();
        $this->enviar($this->evento(['event_id' => 500]))->assertOk();

        $this->assertFalse(MonitorAlerta::sole()->aberto);
    }

    public function test_o_lembrete_nao_conta_queda_nova_nem_muda_o_estado(): void
    {
        $this->enviar($this->evento())->assertOk();
        $this->enviar($this->evento(['derived' => true, 'severity' => 'warning', 'body' => 'GYM ainda fora há 4h', 'occurred_at' => self::T0 + 14400]))->assertOk();

        $alerta = MonitorAlerta::sole();
        $this->assertTrue($alerta->aberto);
        $this->assertSame(1, $alerta->quedas);
        $this->assertSame('critical', $alerta->severity);
        $this->assertSame('GYM fora do ar (HTTP 502)', $alerta->body);
        $this->assertSame('2026-10-03 19:00:00', $alerta->ultimo_aviso_em->format('Y-m-d H:i:s'));
    }

    public function test_queda_repetida_com_o_alerta_aberto_atualiza_sem_contar_outra(): void
    {
        $this->enviar($this->evento(['severity' => 'warning']))->assertOk();
        $this->enviar($this->evento(['severity' => 'critical', 'occurred_at' => self::T0 + 60]))->assertOk();

        $alerta = MonitorAlerta::sole();
        $this->assertSame(1, $alerta->quedas);
        $this->assertSame('critical', $alerta->severity);
        $this->assertSame('2026-10-03 15:00:00', $alerta->caiu_em->format('Y-m-d H:i:s'));
    }

    public function test_o_par_kind_e_entity_e_a_chave(): void
    {
        $this->enviar($this->evento(['kind' => 'monitor', 'entity' => 'GYM']))->assertOk();
        $this->enviar($this->evento(['kind' => 'source', 'entity' => 'GYM']))->assertOk();

        $this->assertSame(2, MonitorAlerta::count());
    }

    public function test_kind_fora_do_escopo_e_descartado_com_2xx(): void
    {
        foreach (['sonda', 'sonda_target', 'digest', 'summary', 'novo_kind'] as $kind) {
            $this->enviar($this->evento(['kind' => $kind]))->assertStatus(202)->assertJson(['motivo' => 'kind']);
        }

        $this->assertSame(0, MonitorAlerta::count());
        $this->assertSame(0, MonitorEvento::count());
    }

    public function test_versao_desconhecida_ou_sem_versao_e_descartada_com_2xx(): void
    {
        $this->enviar($this->evento(['v' => 2]))->assertStatus(202)->assertJson(['motivo' => 'versao']);

        $antigo = $this->evento();
        unset($antigo['v'], $antigo['event_id']);
        $this->enviar($antigo)->assertStatus(202);

        $this->assertSame(0, MonitorEvento::count());
    }

    public function test_corpo_invalido_recusa_com_422(): void
    {
        $semId = $this->evento();
        unset($semId['event_id']);

        $this->enviar($semId)->assertStatus(422)->assertJsonValidationErrors('event_id');
        $this->enviar($this->evento(['severity' => 'grave']))->assertStatus(422);
        $this->enviar($this->evento(['occurred_at' => '2026-10-03T15:00:00Z']))->assertStatus(422);

        $this->assertSame(0, MonitorEvento::count());
    }

    public function test_o_cliente_casa_pelo_nome_sem_acento_nem_caixa(): void
    {
        $cliente = Cliente::create(['nome' => 'Academia Força  Total', 'ativo' => true]);

        $this->enviar($this->evento(['kind' => 'device', 'entity' => 'AlfaControl:123', 'cliente' => 'academia forca total']))->assertOk();

        $this->assertSame($cliente->id, MonitorAlerta::sole()->cliente_id);
        $this->assertSame('academia forca total', MonitorAlerta::sole()->cliente);
    }

    public function test_dois_clientes_com_o_mesmo_nome_nao_ligam_a_nenhum(): void
    {
        Cliente::create(['nome' => 'Academia Centro', 'ativo' => true]);
        Cliente::create(['nome' => 'Academia Centro', 'ativo' => true]);

        $this->enviar($this->evento(['kind' => 'device', 'entity' => 'AlfaGym:9', 'cliente' => 'Academia Centro']))->assertOk();

        $this->assertNull(MonitorAlerta::sole()->cliente_id);
    }

    /**
     * O Matriz só mostra: nada de tarefa, sino ou Telegram por cima do
     * alerta — quem avisa é o monitor (decisão do dono, 17/10/2026).
     */
    public function test_receber_alerta_nao_avisa_ninguem(): void
    {
        $this->enviar($this->evento())->assertOk();
        $this->enviar($this->evento(['resolved' => true, 'severity' => 'info', 'occurred_at' => self::T0 + 60]))->assertOk();

        $this->assertSame(0, Tarefa::count());
        $this->assertSame(0, Notificacao::count());
        Http::assertNothingSent();
    }

    public function test_o_historico_velho_e_podado_e_o_estado_fica(): void
    {
        $this->enviar($this->evento(['occurred_at' => self::T0 - 100 * 86400]))->assertOk();
        $this->enviar($this->evento(['occurred_at' => self::T0]))->assertOk();

        $this->artisan('model:prune', ['--model' => [MonitorEvento::class]])->assertSuccessful();

        $this->assertSame(1, MonitorEvento::count());
        $this->assertSame(1, MonitorAlerta::count());
    }
}
