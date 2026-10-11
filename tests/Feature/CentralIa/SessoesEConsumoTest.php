<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Consumo das IAs, projetos recentes no git e continuar pelo cockpit (ou
 * pelo Telegram) uma conversa começada no VS Code.
 */
class SessoesEConsumoTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Protocolo anterior à ativação da troca de canal.
    }


    private const SESSAO = 'bc6adf44-a516-40dd-a659-e28fc190d90d';

    private function comCentral(): User
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $user;
    }

    private function batida(User $user, array $extra = []): void
    {
        Sanctum::actingAs($user, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.batida'), $extra + [
            'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'], 'projetos' => ['gestor', 'alfahome', 'vixcard'],
            'estado' => [
                'gestor' => ['git' => true, 'branch' => 'feature/custo', 'pendentes' => 1, 'ultimo_commit' => now()->subHour()->toIso8601String()],
                'vixcard' => ['git' => true, 'branch' => 'Felipe', 'pendentes' => 33, 'ultimo_commit' => now()->subDays(20)->toIso8601String()],
            ],
            'sessoes' => [[
                'agente' => 'claude', 'id' => self::SESSAO, 'projeto' => 'gestor', 'cwd' => '/home/x/projetos',
                'ultima_atividade' => now()->subMinutes(30)->toIso8601String(), 'mensagens' => 403,
            ]],
        ])->assertOk();
    }

    public function test_consumo_resumido_por_ia_com_cota_e_so_campos_permitidos(): void
    {
        $user = $this->comCentral();
        $hoje = now()->toDateString();
        $this->batida($user, ['consumo' => [
            'linhas' => [
                ['dia' => $hoje, 'agente' => 'claude', 'projeto' => 'gestor', 'modelo' => 'opus', 'entrada' => 1000, 'saida' => 500, 'cache_leitura' => 90000, 'texto' => 'conteúdo secreto'],
                ['dia' => $hoje, 'agente' => 'copilot', 'projeto' => 'alfahome', 'modelo' => 'x', 'entrada' => 200, 'saida' => 100, 'premium' => 3],
                ['dia' => now()->subDays(3)->toDateString(), 'agente' => 'codex', 'projeto' => 'gestor', 'entrada' => 7000, 'saida' => 0],
                ['dia' => 'ontem', 'agente' => 'claude', 'entrada' => 1],
            ],
            'cota_codex' => ['em' => now()->toIso8601String(), 'primaria' => ['usado_pct' => 19, 'janela_min' => 300], 'semanal' => ['usado_pct' => 3]],
        ]]);

        $this->assertStringNotContainsString('conteúdo secreto', json_encode(CentralIaRunner::first()->consumo));

        $c = $this->actingAs($user)->getJson(route('central-ia.painel'))->assertOk()->json('consumo');
        $porAgente = collect($c['por_agente'])->keyBy('agente');
        $this->assertSame(1500, $porAgente['claude']['hoje']['entrada'] + $porAgente['claude']['hoje']['saida']);
        $this->assertSame(0, $porAgente['codex']['hoje']['entrada']);
        $this->assertSame(7000, $porAgente['codex']['semana']['entrada']);
        $this->assertEquals(3, $porAgente['copilot']['hoje']['premium']);
        $this->assertEquals(19, $c['cota_codex']['primaria']['usado_pct']);
        $this->assertSame('gestor', $c['projetos_hoje'][0]['projeto']);
    }

    public function test_recentes_no_git_pelo_ultimo_commit(): void
    {
        $user = $this->comCentral();
        $this->batida($user);

        $recentes = $this->actingAs($user)->getJson(route('central-ia.painel'))->json('recentes');

        $this->assertSame(['gestor', 'vixcard'], array_column($recentes, 'nome'));
        $this->assertSame(33, $recentes[1]['pendentes']);
    }

    /**
     * A continuação trabalha na PASTA da sessão (o que ela deixou sem commit
     * está lá), retoma uma CÓPIA no Claude na primeira vez e a cópia daí em
     * diante.
     */
    public function test_continuar_sessao_do_vscode_retoma_uma_copia_e_segue_nela(): void
    {
        $user = $this->comCentral();
        $this->batida($user);

        $r = $this->actingAs($user)->postJson(route('central-ia.sessoes.continuar'), ['agente' => 'claude', 'sessao' => self::SESSAO])
            ->assertOk()->assertJsonPath('projeto', 'gestor');
        $tarefa = CentralIaTarefa::findOrFail($r->json('tarefa_id'));
        $this->assertSame('/home/x/projetos', $tarefa->worktree);
        $this->assertNull($tarefa->branch);

        // Pedir de novo devolve a mesma tarefa.
        $this->actingAs($user)->postJson(route('central-ia.sessoes.continuar'), ['agente' => 'claude', 'sessao' => self::SESSAO])
            ->assertJsonPath('tarefa_id', $tarefa->id);

        $this->actingAs($user)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'Siga com o custo da obra', 'client_id' => (string) Str::uuid(),
            'projeto' => 'gestor', 'tarefa_id' => $tarefa->id, 'agente' => 'auto', 'modo' => 'auto',
        ])->assertCreated();
        $this->assertSame('claude', CentralIaExecucao::first()->agente, 'Em automático segue o agente da sessão.');

        Sanctum::actingAs($user, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
        $this->assertSame(['sessao' => self::SESSAO, 'cwd' => '/home/x/projetos', 'fork' => true], $job['continuar']);
        $this->assertSame('/home/x/projetos', $job['tarefa']['worktree']);

        $this->postJson(route('central-ia.runner.concluir', $job['id']), ['status' => 'concluida', 'resposta' => 'ok',
            'sessao_agente' => 'copia-1', 'checkpoint' => ['cwd' => '/home/x/projetos']])->assertOk();

        $this->actingAs($user)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'E agora o relatório', 'client_id' => (string) Str::uuid(),
            'projeto' => 'gestor', 'tarefa_id' => $tarefa->id, 'agente' => 'auto', 'modo' => 'auto',
        ])->assertCreated();
        Sanctum::actingAs($user, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])
            ->assertJsonPath('continuar', ['sessao' => 'copia-1', 'cwd' => '/home/x/projetos', 'fork' => false]);
    }

    public function test_sessao_em_uso_no_vscode_nao_e_continuada(): void
    {
        $user = $this->comCentral();
        $this->batida($user, ['sessoes' => [[
            'agente' => 'claude', 'id' => self::SESSAO, 'projeto' => 'gestor', 'cwd' => '/home/x/projetos',
            'ultima_atividade' => now()->subMinutes(2)->toIso8601String(), 'mensagens' => 10,
        ]]]);

        $this->actingAs($user)->postJson(route('central-ia.sessoes.continuar'), ['agente' => 'claude', 'sessao' => self::SESSAO])
            ->assertUnprocessable()->assertJsonValidationErrors('sessao');
        $this->assertSame(0, CentralIaTarefa::count());
        $this->assertTrue($this->actingAs($user)->getJson(route('central-ia.painel'))->json('sessoes.0.ativa_agora'));
    }

    public function test_sessao_de_outra_conta_ou_inventada_nao_vale(): void
    {
        $dono = $this->comCentral();
        $outro = $this->comCentral();
        $this->batida($dono);
        $this->batida($outro, ['sessoes' => []]);

        $this->actingAs($outro)->postJson(route('central-ia.sessoes.continuar'), ['agente' => 'claude', 'sessao' => self::SESSAO])
            ->assertUnprocessable();
        $this->actingAs($dono)->postJson(route('central-ia.sessoes.continuar'), ['agente' => 'codex', 'sessao' => self::SESSAO])
            ->assertUnprocessable();
        $this->assertSame([], $this->actingAs($outro)->getJson(route('central-ia.painel'))->json('sessoes'));
    }

    /**
     * O que o dono escreveu de fato: sem barra, com o projeto no meio da
     * frase. Uma mensagem só continua a sessão e já vira o primeiro pedido.
     */
    public function test_continuar_dito_com_palavras_ja_manda_o_pedido(): void
    {
        config(['central_ia.telegram.token' => 'token-de-teste']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $user = $this->comCentral();
        $this->batida($user);
        $canal = app(TelegramCanal::class);
        $msg = fn ($id, $t) => ['update_id' => $id, 'message' => ['text' => $t, 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'is_bot' => false]]];
        $canal->receber($msg(1, '/start '.$canal->gerarCodigo($user)));

        $canal->receber($msg(2, 'Continuar gestor de onde paramos'));

        $tarefa = CentralIaTarefa::firstOrFail();
        $execucao = CentralIaExecucao::firstOrFail();
        $this->assertSame(self::SESSAO, $tarefa->dossie['origem']['sessao']);
        $this->assertSame(['claude', 'Continuar gestor de onde paramos', $tarefa->id], [$execucao->agente, $execucao->instrucao, $execucao->tarefa_id]);
        $respostas = collect(Http::recorded())->map(fn ($p) => $p[0]['text'] ?? '')->filter()->values();
        $this->assertStringContainsString('Sua mensagem já foi como primeiro pedido', $respostas[1]);
        $this->assertStringContainsString('Recebido em gestor: Claude', $respostas[2]);
    }

    public function test_continuar_pelo_telegram(): void
    {
        config(['central_ia.telegram.token' => 'token-de-teste']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $user = $this->comCentral();
        $this->batida($user);
        $canal = app(TelegramCanal::class);
        $msg = fn ($id, $t) => ['update_id' => $id, 'message' => ['text' => $t, 'chat' => ['id' => 77, 'type' => 'private'], 'from' => ['id' => 77, 'is_bot' => false]]];
        $canal->receber($msg(1, '/start '.$canal->gerarCodigo($user)));

        $canal->receber($msg(2, '/continuar gestor'));
        $canal->receber($msg(3, 'Siga de onde parou'));

        $this->assertSame('gestor', CentralIaTelegramVinculo::value('projeto_atual'));
        $tarefa = CentralIaTarefa::firstOrFail();
        $this->assertSame(self::SESSAO, $tarefa->dossie['origem']['sessao']);
        $this->assertSame($tarefa->id, CentralIaExecucao::value('tarefa_id'));
        $this->assertStringContainsString('Continuando a sessão de Claude em gestor', collect(Http::recorded())->map(fn ($p) => $p[0]['text'] ?? '')->filter()->values()[1]);
    }
}
