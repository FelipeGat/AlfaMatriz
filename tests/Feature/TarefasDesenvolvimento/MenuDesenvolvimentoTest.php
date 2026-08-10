<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O grupo "Desenvolvimento" no menu lateral: aparece para quem é da matriz e
 * leva ao quadro pela rota `tarefas.index`; some por completo para quem tem
 * escopo de revenda (T-061).
 */
class MenuDesenvolvimentoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-081 O menu leva ao quadro pelo grupo Desenvolvimento.
     */
    public function test_usuario_da_matriz_ve_o_grupo_desenvolvimento_levando_ao_quadro(): void
    {
        $usuario = User::factory()->create();

        $resposta = $this->actingAs($usuario)->get(route('centro-controle'));

        $resposta->assertOk();
        $resposta->assertSee('Desenvolvimento', escape: false);
        $resposta->assertSee('Tarefas', escape: false);
        $resposta->assertSee(route('tarefas.index'), escape: false);
    }

    /**
     * @spec:AC-094 Usuário de revenda não vê o grupo Desenvolvimento no menu.
     */
    public function test_usuario_de_revenda_nao_ve_o_grupo_desenvolvimento(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);

        $resposta = $this->actingAs($usuario)->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertDontSee('Desenvolvimento', escape: false);
        $resposta->assertDontSee(route('tarefas.index'), escape: false);
    }
}
