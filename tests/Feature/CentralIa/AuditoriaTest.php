<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Quem viu o quê e quem alterou o quê (dono, 12/10/2026): com acesso total,
 * a IA decide; a pessoa confere depois no Contexto e no log.
 */
class AuditoriaTest extends TestCase
{
    use RefreshDatabase;

    public function test_rastro_da_execucao_vai_para_o_contexto_e_analise_que_altera_vira_aviso(): void
    {
        config(['central_ia.canal_exclusivo' => false]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['alfahome'], 'visto_em' => now()]);
        $e = app(AgentesService::class)->pedir($u, 'analise o filtro', ['projeto' => 'alfahome', 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'analise o filtro'], 'web', 'c-aud');

        Sanctum::actingAs($u, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('central-ia.runner.concluir', $job['id']), ['status' => 'concluida', 'resposta' => 'feito',
            'auditoria' => ['acesso' => 'total', 'informado' => true,
                'acessos' => [['ferramenta' => 'Bash', 'alvo' => 'git log --all'], ['ferramenta' => 'Read', 'alvo' => 'app/Filtro.php'],
                    ['ferramenta' => 'Bash', 'alvo' => 'export API_TOKEN=valor-sintetico-123']],
                'git' => ['head_antes' => 'a', 'head_depois' => 'a', 'arquivos' => ['app/Filtro.php'], 'revertidos' => [], 'commits' => []]]])->assertOk();

        $this->assertDatabaseHas('central_ia_eventos', ['user_id' => $u->id, 'tipo' => 'analise_alterou', 'nivel' => 'aviso']);
        $t = collect($this->actingAs($u)->getJson(route('central-ia.tarefas', 'alfahome'))->json('tarefas'))->firstWhere('id', $e->tarefa_id);
        $exec = $t['execucoes'][0];
        $this->assertSame(['app/Filtro.php'], $exec['arquivos']);
        $this->assertSame(3, $exec['total_acessos']);
        $this->assertSame('git log --all', $exec['acessos'][0]['alvo']);
        // Segredo que apareça num comando não fica guardado.
        $this->assertStringNotContainsString('valor-sintetico-123', json_encode($exec));
    }
}
