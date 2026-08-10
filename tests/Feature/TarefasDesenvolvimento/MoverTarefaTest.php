<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mover o card pela rota `tarefas.mover` — arrastar ou o menu "Mover ▾": o
 * aviso de recusa do motor do fluxo chega como flash de erro e a tarefa não
 * sai do lugar; as transições que pedem texto (ajustes, cancelamento,
 * conclusão com relatório) recebem esse texto no próprio POST (T-064).
 */
class MoverTarefaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        $criador = User::factory()->create();

        return Tarefa::create(array_merge([
            'titulo' => 'Tarefa de teste',
            'criado_por_id' => $criador->id,
        ], $atributos));
    }

    /**
     * @spec:AC-085 Movimento fora do fluxo é recusado: Backlog não vai direto para Concluída.
     */
    public function test_movimento_fora_do_fluxo_e_recusado(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $responsavel->id]);
        $this->assertSame('backlog', $tarefa->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('Transição inválida', session('erro'));
        $this->assertSame('backlog', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-086 Direcionar para o Backlog exige responsável.
     */
    public function test_direcionar_para_backlog_exige_responsavel(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $this->assertSame('aberta', $tarefa->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'backlog',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('direcionar a tarefa para alguém', session('erro'));
        $this->assertSame('aberta', $tarefa->fresh()->status);

        $responsavel = User::factory()->create();
        $tarefa->update(['responsavel_id' => $responsavel->id]);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'backlog',
        ]);

        $resposta->assertSessionHasNoErrors();
        $resposta->assertSessionMissing('erro');
        $this->assertSame('backlog', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-087 Devolver para ajustes exige dizer o que corrigir.
     */
    public function test_devolver_para_ajustes_exige_motivo(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'ajustes_necessarios',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('descrever o que precisa ser corrigido', session('erro'));
        $this->assertSame('em_testes', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'ajustes_necessarios',
            'motivo' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('ajustes_necessarios', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-088 Cancelar exige motivo.
     */
    public function test_cancelar_exige_motivo(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_desenvolvimento']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'cancelada',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('motivo do cancelamento', session('erro'));
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'cancelada',
            'motivo' => 'Escopo descontinuado.',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('cancelada', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-089 Concluir exige relatório de teste aprovado; registrar um aprovado no próprio movimento libera a conclusão.
     */
    public function test_concluir_exige_relatorio_de_teste_aprovado(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('relatório de teste aprovado', session('erro'));
        $this->assertSame('em_testes', $tarefa->fresh()->status);

        // Registrar um relatório reprovado no próprio movimento continua recusando.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'relatorio_notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('relatório de teste aprovado', session('erro'));
        $this->assertSame('em_testes', $tarefa->fresh()->status);
        $this->assertDatabaseHas('tarefa_relatorios_teste', [
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        // Registrar um relatório aprovado no mesmo movimento libera a conclusão.
        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'relatorio_notas' => 'Tudo certo no reteste.',
            'relatorio_aprovado' => '1',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('concluida', $tarefa->fresh()->status);
        $this->assertDatabaseHas('tarefa_relatorios_teste', [
            'tarefa_id' => $tarefa->id,
            'aprovado' => true,
            'notas' => 'Tudo certo no reteste.',
        ]);
    }

    /**
     * @spec:AC-090 Tarefa concluída pode ser reaberta para desenvolvimento; cancelada não sai de lugar nenhum.
     */
    public function test_tarefa_concluida_pode_ser_reaberta_e_cancelada_nao_tem_saida(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'concluida']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
        ]);

        $resposta->assertSessionMissing('erro');
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $cancelada = $this->criarTarefa(['status' => 'cancelada']);

        $resposta = $this->actingAs($usuario)->post(route('tarefas.mover', $cancelada), [
            'status' => 'em_desenvolvimento',
        ]);

        $resposta->assertSessionHas('erro');
        $this->assertStringContainsString('Transição inválida', session('erro'));
        $this->assertSame('cancelada', $cancelada->fresh()->status);
    }

    /**
     * @spec:AC-085 A recusa do motor do fluxo chega como aviso na tela do quadro, sem mover o card.
     */
    public function test_aviso_de_recusa_aparece_no_quadro(): void
    {
        $usuario = User::factory()->create();
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Card que não deve sair do lugar', 'responsavel_id' => $responsavel->id]);

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
        ]);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));

        $quadro->assertOk();
        $quadro->assertSee('Transição inválida', false);
        $quadro->assertSee('Card que não deve sair do lugar');
    }
}
