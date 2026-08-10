<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quarto nível de prioridade do ciclo, Crítica, com destaque distinto da
 * Alta no card (T-074).
 */
class PrioridadeCriticaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-123 A escolha de prioridade do formulário traz os quatro
     * níveis, incluindo Crítica.
     */
    public function test_formulario_de_tarefa_oferece_as_quatro_prioridades(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSee('<option value="baixa"', false);
        $resposta->assertSee('<option value="media"', false);
        $resposta->assertSee('<option value="alta"', false);
        $resposta->assertSee('<option value="critica"', false);
        $resposta->assertSee('Crítica');
    }

    /**
     * @spec:AC-123 Uma tarefa pode ser salva com prioridade Crítica.
     */
    public function test_tarefa_pode_ser_criada_com_prioridade_critica(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Corrigir vazamento de dados em produção',
            'prioridade' => 'critica',
        ]);

        $resposta->assertRedirect(route('tarefas.index'));
        $this->assertDatabaseHas('tarefas', [
            'titulo' => 'Corrigir vazamento de dados em produção',
            'prioridade' => 'critica',
        ]);
    }

    /**
     * @spec:AC-123 O card de uma tarefa Crítica traz a marca vermelha
     * (tom "crit"), distinta da tarefa Alta (tom "warn").
     */
    public function test_card_de_tarefa_critica_traz_marca_vermelha_distinta_da_alta(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $critica = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Tarefa crítica de teste',
            'prioridade' => 'critica',
            'status' => 'backlog',
        ]);
        $alta = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Tarefa alta de teste',
            'prioridade' => 'alta',
            'status' => 'backlog',
        ]);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();
        $resposta->assertSeeInOrder([
            'data-tarefa="'.$critica->id.'"',
            'background: rgb(var(--crit) / var(--tint-alpha))',
        ], false);
        $resposta->assertSeeInOrder([
            'data-tarefa="'.$alta->id.'"',
            'background: rgb(var(--warn) / var(--tint-alpha))',
        ], false);
    }
}
