<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\ArquivarTarefa;
use App\Mcp\Tools\DesarquivarTarefa;
use App\Mcp\Tools\ListarTarefas;
use App\Mcp\Tools\MoverTarefa;
use App\Mcp\Tools\Referencias;
use App\Mcp\Tools\VerTarefa;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O arquivo pela porta do agente (#208): as duas ferramentas com a regra da
 * tela, e a arquivada fora do "abertas" mas ao alcance de quem a procura.
 */
class ArquivoPeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    private function tarefa(string $titulo, array $atributos = []): Tarefa
    {
        return Tarefa::create(array_merge([
            'titulo' => $titulo,
            'tipo' => 'desenvolvimento',
            'criado_por_id' => User::factory()->membro()->create()->id,
        ], $atributos));
    }

    public function test_arquivar_listar_ver_e_desarquivar(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefa('Relatório de frequência por turma');
        $outra = $this->tarefa('Tela de login nova');

        AlfaMatrizServer::actingAs($admin)
            ->tool(ArquivarTarefa::class, ['tarefa' => $tarefa->codigo(), 'motivo' => 'depois', 'nota' => 'Depois da migração.'])
            ->assertOk()
            ->assertSee('Tarefa '.$tarefa->codigo().' arquivada (Fica para depois).')
            ->assertSee('ARQUIVADA: Fica para depois');

        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, [])
            ->assertSee($outra->titulo)
            ->assertDontSee($tarefa->titulo);

        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, ['situacao' => 'arquivadas'])
            ->assertSee($tarefa->titulo)
            ->assertDontSee($outra->titulo);

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => $tarefa->codigo()])
            ->assertSee('Arquivada (Fica para depois) por '.$admin->name)
            ->assertSee('Depois da migração.')
            ->assertSee('Você não pode mover esta tarefa: Esta tarefa está arquivada. Desarquive antes de mover.');

        AlfaMatrizServer::actingAs($admin)
            ->tool(MoverTarefa::class, ['tarefa' => $tarefa->codigo(), 'de' => $tarefa->status, 'para' => 'em_desenvolvimento'])
            ->assertHasErrors(['Esta tarefa está arquivada. Desarquive antes de mover.']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(DesarquivarTarefa::class, ['tarefa' => $tarefa->codigo()])
            ->assertOk()
            ->assertSee('desarquivada: voltou para '.Tarefa::rotuloDaEtapa($tarefa->status));

        $this->assertFalse($tarefa->fresh()->estaArquivada());
    }

    public function test_as_recusas_sao_as_da_tela(): void
    {
        $admin = User::factory()->create();
        $membro = User::factory()->membro()->create();
        $tarefa = $this->tarefa('Relatório de frequência por turma', ['responsavel_id' => $membro->id]);

        AlfaMatrizServer::actingAs($membro)
            ->tool(ArquivarTarefa::class, ['tarefa' => $tarefa->codigo(), 'motivo' => 'depois'])
            ->assertHasErrors(['Só quem faz triagem arquiva tarefa.']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(DesarquivarTarefa::class, ['tarefa' => $tarefa->codigo()])
            ->assertHasErrors(['A tarefa '.$tarefa->codigo().' não está arquivada.']);

        AlfaMatrizServer::actingAs($admin)
            ->tool(ArquivarTarefa::class, ['tarefa' => '#999999', 'motivo' => 'depois'])
            ->assertHasErrors(['Não há tarefa #999999.']);

        $this->assertFalse($tarefa->fresh()->estaArquivada());
    }

    public function test_referencias_dizem_os_motivos(): void
    {
        AlfaMatrizServer::actingAs(User::factory()->create())
            ->tool(Referencias::class, [])
            ->assertSee('Motivos de arquivamento: depois = Fica para depois, sem_retorno = Sem retorno, nao_confirmado = Não confirmado');
    }
}
