<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\TokenDoQuadro;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * T-472: o agente da Central de IA pedia para abrir tarefa e o `/mcp` dava
 * 401. Agora cada pedido leva um token do quadro em nome de QUEM PEDIU, que
 * morre com o pedido — e só para quem pode.
 */
class QuadroDoAgenteTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(bool $admin = false): User
    {
        $u = User::factory()->create();
        $u->perfis()->sync(array_filter([Perfil::where('slug', 'central_ia')->value('id'), $admin ? Perfil::where('slug', 'admin')->value('id') : null]));

        return $u->fresh();
    }

    private function entregue(User $u, array $roteamento = []): array
    {
        config(['central_ia.canal_exclusivo' => false]);
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'mac', 'agentes' => ['claude'], 'projetos' => ['AlfaGym'], 'visto_em' => now()]);
        $svc = app(AgentesService::class);
        $e = $svc->pedir($u, 'abre uma tarefa do TotalPass', ['projeto' => 'AlfaGym', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'abre uma tarefa do TotalPass'], 'web', 'q'.uniqid());
        if ($roteamento) {
            $e->update(['roteamento' => array_merge($e->roteamento ?? [], $roteamento)]);
        }
        $job = $svc->proxima($u, $r);
        $this->assertNotNull($job, 'a máquina pegou o pedido');

        return [$job, $e->fresh()];
    }

    private function mcp(string $token, string $metodo, array $params = [])
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $metodo, 'params' => $params],
            ['Accept' => 'application/json, text/event-stream']);
    }

    private function tokensDoQuadro(User $u)
    {
        return PersonalAccessToken::where('tokenable_id', $u->id)->where('name', 'like', TokenDoQuadro::PREFIXO.'%')->get();
    }

    public function test_o_pedido_leva_um_token_do_quadro_em_nome_de_quem_pediu_e_ele_morre_com_o_pedido(): void
    {
        $u = $this->pessoa(true);
        [$job, $e] = $this->entregue($u);

        $this->assertNotEmpty($job['quadro']['token']);
        $this->assertSame($u->name, $job['quadro']['conta']);
        $t = $this->tokensDoQuadro($u)->sole();
        $this->assertSame(['mcp'], $t->abilities, 'o token abre o quadro e mais nada');
        $this->assertSame(TokenDoQuadro::PREFIXO.$e->id, $t->name);
        $teto = app(AgentesService::class)->teto($e) + TokenDoQuadro::FOLGA_MINUTOS;
        $this->assertEqualsWithDelta(now()->addMinutes($teto)->timestamp, $t->expires_at->timestamp, 5, 'vence sozinho se a máquina sumir');

        // A tarefa nasce com o nome de quem pediu.
        $this->mcp($job['quadro']['token'], 'tools/call', ['name' => 'criar_tarefa', 'arguments' => ['titulo' => 'TotalPass no AlfaGym', 'tipo' => 'desenvolvimento']])->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame($u->id, Tarefa::where('titulo', 'TotalPass no AlfaGym')->value('criado_por_id'));

        // O registro diz qual pedido abriu a porta, e não o token.
        $evento = DB::table('central_ia_eventos')->where('tipo', 'inicio')->sole();
        $this->assertStringContainsString('com acesso ao quadro', $evento->descricao);
        $this->assertSame($e->id, (int) $evento->execucao_id);
        $this->assertStringNotContainsString(explode('|', $job['quadro']['token'])[1], $evento->descricao);

        app(AgentesService::class)->concluir($u, $e->id, ['status' => CentralIaExecucao::CONCLUIDA, 'resposta' => 'feito']);
        $this->assertCount(0, $this->tokensDoQuadro($u), 'concluído, o token some');
        $this->mcp($job['quadro']['token'], 'tools/list')->assertUnauthorized();
    }

    public function test_qualquer_caminho_que_encerra_a_execucao_apaga_o_token(): void
    {
        $u = $this->pessoa(true);
        [, $e] = $this->entregue($u);
        $this->assertCount(1, $this->tokensDoQuadro($u));
        // Órfã do runner reiniciado, máquina desconectada, teto: todos mudam o status pelo modelo.
        $e->update(['status' => CentralIaExecucao::EXPIRADA, 'concluida_em' => now()]);
        $this->assertCount(0, $this->tokensDoQuadro($u));
    }

    public function test_quem_nao_foi_liberado_nao_recebe_token_e_o_agente_sabe_por_que(): void
    {
        $admin = $this->pessoa(true);
        $membro = $this->pessoa();
        // Libera a execução, mas não o quadro.
        $politica = ['revisao' => 0, 'habilitados' => ['claude' => true, 'codex' => false, 'copilot' => false],
            'pedidos_dia' => ['claude' => null, 'codex' => 0, 'copilot' => 0], 'limite_total' => null, 'paralelo' => 1, 'execucao_bloqueada' => false];
        $this->actingAs($admin)->putJson(route('central-ia.usuarios.politica', $membro->id), $politica)
            ->assertOk()->assertJsonPath('politica.quadro', false);

        [$job] = $this->entregue($membro);
        $this->assertNull($job['quadro']['token']);
        $this->assertSame(TokenDoQuadro::SEM_LIBERACAO, $job['quadro']['motivo']);
        $this->assertCount(0, $this->tokensDoQuadro($membro));

        // Liberado pelo administrador, o próximo pedido leva o token dela.
        $this->actingAs($admin)->putJson(route('central-ia.usuarios.politica', $membro->id), ['revisao' => 1, 'quadro' => true] + $politica)
            ->assertOk()->assertJsonPath('politica.quadro', true);
        CentralIaExecucao::where('user_id', $membro->id)->get()->each->update(['status' => CentralIaExecucao::CONCLUIDA]);
        CentralIaRunner::where('user_id', $membro->id)->delete();
        [$job] = $this->entregue($membro);
        $this->assertNotEmpty($job['quadro']['token']);
        $this->assertSame($membro->id, $this->tokensDoQuadro($membro)->sole()->tokenable_id);
    }

    public function test_pedido_sem_ferramentas_nunca_recebe_o_quadro(): void
    {
        $u = $this->pessoa(true);
        [$job] = $this->entregue($u, ['sem_ferramentas' => true]);
        $this->assertSame('nenhum', $job['acesso']);
        $this->assertNull($job['quadro']);
        $this->assertCount(0, $this->tokensDoQuadro($u));
    }

    public function test_o_runner_monta_o_quadro_sem_por_o_token_em_argumento(): void
    {
        $politica = base_path('deploy/central-ia/politica.mjs');
        $script = <<<JS
            import('{$politica}').then(m => {
                const c = m.configDoQuadro('claude', 'https://matriz.exemplo/', 'SEGREDO', '/tmp/x/central-ia-quadro.json');
                const x = m.configDoQuadro('codex', 'https://matriz.exemplo', 'SEGREDO');
                const p = m.configDoQuadro('copilot', 'https://matriz.exemplo', 'SEGREDO', '/tmp/x/central-ia-quadro.json');
                console.log(JSON.stringify({
                    claude: c, codex: x, copilot: p, sem: m.configDoQuadro('claude', 'https://m', null, '/tmp/q'),
                    leitura: m.permissoes('claude', 'leitura', { quadro: true }),
                    restrito: m.permissoes('claude', 'restrito', { quadro: true }),
                    nenhum: m.permissoes('claude', 'leitura', { quadro: true, semFerramentas: true }),
                    comToken: m.instrucaoDoQuadro({ token: 't', conta: 'Ana' }),
                    semToken: m.instrucaoDoQuadro({ token: null, motivo: 'sem liberação' }),
                }));
            });
            JS;
        $r = Process::run(['node', '--input-type=module', '-e', $script]);
        $this->assertTrue($r->successful(), $r->errorOutput());
        $o = json_decode($r->output(), true);

        foreach (['claude', 'codex', 'copilot'] as $a) {
            $this->assertStringNotContainsString('SEGREDO', implode(' ', $o[$a]['args']), "{$a}: token fora dos argumentos");
        }
        $this->assertSame(['--mcp-config', '/tmp/x/central-ia-quadro.json'], $o['claude']['args']);
        $this->assertSame('https://matriz.exemplo/mcp', json_decode($o['claude']['json'], true)['mcpServers']['quadro']['url']);
        $this->assertSame('Bearer SEGREDO', json_decode($o['claude']['json'], true)['mcpServers']['quadro']['headers']['Authorization']);
        $this->assertSame(['ALFAMATRIZ_MCP_TOKEN' => 'SEGREDO'], $o['codex']['env']);
        $this->assertSame(['--additional-mcp-config', '@/tmp/x/central-ia-quadro.json'], $o['copilot']['args']);
        $this->assertSame([], $o['sem']['args']);
        $this->assertContains('mcp__quadro', $o['leitura'], 'em leitura, o pedido de abrir tarefa precisa passar');
        $this->assertContains('mcp__quadro', $o['restrito']);
        $this->assertNotContains('mcp__quadro', $o['nenhum']);
        $this->assertContains('Read(**/central-ia-quadro.json)', $o['leitura'], 'o arquivo do token não se lê pela ferramenta');
        $this->assertStringContainsString('em nome de Ana', $o['comToken']);
        $this->assertStringContainsString('sem acesso neste pedido', $o['semToken']);
    }
}
