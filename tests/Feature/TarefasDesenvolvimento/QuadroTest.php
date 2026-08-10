<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro do ciclo de desenvolvimento: a rota abre para quem tem permissão
 * de `tarefas`, e as sete colunas do ciclo aparecem na ordem certa, cada uma
 * com a contagem de tarefas que está nela (T-060).
 */
class QuadroTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-081 A rota tarefas.index abre o quadro para usuário da matriz com permissão de tarefas.
     */
    public function test_rota_do_quadro_abre_para_usuario_da_matriz(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
    }

    /**
     * @spec:AC-082 O quadro mostra as etapas do ciclo na ordem, com a contagem de cada uma.
     */
    public function test_quadro_mostra_etapas_na_ordem_com_contagem(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create();

        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'sistema_id' => $sistema->id, 'status' => 'aberta']);
        Tarefa::factory()->count(2)->create(['criado_por_id' => $criador->id, 'status' => 'backlog']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'em_desenvolvimento']);
        Tarefa::factory()->count(3)->create(['criado_por_id' => $criador->id, 'status' => 'em_testes']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'ajustes_necessarios']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'concluida']);
        Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'cancelada']);

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();

        $etapas = $resposta->viewData('etapas');

        $this->assertSame(
            ['aberta', 'backlog', 'em_desenvolvimento', 'em_testes', 'ajustes_necessarios', 'concluida', 'cancelada'],
            array_column($etapas, 'chave')
        );

        $quantidades = array_column($etapas, 'quantidade', 'chave');
        $this->assertSame(1, $quantidades['aberta']);
        $this->assertSame(2, $quantidades['backlog']);
        $this->assertSame(1, $quantidades['em_desenvolvimento']);
        $this->assertSame(3, $quantidades['em_testes']);
        $this->assertSame(1, $quantidades['ajustes_necessarios']);
        $this->assertSame(1, $quantidades['concluida']);
        $this->assertSame(1, $quantidades['cancelada']);

        // A ordem também vale na renderização, não só no array de apoio.
        $conteudo = $resposta->getContent();
        $rotulos = ['Aberta', 'Backlog', 'Em desenvolvimento', 'Em testes', 'Ajustes necessários', 'Concluída', 'Cancelada'];
        $posicoes = collect($rotulos)->map(fn ($rotulo) => strpos($conteudo, $rotulo));

        $this->assertTrue($posicoes->every(fn ($p) => $p !== false));
        $this->assertSame($posicoes->sort()->values()->all(), $posicoes->values()->all());
    }
}
