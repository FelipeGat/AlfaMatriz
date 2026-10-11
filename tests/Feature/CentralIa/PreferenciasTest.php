<?php

namespace Tests\Feature\CentralIa;

use App\Models\{CentralIaExecucao, CentralIaPreferencia, CentralIaRunner, CentralIaTarefa, Perfil, User};
use App\Services\CentralIa\{AgentesService, ConversaService, Preferencias, Roteador};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreferenciasTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        return $u;
    }

    private function salvar(User $u, array $mudancas = []): array
    {
        $r = array_replace_recursive(app(Preferencias::class)->ler($u), $mudancas);
        return $this->actingAs($u)->putJson(route('central-ia.preferencias'), $r)->assertOk()->json('regras');
    }

    public function test_regras_sao_privadas_e_salvar_nao_aceita_identidade_do_navegador(): void
    {
        $felipe = $this->pessoa(); $rossini = $this->pessoa(); $alexandre = $this->pessoa();
        $this->salvar($felipe, ['padroes' => ['investigacao' => 'claude'], 'limite_total' => 3]);
        $this->assertSame('claude', app(Preferencias::class)->ler($felipe)['padroes']['investigacao']);
        $this->assertSame('codex', app(Preferencias::class)->ler($rossini)['padroes']['investigacao']);
        $this->assertSame(0, app(Preferencias::class)->ler($alexandre)['revisao_config']);
        $this->actingAs($rossini)->getJson(route('central-ia.painel'))->assertOk()->assertJsonPath('preferencias.regras.revisao_config', 0);
        $r = app(Preferencias::class)->ler($rossini); $r['user_id'] = $felipe->id;
        $this->putJson(route('central-ia.preferencias'), $r)->assertUnprocessable();
        $this->assertDatabaseCount('central_ia_preferencias', 1);
    }

    public function test_conflito_e_campos_extras_nao_sobrescrevem_regras(): void
    {
        $u = $this->pessoa(); $antiga = app(Preferencias::class)->ler($u);
        $r = $this->salvar($u);
        $this->putJson(route('central-ia.preferencias'), $antiga)->assertConflict();
        $r['habilitados']['outro'] = true;
        $this->putJson(route('central-ia.preferencias'), $r)->assertUnprocessable();
        $this->assertSame(1, app(Preferencias::class)->ler($u)['revisao_config']);
    }

    public function test_roteamento_personalizado_e_relatorios_com_copilot(): void
    {
        $u = $this->pessoa(); $p = app(Preferencias::class);
        $ordem = collect(array_keys($p->ler($u)['padroes']))->mapWithKeys(fn ($c) => [$c => $p->ordem($u, $c)])->all();
        $r = app(Roteador::class);
        $this->assertSame('claude', $r->decidir('Implemente o menu', ['claude','codex','copilot'], preferencias: $ordem)['agente']);
        $this->assertSame('codex', $r->decidir('Analise o menu', ['claude','codex','copilot'], preferencias: $ordem)['agente']);
        $this->assertSame('copilot', $r->decidir('Faça um relatório de uso', ['claude','codex','copilot'], preferencias: $ordem)['agente']);
        $this->salvar($u, ['padroes' => ['investigacao' => 'claude']]);
        $this->assertSame('claude', $p->ordem($u, 'investigacao')[0]);
    }

    public function test_limite_zero_bloqueia_escolha_manual_e_automatica_sem_criar_execucao(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = $this->pessoa();
        $this->salvar($u, ['pedidos_dia' => ['codex' => 0], 'habilitados' => ['claude' => false, 'copilot' => false]]);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'teste', 'agentes' => ['claude','codex','copilot'], 'projetos' => ['alfamatriz'], 'visto_em' => now()]);
        foreach (['codex', 'auto'] as $a) $this->actingAs($u)->postJson(route('central-ia.enviar'), ['conteudo' => 'Analise o menu', 'client_id' => (string) \Illuminate\Support\Str::uuid(), 'projeto' => 'alfamatriz', 'agente' => $a, 'modo' => 'analisar'])->assertUnprocessable();
        $this->assertDatabaseCount('central_ia_execucoes', 0);
    }

    public function test_reenvio_automatico_no_ultimo_lugar_da_cota_nao_duplica_execucao(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = $this->pessoa(); $this->salvar($u, ['limite_total' => 1]);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'teste', 'agentes' => ['codex'], 'projetos' => ['alfamatriz'], 'visto_em' => now()]);
        $dados = ['conteudo' => 'Analise o menu', 'client_id' => (string) \Illuminate\Support\Str::uuid(), 'projeto' => 'alfamatriz', 'agente' => 'auto', 'modo' => 'analisar'];
        $a = $this->actingAs($u)->postJson(route('central-ia.enviar'), $dados)->assertCreated();
        $b = $this->postJson(route('central-ia.enviar'), $dados)->assertOk();
        $this->assertSame($a->json('id'), $b->json('id'));
        $this->assertDatabaseCount('central_ia_execucoes', 1);
        $dados['client_id'] = (string) \Illuminate\Support\Str::uuid();
        $this->postJson(route('central-ia.enviar'), $dados)->assertUnprocessable();
    }

    public function test_arquivamento_preserva_historico_suspende_fila_e_pode_ser_revertido(): void
    {
        $u = $this->pessoa();
        $t = CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => 'alfamatriz', 'titulo' => 'Antigo', 'objetivo' => 'Teste']);
        $m = app(ConversaService::class)->registrarDoAgente($u, 'Central', 'Preservar', projeto: 'alfamatriz', tarefaId: $t->id);
        $e = CentralIaExecucao::create(['user_id' => $u->id, 'pedido_id' => $m->id, 'tarefa_id' => $t->id, 'projeto' => 'alfamatriz', 'agente' => 'codex', 'modo' => 'analisar', 'instrucao' => 'Teste']);
        $runner = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'teste', 'agentes' => ['codex'], 'projetos' => ['alfamatriz'], 'visto_em' => now()]);
        $this->actingAs($u)->postJson(route('central-ia.chats.arquivar'), ['ids' => [$t->id]])->assertOk()->assertJsonPath('arquivadas.0', $t->id);
        $this->assertNull(app(AgentesService::class)->proxima($u, $runner));
        $this->assertSame('pendente', $e->fresh()->status);
        $this->assertDatabaseHas('central_ia_mensagens', ['id' => $m->id, 'conteudo' => 'Preservar']);
        $this->getJson(route('central-ia.painel'))->assertOk()->assertJsonCount(0, 'chats');
        $this->postJson(route('central-ia.tarefas.status', $t->id), ['status' => 'aberta'])->assertOk();
        $this->assertNotNull(app(AgentesService::class)->proxima($u, $runner));
    }

    public function test_arquivar_nao_acessa_outro_usuario_nem_interrompe_execucao(): void
    {
        $u = $this->pessoa(); $outro = $this->pessoa();
        $t = CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => 'alfamatriz', 'titulo' => 'Em curso', 'objetivo' => 'Teste']);
        $estranha = CentralIaTarefa::create(['user_id' => $outro->id, 'projeto' => 'alfamatriz', 'titulo' => 'Privado', 'objetivo' => 'Teste']);
        $m = app(ConversaService::class)->registrarDoAgente($u, 'Central', 'Teste', projeto: 'alfamatriz', tarefaId: $t->id);
        CentralIaExecucao::create(['user_id' => $u->id, 'pedido_id' => $m->id, 'tarefa_id' => $t->id, 'projeto' => 'alfamatriz', 'agente' => 'codex', 'modo' => 'analisar', 'instrucao' => 'Teste', 'status' => 'executando']);
        $this->actingAs($u)->postJson(route('central-ia.chats.arquivar'), ['ids' => [$t->id, $estranha->id]])->assertNotFound();
        $this->postJson(route('central-ia.chats.arquivar'), ['ids' => [$t->id]])->assertOk()->assertJsonPath('rodando.0', $t->id);
        $this->assertNotSame('arquivada', $t->fresh()->status);
    }

    public function test_administrador_sem_permissao_nao_pode_salvar_nem_arquivar(): void
    {
        $u = User::factory()->create();
        $u->perfis()->sync([Perfil::where('slug', 'admin')->value('id')]);
        $this->actingAs($u)->putJson(route('central-ia.preferencias'), app(Preferencias::class)->ler($u))->assertForbidden();
        $this->postJson(route('central-ia.chats.arquivar'), ['ids' => [1]])->assertForbidden();
    }
}
