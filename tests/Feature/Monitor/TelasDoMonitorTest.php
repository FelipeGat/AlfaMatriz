<?php

namespace Tests\Feature\Monitor;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\Monitor\ApiDoMonitor;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * As sete telas do Monitor (#394), lendo a API de leitura do AlfaMonitor.
 *
 * As respostas falsas são os exemplos que o monitor tirou da produção em
 * 17/10/2026 (`tests/Fixtures/alfamonitor`), com os nomes de cliente e de
 * aparelho trocados por fictícios e os IPs na faixa de documentação.
 */
class TelasDoMonitorTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://monitor.exemplo';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-17 14:33:00'));

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
    private function exemplo(string $nome): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/alfamonitor/'.$nome.'.json')), true);
    }

    /** Todas as rotas respondendo com o exemplo de produção. */
    private function monitorNoAr(): void
    {
        Http::fake([
            self::BASE.'/api/v1/visao-geral*' => Http::response($this->exemplo('visao-geral')),
            self::BASE.'/api/v1/hostinger*' => Http::response($this->exemplo('hostinger')),
            self::BASE.'/api/v1/acesso*' => Http::response($this->exemplo('acesso-AlfaGym')),
            self::BASE.'/api/v1/servidores/control-vps*' => Http::response($this->exemplo('servidor-control-vps')),
            self::BASE.'/api/v1/servidores/*' => Http::response(['erro' => 'nao_encontrado'], 404),
            self::BASE.'/api/v1/clientes/AlfaGym:5*' => Http::response($this->exemplo('cliente-AlfaGym-5')),
            self::BASE.'/api/v1/clientes/*' => Http::response(['erro' => 'nao_encontrado'], 404),
            self::BASE.'/api/v1/clientes*' => Http::response($this->exemplo('clientes')),
            self::BASE.'/api/v1/incidentes*' => fn (Request $r) => Http::response($this->exemplo(
                ($r->data()['estado'] ?? null) === 'historico' ? 'incidentes-historico' : 'incidentes-aberto'
            )),
            self::BASE.'/api/v1/relatorio/exportar*' => Http::response("Janela,7 dias\nSaúde geral,93\n", 200, ['Content-Type' => 'text/csv']),
            self::BASE.'/api/v1/relatorio*' => Http::response($this->exemplo('relatorio-7d')),
        ]);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_sem_endereco_e_token_a_tela_diz_o_que_falta_e_nao_consulta(): void
    {
        config(['services.alfamonitor.api_token' => null]);
        Http::fake();

        $this->actingAs($this->admin())->get(route('monitor.geral'))
            ->assertOk()
            ->assertSee('Sem dados do AlfaMonitor agora.')
            ->assertSee('ALFAMONITOR_API_TOKEN');

        Http::assertNothingSent();
    }

    /**
     * A API vai pela rede interna do alfa-server (`ALFAMONITOR_API_URL`), e o
     * link do navegador pela tailnet (`ALFAMONITOR_URL`). Sem a primeira,
     * vale a segunda — é o default do `config/services.php`, refeito aqui.
     */
    public function test_a_api_usa_o_endereco_interno_e_sem_ele_a_url_da_web(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk();
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/api/v1/clientes'));

        config(['services.alfamonitor.url' => self::BASE.'/web', 'services.alfamonitor.api_url' => self::BASE.'/web']);
        app(ApiDoMonitor::class)->invalidar();

        $this->actingAs($admin)->get(route('monitor.clientes'));
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), self::BASE.'/web/api/v1/clientes'));
    }

    public function test_a_geral_mostra_o_retrato_do_monitor(): void
    {
        $this->monitorNoAr();

        $this->actingAs($this->admin())->get(route('monitor.geral'))
            ->assertOk()
            ->assertSee('dados do monitor de 14:32:31')
            ->assertSeeInOrder(['Servidores online', '4 / 4', 'Sistemas no ar', '5 / 5', 'Dispositivos online', '14 / 42', 'Acessos hoje', '1.354'])
            ->assertSee('Atenção')
            ->assertSee('CLIENTE 1')
            ->assertSee('CONTROL VPS')
            ->assertSee('7,8 GB')
            ->assertSee('Rodando')
            ->assertSee(route('monitor.servidor', 'control-vps'), false)
            ->assertSee('Disponibilidade dos sistemas')
            ->assertSee('99,93%')
            ->assertSee('Acesso · AlfaControl')
            ->assertSee('1.175');

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/api/v1/visao-geral'
            && $r->hasHeader('Authorization', 'Bearer token-de-leitura'));
    }

    /**
     * "Uma pessoa pode ter acesso à parte de monitoramento mas não à de
     * assinaturas da Hostinger" (o dono, 17/10/2026). Sem a permissão, a rota
     * nem é consultada: o dado não chega ao HTML.
     */
    public function test_a_hostinger_so_aparece_para_quem_tem_a_permissao_dela(): void
    {
        $this->monitorNoAr();

        $this->actingAs(User::factory()->membro()->create())->get(route('monitor.geral'))
            ->assertOk()
            ->assertSee('CONTROL VPS')
            ->assertDontSee('KVM 2')
            ->assertDontSee('Renovação automática');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/api/v1/hostinger'));

        $this->actingAs($this->admin())->get(route('monitor.geral'))
            ->assertSee('KVM 2')
            ->assertSeeInOrder(['control.alfasolucoes.cloud', 'em 1 dia', 'Desligada', 'jornada.alfasolucoes.cloud', 'em 11 dias']);
    }

    public function test_o_cache_segura_a_consulta_e_o_webhook_o_invalida(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk();
        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk();
        Http::assertSentCount(1);

        app(ApiDoMonitor::class)->invalidar();

        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_o_alerta_que_chega_pelo_webhook_invalida_o_cache(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk();

        $sistema = Sistema::factory()->create(['slug' => 'alfamonitor']);
        $sistema->forceFill(['monitor_token_hash' => hash('sha256', 'segredo')])->save();

        $this->withHeader('X-AlfaMonitor-Token', $sistema->id.'|segredo')
            ->postJson(route('monitor.eventos'), [
                'v' => 1, 'event_id' => 1, 'incident_id' => 1, 'kind' => 'device', 'entity' => 'AlfaGym:8',
                'severity' => 'critical', 'title' => 'Caiu', 'resolved' => false, 'occurred_at' => now()->timestamp,
            ])->assertOk();

        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk();
        Http::assertSentCount(2);
    }

    public function test_com_o_monitor_fora_mostra_o_ultimo_retrato_bom_e_avisa(): void
    {
        $admin = $this->admin();

        Http::fakeSequence(self::BASE.'/api/v1/clientes*')
            ->push($this->exemplo('clientes'))
            ->push(['erro' => 'retrato_indisponivel'], 503);

        $this->actingAs($admin)->get(route('monitor.clientes'))->assertOk()->assertSee('CLIENTE 5');

        $this->travel(ApiDoMonitor::CACHE_VIVO_S + 1)->seconds();

        $this->actingAs($admin)->get(route('monitor.clientes'))
            ->assertOk()
            ->assertSee('O AlfaMonitor está sem retrato recente')
            ->assertSee('Mostrando o último retrato que chegou')
            ->assertSee('CLIENTE 5');
    }

    public function test_sem_resposta_e_sem_retrato_guardado_diz_que_nao_ha_dados(): void
    {
        Http::fake([self::BASE.'/*' => Http::response('', 500)]);

        $this->actingAs($this->admin())->get(route('monitor.geral'))
            ->assertOk()
            ->assertSee('Sem dados do AlfaMonitor agora.')
            ->assertSee('O AlfaMonitor respondeu com erro (500).');
    }

    public function test_versao_de_contrato_desconhecida_nao_vira_tela_de_zeros(): void
    {
        Http::fake([self::BASE.'/*' => Http::response(['v' => 2, 'topo' => []])]);

        $this->actingAs($this->admin())->get(route('monitor.geral'))
            ->assertOk()
            ->assertSee('mudou o formato da resposta');
    }

    /**
     * Os ajustes que o monitor fez em 17/10/2026, depois dos exemplos: `desde`
     * em ISO e a identidade estável (`acesso`) no Atenção. A tela aceita os
     * dois formatos — a produção do monitor só os manda depois de publicada.
     */
    public function test_o_formato_novo_do_atencao_e_aceito(): void
    {
        $geral = $this->exemplo('visao-geral');
        $geral['atencao'] = [[
            'tipo' => 'dispositivos', 'sev' => 'warn', 'nome' => 'CLIENTE NOVO', 'detalhe' => '2/3 online',
            'desde' => '2026-10-17T13:58:42-03:00',
            'ref' => ['cliente' => 'autoabc', 'acesso' => [['fonte' => 'AlfaControl', 'cliente_id' => 77]]],
        ]];

        Http::fake([self::BASE.'/api/v1/visao-geral*' => Http::response($geral), self::BASE.'/*' => Http::response($this->exemplo('hostinger'))]);

        $this->actingAs($this->admin())->get(route('monitor.geral'))
            ->assertOk()
            ->assertSee(route('monitor.cliente', 'AlfaControl:77'), false)
            ->assertDontSee(route('monitor.cliente', 'autoabc'), false)
            ->assertSee('há 34 minutos');
    }

    public function test_o_cliente_mostra_os_servidores_e_monitores_ligados(): void
    {
        $cliente = $this->exemplo('cliente-AlfaGym-5');
        $cliente['servidores'] = [['nome' => 'CONTROL VPS', 'slug' => 'control-vps', 'online' => true, 'sev' => 'ok']];
        $cliente['monitores'] = [['nome' => 'GYM', 'estado' => 'lento']];

        Http::fake([self::BASE.'/*' => Http::response($cliente)]);

        $this->actingAs($this->admin())->get(route('monitor.cliente', 'AlfaGym:5'))
            ->assertOk()
            ->assertSee(route('monitor.servidor', 'control-vps'), false)
            ->assertSee('CONTROL VPS')
            ->assertSee('GYM');
    }

    public function test_o_servidor_mostra_a_ultima_hora_os_discos_e_os_processos(): void
    {
        $this->monitorNoAr();

        $this->actingAs($this->admin())->get(route('monitor.servidor', 'control-vps'))
            ->assertOk()
            ->assertSee('CONTROL VPS')
            ->assertSee('Última hora')
            ->assertSee('<polyline', false)
            ->assertSee('Discos')
            ->assertSee('/usr/lib/systemd/systemd')
            ->assertSee('VPS na Hostinger');

        $this->actingAs($this->admin())->get(route('monitor.servidor', 'nao-existe'))->assertNotFound();
    }

    public function test_o_acesso_filtra_por_situacao_e_busca(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.acesso', ['fonte' => 'AlfaGym']))
            ->assertOk()
            ->assertSee('CLIENTE 5')
            ->assertSee('Aparelho 1')
            ->assertSee('192.0.2.33')
            ->assertSee('desligado');

        $this->actingAs($admin)->get(route('monitor.acesso', ['fonte' => 'AlfaGym', 'situacao' => 'online']))
            ->assertOk()
            ->assertDontSee('Aparelho 1');

        $this->actingAs($admin)->get(route('monitor.acesso', ['fonte' => 'AlfaGym', 'busca' => 'cliente 6']))
            ->assertOk()
            ->assertSee('CLIENTE 6')
            ->assertDontSee('CLIENTE 5');

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/api/v1/acesso?fonte=AlfaGym&por_pagina=200');
    }

    public function test_clientes_e_a_pagina_do_cliente(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        // O link usa a identidade estável (fonte:cliente_id), e não o id
        // "auto…" do monitor, que muda numa atualização dele.
        $this->actingAs($admin)->get(route('monitor.clientes'))
            ->assertOk()
            ->assertSee(route('monitor.cliente', 'AlfaGym:5'), false);

        $this->actingAs($admin)->get(route('monitor.cliente', 'AlfaGym:5'))
            ->assertOk()
            ->assertSee('CLIENTE 5')
            ->assertSee('Acessos por dia')
            ->assertSee('Quedas da semana')
            ->assertSee('3 h 23 min')
            ->assertSee('76,81%');

        $this->actingAs($admin)->get(route('monitor.cliente', 'AlfaGym:999'))->assertNotFound();
    }

    public function test_incidentes_em_aberto_historico_e_por_cliente(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.incidentes'))
            ->assertOk()
            ->assertSee('Do monitor')
            ->assertSee('Aparelho 4')
            ->assertSee('ainda fora');

        $this->actingAs($admin)->get(route('monitor.incidentes', ['estado' => 'historico', 'dias' => 7, 'visao' => 'cliente']))
            ->assertOk()
            ->assertSee('Sem cliente (disponibilidade)')
            ->assertSee('14/10 15:05');

        Http::assertSent(fn (Request $r) => $r->url() === self::BASE.'/api/v1/incidentes?dias=7&estado=historico&por_pagina=200');
    }

    /**
     * Um incidente de mentira, no formato do contrato.
     *
     * @return array<string, mixed>
     */
    private function incidente(int $n): array
    {
        return [
            'aberto' => false, 'cliente' => null, 'duracao_s' => 60, 'event_id' => $n, 'incident_id' => $n,
            'fim' => Carbon::parse('2026-10-17 00:00:00')->addMinutes($n + 1)->toIso8601String(),
            'inicio' => Carbon::parse('2026-10-17 00:00:00')->addMinutes($n)->toIso8601String(),
            'nome' => sprintf('Incidente %03d', $n), 'origem' => 'AlfaGym', 'recorrencia_7d' => 0,
            'sev' => 'crit', 'tipo' => 'dispositivos', 'fora_do_horario' => false, 'silenciado' => false,
        ];
    }

    /**
     * A API pagina sempre desde 17/10/2026; o Matriz pede o máximo e junta as
     * páginas, senão a tela mostraria só a primeira.
     *
     * @param  list<array<string, mixed>>  $itens
     * @return array<string, mixed>
     */
    private function paginaDeIncidentes(array $itens, int $pagina, int $porPagina): array
    {
        return [
            'v' => 1, 'estado' => 'historico', 'dias' => 30, 'gerado_em' => '2026-10-17T14:32:58-03:00',
            'incidentes' => array_slice($itens, ($pagina - 1) * $porPagina, $porPagina),
            'paginacao' => ['pagina' => $pagina, 'por_pagina' => $porPagina, 'total' => count($itens), 'paginas' => (int) ceil(count($itens) / $porPagina)],
        ];
    }

    public function test_as_paginas_da_api_sao_juntadas_e_a_tela_pagina_em_50(): void
    {
        $itens = array_map(fn ($n) => $this->incidente($n), range(1, 60));

        // O monitor de teste pagina de 25 em 25, para ser preciso juntar 3.
        Http::fake([
            self::BASE.'/api/v1/incidentes*' => fn (Request $r) => Http::response(
                $this->paginaDeIncidentes($itens, (int) ($r->data()['pagina'] ?? 1), 25)
            ),
        ]);
        $admin = $this->admin();

        // Os mais recentes primeiro: a página 1 vai do 60 ao 11.
        $this->actingAs($admin)->get(route('monitor.incidentes', ['estado' => 'historico', 'dias' => 30]))
            ->assertOk()
            ->assertSee('60 incidentes em 30 dias')
            ->assertSee('Incidente 060')
            ->assertSee('Incidente 011')
            ->assertDontSee('Incidente 010')
            ->assertSee('page=2', false);

        $this->actingAs($admin)->get(route('monitor.incidentes', ['estado' => 'historico', 'dias' => 30, 'page' => 2]))
            ->assertOk()
            ->assertSee('Incidente 010')
            ->assertSee('Incidente 001')
            ->assertDontSee('Incidente 011');

        // "Por cliente" fica inteira, senão o grupo contaria errado.
        $this->actingAs($admin)->get(route('monitor.incidentes', ['estado' => 'historico', 'dias' => 30, 'visao' => 'cliente']))
            ->assertOk()
            ->assertSee('60 incidentes')
            ->assertSee('Incidente 001')
            ->assertSee('Incidente 060');

        Http::assertSent(fn (Request $r) => (string) ($r->data()['por_pagina'] ?? '') === '200' && ! isset($r->data()['pagina']));
        Http::assertSent(fn (Request $r) => (string) ($r->data()['pagina'] ?? '') === '3');
        Http::assertNotSent(fn (Request $r) => (string) ($r->data()['pagina'] ?? '') === '4');
    }

    public function test_se_uma_pagina_falha_a_tela_nao_mostra_a_lista_pela_metade(): void
    {
        $itens = array_map(fn ($n) => $this->incidente($n), range(1, 30));

        Http::fake([
            self::BASE.'/api/v1/incidentes*' => fn (Request $r) => isset($r->data()['pagina'])
                ? Http::response(['erro' => 'interno'], 500)
                : Http::response($this->paginaDeIncidentes($itens, 1, 20)),
        ]);

        $this->actingAs($this->admin())->get(route('monitor.incidentes', ['estado' => 'historico', 'dias' => 30]))
            ->assertOk()
            ->assertSee('O AlfaMonitor não entregou a lista inteira.')
            ->assertDontSee('Incidente 030');
    }

    public function test_a_busca_de_clientes_acha_quem_esta_em_outra_pagina_da_api(): void
    {
        $clientes = $this->exemplo('clientes');
        $todos = $clientes['clientes'];
        $total = count($todos);

        Http::fake([
            self::BASE.'/api/v1/clientes*' => function (Request $r) use ($clientes, $todos, $total) {
                $pagina = (int) ($r->data()['pagina'] ?? 1);

                return Http::response(['clientes' => array_slice($todos, ($pagina - 1) * 2, 2),
                    'paginacao' => ['pagina' => $pagina, 'por_pagina' => 2, 'total' => $total, 'paginas' => (int) ceil($total / 2)]] + $clientes);
            },
        ]);

        $ultimo = end($todos)['nome'];

        $this->actingAs($this->admin())->get(route('monitor.clientes', ['busca' => $ultimo]))
            ->assertOk()
            ->assertSee($total.' clientes no monitor')
            ->assertSee($ultimo);
    }

    public function test_o_acesso_junta_as_paginas_de_cada_fonte(): void
    {
        $acesso = $this->exemplo('acesso-AlfaGym');
        $clientes = $acesso['fontes'][0]['clientes'];

        Http::fake([
            self::BASE.'/api/v1/acesso*' => function (Request $r) use ($acesso, $clientes) {
                $pagina = (int) ($r->data()['pagina'] ?? 1);
                $acesso['fontes'][0]['clientes'] = array_slice($clientes, $pagina - 1, 1);
                $acesso['fontes'][0]['paginacao'] = ['pagina' => $pagina, 'por_pagina' => 1, 'total' => count($clientes), 'paginas' => count($clientes)];

                return Http::response($acesso);
            },
        ]);

        $resposta = $this->actingAs($this->admin())->get(route('monitor.acesso', ['fonte' => 'AlfaGym']))->assertOk();

        foreach ($clientes as $cliente) {
            $resposta->assertSee($cliente['nome']);
        }

        Http::assertSentCount(count($clientes));
    }

    public function test_o_relatorio_e_a_exportacao(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.relatorio', ['janela' => '7d']))
            ->assertOk()
            ->assertSee('Saúde geral')
            ->assertSee('93 / 100')
            ->assertSee('Recomendações')
            ->assertSee('O que fazer:')
            ->assertSee('Disponibilidade e SLA')
            ->assertSee('Exportar CSV');

        $csv = $this->actingAs($admin)->get(route('monitor.relatorio.exportar', ['janela' => '7d']));
        $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Saúde geral,93', $csv->getContent());
    }

    public function test_exportar_exige_imprimir(): void
    {
        $this->monitorNoAr();
        $this->seed(PerfilPermissaoSeeder::class);

        $perfil = Perfil::create(['slug' => 'so-olha', 'nome' => 'Só olha']);
        $perfil->permissoes()->attach(Permissao::where('recurso', 'monitor')->value('id'), [
            'ler' => true, 'incluir' => false, 'editar' => false, 'imprimir' => false, 'excluir' => false,
        ]);
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach($perfil->id);

        $this->actingAs($usuario)->get(route('monitor.relatorio'))->assertOk()->assertDontSee('Exportar CSV');
        $this->actingAs($usuario)->get(route('monitor.relatorio.exportar'))->assertForbidden();
    }

    public function test_o_menu_mostra_o_monitor_so_a_quem_tem_a_permissao(): void
    {
        $this->monitorNoAr();
        $this->seed(PerfilPermissaoSeeder::class);

        $this->actingAs($this->admin())->get(route('dashboard'))->assertSee(route('monitor.geral'), false);

        $exibicao = User::factory()->semPerfil()->create();
        $exibicao->perfis()->attach(Perfil::where('slug', 'exibicao')->value('id'));

        $this->actingAs($exibicao)->get(route('tarefas.index'))->assertDontSee(route('monitor.geral'), false);
        $this->actingAs($exibicao)->get(route('monitor.geral'))->assertForbidden();
    }

    public function test_revenda_nao_entra(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);

        $this->actingAs(User::factory()->create(['revenda_id' => $revenda->id]))
            ->get(route('monitor.geral'))->assertForbidden();
    }

    public function test_as_telas_vivas_se_atualizam_e_o_relatorio_nao(): void
    {
        $this->monitorNoAr();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('monitor.geral'))->assertSee('monitorTela({ automatico: true })', false);
        $this->actingAs($admin)->get(route('monitor.relatorio'))->assertSee('monitorTela({ automatico: false })', false);
    }
}
