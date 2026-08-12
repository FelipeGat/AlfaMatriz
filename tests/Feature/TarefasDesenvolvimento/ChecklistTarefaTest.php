<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O checklist da tarefa — e checklist, não subtarefa: o item não tem
 * responsável nem etapa, não entra no limite de WIP e não vai para o histórico
 * (US-059).
 */
class ChecklistTarefaTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_desenvolvimento',
        ], $atributos));
    }

    /**
     * @spec:AC-196 O item entra no fim da lista e a ordem é de quem escreve, não a de
     * criação: um checklist é uma sequência — "conferir isto ANTES daquilo" —, e o item
     * lembrado depois pode ser o primeiro passo.
     */
    public function test_item_entra_no_fim_e_a_lista_pode_ser_reordenada(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        foreach (['Conferir o boleto', 'Avisar a revenda', 'Fechar o chamado'] as $texto) {
            $this->actingAs($usuario)
                ->post(route('tarefas.itens.store', $tarefa), ['texto' => $texto])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(
            ['Conferir o boleto', 'Avisar a revenda', 'Fechar o chamado'],
            $tarefa->fresh()->itens->pluck('texto')->all()
        );

        // A ordem chega inteira, e não como "mova X para N": arrastar reordena
        // a lista toda na tela, e mandar só o movimento faria o servidor
        // recalcular o que o navegador já sabe.
        $ids = $tarefa->fresh()->itens->pluck('id')->all();

        $this->actingAs($usuario)->post(route('tarefas.itens.ordenar', $tarefa), [
            'ordem' => [$ids[2], $ids[0], $ids[1]],
        ])->assertSessionHasNoErrors();

        $this->assertSame(
            ['Fechar o chamado', 'Conferir o boleto', 'Avisar a revenda'],
            $tarefa->fresh()->itens->pluck('texto')->all()
        );
    }

    /**
     * @spec:AC-197 Marcar, desmarcar e corrigir o texto são o mesmo gesto de mexer no
     * item. Diferente do comentário, o item NÃO é do autor: checklist é combinado do
     * time, e quem confere um passo raramente é quem o escreveu.
     */
    public function test_item_e_marcado_desmarcado_e_corrigido_por_qualquer_um(): void
    {
        $autor = User::factory()->create();
        $outra = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $item = $tarefa->itens()->create(['texto' => 'Conferir o boleto']);

        $this->assertFalse($item->feito);

        // Outra pessoa marca o item que não escreveu.
        $this->actingAs($outra)->put(route('tarefas.itens.update', $item), ['feito' => '1'])
            ->assertSessionHasNoErrors();
        $this->assertTrue($item->fresh()->feito);

        // E desmarca: a caixa desmarcada manda `feito=0`, senão o item nunca
        // voltaria a ser pendente.
        $this->actingAs($outra)->put(route('tarefas.itens.update', $item), ['feito' => '0']);
        $this->assertFalse($item->fresh()->feito);

        $this->actingAs($autor)->put(route('tarefas.itens.update', $item), [
            'texto' => 'Conferir o boleto de agosto',
        ]);
        $this->assertSame('Conferir o boleto de agosto', $item->fresh()->texto);

        // Texto em branco não apaga o item: quem quis remover tem o remover.
        $this->actingAs($autor)->put(route('tarefas.itens.update', $item), ['texto' => '   ']);
        $this->assertSame('Conferir o boleto de agosto', $item->fresh()->texto);

        $this->actingAs($autor)->delete(route('tarefas.itens.destroy', $item));
        $this->assertDatabaseMissing('tarefa_itens', ['id' => $item->id]);
    }

    /**
     * @spec:AC-198 O card mostra o progresso do checklist, e só quando há checklist:
     * "0/0" anunciaria como pendência uma tarefa que simplesmente não tem lista.
     */
    public function test_o_card_mostra_o_progresso_so_quando_ha_checklist(): void
    {
        $usuario = User::factory()->create();

        $semLista = $this->criarTarefa(['titulo' => 'Sem checklist']);
        $comLista = $this->criarTarefa(['titulo' => 'Com checklist']);

        $comLista->itens()->create(['texto' => 'Um', 'feito' => true]);
        $comLista->itens()->create(['texto' => 'Dois']);
        $comLista->itens()->create(['texto' => 'Três']);

        $this->assertNull($semLista->fresh()->progressoDoChecklist());
        $this->assertSame(['feitos' => 1, 'total' => 3], $comLista->fresh()->progressoDoChecklist());

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('1/3', $html);
        $this->assertStringContainsString('1 de 3 itens do checklist concluídos', $html);
    }

    /**
     * @spec:AC-199 Reordenar não alcança checklist de outra tarefa: a lista de ids chega
     * do navegador, e um id estranho no meio dela regravaria a ordem alheia.
     */
    public function test_reordenar_ignora_item_de_outra_tarefa(): void
    {
        $usuario = User::factory()->create();
        $minha = $this->criarTarefa();
        $alheia = $this->criarTarefa();

        $meuItem = $minha->itens()->create(['texto' => 'Meu item']);
        $itemAlheio = $alheia->itens()->create(['texto' => 'Item de outra tarefa']);

        $ordemOriginal = $itemAlheio->ordem;

        $this->actingAs($usuario)->post(route('tarefas.itens.ordenar', $minha), [
            'ordem' => [$itemAlheio->id, $meuItem->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame($ordemOriginal, $itemAlheio->fresh()->ordem,
            'A ordem de item de outra tarefa não pode ser tocada.');
        $this->assertSame(2, $meuItem->fresh()->ordem);
    }

    /**
     * @spec:AC-200 O item morre com a tarefa: apagar a tarefa não pode deixar checklist
     * órfão apontando para uma linha que não existe mais.
     */
    public function test_apagar_a_tarefa_leva_o_checklist_junto(): void
    {
        $tarefa = $this->criarTarefa();
        $item = $tarefa->itens()->create(['texto' => 'Some junto']);

        $tarefa->forceDelete();

        $this->assertDatabaseMissing('tarefa_itens', ['id' => $item->id]);
    }

    /**
     * @spec:AC-201 Revenda não alcança o checklist: as rotas dele seguem a mesma trava
     * do quadro (AC-095), senão o backlog interno vazaria por uma porta lateral.
     */
    public function test_revenda_nao_alcanca_as_rotas_do_checklist(): void
    {
        $tarefa = $this->criarTarefa();
        $item = $tarefa->itens()->create(['texto' => 'Interno']);

        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);

        $this->actingAs($usuario)->post(route('tarefas.itens.store', $tarefa), ['texto' => 'x'])->assertForbidden();
        $this->actingAs($usuario)->put(route('tarefas.itens.update', $item), ['feito' => '1'])->assertForbidden();
        $this->actingAs($usuario)->delete(route('tarefas.itens.destroy', $item))->assertForbidden();
        $this->actingAs($usuario)->post(route('tarefas.itens.ordenar', $tarefa), ['ordem' => [$item->id]])->assertForbidden();

        $this->assertDatabaseHas('tarefa_itens', ['id' => $item->id, 'feito' => false]);
    }
}
