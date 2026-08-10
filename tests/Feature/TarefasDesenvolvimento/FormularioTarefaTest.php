<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Criar e editar tarefa pelo formulário do quadro: status inicial de acordo
 * com o responsável escolhido e vínculo com um sistema já cadastrado (T-063).
 */
class FormularioTarefaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-083 Tarefa salva sem responsável nasce na coluna Aberta.
     */
    public function test_tarefa_criada_sem_responsavel_nasce_aberta(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Ajustar relatório de vendas',
            'prioridade' => 'media',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Ajustar relatório de vendas',
            'responsavel_id' => null,
            'status' => 'aberta',
        ]);
    }

    /**
     * @spec:AC-083 Tarefa salva com responsável já nasce direto no Backlog.
     */
    public function test_tarefa_criada_com_responsavel_nasce_no_backlog(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Corrigir tela de login',
            'responsavel_id' => $responsavel->id,
            'prioridade' => 'alta',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Corrigir tela de login',
            'responsavel_id' => $responsavel->id,
            'status' => 'backlog',
        ]);
    }

    /**
     * @spec:AC-084 A tarefa criada fica vinculada ao sistema escolhido no formulário.
     */
    public function test_tarefa_criada_fica_vinculada_ao_sistema_escolhido(): void
    {
        $usuario = User::factory()->create();
        $sistema = Sistema::factory()->create(['nome' => 'AlfaControl', 'ativo' => true]);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Integrar boleto',
            'sistema_id' => $sistema->id,
            'prioridade' => 'baixa',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Integrar boleto',
            'sistema_id' => $sistema->id,
        ]);
    }

    /**
     * @spec:AC-084 Só sistemas ativos aparecem como opção no formulário de nova tarefa.
     */
    public function test_apenas_sistemas_ativos_sao_oferecidos_no_formulario(): void
    {
        $usuario = User::factory()->create();
        Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'ativo' => true]);
        Sistema::factory()->create(['nome' => 'SistemaDesativado', 'slug' => 'desativado', 'ativo' => false]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('AlfaGym');
        $resposta->assertDontSee('SistemaDesativado');
    }

    /**
     * @spec:AC-084 Editar uma tarefa existente troca o sistema vinculado, e o card passa a mostrar o novo sistema.
     */
    public function test_editar_tarefa_troca_o_sistema_vinculado(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistemaAntigo = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);
        $sistemaNovo = Sistema::factory()->create(['nome' => 'AlfaControl', 'slug' => 'alfacontrol']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistemaAntigo->id,
            'status' => 'backlog',
        ]);

        $resposta = $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'sistema_id' => $sistemaNovo->id,
            'prioridade' => $tarefa->prioridade,
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertSame($sistemaNovo->id, $tarefa->fresh()->sistema_id);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));
        $quadro->assertSee('AlfaControl');
    }
}
