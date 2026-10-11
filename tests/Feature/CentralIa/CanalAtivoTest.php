<?php

namespace Tests\Feature\CentralIa;

use App\Models\{CentralIaEntrega, CentralIaExecucao, CentralIaMensagem, CentralIaTelegramVinculo, CentralIaTarefa, Perfil, User};
use App\Services\CentralIa\{CanalAtivo, ConversaService, TelegramCanal};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CanalAtivoTest extends TestCase
{
    use RefreshDatabase;

    private User $dono;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => true, 'central_ia.telegram.token' => 'teste']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 10]])]);
        $this->dono = User::factory()->create();
        $this->dono->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaTelegramVinculo::create(['user_id' => $this->dono->id, 'chat_id' => 987]);
    }

    private function trocar(string $canal, array $extra = [])
    {
        return $this->actingAs($this->dono)->postJson(route('central-ia.canal'), ['canal' => $canal] + $extra);
    }

    private function receber(string $texto, int $id = 1): void
    {
        app(TelegramCanal::class)->receber(['update_id' => $id, 'message' => [
            'text' => $texto, 'chat' => ['type' => 'private', 'id' => 987], 'from' => ['id' => 987, 'is_bot' => false],
        ]]);
    }

    public function test_web_por_padrao_nao_espelha_e_telegram_inativo_nao_executa(): void
    {
        app(ConversaService::class)->registrarDoUsuario($this->dono, 'Olá', 'web', (string) Str::uuid());
        app(ConversaService::class)->registrarDoAgente($this->dono, 'Codex', 'Resposta');
        $this->receber('/codex alfamatriz implemente');
        $this->assertSame(0, CentralIaEntrega::count());
        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertSame(2, CentralIaMensagem::count());
        Http::assertSent(fn ($r) => str_contains($r['text'] ?? '', 'Continuar pelo Telegram'));
    }

    public function test_transferencia_persiste_confirma_uma_vez_e_nao_reenvia_historico(): void
    {
        $antiga = app(ConversaService::class)->registrarDoUsuario($this->dono, 'Histórico preservado', 'web');
        $this->trocar('telegram')->assertOk()->assertJsonPath('ativo', 'telegram');
        $this->trocar('telegram')->assertOk();
        $this->assertSame(1, CentralIaEntrega::count());
        $this->assertDatabaseHas('central_ia_mensagens', ['id' => $antiga->id, 'conteudo' => 'Histórico preservado']);
        $this->actingAs($this->dono)->getJson(route('central-ia.painel'))->assertOk()->assertJsonPath('canal.ativo', 'telegram');
        app(TelegramCanal::class)->entregarPendentes();
        Http::assertSentCount(1);
    }

    public function test_web_inativa_recusa_envio_sem_perder_historico_ou_criar_execucao(): void
    {
        $this->trocar('telegram')->assertOk();
        $this->actingAs($this->dono)->postJson(route('central-ia.enviar'), [
            'conteudo' => 'Pedido recusado', 'client_id' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('canal');
        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertDatabaseMissing('central_ia_mensagens', ['conteudo' => 'Pedido recusado']);
    }

    public function test_resposta_em_andamento_vai_somente_ao_canal_ativo(): void
    {
        $this->trocar('telegram')->assertOk();
        $a = app(ConversaService::class)->registrarDoAgente($this->dono, 'Codex', 'Resposta em andamento');
        $this->assertNotNull($a->entrega);
        $this->trocar('web')->assertOk();
        app(TelegramCanal::class)->entregarPendentes();
        Http::assertNothingSent();
        $this->assertDatabaseHas('central_ia_entregas', ['mensagem_id' => $a->id, 'erro' => 'canal_inativo']);
        $b = app(ConversaService::class)->registrarDoAgente($this->dono, 'Claude', 'Concluído na web');
        $this->assertNull($b->entrega);
        $this->assertDatabaseHas('central_ia_mensagens', ['id' => $a->id]);
    }

    public function test_telegram_ativo_registra_uma_vez_e_matriz_retoma(): void
    {
        $this->trocar('telegram')->assertOk();
        $this->receber('Olá pelo celular'); $this->receber('Olá pelo celular');
        $this->assertSame(1, CentralIaMensagem::where('telegram_update_id', 1)->count());
        $this->receber('/matriz', 2);
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
        $this->receber('Não deve entrar', 3);
        $this->assertDatabaseMissing('central_ia_mensagens', ['conteudo' => 'Não deve entrar']);
    }

    public function test_pareamento_nao_ativa_telegram_e_sem_pareamento_transferencia_recusada(): void
    {
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
        CentralIaTelegramVinculo::query()->delete();
        $this->trocar('telegram')->assertUnprocessable();
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
    }

    public function test_tarefa_alheia_nao_pode_ser_transferida(): void
    {
        $outro = User::factory()->create();
        $t = CentralIaTarefa::create(['user_id' => $outro->id, 'projeto' => 'alfamatriz', 'titulo' => 'Privada', 'objetivo' => 'Privado', 'status' => 'em_curso']);
        $this->trocar('telegram', ['projeto' => 'alfamatriz', 'tarefa_id' => $t->id])->assertNotFound();
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
    }

    public function test_sem_permissao_nao_pode_transferir_nem_escolher_dono_por_id(): void
    {
        $outro = User::factory()->create();
        $this->actingAs($outro)->postJson(route('central-ia.canal'), ['canal' => 'telegram', 'user_id' => $this->dono->id])->assertForbidden();
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
    }

    public function test_fila_antiga_nao_reaparece_apos_nova_transferencia(): void
    {
        $antiga = app(ConversaService::class)->registrarDoAgente($this->dono, 'Codex', 'Antiga');
        CentralIaEntrega::create(['user_id' => $this->dono->id, 'mensagem_id' => $antiga->id, 'chat_id' => 987]);
        $this->trocar('telegram')->assertOk();
        app(TelegramCanal::class)->entregarPendentes();
        $this->assertDatabaseHas('central_ia_entregas', ['mensagem_id' => $antiga->id, 'erro' => 'canal_inativo']);
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Antiga'));
    }
    public function test_transferencia_preserva_tarefa_e_filtra_respostas_de_outros_projetos(): void
    {
        \App\Models\CentralIaProjeto::create(['user_id' => $this->dono->id, 'projeto' => 'alfamatriz']);
        $t = CentralIaTarefa::create(['user_id' => $this->dono->id, 'projeto' => 'alfamatriz', 'titulo' => 'Minha tarefa', 'objetivo' => 'Teste', 'status' => 'aberta']);
        $this->trocar('telegram', ['projeto' => 'alfamatriz', 'tarefa_id' => $t->id])->assertOk()->assertJsonPath('tarefa_id', $t->id);
        $c = app(ConversaService::class);
        $certa = $c->registrarDoAgente($this->dono, 'Codex', 'Certa', projeto: 'alfamatriz', tarefaId: $t->id);
        $outra = $c->registrarDoAgente($this->dono, 'Codex', 'Outro projeto', projeto: 'gestor');
        $outraTarefa = $c->registrarDoAgente($this->dono, 'Codex', 'Outra tarefa', projeto: 'alfamatriz');
        $this->assertNotNull($certa->entrega); $this->assertNull($outra->entrega); $this->assertNull($outraTarefa->entrega);
        $this->assertSame($t->id, app(CanalAtivo::class)->estado($this->dono)->tarefa_canal);
    }

    public function test_desvincular_retorna_a_web_sem_apagar_historico(): void
    {
        $this->trocar('telegram')->assertOk();
        $quantidade = CentralIaMensagem::count();
        $this->actingAs($this->dono)->deleteJson(route('central-ia.telegram.desvincular'))->assertOk();
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
        $this->assertSame($quantidade, CentralIaMensagem::count());
        $this->assertSame(0, CentralIaTelegramVinculo::count());
    }

    public function test_permissao_revogada_bloqueia_telegram_e_transferencia(): void
    {
        $this->trocar('telegram')->assertOk();
        $this->dono->perfis()->detach(); $this->dono->esquecerPermissoes();
        $this->receber('Não executar');
        $this->assertDatabaseMissing('central_ia_mensagens', ['conteudo' => 'Não executar']);
        $this->trocar('web')->assertForbidden();
        app(TelegramCanal::class)->entregarPendentes();
        Http::assertNotSent(fn ($r) => str_contains($r['text'] ?? '', 'Conversa ativada'));
    }

    public function test_conta_inativa_nao_transfere_nem_recebe_pedido_telegram(): void
    {
        $this->dono->update(['ativo' => false]);
        $this->trocar('telegram')->assertRedirect(route('login'));
        $this->receber('Recusar');
        $this->assertSame('web', app(CanalAtivo::class)->ativo($this->dono));
        $this->assertSame(0, CentralIaExecucao::count());
        $this->assertSame(0, CentralIaMensagem::count());
    }

    public function test_reentrega_do_comando_matriz_nao_desativa_transferencia_posterior(): void
    {
        $this->trocar('telegram')->assertOk();
        $this->receber('/matriz', 40);
        $this->trocar('telegram')->assertOk();
        $this->receber('/matriz', 40);
        $this->assertSame('telegram', app(CanalAtivo::class)->ativo($this->dono));
        $this->assertSame(1, CentralIaMensagem::where('telegram_update_id', 40)->count());
    }

}
