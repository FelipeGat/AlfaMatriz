<?php

namespace Tests\Feature\Secretario;

use App\Models\SecretarioVixOrganize;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaGrupo;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\SecretarioVixOrganizePedido;
use App\Services\CentralIa\AgentesService;
use App\Services\Secretario\VixOrganize\Cliente;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Projetos da VixCard pelo VixOrganize (docs/specs/secretario-vixorganize).
 * O endereço é um IP público literal: o teste não depende de DNS.
 */
class VixOrganizeTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    private const URL = 'https://1.1.1.1';

    private const CHAVE = 'chave-de-escrita-com-mais-de-16';

    private function resumo(array $extra = []): array
    {
        return $extra + ['id' => 1, 'slug' => 'totem-rfid', 'name' => 'Totem RFID', 'rank' => 1, 'status' => 'em_andamento', 'status_label' => 'Em andamento',
            'owner' => 'Victor', 'current_phase' => 'Planejamento', 'progress' => 25, 'due_date' => '2026-10-10', 'completed_at' => null, 'updated_at' => '2026-10-17T10:00:00-03:00'];
    }

    private function detalhe(array $extra = []): array
    {
        return $this->resumo($extra) + ['description' => 'Totem para a recepção', 'phases' => [
            ['id' => 11, 'name' => 'Descoberta', 'position' => 1, 'completed_at' => '2026-10-15T10:00:00-03:00', 'steps' => [
                ['id' => 101, 'title' => 'Definir objetivo', 'notes' => null, 'is_done' => true, 'done_at' => '2026-10-15T10:00:00-03:00', 'done_by' => 'Victor']]],
            ['id' => 12, 'name' => 'Planejamento', 'position' => 2, 'completed_at' => null, 'steps' => [
                ['id' => 102, 'title' => 'Cotar fornecedores', 'notes' => 'três cotações', 'is_done' => false, 'done_at' => null, 'done_by' => null]]],
        ]];
    }

    /** Resposta forçada para tudo (o primeiro Http::fake vale até o fim do teste). */
    private $forcar = null;

    private function fakeOrganize(): void
    {
        $rotas = [
            '/api/projetos/totem-rfid' => fn () => Http::response(['data' => $this->detalhe()]),
            '/api/projetos/totem-rfid/passos' => fn () => Http::response(['data' => ['step_id' => 103, 'phase_id' => 12, 'project' => $this->detalhe(['progress' => 33])]], 201),
            '/api/projetos/totem-rfid/passos/102/check' => fn () => Http::response(['data' => $this->detalhe(['progress' => 50])]),
            '/api/projetos/totem-rfid/passos/102/registros' => fn () => Http::response(['data' => ['comment_id' => 9]], 201),
            '/api/projetos/totem-rfid/status' => fn () => Http::response(['data' => $this->detalhe(['status' => 'pausado', 'status_label' => 'Pausado'])]),
        ];
        Http::fake(function (Request $r) use ($rotas) {
            if ($this->forcar) {
                return ($this->forcar)();
            }
            $caminho = parse_url($r->url(), PHP_URL_PATH);
            if ($caminho === '/api/projetos') {
                return $r->method() === 'GET'
                    ? Http::response(['data' => [$this->resumo(), $this->resumo(['id' => 2, 'slug' => 'antigo', 'name' => 'Antigo', 'rank' => 2, 'status' => 'concluido', 'status_label' => 'Concluído'])]])
                    : Http::response(['data' => $this->detalhe(['slug' => 'novo', 'name' => $r['name'], 'rank' => 3, 'status' => 'aguardando', 'status_label' => 'Aguardando', 'due_date' => null])], 201);
            }

            return isset($rotas[$caminho]) ? $rotas[$caminho]() : Http::response(['message' => 'Not Found'], 404);
        });
    }

    private function conectado(?User $u = null): User
    {
        $u ??= $this->dono(telegram: false);
        $this->fakeOrganize();
        $this->actingAs($u)->post(route('secretario.vixorganize.conectar'), ['url' => self::URL.'/', 'chave' => self::CHAVE, 'autor_email' => 'felipe@vix.local'])
            ->assertRedirect(route('secretario.configuracoes').'#vixorganize');

        return $u;
    }

    public function test_conecta_le_a_fila_e_guarda_a_chave_cifrada(): void
    {
        $u = $this->conectado();

        $c = SecretarioVixOrganize::de($u)->sole();
        $this->assertSame(self::URL, $c->url, 'Sem a barra do fim');
        $this->assertSame(self::CHAVE, $c->chave);
        $this->assertStringNotContainsString(self::CHAVE, (string) \DB::table('secretario_vixorganize')->value('chave'));
        $this->assertSame(['totem-rfid', 'antigo'], array_column($c->projetos(), 'slug'));
        $this->assertCount(2, $c->projeto('totem-rfid')['phases'], 'O aberto vem com o detalhe');
        Http::assertSent(fn (Request $r) => $r->hasHeader('X-Api-Key', self::CHAVE) && $r->hasHeader('X-Vix-Autor', 'felipe@vix.local'));

        $this->get(route('secretario.configuracoes'))->assertOk()->assertSee(self::URL)->assertDontSee(self::CHAVE);
    }

    public function test_chave_recusada_nao_guarda_nada(): void
    {
        $this->fakeOrganize();
        $this->forcar = fn () => Http::response(['message' => 'Unauthorized'], 401);
        $u = $this->dono(telegram: false);

        $this->actingAs($u)->post(route('secretario.vixorganize.conectar'), ['url' => self::URL, 'chave' => self::CHAVE])
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'recusou a chave'));
        $this->assertSame(0, SecretarioVixOrganize::count());
    }

    public function test_endereco_http_ou_interno_e_recusado_e_redirect_nao_e_seguido(): void
    {
        $this->fakeOrganize();
        $u = $this->dono(telegram: false);
        foreach (['http://1.1.1.1', 'https://10.0.0.5', 'https://127.0.0.1', 'https://usuario@1.1.1.1', 'ftp://1.1.1.1'] as $url) {
            $this->actingAs($u)->post(route('secretario.vixorganize.conectar'), ['url' => $url, 'chave' => self::CHAVE])->assertSessionHas('erro');
        }
        Http::assertNothingSent();

        $this->forcar = fn () => Http::response('', 302, ['Location' => 'https://outro.example/api/projetos']);
        $this->post(route('secretario.vixorganize.conectar'), ['url' => self::URL, 'chave' => self::CHAVE])
            ->assertSessionHas('erro', fn ($m) => str_contains($m, 'redireciona'));
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'outro.example'));
        $this->assertSame(0, SecretarioVixOrganize::count());
    }

    public function test_liberacao_local_nao_vale_em_producao(): void
    {
        config(['services.vixorganize.permite_local' => true]);
        $this->assertSame('http://10.0.0.5', Cliente::normalizarUrl('http://10.0.0.5')[0], 'Fora da produção, a liberação vale');

        $this->app->detectEnvironment(fn () => 'production');
        foreach (['http://1.1.1.1', 'https://10.0.0.5', 'https://127.0.0.1'] as $url) {
            $this->assertNull(Cliente::normalizarUrl($url)[0], $url);
        }
    }

    public function test_tela_mostra_a_fila_e_o_kanban_e_envia_as_demandas(): void
    {
        $u = $this->conectado();

        $this->get(route('secretario.vixorganize'))->assertOk()
            ->assertSeeInOrder(['Totem RFID', 'Em andamento', '25%', 'venceu 10/10'])
            ->assertSee('Concluídos (1)')->assertSee('Cotar fornecedores')->assertSee('três cotações')->assertSee('Enviar demanda');

        $this->post(route('secretario.vixorganize.passos', 'totem-rfid'), ['titulo' => 'Cotar leitores UHF', 'fase' => 12, 'notas' => 'ver 3'])
            ->assertSessionHas('status', 'Demanda enviada ao VixOrganize.');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/totem-rfid/passos')
            && $r['title'] === 'Cotar leitores UHF' && $r['phase_id'] === 12 && $r['notes'] === 'ver 3' && $r->hasHeader('X-Vix-Origem', 'AlfaMatriz'));
        $this->assertSame(33, SecretarioVixOrganize::de($u)->sole()->projeto('totem-rfid')['progress'], 'O retrato guarda o projeto que voltou');

        $this->post(route('secretario.vixorganize.marcar', ['totem-rfid', 102]), ['feito' => 1])->assertSessionHas('status', 'Passo marcado.');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/passos/102/check') && $r['done'] === true);

        $this->post(route('secretario.vixorganize.registrar', ['totem-rfid', 102]), ['texto' => 'Cliente pediu até sexta'])->assertSessionHas('status');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/passos/102/registros') && $r['body'] === 'Cliente pediu até sexta');

        $this->post(route('secretario.vixorganize.status', 'totem-rfid'), ['status' => 'pausado'])->assertSessionHas('status');
        $this->assertSame('pausado', SecretarioVixOrganize::de($u)->sole()->projeto('totem-rfid')['status']);

        $this->post(route('secretario.vixorganize.projetos'), ['name' => 'Projeto novo'])
            ->assertRedirect(route('secretario.vixorganize', ['projeto' => 'novo']));
        $this->assertNotNull(SecretarioVixOrganize::de($u)->sole()->projeto('novo'));
    }

    public function test_falha_no_envio_volta_com_o_texto_e_o_motivo(): void
    {
        $u = $this->conectado();
        $this->forcar = fn () => Http::response(['message' => 'Fase não é deste projeto.'], 422);

        $this->from(route('secretario.vixorganize'))->post(route('secretario.vixorganize.passos', 'totem-rfid'), ['titulo' => 'Demanda', 'fase' => 999])
            ->assertRedirect(route('secretario.vixorganize'))->assertSessionHasInput('titulo', 'Demanda')
            ->assertSessionHas('erro', 'O VixOrganize recusou: Fase não é deste projeto.');
    }

    public function test_leitura_agendada_guarda_erro_sem_perder_o_retrato(): void
    {
        $u = $this->conectado();
        $this->forcar = fn () => Http::response('', 500);

        $this->artisan('secretario:vixorganize')->assertSuccessful();
        $c = SecretarioVixOrganize::de($u)->sole();
        $this->assertSame('O VixOrganize respondeu com erro (500).', $c->erro);
        $this->assertNotNull($c->projeto('totem-rfid'));
        $this->get(route('secretario.vixorganize'))->assertOk()->assertSee('Última leitura falhou')->assertSee('Totem RFID');
    }

    public function test_central_de_gestao_mostra_os_projetos_abertos(): void
    {
        $this->conectado();

        $this->get(route('secretario.acompanhamentos'))->assertOk()
            ->assertSee('Totem RFID')->assertSee('#1 na fila · Planejamento · 25%')->assertSee('VixOrganize')
            ->assertSee(route('secretario.vixorganize', ['projeto' => 'totem-rfid']), false)
            ->assertDontSee('Antigo');
    }

    public function test_outra_conta_nao_ve_nem_envia(): void
    {
        $this->conectado();
        $outro = $this->dono(telegram: false);
        $antes = count(Http::recorded());

        $this->actingAs($outro)->get(route('secretario.vixorganize'))->assertRedirect(route('secretario.configuracoes').'#vixorganize');
        $this->post(route('secretario.vixorganize.passos', 'totem-rfid'), ['titulo' => 'x'])->assertNotFound();
        $this->post(route('secretario.vixorganize.marcar', ['totem-rfid', 102]), ['feito' => 1])->assertNotFound();
        $this->get(route('secretario.acompanhamentos'))->assertOk()->assertDontSee('Totem RFID');
        $this->get(route('secretario.meu-dia'))->assertOk()->assertDontSee(route('secretario.vixorganize'));
        $this->assertCount($antes, Http::recorded(), 'Nada saiu para o VixOrganize');
    }

    /** O VixOrganize saiu do uso (Felipe, 18/10/2026): o menu não o oferece mais, nem conectado. */
    public function test_menu_nao_oferece_mais_o_vixorganize_e_desligar_apaga(): void
    {
        $u = $this->conectado();
        $this->get(route('secretario.meu-dia'))->assertOk()->assertDontSee(route('secretario.vixorganize'));

        $this->delete(route('secretario.vixorganize.desconectar'))->assertSessionHas('status');
        $this->assertSame(0, SecretarioVixOrganize::count());
        $this->get(route('secretario.meu-dia'))->assertOk()->assertDontSee(route('secretario.vixorganize'));
    }

    // --- Central de IA (FR-008+): o chat lê a fila e envia só com "sim" ---

    private function comRunner(?User $u = null): array
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u ??= $this->dono(telegram: false);
        $grupo = CentralIaGrupo::create(['user_id' => $u->id, 'nome' => 'VixCard']);
        $this->conectado($u);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex', 'claude'], 'projetos' => [], 'visto_em' => now()]);

        return [$u, $grupo, CentralIaTarefa::find(SecretarioVixOrganize::de($u)->value('tarefa_id'))];
    }

    private function falar(string $texto, ?CentralIaTarefa $chat = null)
    {
        return $this->postJson(route('central-ia.enviar'), ['client_id' => (string) Str::uuid(), 'conteudo' => $texto, 'agente' => 'claude',
            'projeto' => '', 'tarefa_id' => $chat?->id]);
    }

    private function job(User $u): array
    {
        return app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
    }

    public function test_conectar_cria_o_chat_de_business_no_grupo_vixcard_uma_vez(): void
    {
        [$u, $grupo, $chat] = $this->comRunner();

        $this->assertTrue($chat->ehAssunto());
        $this->assertSame('Projetos VixCard (VixOrganize)', $chat->titulo);
        $this->assertSame($grupo->id, $chat->grupo_id);
        $this->post(route('secretario.vixorganize.conectar'), ['url' => self::URL, 'autor_email' => 'felipe@vix.local'])->assertSessionHas('status');
        $this->assertSame(1, CentralIaTarefa::assuntos()->count(), 'Reconectar não duplica o chat');
    }

    public function test_chat_le_a_fila_propoe_e_so_envia_com_sim(): void
    {
        [$u, , $chat] = $this->comRunner();

        $this->falar('lança cotar leitores UHF no totem e marca cotar fornecedores', $chat)->assertCreated();
        $job = $this->job($u);
        $this->assertStringContainsString('VixOrganize — fila de projetos da VixCard', $job['contexto']);
        $this->assertStringContainsString('slug: totem-rfid', $job['contexto']);
        $this->assertStringContainsString('Cotar fornecedores (passo 102)', $job['contexto']);
        $this->assertTrue(CentralIaExecucao::find($job['id'])->roteamento['vixorganize']);

        $bloco = json_encode(['acoes' => [
            ['acao' => 'nova_demanda', 'projeto' => 'totem-rfid', 'titulo' => 'Cotar leitores UHF', 'fase_id' => 12, 'notas' => null],
            ['acao' => 'marcar', 'projeto' => 'totem-rfid', 'passo_id' => 102, 'feito' => true],
            ['acao' => 'marcar', 'projeto' => 'totem-rfid', 'passo_id' => 999, 'feito' => true],
            ['acao' => 'status', 'projeto' => 'nao-existe', 'status' => 'pausado'],
        ]]);
        app(AgentesService::class)->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Preparei os dois envios.\n<<<VIXORGANIZE {$bloco} VIXORGANIZE>>>"]);
        $resposta = CentralIaMensagem::where('tarefa_id', $chat->id)->latest('id')->value('conteudo');
        $this->assertStringContainsString('Para enviar ao VixOrganize', $resposta);
        $this->assertStringContainsString('1. Nova demanda em Totem RFID › Planejamento: «Cotar leitores UHF»', $resposta);
        $this->assertStringContainsString('2. Marcar como feito em Totem RFID: «Cotar fornecedores»', $resposta);
        $this->assertStringContainsString('2 ação(ões) ignorada(s)', $resposta);
        $this->assertStringNotContainsString('<<<VIXORGANIZE', $resposta);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        $this->falar('sim', $chat)->assertCreated();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/totem-rfid/passos') && $r['title'] === 'Cotar leitores UHF' && $r['phase_id'] === 12);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/passos/102/check') && $r['done'] === true);
        $this->assertStringContainsString("Enviado ao VixOrganize:\n✓ Nova demanda", CentralIaMensagem::where('tarefa_id', $chat->id)->latest('id')->value('conteudo'));
        $this->assertSame(SecretarioVixOrganizePedido::ENVIADO, SecretarioVixOrganizePedido::sole()->status);
        $this->assertSame(1, CentralIaExecucao::count(), 'O "sim" não vai à IA');
    }

    public function test_nao_descarta_e_falha_de_envio_aparece_na_conversa(): void
    {
        [$u, , $chat] = $this->comRunner();
        $propor = function () use ($u, $chat) {
            $this->falar('marca cotar fornecedores', $chat)->assertCreated();
            $job = $this->job($u);
            app(AgentesService::class)->concluir($u, $job['id'], ['status' => 'concluida',
                'resposta' => '<<<VIXORGANIZE {"acoes":[{"acao":"marcar","projeto":"totem-rfid","passo_id":102,"feito":true}]} VIXORGANIZE>>>']);
        };

        $propor();
        $this->falar('não', $chat)->assertCreated();
        $this->assertSame(SecretarioVixOrganizePedido::DESCARTADO, SecretarioVixOrganizePedido::sole()->status);
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');

        $propor();
        $this->forcar = fn () => Http::response(['message' => 'Unauthorized'], 401);
        $this->falar('sim', $chat)->assertCreated();
        $this->assertStringContainsString('✗ Marcar como feito em Totem RFID: «Cotar fornecedores» — O VixOrganize recusou a chave',
            CentralIaMensagem::where('tarefa_id', $chat->id)->latest('id')->value('conteudo'));
    }

    public function test_fora_do_chat_a_fila_so_vai_quando_o_pedido_cita_o_organize(): void
    {
        [$u] = $this->comRunner();

        $this->falar('qual o prazo do contrato da Condonal?')->assertCreated();
        $this->assertStringNotContainsString('VixOrganize — fila', (string) $this->job($u)['contexto']);

        $this->falar('como está a fila do organize?')->assertCreated();
        $this->assertStringContainsString('VixOrganize — fila', (string) $this->job($u)['contexto']);
    }

    public function test_bloco_sem_a_fila_no_contexto_nao_vira_pedido(): void
    {
        [$u] = $this->comRunner();

        $this->falar('qual o prazo do contrato da Condonal?')->assertCreated();
        $job = $this->job($u);
        app(AgentesService::class)->concluir($u, $job['id'], ['status' => 'concluida',
            'resposta' => 'Texto. <<<VIXORGANIZE {"acoes":[{"acao":"status","projeto":"totem-rfid","status":"concluido"}]} VIXORGANIZE>>>']);
        $this->assertSame(0, SecretarioVixOrganizePedido::count());
        $this->falar('sim')->assertCreated();
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }
}
