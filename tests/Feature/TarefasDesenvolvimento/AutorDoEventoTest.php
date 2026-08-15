<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O autor do evento de fluxo (US-083): todo movimento novo grava quem o fez.
 * O passado nunca gravou — evento antigo tem autor nulo, e nulo não é erro:
 * é a verdade sobre o que se sabe daquele movimento.
 */
class AutorDoEventoTest extends TestCase
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
     * @spec:AC-301 Movimento novo grava quem o fez: mover pela rota deixa o
     * evento apontando para o usuário logado.
     */
    public function test_movimento_pela_rota_grava_o_autor(): void
    {
        $usuario = User::factory()->membro()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $usuario->id]);
        $this->assertSame('backlog', $tarefa->status);

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'de_status' => 'backlog',
        ])->assertSessionMissing('erro');

        $evento = $tarefa->eventos()->latest('id')->first();
        $this->assertSame('em_desenvolvimento', $evento->para_status);
        $this->assertSame($usuario->id, $evento->user_id);
    }

    /**
     * @spec:AC-301 Movimento sem ninguém logado fica sem autor: a rotina que
     * mover tarefa sem sessão registra o movimento como ele foi — anônimo.
     */
    public function test_movimento_sem_sessao_fica_sem_autor(): void
    {
        $tarefa = $this->criarTarefa(['responsavel_id' => User::factory()->create()->id]);

        app(FluxoTarefaService::class)->mover($tarefa, 'em_desenvolvimento');

        $this->assertNull($tarefa->eventos()->latest('id')->first()->user_id);
    }

    /**
     * @spec:AC-301 Conta excluída solta o vínculo em vez de levar o evento
     * junto (ASM-073): o movimento fica no histórico, agora sem autor — como
     * a tarefa da mesma conta fica sem responsável.
     */
    public function test_conta_excluida_deixa_o_evento_sem_autor(): void
    {
        $usuario = User::factory()->membro()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $usuario->id]);

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'de_status' => 'backlog',
        ]);

        // Quem exclui é OUTRA conta: a auditoria da exclusão registra o ator,
        // e um ator que acabou de deixar de existir não tem como assinar.
        $this->actingAs(User::factory()->create());
        $usuario->forceDelete();

        $evento = $tarefa->eventos()->latest('id')->first();
        $this->assertNotNull($evento);
        $this->assertNull($evento->user_id);
    }
}
