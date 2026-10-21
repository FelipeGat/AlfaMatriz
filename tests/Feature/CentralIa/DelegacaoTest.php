<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tarefa #14 (13/10/2026): o Claude implementou, o projeto exige QA do
 * Copilot, e a pessoa virou mensageira entre IAs. Agora o agente delega, e
 * "pode seguir" continua com quem falou por último.
 */
class DelegacaoTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'], 'projetos' => ['gestor'], 'visto_em' => now()]);

        return [$u, $r, app(AgentesService::class)];
    }

    public function test_agente_delega_o_qa_e_a_central_encaminha_avisando(): void
    {
        [$u, $r, $s] = $this->cenario();
        $e = $s->pedir($u, 'revise o relatório', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'revise o relatório'], 'web', 'd1');
        $job = $s->proxima($u, $r);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Implementei.\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'ok',
            'delegar' => ['agente' => 'copilot', 'modo' => 'analisar', 'pedido' => 'QA independente do .qa-diff.md; escreva .qa-report.md com o veredito']])."\nCENTRAL-IA>>>"]);

        $qa = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertSame(['copilot', 'analisar', $e->tarefa_id], [$qa->agente, $qa->modo, $qa->tarefa_id]);
        $this->assertStringContainsString('.qa-diff.md', $qa->instrucao);
        $this->assertDatabaseHas('central_ia_eventos', ['user_id' => $u->id, 'tipo' => 'delegacao']);
        $this->assertStringContainsString('passou para Copilot', \App\Models\CentralIaMensagem::find($qa->pedido_id)->conteudo);
    }

    public function test_pode_seguir_continua_com_quem_falou_por_ultimo(): void
    {
        [$u, $r, $s] = $this->cenario();
        $e = $s->pedir($u, 'implemente o filtro', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'implemente o filtro'], 'web', 's1');
        $job = $s->proxima($u, $r);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => 'Implementei; falta o QA e o commit.']);

        $seg = $s->pedir($u, 'pode seguir', ['projeto' => 'gestor', 'agente' => 'auto', 'modo' => 'auto', 'instrucao' => 'pode seguir', 'tarefa_id' => $e->tarefa_id], 'web', 's2');
        $this->assertSame(['claude', 'implementar'], [$seg->agente, $seg->modo]);
        $this->assertStringContainsString('Implementei; falta o QA e o commit.', $seg->instrucao);
        $this->assertStringStartsWith('Continua com Claude', $seg->roteamento['motivo']);
    }

    /**
     * Tarefa #30 (21/10/2026): pedido de correção, o Claude passa a revisão ao Codex, e o Codex
     * devolve "corrigir" SEM dizer o modo. Antes virava análise e girava sem alterar nada.
     */
    public function test_passagem_sem_modo_segue_o_que_o_pedido_da_pessoa_autorizou(): void
    {
        [$u, $r, $s] = $this->cenario();
        $raiz = 'passe um olho clínico no sistema e corrija os erros';
        $e = $s->pedir($u, $raiz, ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => $raiz], 'web', 'r1');
        $job = $s->proxima($u, $r);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Corrigi.\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'ok',
            'delegar' => ['agente' => 'codex', 'pedido' => 'Revisão independente do controle de acesso; liste os achados']])."\nCENTRAL-IA>>>"]);
        $revisao = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertSame(['codex', 'analisar'], [$revisao->agente, $revisao->modo], 'revisão continua sendo análise');

        $job = $s->proxima($u, $r);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Achei 2 falhas.\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'achados',
            'delegar' => ['agente' => 'claude', 'pedido' => 'Corrigir somente no ambiente local os dois achados: GET /orders sem permissão e perfis fora da transação']])."\nCENTRAL-IA>>>"]);
        $correcao = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertSame(['claude', 'implementar'], [$correcao->agente, $correcao->modo]);
        $this->assertStringContainsString('implementar: o passo pede alteração, e o seu pedido autorizou implementar', $correcao->roteamento['motivo']);
        $this->assertSame($raiz, $correcao->roteamento['delegacao']['raiz_pedido'], 'o pedido da pessoa vai junto');

        // Na tela, a rodada passada mostra o pedido da pessoa.
        $msgs = collect($this->actingAs($u)->getJson(route('central-ia.mensagens', ['projeto' => 'gestor']))->assertOk()->json('mensagens'));
        $this->assertTrue($msgs->contains(fn ($m) => ($m['pedido']['pedido_da_pessoa'] ?? null) === $raiz));
    }

    public function test_passagem_que_pede_alteracao_num_pedido_de_analise_fica_em_analise_e_explica(): void
    {
        [$u, $r, $s] = $this->cenario();
        $s->pedir($u, 'analise o módulo', ['projeto' => 'gestor', 'agente' => 'codex', 'modo' => 'analisar', 'instrucao' => 'analise o módulo'], 'web', 'a1');
        $job = $s->proxima($u, $r);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Achados.\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'x',
            'delegar' => ['agente' => 'claude', 'pedido' => 'Corrigir os achados']])."\nCENTRAL-IA>>>"]);
        $p = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertSame('analisar', $p->modo);
        $this->assertStringContainsString('use "Sim, implementar" para liberar', $p->roteamento['motivo']);
    }

    /** "use o claude e execute, corrigindo tudo" depois de uma revisão passada entre agentes. */
    public function test_execute_corrigindo_e_implementacao(): void
    {
        $r = app(\App\Services\CentralIa\Roteador::class);
        $this->assertSame('implementar', $r->decidir('otimo, use o claude e execute, corrigindo tudo que for necessario.', [])['modo']);
        $this->assertSame('implementar', $r->decidir('arrume a tela de login', [])['modo']);
    }
}
