<?php

namespace Tests\Feature\CentralIa;

use App\Models\{CentralIaEntrega, CentralIaExecucao, CentralIaMensagem, CentralIaRunner, CentralIaTelegramVinculo, Perfil, User};
use App\Services\CentralIa\{AgentesService, ProgressoService, TelegramCanal};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProgressoTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => ['alfamatriz'], 'visto_em' => now()]);
        return $u;
    }

    private function pedir(User $u, string $texto = 'Analise os erros', array $extra = []): CentralIaExecucao
    {
        return app(AgentesService::class)->pedir($u, $texto, $extra + ['agente' => 'codex', 'modo' => 'auto',
            'instrucao' => $texto, 'projeto' => 'alfamatriz'], 'web', (string) Str::uuid());
    }

    public function test_avisos_89_90_180_segundos_deduplicados_e_entrega_nos_dois_canais(): void
    {
        $this->freezeTime();
        $u = $this->pessoa();
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 42]);
        $e = $this->pedir($u);
        $s = app(ProgressoService::class);
        $this->travel(89)->seconds(); $s->publicar($u);
        $this->assertSame(0, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
        $this->travel(1)->seconds(); $s->publicar($u); $s->publicar();
        $aviso = CentralIaMensagem::where('autor_tipo', 'sistema')->firstOrFail();
        $this->assertStringContainsString('Na fila há 1 min 30 s', $aviso->conteudo);
        $this->assertSame($e->tarefa_id, $aviso->tarefa_id);
        $this->assertSame(1, CentralIaEntrega::where('mensagem_id', $aviso->id)->count());
        $this->actingAs($u)->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz']))
            ->assertJsonPath('mensagens.1.autor_tipo', 'sistema');
        $this->travel(89)->seconds(); $s->publicar($u);
        $this->assertSame(1, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
        $this->travel(1)->seconds(); $s->publicar($u);
        $this->assertSame(2, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
        $e->update(['status' => 'concluida']);
        $this->travel(90)->seconds(); $s->publicar($u);
        $this->assertSame(2, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
    }

    public function test_atividade_por_execucao_sem_confundir_maquina_online_com_progresso(): void
    {
        $this->freezeTime(); $u = $this->pessoa(); $e = $this->pedir($u);
        $runner = CentralIaRunner::where('user_id', $u->id)->first();
        app(AgentesService::class)->proxima($u, $runner);
        $s = app(ProgressoService::class);
        $s->registrar($u, 'wsl', $e->id, ['sequencia' => 2, 'etapa' => 'comando', 'atividade_em' => now()->toIso8601String(), 'processo_ativo' => true]);
        $s->registrar($u, 'wsl', $e->id, ['sequencia' => 1, 'etapa' => 'agente', 'processo_ativo' => true]);
        $this->assertSame(2, $e->fresh()->progresso['sequencia']);
        $this->assertStringContainsString('Executando uma ferramenta', $s->texto($e->fresh()));
        $this->assertStringContainsString('Processo do agente ativo', $s->texto($e->fresh()));
        $this->travel(90)->seconds(); $runner->update(['visto_em' => now()]);
        $this->assertStringContainsString('Sem confirmação recente desta execução', $s->texto($e->fresh()));
        $s->publicar($u);
        $this->assertStringContainsString('Sem confirmação recente', CentralIaMensagem::where('autor_tipo', 'sistema')->value('conteudo'));
        $e->update(['status' => 'falhou']);
        $s->registrar($u, 'wsl', $e->id, ['sequencia' => 3, 'etapa' => 'agente', 'processo_ativo' => true]);
        $this->assertSame(2, $e->fresh()->progresso['sequencia']);
        $this->travel(90)->seconds(); $s->publicar($u);
        $this->assertSame(1, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
    }

    public function test_fila_da_mesma_tarefa_e_desconexao(): void
    {
        $this->freezeTime(); $u = $this->pessoa(); $e = $this->pedir($u);
        $a = app(AgentesService::class); $runner = CentralIaRunner::where('user_id', $u->id)->first();
        $a->proxima($u, $runner);
        $segundo = $this->pedir($u, extra: ['tarefa_id' => $e->tarefa_id]);
        $this->assertNull($a->proxima($u, $runner), 'Nunca duas execuções na mesma tarefa.');
        $s = app(ProgressoService::class);
        $this->assertStringContainsString('execução anterior da mesma tarefa', $s->texto($segundo->fresh()));
        $this->travel(90)->seconds();
        $this->assertStringContainsString('Máquina sem conexão recente', $s->texto($segundo->fresh()));
    }

    public function test_endpoint_privado_por_conta_maquina_e_status_com_validacao(): void
    {
        $u = $this->pessoa(); $e = $this->pedir($u);
        app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first());
        $dados = ['maquina' => 'wsl', 'sequencia' => 1, 'etapa' => 'agente', 'processo_ativo' => true];
        Sanctum::actingAs($this->pessoa(), ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.progresso', $e->id), $dados)->assertNotFound();
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.progresso', $e->id), array_replace($dados, ['maquina' => 'outra']))->assertNotFound();
        $this->postJson(route('central-ia.runner.progresso', $e->id), array_replace($dados, ['etapa' => 'texto arbitrario']))->assertUnprocessable();
        $this->postJson(route('central-ia.runner.progresso', $e->id), $dados + ['atividade_em' => now()->toIso8601String()])->assertOk();
        $this->assertSame('agente', $e->fresh()->progresso['etapa']);
    }

    public function test_consulta_web_direta_idempotente_e_privada_nao_gasta_execucao(): void
    {
        $u = $this->pessoa(); $e = $this->pedir($u);
        $dados = ['client_id' => (string) Str::uuid(), 'conteudo' => 'Como estamos?', 'projeto' => 'alfamatriz', 'tarefa_id' => $e->tarefa_id, 'agente' => 'codex'];
        $this->actingAs($u)->postJson(route('central-ia.enviar'), $dados)->assertCreated()->assertJsonPath('status_direto', true);
        $this->postJson(route('central-ia.enviar'), $dados)->assertOk();
        $this->assertSame(1, CentralIaExecucao::count());
        $this->assertSame(1, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
        $this->actingAs($this->pessoa())->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz']))->assertJsonCount(0, 'mensagens');
        $this->assertFalse(app(ProgressoService::class)->consulta('Analise o status das obras atrasadas'));
    }

    public function test_consulta_telegram_direta_aparece_na_central_e_nao_entra_na_fila(): void
    {
        config(['central_ia.telegram.token' => 'token-teste']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $u = $this->pessoa(); $this->pedir($u);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 42, 'projeto_atual' => 'alfamatriz']);
        $msg = ['update_id' => 42, 'message' => ['text' => 'Travou?', 'chat' => ['type' => 'private', 'id' => 42], 'from' => ['id' => 42, 'is_bot' => false]]];
        $canal = app(TelegramCanal::class); $canal->receber($msg); $canal->receber($msg);
        $this->assertSame(1, CentralIaExecucao::count());
        $this->assertSame(1, CentralIaMensagem::where('autor_tipo', 'sistema')->count());
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Status observado pela Central'));
    }

    public function test_grafias_de_implementacao_modo_e_timeout_coerentes_com_codex_manual(): void
    {
        $u = $this->pessoa();
        config(['central_ia.agentes.timeout_implementar_minutos' => 60, 'central_ia.agentes.timeout_minutos' => 15]);
        $a = app(AgentesService::class);
        foreach (['Pode implementar', 'INPLEMENTAR', 'Pode seguir usando o codex Inplementar', 'Ok. Pode seguir com o codex para análise e implantação', 'Uma outra análise com implementação que preciso que faça é o histórico da conversa'] as $texto) {
            $e = $this->pedir($u, $texto);
            $this->assertSame('codex', $e->agente);
            $this->assertSame('implementar', $e->modo);
            $this->assertSame(60, $a->teto($e));
        }
        $analise = $this->pedir($u, 'Analise o plano de implantação');
        $this->assertSame('analisar', $analise->modo);
        $e = $this->pedir($u, 'Analise antes de implementar', ['modo' => 'analisar']);
        $this->assertSame('analisar', $e->modo, 'Escolha explícita de só leitura permanece.');
        $this->assertSame(15, $a->teto($e));
    }
}
