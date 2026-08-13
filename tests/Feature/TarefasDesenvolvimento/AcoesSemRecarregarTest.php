<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As ações feitas DENTRO do modal da tarefa não recarregam a tela.
 *
 * Mexer numa tarefa quase sempre acontece com ela aberta: marcar dois itens do
 * checklist, corrigir um comentário, responder a dúvida da tarja. Recarregar
 * ali cobrava o texto ainda não publicado do campo de comentário, a rolagem do
 * quadro e a posição na conversa — e o `tarefa-aberta` da sessão remendava só o
 * sintoma mais visível, reabrindo o modal depois de ele ter fechado.
 *
 * O contrato tem DOIS lados, e os dois são exercitados aqui:
 *
 * - com `Accept: application/json`, volta o quadro redesenhado e as regiões do
 *   modal, para serem trocadas no lugar;
 * - sem ele, volta o redirect de sempre. Esse não é herança a ser removida: é
 *   o que responde ao `<form>` puro, que é como estas ações continuam
 *   funcionando se o `fetch` falhar.
 */
class AcoesSemRecarregarTest extends TestCase
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
     * @spec:AC-229 Marcar um item do checklist devolve o quadro e as regiões do modal em
     * vez de um redirect — a tela não recarrega e o que estava escrito continua escrito.
     */
    public function test_mexer_no_checklist_devolve_os_pedacos_e_nao_um_redirect(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Corrigir o boleto da Orbe']);
        $item = $tarefa->itens()->create(['texto' => 'Conferir o valor']);

        $resposta = $this->actingAs($usuario)
            ->putJson(route('tarefas.itens.update', $item), ['feito' => '1']);

        $resposta->assertOk();
        $resposta->assertJsonStructure(['quadro', 'pedacos', 'tarefa', 'bloqueada', 'aviso']);
        $resposta->assertJsonPath('tarefa', $tarefa->id);

        $this->assertTrue($item->fresh()->feito);

        // O quadro volta INTEIRO, e não só o card: marcar um item muda o "1/1"
        // do rodapé do card, e travar uma tarefa mudaria o contador de WIP da
        // coluna e os chips do cabeçalho. Trocar só o card deixaria esses
        // números mentindo, que é pior do que recarregar.
        $quadro = $resposta->json('quadro');
        $this->assertStringContainsString('Corrigir o boleto da Orbe', $quadro);
        $this->assertStringContainsString('Quadro de tarefas', $quadro);

        // Só as regiões que a ação MEXEU. A conversa fica de fora de propósito:
        // trocá-la redesenharia por baixo de quem talvez esteja corrigindo um
        // comentário ali. E o formulário da tarefa não volta em região nenhuma
        // — é essa ausência que preserva o título editado.
        $pedacos = $resposta->json('pedacos');
        $this->assertSame([
            "checklist-{$tarefa->id}",
            "checklist-envios-{$tarefa->id}",
        ], array_keys($pedacos));

        $this->assertStringContainsString('Conferir o valor', $pedacos["checklist-{$tarefa->id}"]);
        $this->assertStringNotContainsString('name="titulo"', $resposta->getContent());
    }

    /**
     * @spec:AC-229 Sem `Accept: application/json` o caminho antigo continua inteiro: é o
     * que responde ao formulário enviado pelo navegador quando o `fetch` não roda.
     */
    public function test_sem_json_o_envio_continua_redirecionando_com_a_tarefa_aberta(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $item = $tarefa->itens()->create(['texto' => 'Conferir o valor']);

        $this->actingAs($usuario)
            ->put(route('tarefas.itens.update', $item), ['feito' => '1'])
            ->assertRedirect()
            ->assertSessionHas('tarefa-aberta', $tarefa->id);

        $this->assertTrue($item->fresh()->feito);
    }

    /**
     * @spec:AC-229 O quadro que volta é o quadro FILTRADO. Sem isto, marcar um item
     * dentro de um recorte devolveria o quadro inteiro e desfaria o filtro na tela.
     */
    public function test_o_quadro_que_volta_respeita_o_recorte_da_query_string(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa(['titulo' => 'Corrigir o boleto da Orbe']);
        $foraDoRecorte = $this->criarTarefa(['titulo' => 'Trocar o certificado do vigia']);
        $item = $tarefa->itens()->create(['texto' => 'Conferir o valor']);

        $quadro = $this->actingAs($usuario)
            ->putJson(route('tarefas.itens.update', $item).'?busca=boleto', ['feito' => '1'])
            ->assertOk()
            ->json('quadro');

        $this->assertStringContainsString('Corrigir o boleto da Orbe', $quadro);
        $this->assertStringNotContainsString($foraDoRecorte->titulo, $quadro);
    }

    /**
     * @spec:AC-229 O item novo volta com os envios DELE. Sem isso, corrigir ou apagar o
     * que se acabou de escrever apontaria para um formulário que não existe, e o botão
     * não faria nada — falha silenciosa, que é a pior de ler na tela.
     */
    public function test_o_item_recem_criado_ja_volta_com_os_envios_de_corrigir_e_apagar(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $pedacos = $this->actingAs($usuario)
            ->postJson(route('tarefas.itens.store', $tarefa), ['texto' => 'Avisar a revenda'])
            ->assertOk()
            ->json('pedacos');

        $item = $tarefa->fresh()->itens->firstWhere('texto', 'Avisar a revenda');

        $this->assertStringContainsString("item-{$item->id}", $pedacos["checklist-envios-{$tarefa->id}"]);
        $this->assertStringContainsString("apagar-item-{$item->id}", $pedacos["checklist-envios-{$tarefa->id}"]);
    }

    /**
     * @spec:AC-229 Travar avisa o rodapé do modal por um booleano, porque a decisão entre
     * "Marcar como bloqueada" e "Destravar tarefa" muda sem a página recarregar.
     */
    public function test_bloquear_devolve_o_estado_de_travada_e_a_tarja_no_quadro(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $bloqueio = $this->actingAs($usuario)->postJson(
            route('tarefas.bloquear', $tarefa),
            ['motivo' => 'Esperando o acesso do cliente'],
        );

        $bloqueio->assertOk();
        $bloqueio->assertJsonPath('bloqueada', true);
        $this->assertStringContainsString('Esperando o acesso do cliente', $bloqueio->json('quadro'));
        $this->assertStringContainsString(
            'Esperando o acesso do cliente',
            $bloqueio->json("pedacos.avisos-{$tarefa->id}"),
        );

        $destrave = $this->actingAs($usuario)->postJson(route('tarefas.bloquear', $tarefa));

        $destrave->assertOk();
        $destrave->assertJsonPath('bloqueada', false);
        $this->assertStringNotContainsString('Esperando o acesso do cliente', $destrave->json('quadro'));
    }

    /**
     * @spec:AC-229 A recusa do motor de fluxo também volta pelo caminho parcial. Um
     * `back()` cru trocaria a tela inteira por causa de um erro — e a frase que explica a
     * recusa é justamente o que se precisa ler sem perder de vista onde se estava.
     */
    public function test_a_recusa_do_fluxo_volta_como_aviso_e_nao_como_troca_de_tela(): void
    {
        $usuario = User::factory()->create();

        // Concluída é terminal: perguntar numa tarefa encerrada é recusado pelo
        // motor, e é o caminho mais curto até uma `RuntimeException`.
        $tarefa = $this->criarTarefa(['status' => 'concluida']);

        $resposta = $this->actingAs($usuario)->postJson(
            route('tarefas.conversar', $tarefa),
            ['corpo' => 'Isso ainda vale?'],
        );

        $resposta->assertOk();
        $this->assertNotNull($resposta->json('aviso'));
        $this->assertSame(0, $tarefa->fresh()->comentarios()->count());
    }

    /**
     * @spec:AC-229 Perguntar devolve a conversa já com o comentário publicado — é o que
     * substitui a recarga que antes fazia a frase aparecer na lista.
     */
    public function test_perguntar_devolve_a_conversa_com_o_comentario_publicado(): void
    {
        $quemPergunta = User::factory()->create();
        $outroLado = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $outroLado->id, 'status' => 'em_revisao']);

        $resposta = $this->actingAs($quemPergunta)->postJson(route('tarefas.conversar', $tarefa), [
            'corpo' => 'O botão some no celular — é de propósito?',
            'pergunta_para_id' => $outroLado->id,
        ]);

        $resposta->assertOk();
        $this->assertStringContainsString(
            'O botão some no celular',
            $resposta->json("pedacos.conversa-{$tarefa->id}"),
        );
        $this->assertStringContainsString('Aguardando resposta', $resposta->json('quadro'));
    }

    /**
     * @spec:AC-229 Excluir a tarefa fica de fora do caminho parcial de propósito: não há
     * para onde voltar. Apagada a tarefa, o modal não deve seguir aberto e o quadro
     * inteiro é outro — recarregar ali é a resposta certa.
     */
    public function test_excluir_a_tarefa_continua_recarregando_a_tela(): void
    {
        // A conta padrão da fábrica já triaga, e excluir é só de quem triaga.
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        $this->actingAs($usuario)
            ->delete(route('tarefas.destroy', $tarefa))
            ->assertRedirect(route('tarefas.index'));

        $this->assertNull(Tarefa::withTrashed()->find($tarefa->id));
    }
}
