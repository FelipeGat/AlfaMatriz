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

    /**
     * @spec:AC-258 A barra fixa tem borda inferior: esconder o card sem marcar
     * ONDE ele some faz parecer que ele foi cortado no meio por nada.
     */
    public function test_a_barra_fixa_marca_onde_o_card_some(): void
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

        // A borda tem de estar NA BARRA, e não numa qualquer da página — então
        // a asserção recorta a tag da barra e olha só dentro dela. Fixar a
        // lista inteira de classes tornaria o teste refém de qualquer ajuste de
        // espaçamento, o que já aconteceu uma vez.
        $inicio = strpos($html, '<div class="sticky top-0');
        $this->assertNotFalse($inicio, 'A barra fixa das etapas sumiu do quadro em raias.');

        $tag = substr($html, $inicio, strpos($html, '>', $inicio) - $inicio);

        $this->assertStringContainsString('border-b border-line', $tag,
            'A barra fixa das raias perdeu a borda inferior — o card volta a '.
            'parecer cortado no meio em vez de deslizar para trás dela.');
    }

    /**
     * @spec:AC-259 Não sobra fresta acima da barra fixa: o respiro de cima mora
     * DENTRO dela, senão o card reaparece acima do cabeçalho depois de passar.
     */
    public function test_nao_sobra_fresta_acima_da_barra_fixa(): void
    {
        $usuario = User::factory()->create();
        Tarefa::factory()->count(3)->create([
            'criado_por_id' => $usuario->id,
            'responsavel_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
        ]);

        $comRaias = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['raias' => 'responsavel']))
            ->assertOk()
            ->getContent();

        // O contêiner de rolagem perde o respiro DE CIMA: é ele que empurrava o
        // content box para baixo e criava a fresta que o `sticky` não alcança.
        $this->assertStringContainsString('overflow-auto px-3.5 pb-3.5', $comRaias);
        $this->assertStringNotContainsString('overflow-auto px-3.5 pb-3.5 pt-3.5', $comRaias,
            'O respiro de cima voltou para o contêiner — a fresta volta com ele.');

        // E o respiro reaparece DENTRO da barra, onde o fundo dela o pinta.
        $this->assertStringContainsString('sticky top-0 z-10 shrink-0 flex gap-[10px] pt-3.5', $comRaias,
            'A barra fixa perdeu o respiro próprio — em repouso o cabeçalho '.
            'passa a encostar na borda do quadro.');

        // Sem raias não há barra para carregar o respiro: ele fica no contêiner.
        $semRaias = $this->actingAs($usuario)
            ->get(route('tarefas.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('overflow-auto px-3.5 pb-3.5 pt-3.5', $semRaias,
            'O quadro sem raias perdeu o respiro de cima — as colunas encostam '.
            'na borda.');
    }
}
