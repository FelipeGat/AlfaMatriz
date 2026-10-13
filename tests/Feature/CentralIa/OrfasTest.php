<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O WSL reiniciou (13/10/2026) e o pedido #84 ficou "executando" sem processo
 * nenhum: a batida do runner novo encerra o que o anterior deixou.
 */
class OrfasTest extends TestCase
{
    use RefreshDatabase;

    public function test_batida_do_processo_novo_encerra_o_que_o_anterior_deixou(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $svc = app(AgentesService::class);
        $orfa = $svc->pedir($u, 'a', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'a'], 'web', 'o1');
        $svc->proxima($u, $r);
        $viva = $svc->pedir($u, 'b', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'b'], 'web', 'o2');
        $svc->proxima($u, $r);
        CentralIaExecucao::whereKey([$orfa->id, $viva->id])->update(['iniciada_em' => now()->subMinutes(10)]);

        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'],
            'desde' => now()->subMinute()->toIso8601String(), 'em_curso' => [$viva->id]])->assertOk();

        $this->assertSame(CentralIaExecucao::FALHOU, $orfa->fresh()->status);
        $this->assertStringStartsWith('interrompida', $orfa->fresh()->erro);
        $this->assertSame(CentralIaExecucao::EXECUTANDO, $viva->fresh()->status, 'o que está com o processo novo segue');
        $this->assertStringContainsString('máquina reiniciou', \App\Models\CentralIaMensagem::find($orfa->fresh()->resposta_id)->conteudo);

        // Começou depois que o processo subiu: não é órfã, mesmo fora da lista.
        $nova = $svc->pedir($u, 'c', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'c'], 'web', 'o3');
        $this->assertNotNull($svc->proxima($u, $r));
        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'],
            'desde' => now()->subMinute()->toIso8601String(), 'em_curso' => []])->assertOk();
        $this->assertSame(CentralIaExecucao::EXECUTANDO, $nova->fresh()->status);
    }

    public function test_seguir_depois_da_interrupcao_retoma_com_o_mesmo_agente_e_modo(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $svc = app(AgentesService::class);
        $e = $svc->pedir($u, 'x', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'crie os três módulos'], 'web', 's1');
        $svc->proxima($u, $r);
        $e->update(['iniciada_em' => now()->subMinutes(5)]);
        $svc->interromperOrfas($u, $r->fresh(), now(), []);

        $novo = $svc->pedir($u, 'seguir', ['projeto' => 'gestor', 'tarefa_id' => $e->tarefa_id, 'agente' => 'auto', 'modo' => 'auto', 'instrucao' => 'seguir'], 'web', 's2');
        $this->assertSame('claude', $novo->agente);
        $this->assertSame('implementar', $novo->modo);
        $this->assertStringContainsString('crie os três módulos', $novo->instrucao);
    }

    public function test_horario_do_runner_em_utc_nao_encerra_o_que_comecou_depois(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        $svc = app(AgentesService::class);
        $e = $svc->pedir($u, 'a', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'a'], 'web', 'utc');
        $svc->proxima($u, $r);

        // O runner subiu há 10 min e manda em UTC, com "Z" (13/10/2026: o
        // servidor comparava 14:27Z com 11:36 local e encerrava o pedido).
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'],
            'desde' => now()->subMinutes(10)->utc()->format('Y-m-d\\TH:i:s.v\\Z'), 'em_curso' => []])->assertOk();
        $this->assertSame(CentralIaExecucao::EXECUTANDO, $e->fresh()->status);
    }
}
