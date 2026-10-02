<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\CriarTarefa;
use App\Mcp\Tools\MarcarDuplicada;
use App\Mcp\Tools\VerTarefa;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicidade pela porta do agente (#205): o aviso de parecidas na resposta
 * do `criar_tarefa`, sem barrar, e o `marcar_duplicada` com a regra da tela.
 */
class DuplicidadePeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    private function tarefa(string $titulo, array $atributos = []): Tarefa
    {
        return Tarefa::create(array_merge([
            'titulo' => $titulo,
            'criado_por_id' => User::factory()->create()->id,
        ], $atributos));
    }

    public function test_criar_avisa_das_parecidas_e_cria_assim_mesmo(): void
    {
        $admin = User::factory()->create();
        $original = $this->tarefa('Wellhub não registra check-in');

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['tipo' => 'desenvolvimento', 'titulo' => 'Check-in do Wellhub falhando'])
            ->assertOk()
            ->assertSee('criada')
            ->assertSee('já existem tarefas parecidas em curso')
            ->assertSee($original->codigo())
            ->assertSee('marcar_duplicada');

        $this->assertSame(2, Tarefa::count());
    }

    public function test_criar_sem_parecida_nao_avisa(): void
    {
        $admin = User::factory()->create();
        $this->tarefa('Exportar planilha de alunos');

        AlfaMatrizServer::actingAs($admin)
            ->tool(CriarTarefa::class, ['tipo' => 'desenvolvimento', 'titulo' => 'Wellhub não registra check-in'])
            ->assertOk()
            ->assertDontSee('parecidas');
    }

    public function test_marcar_duplicada_cancela_e_ver_tarefa_mostra_as_duas_pontas(): void
    {
        $admin = User::factory()->create();
        $original = $this->tarefa('Wellhub não registra check-in');
        $copia = $this->tarefa('Check-in do Wellhub falhando');

        AlfaMatrizServer::actingAs($admin)
            ->tool(MarcarDuplicada::class, ['tarefa' => $copia->codigo(), 'original' => $original->codigo()])
            ->assertOk()
            ->assertSee('cancelada como duplicada de '.$original->codigo());

        $this->assertSame('cancelada', $copia->fresh()->status);
        $this->assertSame($original->id, $copia->fresh()->duplicada_de_id);

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => $copia->codigo()])
            ->assertSee('Cancelada como duplicada de '.$original->codigo());

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => $original->codigo()])
            ->assertSee('Pedida de novo (canceladas como duplicadas desta): '.$copia->codigo());
    }

    public function test_marcar_duplicada_recusa_com_a_frase_da_tela(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefa('Sozinha');

        AlfaMatrizServer::actingAs($admin)
            ->tool(MarcarDuplicada::class, ['tarefa' => $tarefa->codigo(), 'original' => $tarefa->codigo()])
            ->assertHasErrors(['Uma tarefa não é duplicada de si mesma.']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(MarcarDuplicada::class, ['tarefa' => $tarefa->codigo(), 'original' => '#999999'])
            ->assertHasErrors(['Não há tarefa #999999.']);

        $this->assertSame('aberta', $tarefa->fresh()->status);
    }
}
