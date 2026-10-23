<?php

namespace Tests\Feature\Monitor;

use App\Models\MonitorAlerta;
use App\Models\MonitorEvento;
use App\Models\Sistema;
use App\Services\Monitor\ReconciliacaoDoMonitor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * A reconciliação dos alertas que o webhook do AlfaMonitor não entregou
 * (#399): `GET /api/v1/notificacoes?depois_de=`, pelo cursor do `event_id`,
 * passando pela mesma porta do webhook.
 */
class ReconciliacaoDoMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://monitor.exemplo';

    /** 17/10/2026 10:00 em São Paulo, em segundos desde 1970. */
    private const T0 = 1792242000;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 20:00:00'));
        Http::preventStrayRequests();

        config([
            'services.alfamonitor.url' => 'https://app.exemplo',
            'services.alfamonitor.api_url' => self::BASE,
            'services.alfamonitor.api_token' => 'token-de-leitura',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function notificacao(int $id, array $extra = []): array
    {
        return array_merge([
            'event_id' => $id,
            'incident_id' => $id,
            'kind' => 'monitor',
            'entity' => 'GYM',
            'entity_name' => 'AlfaGym produção',
            'severity' => 'critical',
            'cliente' => null,
            'title' => 'GYM',
            'body' => 'GYM fora do ar (HTTP 502)',
            'resolved' => false,
            'derived' => false,
            'link' => '/monitor/GYM',
            'occurred_at' => self::T0 + $id,
            // O que a rota acrescenta ao corpo do webhook (e o `at` sai).
            'v' => 1,
            'entrega' => null,
        ], $extra);
    }

    /**
     * Um monitor falso que responde pelo cursor, como o contrato: o que tem
     * `event_id` maior que `depois_de`, em ordem, `$porPagina` por vez.
     *
     * @param  list<array<string, mixed>>  $todas
     */
    private function monitorCom(array $todas, int $porPagina = 200): void
    {
        Http::fake([
            self::BASE.'/api/v1/notificacoes*' => function (Request $r) use ($todas, $porPagina) {
                $depoisDe = (int) ($r->data()['depois_de'] ?? 0);
                $restantes = array_values(array_filter($todas, fn ($n) => $n['event_id'] > $depoisDe));
                $pagina = array_slice($restantes, 0, $porPagina);

                return Http::response([
                    'v' => 1,
                    'gerado_em' => '2026-10-17T20:00:00-03:00',
                    'notificacoes' => $pagina,
                    'proximo' => $pagina ? end($pagina)['event_id'] : $depoisDe,
                    'tem_mais' => count($restantes) > $porPagina,
                ]);
            },
        ]);
    }

    public function test_recupera_a_queda_e_a_volta_que_o_webhook_nao_entregou(): void
    {
        $this->monitorCom([
            $this->notificacao(10),
            $this->notificacao(11, ['resolved' => true, 'severity' => 'info', 'body' => 'GYM voltou']),
        ]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Recuperados: 2')
            ->assertSuccessful();

        $alerta = MonitorAlerta::where('kind', 'monitor')->where('entity', 'GYM')->sole();
        $this->assertFalse($alerta->aberto);
        $this->assertFalse($alerta->voltou_sem_queda);
        $this->assertSame(1, $alerta->quedas);
        $this->assertSame('critical', $alerta->severity);
        $this->assertSame(2, MonitorEvento::count());
        $this->assertSame(11, app(ReconciliacaoDoMonitor::class)->cursor());

        Http::assertSent(fn (Request $r) => (string) ($r->data()['depois_de'] ?? '') === '0'
            && (string) ($r->data()['por_pagina'] ?? '') === '200'
            && $r->hasHeader('Authorization', 'Bearer token-de-leitura'));
    }

    public function test_o_que_o_webhook_ja_trouxe_nao_duplica(): void
    {
        $queda = $this->notificacao(20);

        $this->postJsonDoWebhook($queda);
        $this->assertSame(1, MonitorEvento::count());

        $this->monitorCom([$queda, $this->notificacao(21, ['resolved' => true, 'severity' => 'info'])]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Recuperados: 1 · já recebidos: 1')
            ->assertSuccessful();

        // E rodar de novo não faz nada: o cursor já passou.
        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Recuperados: 0 · já recebidos: 0')
            ->assertSuccessful();

        $this->assertSame(2, MonitorEvento::count());
        $this->assertSame(1, MonitorAlerta::sole()->quedas);
    }

    public function test_so_pede_o_que_veio_depois_do_cursor(): void
    {
        Cache::forever(ReconciliacaoDoMonitor::CURSOR, 50);
        $this->monitorCom([$this->notificacao(49), $this->notificacao(51)]);

        $this->artisan('alfa:monitor-reconciliar')->expectsOutputToContain('Recuperados: 1')->assertSuccessful();

        $this->assertNull(MonitorEvento::where('event_id', 49)->first());
        $this->assertNotNull(MonitorEvento::where('event_id', 51)->first());
        Http::assertSent(fn (Request $r) => (string) ($r->data()['depois_de'] ?? '') === '50');
    }

    public function test_do_inicio_confere_tudo_o_que_o_monitor_guarda(): void
    {
        Cache::forever(ReconciliacaoDoMonitor::CURSOR, 50);
        $this->monitorCom([$this->notificacao(49), $this->notificacao(51)]);

        $this->artisan('alfa:monitor-reconciliar', ['--do-inicio' => true])
            ->expectsOutputToContain('Recuperados: 2')
            ->assertSuccessful();
    }

    public function test_percorre_as_paginas_pelo_cursor(): void
    {
        $todas = array_map(fn ($n) => $this->notificacao($n, ['entity' => 'E'.$n]), range(1, 7));
        $this->monitorCom($todas, porPagina: 3);

        $this->artisan('alfa:monitor-reconciliar')->expectsOutputToContain('Recuperados: 7')->assertSuccessful();

        $this->assertSame(7, MonitorAlerta::count());
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => (string) ($r->data()['depois_de'] ?? '') === '6');
    }

    public function test_kind_fora_do_escopo_e_notificacao_quebrada_nao_travam_o_cursor(): void
    {
        $this->monitorCom([
            $this->notificacao(1, ['kind' => 'camera']),
            $this->notificacao(2, ['severity' => 'gravissimo']),
            $this->notificacao(3),
        ]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Recuperados: 1 · já recebidos: 0 · ignorados: 2 · cursor: 3')
            ->assertSuccessful();

        $this->assertSame([3], MonitorEvento::pluck('event_id')->all());
    }

    /** A rota não devolve suprimida; se um dia vier marcada, continua de fora. */
    public function test_a_suprimida_nao_entra_porque_o_webhook_nunca_a_manda(): void
    {
        $this->monitorCom([$this->notificacao(1, ['suprimida' => true]), $this->notificacao(2, ['entity' => 'CONTROL'])]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Recuperados: 1 · já recebidos: 0 · ignorados: 1 · cursor: 2')
            ->assertSuccessful();

        $this->assertSame(['CONTROL'], MonitorAlerta::pluck('entity')->all());
    }

    /**
     * A queda e a volta de um leitor, como a rota respondeu na cópia do banco
     * de produção em 17/10/2026 (cliente e aparelho anonimizados pela Central
     * AlfaMonitor). Os 565 min fora são exatamente o caso da #399: a volta
     * chegou depois de a queda já ter esgotado as tentativas.
     */
    public function test_o_exemplo_de_producao_da_rota(): void
    {
        Http::fake([
            self::BASE.'/api/v1/notificacoes*' => Http::response(json_decode(<<<'JSON'
                {"v":1,"gerado_em":"2026-10-17T19:47:56-03:00","retrato_idade_s":0,"webhook_cadastrado":true,"notificacoes":[
                {"event_id":3662,"incident_id":3662,"kind":"device","entity":"AlfaGym:0","entity_name":null,"severity":"critical","cliente":"CLIENTE EXEMPLO","title":"CLIENTE EXEMPLO","body":"Leitor principal está offline há 5 min · AlfaGym","resolved":false,"derived":false,"link":"/devices","occurred_at":1792199474,"v":1,"entrega":null},
                {"event_id":3673,"incident_id":3662,"kind":"device","entity":"AlfaGym:0","entity_name":null,"severity":"info","cliente":"CLIENTE EXEMPLO","title":"CLIENTE EXEMPLO","body":"Leitor principal voltou — ficou 565 min offline · AlfaGym","resolved":true,"derived":false,"link":"/devices","occurred_at":1792233029,"v":1,"entrega":null}
                ],"proximo":3673,"tem_mais":false}
                JSON, true)),
        ]);

        $this->artisan('alfa:monitor-reconciliar')->expectsOutputToContain('Recuperados: 2')->assertSuccessful();

        $alerta = MonitorAlerta::where('kind', 'device')->where('entity', 'AlfaGym:0')->sole();
        $this->assertFalse($alerta->aberto);
        $this->assertSame(3662, (int) $alerta->incident_id);
        $this->assertSame('critical', $alerta->severity);
        $this->assertSame('CLIENTE EXEMPLO', $alerta->cliente);
        // O monitor conta os 565 min desde que o leitor saiu; a queda só é
        // avisada 5 min depois ("offline há 5 min"), e é dela que o Matriz conta.
        $this->assertEqualsWithDelta(560, $alerta->caiu_em->diffInMinutes($alerta->voltou_em), 1);
        $this->assertSame(3673, app(ReconciliacaoDoMonitor::class)->cursor());
    }

    public function test_falha_no_meio_guarda_o_que_ja_conferiu(): void
    {
        $chamadas = 0;

        Http::fake([
            self::BASE.'/api/v1/notificacoes*' => function () use (&$chamadas) {
                $chamadas++;

                return $chamadas === 1
                    ? Http::response(['v' => 1, 'notificacoes' => [$this->notificacao(1)], 'proximo' => 1, 'tem_mais' => true])
                    : Http::response(['erro' => 'interno'], 500);
            },
        ]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Recuperados: 1')
            ->expectsOutputToContain('respondeu com erro (500)')
            ->assertSuccessful();

        $this->assertSame(1, app(ReconciliacaoDoMonitor::class)->cursor());
    }

    /**
     * 22/10/2026 12:20 (T-482): a rede oscilou, o monitor "não respondeu" uma
     * vez e o exit 1 virou Bug no vigia, sem a causa. Falha isolada é WARNING
     * com o motivo, e o comando sai com 0.
     */
    public function test_falha_isolada_fica_em_aviso_com_a_causa(): void
    {
        Log::spy();
        Http::fake([self::BASE.'/api/v1/notificacoes*' => fn () => throw new ConnectionException('cURL error 28: Connection timed out')]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('cURL error 28')
            ->expectsOutputToContain('Falha 1 de 3')
            ->assertSuccessful();

        Log::shouldHaveReceived('warning')->with('AlfaMonitor: a reconciliação parou.', \Mockery::on(
            fn ($c) => str_contains($c['erro'], 'não respondeu (cURL error 28') && $c['falhas_seguidas'] === 1
        ))->once();
        Log::shouldNotHaveReceived('error');
    }

    public function test_tres_rodadas_seguidas_de_falha_viram_erro_com_o_motivo(): void
    {
        Log::spy();
        Http::fake([self::BASE.'/api/v1/notificacoes*' => fn () => throw new ConnectionException('Could not resolve host')]);

        $this->artisan('alfa:monitor-reconciliar')->assertSuccessful();
        $this->artisan('alfa:monitor-reconciliar')->assertSuccessful();
        Log::shouldNotHaveReceived('error');

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('Falhou 3 rodadas seguidas')
            ->assertSuccessful();

        Log::shouldHaveReceived('error')->with(
            'AlfaMonitor: a reconciliação falha há 3 rodadas seguidas ou mais.',
            \Mockery::on(fn ($c) => str_contains($c['erro'], 'Could not resolve host') && $c['falhas_seguidas'] === 3),
        )->once();
    }

    public function test_rodada_boa_zera_a_conta_de_falhas(): void
    {
        Cache::forever(ReconciliacaoDoMonitor::FALHAS_SEGUIDAS, 2);
        Http::fake([self::BASE.'/api/v1/notificacoes*' => Http::sequence()
            ->push(['v' => 1, 'notificacoes' => [$this->notificacao(1)], 'proximo' => 1, 'tem_mais' => false])
            ->push(['erro' => 'interno'], 500)]);

        $this->artisan('alfa:monitor-reconciliar')->assertSuccessful();

        $this->assertNull(Cache::get(ReconciliacaoDoMonitor::FALHAS_SEGUIDAS));

        // E a falha seguinte volta a ser a primeira, não a terceira.
        Log::spy();

        $this->artisan('alfa:monitor-reconciliar')->expectsOutputToContain('Falha 1 de 3')->assertSuccessful();

        Log::shouldNotHaveReceived('error');
    }

    public function test_monitor_sem_a_rota_nao_e_erro(): void
    {
        Http::fake([self::BASE.'/api/v1/notificacoes*' => Http::response(['erro' => 'nao_encontrado'], 404)]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('ainda não tem a rota')
            ->assertSuccessful();

        $this->assertSame(0, app(ReconciliacaoDoMonitor::class)->cursor());
    }

    public function test_versao_de_contrato_desconhecida_nao_anda_o_cursor(): void
    {
        Http::fake([self::BASE.'/api/v1/notificacoes*' => Http::response(['v' => 2, 'notificacoes' => [$this->notificacao(1)], 'proximo' => 1])]);

        $this->artisan('alfa:monitor-reconciliar')
            ->expectsOutputToContain('mudou o formato')
            ->assertSuccessful();

        $this->assertSame(0, MonitorEvento::count());
        $this->assertSame(0, app(ReconciliacaoDoMonitor::class)->cursor());
    }

    public function test_sem_o_monitor_configurado_nao_consulta_nem_falha(): void
    {
        config(['services.alfamonitor.api_url' => null, 'services.alfamonitor.url' => null]);

        $this->artisan('alfa:monitor-reconciliar')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_recuperar_invalida_o_cache_das_telas(): void
    {
        Cache::forever('alfamonitor-api:geracao', 4);
        $this->monitorCom([$this->notificacao(1)]);

        $this->artisan('alfa:monitor-reconciliar')->assertSuccessful();

        $this->assertSame(5, Cache::get('alfamonitor-api:geracao'));
    }

    public function test_roda_a_cada_hora(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'alfa:monitor-reconciliar'));

        $this->assertNotNull($evento);
        $this->assertSame('20 * * * *', $evento->expression);
    }

    /** O mesmo evento chegando pelo webhook, para provar que a trava é uma só. */
    private function postJsonDoWebhook(array $notificacao): void
    {
        $monitor = Sistema::factory()->create(['nome' => 'AlfaMonitor', 'slug' => 'alfamonitor']);
        $monitor->forceFill(['monitor_token_hash' => hash('sha256', 'segredo')])->save();

        $this->postJson(route('monitor.eventos'), ['v' => 1] + $notificacao, ['X-AlfaMonitor-Token' => $monitor->id.'|segredo'])
            ->assertOk();
    }
}
