<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaEntrega;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pedidos aos agentes e a porta do runner.
 *
 * O AlfaMatriz não executa IA: ele guarda o pedido, entrega ao runner da
 * própria pessoa e grava a resposta. O que se confere aqui é essa fila — de
 * quem é cada pedido, quem pode pegar, o que não pode dobrar.
 */
class AgentesTest extends TestCase
{
    use BotProprioNosTestes, RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Protocolo anterior à ativação da troca de canal.
    }


    private function comCentral(): User
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $user;
    }

    private function maquina(User $user, array $projetos = ['alfacontrol', 'alfahome'], array $agentes = ['claude', 'codex', 'copilot']): CentralIaRunner
    {
        return CentralIaRunner::create([
            'user_id' => $user->id, 'maquina' => 'wsl', 'agentes' => $agentes, 'projetos' => $projetos, 'visto_em' => now(),
        ]);
    }

    private function pedirPelaTela(User $user, array $extra = [], ?string $id = null)
    {
        return $this->actingAs($user)->postJson(route('central-ia.enviar'), $extra + [
            'conteudo' => 'Analise os erros do quadro',
            'client_id' => $id ?? (string) Str::uuid(),
            'agente' => 'claude', 'projeto' => 'alfacontrol', 'modo' => 'analisar',
        ]);
    }

    public function test_atalho_de_texto(): void
    {
        $s = app(AgentesService::class);

        $this->assertSame(['agente' => 'claude', 'modo' => 'analisar', 'projeto' => 'alfacontrol', 'instrucao' => 'veja os erros'], $s->interpretar('/claude alfacontrol veja os erros'));
        $this->assertSame('implementar', $s->interpretar('/claude! alfahome corrija')['modo']);
        $this->assertSame('codex', $s->interpretar('/gpt vix-id resuma')['agente']);
        $this->assertSame('copilot', $s->interpretar("/copilot@CentralDaAlfaBot alfagym liste\nas rotas")['agente']);
        $this->assertNull($s->interpretar('claude alfacontrol sem barra'));
        $this->assertNull($s->interpretar('/claude alfacontrol'));
        $this->assertNull($s->interpretar('/outro alfacontrol x'));
    }

    public function test_pedido_pela_tela_vira_mensagem_e_execucao_sem_dobrar(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $id = (string) Str::uuid();

        $this->pedirPelaTela($user, id: $id)->assertCreated();
        $this->pedirPelaTela($user, id: $id)->assertOk();

        $this->assertSame(1, CentralIaExecucao::count());
        $execucao = CentralIaExecucao::first();
        $this->assertSame(['claude', 'alfacontrol', 'analisar', 'pendente'], [$execucao->agente, $execucao->projeto, $execucao->modo, $execucao->status]);

        $r = $this->actingAs($user)->getJson(route('central-ia.mensagens', ['projeto' => 'alfacontrol']))
            ->assertJsonPath('mensagens.0.pedido.agente', 'Claude')
            ->assertJsonPath('mensagens.0.pedido.status', 'pendente');
        // O motivo diz a escolha e o nível de custo (ex.: "· nível médio (sonnet)").
        $this->assertStringStartsWith('Escolha manual · nível', $r->json('mensagens.0.pedido.motivo'));
        $this->actingAs($user)->getJson(route('central-ia.painel'))
            ->assertJsonPath('maquina.online', true)
            ->assertJsonPath('maquina.projetos', ['alfacontrol', 'alfahome']);
        $this->actingAs($user)->getJson(route('central-ia.mensagens'))->assertJsonCount(0, 'mensagens');
    }

    public function test_atalho_digitado_na_tela_tambem_pede(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);

        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => '/copilot! alfahome ajuste o layout', 'client_id' => (string) Str::uuid()])
            ->assertCreated();

        $this->assertSame(['copilot', 'alfahome', 'implementar', 'ajuste o layout'], array_values(CentralIaExecucao::first()->only(['agente', 'projeto', 'modo', 'instrucao'])));
    }

    public function test_projeto_que_a_maquina_nao_tem_e_recusado(): void
    {
        $user = $this->comCentral();
        $this->maquina($user, ['alfahome']);

        $this->pedirPelaTela($user)->assertUnprocessable()->assertJsonValidationErrors('projeto');
        $this->assertSame(0, CentralIaMensagem::count());
    }

    public function test_sem_a_permissao_de_agentes_so_conversa(): void
    {
        $user = User::factory()->semPerfil()->create();
        $perfil = Perfil::create(['nome' => 'Só conversa', 'slug' => 'so-conversa']);
        $perfil->permissoes()->attach(Permissao::where('recurso', 'central_ia')->value('id'), ['ler' => true, 'incluir' => true]);
        $user->perfis()->attach($perfil->id);
        $this->maquina($user);

        $this->pedirPelaTela($user)->assertForbidden();
        $this->assertSame(0, CentralIaExecucao::count());
    }

    public function test_limite_diario(): void
    {
        config(['central_ia.agentes.limite_diario' => 2, 'central_ia.agentes.limite_diario_ativo' => true]);
        $user = $this->comCentral();
        $this->maquina($user);

        $this->pedirPelaTela($user)->assertCreated();
        $this->pedirPelaTela($user)->assertCreated();
        $this->pedirPelaTela($user)->assertUnprocessable()->assertJsonValidationErrors('agente');

        $this->assertSame(2, CentralIaExecucao::count());
        $this->assertSame(2, CentralIaMensagem::count(), 'O pedido recusado não fica na conversa.');
    }

    public function test_ciclo_do_runner_batida_proxima_conclusao(): void
    {
        $user = $this->comCentral();
        Sanctum::actingAs($user, ['central-ia-runner']);

        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude', 'codex'], 'projetos' => ['alfacontrol']])->assertOk();
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertNoContent();

        $this->pedirPelaTela($user);
        $tarefa = CentralIaExecucao::value('tarefa_id');
        // Segundo pedido na MESMA tarefa: espera o primeiro, mesmo com vaga.
        $this->pedirPelaTela($user, ['conteudo' => 'segundo', 'tarefa_id' => $tarefa]);
        Sanctum::actingAs($user, ['central-ia-runner']);

        $trabalho = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk()
            ->assertJsonPath('agente', 'claude')->assertJsonPath('projeto', 'alfacontrol')
            ->assertJsonPath('tarefa.id', $tarefa)
            // A máquina guarda o chat confirmado por servidor + conta + chat (T-368).
            ->assertJsonPath('conta', $user->id)
            ->assertJsonPath('continuar', null)->json();

        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertNoContent();

        $corpo = ['status' => 'concluida', 'resposta' => 'Achei 3 problemas.', 'sessao_agente' => 'sess-1'];
        $this->postJson(route('central-ia.runner.concluir', $trabalho['id']), $corpo)->assertOk()->assertJsonPath('status', 'concluida');
        $this->postJson(route('central-ia.runner.concluir', $trabalho['id']), $corpo)->assertOk();

        $respostas = CentralIaMensagem::where('autor_tipo', 'agente')->get();
        $this->assertCount(1, $respostas, 'Conclusão reenviada não dobra a resposta.');
        $this->assertSame('Claude', $respostas[0]->autor_nome);
        $this->assertSame('Achei 3 problemas.', $respostas[0]->conteudo);

        // O segundo pedido continua a sessão do primeiro.
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk()
            ->assertJsonPath('continuar.sessao', 'sess-1');
        $this->assertSame(2, CentralIaExecucao::count(), 'Resposta de agente não vira pedido novo.');
    }

    public function test_falha_no_runner_vira_aviso_na_conversa(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $this->pedirPelaTela($user);
        Sanctum::actingAs($user, ['central-ia-runner']);
        $id = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json('id');

        $this->postJson(route('central-ia.runner.concluir', $id), ['status' => 'falhou', 'erro' => 'tempo esgotado'])->assertOk();

        $this->assertSame('falhou', CentralIaExecucao::value('status'));
        $this->assertStringContainsString('tempo esgotado', CentralIaMensagem::where('autor_tipo', 'agente')->value('conteudo'));
    }

    public function test_runner_de_um_nao_ve_nem_conclui_pedido_de_outro(): void
    {
        $felipe = $this->comCentral();
        $rossini = $this->comCentral();
        $this->maquina($felipe);
        $this->maquina($rossini);
        $this->pedirPelaTela($felipe);
        $id = CentralIaExecucao::value('id');

        Sanctum::actingAs($rossini, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertNoContent();
        $this->postJson(route('central-ia.runner.concluir', $id), ['status' => 'concluida', 'resposta' => 'x'])->assertNotFound();

        $this->assertSame('pendente', CentralIaExecucao::value('status'));
    }

    public function test_token_sem_a_capacidade_ou_conta_sem_perfil_nao_entram(): void
    {
        $user = $this->comCentral();
        Sanctum::actingAs($user, ['mcp']);
        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => [], 'projetos' => []])->assertForbidden();

        $admin = User::factory()->create();
        Sanctum::actingAs($admin, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => [], 'projetos' => []])->assertForbidden();

        $this->postJson(route('central-ia.runner.batida'))->assertForbidden();
        $this->assertSame(0, CentralIaRunner::count());
    }

    public function test_batida_recusa_projeto_com_caminho(): void
    {
        Sanctum::actingAs($this->comCentral(), ['central-ia-runner']);

        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['../etc']])
            ->assertUnprocessable();
    }

    public function test_execucao_sem_noticia_expira_e_a_fila_anda(): void
    {
        $user = $this->comCentral();
        $runner = $this->maquina($user);
        $this->pedirPelaTela($user);
        $this->pedirPelaTela($user, ['conteudo' => 'próximo']);
        app(AgentesService::class)->proxima($user, $runner);

        $this->travel(21)->minutes();
        $runner->update(['visto_em' => now()]);
        $seguinte = app(AgentesService::class)->proxima($user, $runner);

        $this->assertSame('expirada', CentralIaExecucao::orderBy('id')->value('status'));
        $this->assertSame('próximo', CentralIaExecucao::find($seguinte['id'])->instrucao);
        $this->assertStringContainsString('não respondeu a tempo', CentralIaMensagem::where('autor_tipo', 'sistema')->value('conteudo'));
    }

    public function test_pedido_pelo_telegram_e_resposta_volta_para_la(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 7]])]);
        $user = $this->comCentral();
        $this->maquina($user);
        $canal = app(TelegramCanal::class);
        $this->entregarAoBot($canal, ['update_id' => 1, 'message' => ['text' => '/start '.$canal->gerarCodigo($user), 'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42, 'is_bot' => false]]]);

        $this->entregarAoBot($canal, ['update_id' => 2, 'message' => ['text' => '/gpt alfahome resuma o projeto', 'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42, 'is_bot' => false]]]);
        $this->entregarAoBot($canal, ['update_id' => 3, 'message' => ['text' => '/claude naoexiste x', 'chat' => ['id' => 42, 'type' => 'private'], 'from' => ['id' => 42, 'is_bot' => false]]]);

        $this->assertSame(1, CentralIaExecucao::count());
        $execucao = CentralIaExecucao::first();
        $this->assertSame(['codex', 'alfahome'], [$execucao->agente, $execucao->projeto]);

        Sanctum::actingAs($user, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl']);
        $this->postJson(route('central-ia.runner.concluir', $execucao->id), ['status' => 'concluida', 'resposta' => str_repeat('a', 5000)]);

        $this->assertSame(1, CentralIaEntrega::count(), 'Só a resposta vai ao Telegram; o pedido veio de lá.');
        $canal->entregarPendentes();
        $enviado = collect(Http::recorded())->map(fn ($p) => $p[0]['text'] ?? '')->last();
        $this->assertLessThan(4096, mb_strlen($enviado));
        $this->assertStringContainsString('GPT (Codex)', $enviado);
    }
}
