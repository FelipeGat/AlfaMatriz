<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro de desenvolvimento é exclusivo da matriz: usuário de revenda toma
 * 403 em qualquer rota de tarefas, mesmo quando o perfil dele carrega a
 * permissão `tarefas` (T-060).
 */
class AcessoTarefasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-095 Usuário de revenda recebe 403 nas rotas de tarefas, sem ver conteúdo nenhum do quadro.
     */
    public function test_usuario_de_revenda_toma_403_nas_rotas_de_tarefas(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $criador = User::factory()->create();
        // Perfil admin de fábrica carrega TODAS as permissões, inclusive
        // `tarefas` — o bloqueio precisa vencer mesmo assim, só pelo escopo.
        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);
        $tarefa = Tarefa::factory()->create(['criado_por_id' => $criador->id]);

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'));
        $quadro->assertForbidden();
        $quadro->assertDontSee('Aberta');
        $quadro->assertDontSee('Backlog');
        $quadro->assertDontSee($tarefa->titulo);

        $this->actingAs($usuario)
            ->post(route('tarefas.store'), ['titulo' => 'Tentativa de criação por revenda'])
            ->assertForbidden();
        $this->assertDatabaseMissing('tarefas', ['titulo' => 'Tentativa de criação por revenda']);

        $this->actingAs($usuario)
            ->post(route('tarefas.mover', $tarefa), ['status' => 'backlog'])
            ->assertForbidden();
        $this->assertSame('aberta', $tarefa->fresh()->status);

        $this->actingAs($usuario)
            ->put(route('tarefas.update', $tarefa), ['titulo' => 'Outro título'])
            ->assertForbidden();
        $this->assertSame($tarefa->titulo, $tarefa->fresh()->titulo);

        $this->actingAs($usuario)->get(route('tarefas.historico'))->assertForbidden();
    }
}
