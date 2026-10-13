<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Análise que propõe mudança termina com três saídas (dono, 13/10/2026):
 * implementar, outra sugestão da mesma IA, outra sugestão de outra IA.
 */
class PropostaTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $s = app(AgentesService::class);
        $e = $s->pedir($u, 'analise esse print do relatório', ['projeto' => 'gestor', 'agente' => 'codex', 'modo' => 'analisar', 'instrucao' => 'analise esse print do relatório'], 'web', 'a1');
        $job = $s->proxima($u, $r);
        $s->concluir($u, $job['id'], ['status' => 'concluida', 'resposta' => "Proposta: uma linha por dia.\n<<<CENTRAL-IA\n".json_encode(['resumo' => 'propus', 'duvida' => 'É isso que você quer? Posso implementar?',
            'opcoes_duvida' => array_values(AgentesService::OPCOES_DA_PROPOSTA)])."\nCENTRAL-IA>>>"]);

        return [$u, $s, $e];
    }

    private function responder(User $u, AgentesService $s, $e, string $opcao, string $id)
    {
        return $s->pedir($u, 'x', ['projeto' => 'gestor', 'agente' => 'codex', 'modo' => 'auto', 'tarefa_id' => $e->tarefa_id,
            'instrucao' => 'Resposta à sua pergunta ("É isso que você quer? Posso implementar?"): '.$opcao], 'web', $id);
    }

    public function test_sim_implementar_vira_implementacao_com_a_proposta(): void
    {
        [$u, $s, $e] = $this->cenario();
        $i = $this->responder($u, $s, $e, 'Sim, implementar', 'a2');
        $this->assertSame('implementar', $i->modo);
        $this->assertSame('claude', $i->agente, 'implementação vai a quem implementa');
        $this->assertSame('forte', $i->roteamento['nivel']);
        $this->assertStringContainsString('Proposta: uma linha por dia.', $i->instrucao);
    }

    public function test_modelo_para_meu_ok_e_proposta_e_nao_implementacao(): void
    {
        $r = app(\App\Services\CentralIa\Roteador::class)->decidir('a landing ainda está antiga, me traga um modelo conceito da landing para meu ok e depois faça a implementaçao dela.', ['claude', 'codex', 'copilot']);
        $this->assertSame('analisar', $r['modo']);
        $this->assertSame('planejamento', $r['categoria']);
    }

    public function test_outra_sugestao_mesma_ou_outra_ia(): void
    {
        [$u, $s, $e] = $this->cenario();
        $m = $this->responder($u, $s, $e, 'Trazer outra sugestão', 'a3');
        $this->assertSame(['analisar', 'codex'], [$m->modo, $m->agente]);

        $s->proxima($u, CentralIaRunner::first()); // a anterior sai da fila
        $o = $this->responder($u, $s, $e, 'Outra sugestão com outra IA', 'a4');
        $this->assertSame('analisar', $o->modo);
        $this->assertNotSame('codex', $o->agente);
    }
}
