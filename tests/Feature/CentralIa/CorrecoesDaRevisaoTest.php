<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaEntrega;
use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\CanalAtivo;
use App\Services\CentralIa\ConversaService;
use App\Services\CentralIa\Dossie;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Defeitos achados na revisão de 12/10/2026 (docs/specs/central-ia/revisao-2026-10-12.md). */
class CorrecoesDaRevisaoTest extends TestCase
{
    use BotProprioNosTestes, RefreshDatabase;

    private function comCentral(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $u;
    }

    public function test_status_no_telegram_responde_com_projeto_e_tarefa_no_canal(): void
    {
        config(['central_ia.canal_exclusivo' => true]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $u = $this->comCentral();
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => ['alfahome'], 'visto_em' => now()]);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 77, 'vinculado_em' => now()]);
        $t = CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => 'alfahome', 'titulo' => 'Filtro', 'objetivo' => 'Filtro']);
        app(CanalAtivo::class)->transferir($u, 'telegram', 'alfahome', $t->id);

        $this->entregarAoBot(app(TelegramCanal::class), ['update_id' => 900, 'message' => ['message_id' => 9, 'chat' => ['id' => 77, 'type' => 'private'],
            'from' => ['id' => 77, 'is_bot' => false], 'text' => '/status']]);

        $resposta = CentralIaMensagem::where('autor_tipo', CentralIaMensagem::AUTOR_SISTEMA)->where('conteudo', 'like', 'Status observado%')->firstOrFail();
        $this->assertSame($t->id, $resposta->tarefa_id);
        $this->assertTrue(CentralIaEntrega::where('mensagem_id', $resposta->id)->exists(), 'a resposta do /status sai para o Telegram');
    }

    public function test_pedido_sem_maquina_sai_da_fila_em_30_min_com_aviso(): void
    {
        $u = $this->comCentral();
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => ['alfahome'], 'visto_em' => now()]);
        $e = app(AgentesService::class)->pedir($u, 'analise o filtro', ['projeto' => 'alfahome', 'agente' => 'codex', 'modo' => 'analisar', 'instrucao' => 'analise o filtro'], 'web', 'c-fila');
        CentralIaRunner::query()->update(['visto_em' => now()->subHour()]);

        $this->travel(29)->minutes();
        app(AgentesService::class)->expirarPresas($u);
        $this->assertSame(CentralIaExecucao::PENDENTE, $e->fresh()->status);

        $this->travel(2)->minutes();
        app(AgentesService::class)->expirarPresas($u);
        $this->assertSame(CentralIaExecucao::EXPIRADA, $e->fresh()->status);
        $this->assertDatabaseHas('central_ia_mensagens', ['user_id' => $u->id, 'autor_tipo' => 'sistema', 'tarefa_id' => $e->tarefa_id]);
    }

    public function test_sessao_e_retomada_depois_de_falha_mesmo_sem_checkpoint(): void
    {
        $u = $this->comCentral();
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['alfahome'], 'visto_em' => now()]);
        $t = CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => 'alfahome', 'titulo' => 'X', 'objetivo' => 'X', 'worktree' => '/home/p/alfahome']);
        $m = app(ConversaService::class)->registrarDoAgente($u, 'Central', 'x', projeto: 'alfahome', tarefaId: $t->id);
        CentralIaExecucao::create(['user_id' => $u->id, 'pedido_id' => $m->id, 'tarefa_id' => $t->id, 'projeto' => 'alfahome', 'agente' => 'claude', 'modo' => 'implementar',
            'instrucao' => 'x', 'status' => CentralIaExecucao::FALHOU, 'sessao_agente' => 'sessao-123', 'concluida_em' => now()]);
        app(AgentesService::class)->pedir($u, 'continue', ['projeto' => 'alfahome', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'continue', 'tarefa_id' => $t->id], 'web', 'c-ret');

        $job = app(AgentesService::class)->proxima($u, $r);
        $this->assertSame('sessao-123', $job['continuar']['sessao']);
        $this->assertSame('/home/p/alfahome', $job['continuar']['cwd']);
    }

    public function test_cerca_de_codigo_nao_sobra_depois_do_bloco(): void
    {
        [$texto, $bloco] = Dossie::extrair("Feito.\n```json\n<<<CENTRAL-IA\n{\"resumo\": \"ok\"}\nCENTRAL-IA>>>\n```");
        $this->assertSame('Feito.', $texto);
        $this->assertSame('ok', $bloco['resumo']);
    }
}
