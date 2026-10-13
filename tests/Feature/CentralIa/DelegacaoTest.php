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
}
