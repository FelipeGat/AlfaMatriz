<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaAnexo;
use App\Models\CentralIaEntrega;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\FalhaDeProvedor;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FallbackTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Protocolo anterior à ativação da troca de canal.
    }


    private function conta(array $agentes = ['claude', 'codex', 'copilot']): array
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $runner = CentralIaRunner::create(['user_id' => $user->id, 'maquina' => 'teste',
            'agentes' => $agentes, 'projetos' => ['alfamatriz'], 'visto_em' => now()]);
        return [$user, $runner];
    }

    private function pedido(User $user, array $extra = []): CentralIaExecucao
    {
        return app(AgentesService::class)->pedir($user, 'Corrija e teste o filtro', $extra + [
            'agente' => 'claude', 'modo' => 'implementar', 'projeto' => 'alfamatriz',
            'instrucao' => 'Corrija e teste o filtro'], 'web', (string) Str::uuid());
    }

    public function test_limite_20_nao_bloqueia_assinaturas_por_padrao(): void
    {
        [$user] = $this->conta();
        config(['central_ia.agentes.limite_diario' => 20]);
        for ($i = 0; $i < 21; $i++) {
            $this->pedido($user);
        }
        $this->assertSame(21, CentralIaExecucao::count());
        $this->actingAs($user)->getJson(route('central-ia.painel'))->assertOk()
            ->assertJsonPath('consumo.limites.central.limite', null)->assertJsonPath('consumo.limites.central.usados', 21);
    }

    public function test_franquia_esgotada_preserva_tarefa_instrucao_git_e_historico(): void
    {
        [$user, $runner] = $this->conta();
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $dados = ['status' => 'falhou', 'erro' => "You've hit your weekly limit", 'checkpoint' => ['cwd' => '/projetos/alfamatriz', 'head' => 'abc']];
        $s->concluir($user, $e->id, $dados);
        $s->concluir($user, $e->id, $dados);
        $nova = CentralIaExecucao::latest('id')->first();
        $this->assertSame(2, CentralIaExecucao::count(), 'Reentrega não cria outro handoff.');
        $this->assertSame('falhou', $e->fresh()->status);
        $this->assertSame('pendente', $nova->status);
        $this->assertSame('codex', $nova->agente);
        $this->assertSame($e->instrucao, $nova->instrucao);
        $this->assertSame($e->tarefa_id, $nova->tarefa_id);
        $this->assertSame($e->pedido_id, $nova->roteamento['fallback']['pedido_original']);
        $this->assertSame(['claude'], $nova->roteamento['fallback']['tentados']);
        $this->assertSame(1, CentralIaMensagem::where('autor_tipo', 'usuario')->count());
        $job = $s->proxima($user, $runner);
        $this->assertSame('implementar', $job['modo']);
        $this->assertStringContainsString('Corrija e teste o filtro', $job['contexto']);
        $this->assertStringContainsString('abc', $job['contexto']);
        $s->concluir($user, $nova->id, ['status' => 'concluida', 'resposta' => 'Filtro corrigido e validado.']);
        $this->assertSame(2, CentralIaExecucao::count(), 'Resposta de sucesso não dispara IA.');
    }

    public function test_cascata_tenta_cada_provedor_uma_vez_e_para(): void
    {
        [$user, $runner] = $this->conta();
        $this->pedido($user);
        $s = app(AgentesService::class);
        foreach (['claude', 'codex', 'copilot'] as $agente) {
            $job = $s->proxima($user, $runner);
            $this->assertSame($agente, $job['agente']);
            $s->concluir($user, $job['id'], ['status' => 'falhou', 'erro' => 'usage limit reached']);
        }
        $this->assertSame(3, CentralIaExecucao::count());
        $this->assertNull($s->proxima($user, $runner));
        $this->assertStringContainsString('nenhuma API paga', CentralIaMensagem::latest('id')->value('conteudo'));
    }

    public function test_falha_confirmada_desvia_proximos_pedidos_automaticos(): void
    {
        [$user, $runner] = $this->conta();
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => "You've hit your weekly limit"]);
        $this->assertSame('codex', $this->pedido($user, ['agente' => 'auto'])->agente);
        $this->travel(361)->minutes();
        $this->assertArrayNotHasKey('claude', $s->falhasRecentes($user));
    }

    public function test_fallback_nao_consume_outro_pedido_do_teto_interno(): void
    {
        [$user, $runner] = $this->conta();
        config(['central_ia.agentes.limite_diario_ativo' => true, 'central_ia.agentes.limite_diario' => 2]);
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'weekly limit reached']);
        $this->pedido($user);
        $this->assertSame(3, CentralIaExecucao::count());
    }

    public function test_fallback_nao_usa_maquina_nem_historico_de_outra_conta(): void
    {
        [$user, $runner] = $this->conta(['claude']);
        [$outro, $outraMaquina] = $this->conta();
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'weekly limit reached']);
        $this->assertSame(1, CentralIaExecucao::count());
        $this->assertNull($s->proxima($outro, $outraMaquina));
        $this->assertSame(0, CentralIaMensagem::where('user_id', $outro->id)->count());
        $this->assertSame([], $s->falhasRecentes($outro));
    }

    public function test_timeout_e_erros_de_projeto_nao_repetem_implementacao(): void
    {
        foreach (['tempo esgotado', 'permission denied', 'interrompida', 'login required', 'erro de teste'] as $erro) {
            $this->assertNull(FalhaDeProvedor::tipo($erro));
        }
        [$user, $runner] = $this->conta();
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'permission denied']);
        $this->assertSame(1, CentralIaExecucao::count());
    }

    public function test_indisponibilidade_transitoria_tambem_troca_provedor(): void
    {
        [$user, $runner] = $this->conta();
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'service unavailable (503)']);
        $this->assertSame('codex', CentralIaExecucao::latest('id')->value('agente'));
    }

    public function test_anexos_do_pedido_original_acompanham_a_troca_sem_duplicacao(): void
    {
        [$user, $runner] = $this->conta();
        $anexo = CentralIaAnexo::create(['id' => (string) Str::uuid(), 'user_id' => $user->id,
            'nome' => 'teste.pdf', 'mime' => 'application/pdf', 'tamanho' => 8,
            'caminho' => 'apenas-metadados-de-teste', 'sha256' => str_repeat('a', 64)]);
        $e = $this->pedido($user, ['anexos' => [$anexo->id]]);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'weekly limit reached']);
        $job = $s->proxima($user, $runner);
        $this->assertSame($anexo->id, $job['anexos'][0]['id']);
        $this->assertSame(1, CentralIaAnexo::count());
        $this->assertSame($e->pedido_id, $anexo->fresh()->mensagem_id);
    }

    public function test_handoff_e_resposta_ficam_na_mesma_conversa_e_no_telegram(): void
    {
        [$user, $runner] = $this->conta();
        config(['central_ia.telegram.token' => 'TOKEN_SINTETICO']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
        $canal = app(TelegramCanal::class);
        $canal->receber(['update_id' => 1, 'message' => ['text' => '/start '.$canal->gerarCodigo($user),
            'chat' => ['id' => 999, 'type' => 'private'], 'from' => ['id' => 999, 'is_bot' => false]]]);
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'weekly limit reached']);
        $job = $s->proxima($user, $runner);
        $s->concluir($user, $job['id'], ['status' => 'concluida', 'resposta' => 'Resultado do Codex.']);
        $canal->entregarPendentes();
        $this->assertSame(1, CentralIaMensagem::where('user_id', $user->id)->where('conteudo', 'Resultado do Codex.')->count());
        $this->assertSame(0, CentralIaEntrega::where('status', 'pendente')->count());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendMessage') && str_contains($r['text'], 'Resultado do Codex.'));
    }

    public function test_continuacao_ja_na_fila_nao_recebe_repeticao_obsoleta(): void
    {
        [$user, $runner] = $this->conta();
        $e = $this->pedido($user);
        $s = app(AgentesService::class);
        $s->proxima($user, $runner);
        $this->pedido($user, ['tarefa_id' => $e->tarefa_id, 'instrucao' => 'Nova orientação']);
        $s->concluir($user, $e->id, ['status' => 'falhou', 'erro' => 'weekly limit reached']);
        $this->assertSame(2, CentralIaExecucao::count());
    }
}
