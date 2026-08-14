<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Em raias quem rola na vertical é o QUADRO INTEIRO — as faixas empilham.
 *
 * Cada coluna, porém, é um contêiner de rolagem próprio, e ela carregava
 * `overscroll-y-contain`. `overscroll-behavior: contain` corta o encadeamento
 * da rolagem para o pai: com o cursor sobre um card — ou seja, na maior parte
 * da tela — a roda do mouse morria na coluna e o quadro não andava. Rolar para
 * baixo virava caçar um vão entre os cards.
 *
 * Sem raias a contenção é CERTA e continua: ali a coluna é quem rola, o quadro
 * não rola na vertical, e conter evita que a página role junto quando a lista
 * da coluna chega ao fim.
 */
class RolagemEmRaiasTest extends TestCase
{
    use RefreshDatabase;

    private function semear(User $usuario): void
    {
        Tarefa::factory()->count(4)->create([
            'criado_por_id' => $usuario->id,
            'responsavel_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);
    }

    /**
     * @spec:AC-257 Em raias, a coluna não corta a rolagem do quadro: com o
     * cursor sobre um card, a roda continua rolando a tela.
     */
    public function test_em_raias_a_coluna_nao_engole_a_rolagem(): void
    {
        $usuario = User::factory()->create();
        $this->semear($usuario);

        $html = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['raias' => 'responsavel']))
            ->assertOk()
            ->getContent();

        // A lista de cards continua rolando por dentro quando a célula estoura…
        $this->assertStringContainsString('data-cards=', $html);

        // …mas sem cortar a corrente para o quadro, que é quem rola em raias.
        $this->assertStringNotContainsString('overscroll-y-contain', $html,
            'A coluna voltou a conter a rolagem em raias — com o cursor sobre '.
            'um card, o quadro para de rolar.');
    }

    /**
     * @spec:AC-257 Sem raias a contenção fica: ali a coluna é quem rola, e sem
     * ela a página inteira rolaria junto ao fim da lista.
     */
    public function test_sem_raias_a_contencao_continua(): void
    {
        $usuario = User::factory()->create();
        $this->semear($usuario);

        $html = $this->actingAs($usuario)
            ->get(route('tarefas.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('overscroll-y-contain', $html,
            'A coluna perdeu a contenção no quadro sem raias — ao fim da lista, '.
            'a página passa a rolar junto.');
    }
}
