<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\MoverTarefa;
use App\Mcp\Tools\VerTarefa;
use App\Models\Tarefa;
use App\Models\TarefaEntrega;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A entrega para a revisão pela porta do agente (#210): a mesma cobrança do
 * motor, a mesma frase de recusa, e o `ver_tarefa` trazendo o que foi entregue.
 */
class EntregaPeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_mover_para_a_revisao_cobra_a_entrega_e_ver_tarefa_a_mostra(): void
    {
        $dev = User::factory()->membro()->create(['name' => 'Alexandre Blank']);
        $tarefa = Tarefa::factory()->create([
            'status' => 'em_desenvolvimento', 'responsavel_id' => $dev->id, 'criado_por_id' => $dev->id,
        ]);

        AlfaMatrizServer::actingAs($dev)
            ->tool(MoverTarefa::class, ['tarefa' => $tarefa->codigo(), 'para' => 'em_revisao', 'de' => 'em_desenvolvimento'])
            ->assertHasErrors()
            ->assertSee('Para mandar para revisão, diga o que foi feito e como testar.');

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        AlfaMatrizServer::actingAs($dev)
            ->tool(MoverTarefa::class, [
                'tarefa' => $tarefa->codigo(), 'para' => 'em_revisao', 'de' => 'em_desenvolvimento',
                'o_que_foi_feito' => 'O boleto deixou de sair duplicado.',
                'como_testar' => 'Gerar o boleto da Orbe e conferir que sai um só.',
                'pr_commits' => 'https://github.com/alfa/matriz/pull/42',
            ])
            ->assertOk()
            ->assertSee('movida de Em andamento para Em revisão');

        $this->assertSame($dev->id, TarefaEntrega::sole()->user_id);

        AlfaMatrizServer::actingAs($dev)
            ->tool(VerTarefa::class, ['tarefa' => $tarefa->codigo()])
            ->assertSee('Entrega para a revisão (1ª de 1) por Alexandre Blank')
            ->assertSee('- O que foi feito: O boleto deixou de sair duplicado.')
            ->assertSee('- Como testar: Gerar o boleto da Orbe e conferir que sai um só.')
            ->assertSee('- PR e commits: https://github.com/alfa/matriz/pull/42');
    }

    public function test_o_mover_anuncia_os_campos_da_entrega(): void
    {
        $token = User::factory()->create()->createToken('agente', ['mcp'])->plainTextToken;

        $ferramentas = collect($this->withToken($token)
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []],
                ['Accept' => 'application/json, text/event-stream'])
            ->assertOk()
            ->json('result.tools'))->keyBy('name');

        $mover = $ferramentas['mover_tarefa'];

        $this->assertStringContainsString('o_que_foi_feito', $mover['description']);
        $this->assertArrayHasKey('o_que_foi_feito', $mover['inputSchema']['properties']);
        $this->assertArrayHasKey('como_testar', $mover['inputSchema']['properties']);
        $this->assertArrayHasKey('pr_commits', $mover['inputSchema']['properties']);
        $this->assertStringContainsString('entrega para a revisão', $ferramentas['ver_tarefa']['description']);
    }
}
