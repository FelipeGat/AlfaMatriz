<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Em raias o cabeçalho das etapas fica FIXO no topo — as faixas empilham e a
 * rolagem vira vertical, então sem ele a pessoa perde de vista em que coluna
 * está olhando na terceira raia.
 *
 * Ele era pintado com `bg-board`, e `--board` é um VÉU: `rgba(0,0,0,0.28)` no
 * tema escuro. Como fundo do quadro isso é certo, ele recua sobre o canvas da
 * página. Como fundo de barra fixa, não: 72% dela é buraco, e os cards
 * continuavam aparecendo através do cabeçalho enquanto se rolava. No tema claro
 * `--board` é sólido e o defeito não aparecia — foi assim que ele passou.
 */
class CabecalhoFixoEmRaiasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-251 O cabeçalho fixo das raias é OPACO nos dois temas: ele empilha
     * o véu do quadro sobre a base da página, em vez de usar só o véu.
     */
    public function test_o_cabecalho_fixo_das_raias_e_opaco(): void
    {
        $usuario = User::factory()->create();
        Tarefa::factory()->count(3)->create([
            'criado_por_id' => $usuario->id,
            'responsavel_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);

        $html = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['raias' => 'responsavel']))
            ->assertOk()
            ->getContent();

        // A barra fixa existe — é o modo raias que a traz.
        $this->assertStringContainsString('sticky top-0', $html,
            'O cabeçalho das etapas deixou de ficar fixo no topo em raias.');

        // E ela carrega a BASE junto com o véu. Sem a base, o tema escuro deixa
        // os cards atravessarem o cabeçalho.
        $this->assertStringContainsString('background: var(--board), rgb(var(--canvas))', $html,
            'A barra fixa voltou a ser pintada só com o véu translúcido — no tema '.
            'escuro os cards aparecem através dela ao rolar.');

        // A classe `bg-board` sozinha na barra fixa é o defeito: ela não pode
        // voltar. (O quadro inteiro continua usando `bg-board`, e deve.)
        $this->assertStringNotContainsString('sticky top-0 z-10 shrink-0 flex gap-[10px] bg-board', $html,
            'A barra fixa voltou a ser pintada só com `bg-board`.');
    }
}
