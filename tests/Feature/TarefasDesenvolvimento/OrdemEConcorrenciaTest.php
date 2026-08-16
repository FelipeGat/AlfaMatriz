<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Duas mãos no mesmo quadro: a guarda de concorrência ao mover e a ordem
 * escolhida à mão dentro da coluna (US-062).
 */
class OrdemEConcorrenciaTest extends TestCase
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
     * @spec:AC-208 Movimento sobre movimento alheio é recusado com o que aconteceu dito.
     * Antes o segundo envio ganhava em silêncio, e quem moveu primeiro só descobria ao
     * recarregar — se recarregasse.
     */
    public function test_mover_sobre_movimento_alheio_e_recusado(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        // Alguém já moveu enquanto o menu estava aberto na tela do outro.
        $tarefa->update(['status' => 'em_revisao']);

        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'backlog',
            'de_status' => 'em_desenvolvimento',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('Alguém já moveu esta tarefa', session('erro'));
        $this->assertStringContainsString('Em revisão', session('erro'));
        $this->assertSame('em_revisao', $tarefa->fresh()->status);

        // Com a etapa de origem certa, o movimento passa.
        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'de_status' => 'em_revisao',
            'motivo' => 'Falta tratar o retorno vazio da API.',
        ])->assertSessionMissing('erro');

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-209 A coluna arrumada à mão fica como foi arrumada; a intocada segue a
     * régua automática (AC-128). A ordem automática responde "o que é mais grave", que
     * não é a mesma pergunta que "qual eu pego primeiro".
     */
    public function test_coluna_arrumada_a_mao_manda_e_a_intocada_segue_a_regua(): void
    {
        $usuario = User::factory()->create();

        $critica = $this->criarTarefa(['titulo' => 'Crítica', 'prioridade' => 'critica']);
        $baixa = $this->criarTarefa(['titulo' => 'Baixa', 'prioridade' => 'baixa']);

        $colunaDe = fn () => $this->actingAs($usuario)->get(route('tarefas.index'))
            ->viewData('colunas')['em_desenvolvimento']->pluck('titulo')->all();

        // Sem ninguém posicionar, a gravidade manda.
        $this->assertSame(['Crítica', 'Baixa'], $colunaDe());

        // Alguém arruma: a baixa destrava a crítica, e quem conhece o trabalho
        // sabe disso — o quadro não tem como saber.
        $this->actingAs($usuario)->post(route('tarefas.posicionar'), [
            'status' => 'em_desenvolvimento',
            'ordem' => [$baixa->id, $critica->id],
        ])->assertSessionHasNoErrors();

        $this->assertSame(['Baixa', 'Crítica'], $colunaDe());
    }

    /**
     * @spec:AC-209 O card que chega depois entra no FIM da coluna arrumada, sem posição:
     * ele ainda não foi colocado em lugar nenhum. E mudar de etapa apaga a posição, que
     * é sempre dentro de UMA coluna.
     */
    public function test_quem_chega_depois_entra_no_fim_e_mudar_de_etapa_apaga_a_posicao(): void
    {
        $usuario = User::factory()->create();

        $primeira = $this->criarTarefa(['titulo' => 'Primeira', 'prioridade' => 'baixa']);
        $segunda = $this->criarTarefa(['titulo' => 'Segunda', 'prioridade' => 'baixa']);

        $this->actingAs($usuario)->post(route('tarefas.posicionar'), [
            'status' => 'em_desenvolvimento',
            'ordem' => [$primeira->id, $segunda->id],
        ]);

        // Uma crítica chega depois: mesmo sendo a mais grave, entra no fim.
        $this->criarTarefa(['titulo' => 'Recém-chegada', 'prioridade' => 'critica']);

        $coluna = $this->actingAs($usuario)->get(route('tarefas.index'))
            ->viewData('colunas')['em_desenvolvimento']->pluck('titulo')->all();

        $this->assertSame(['Primeira', 'Segunda', 'Recém-chegada'], $coluna);

        // E a posição não viaja com o card para outra coluna.
        $this->actingAs($usuario)->post(route('tarefas.mover', $primeira), ['status' => 'em_revisao']);

        $this->assertNull($primeira->fresh()->ordem);
    }

    /**
     * @spec:AC-209 Posicionar é organizar trabalho alheio, então segue a mesma capacidade
     * de priorizar e direcionar — e não alcança card de outra coluna, porque a lista de
     * ids vem do navegador.
     */
    public function test_posicionar_exige_triagem_e_nao_alcanca_outra_coluna(): void
    {
        $membro = User::factory()->membro()->create();
        $admin = User::factory()->create();

        $daColuna = $this->criarTarefa(['titulo' => 'Desta coluna']);
        $deOutra = $this->criarTarefa(['titulo' => 'De outra', 'status' => 'em_revisao']);

        $this->actingAs($membro)->post(route('tarefas.posicionar'), [
            'status' => 'em_desenvolvimento',
            'ordem' => [$daColuna->id],
        ])->assertSessionHas('erro');

        $this->assertNull($daColuna->fresh()->ordem);

        $this->actingAs($admin)->post(route('tarefas.posicionar'), [
            'status' => 'em_desenvolvimento',
            'ordem' => [$deOutra->id, $daColuna->id],
        ])->assertSessionHasNoErrors();

        $this->assertNull($deOutra->fresh()->ordem, 'Card de outra coluna não pode ser posicionado aqui.');
        $this->assertSame(2, $daColuna->fresh()->ordem);
    }

    /**
     * @spec:AC-210 Excluir apaga o registro e é só de quem triaga; cancelar encerra com
     * motivo e fica no histórico. A tela declara a diferença no lugar onde ela é
     * decidida, porque as duas palavras são sinônimos na cabeça de quem lê.
     */
    public function test_excluir_apaga_de_verdade_e_e_so_de_quem_triaga(): void
    {
        $membro = User::factory()->membro()->create();
        $admin = User::factory()->create();
        $tarefa = $this->criarTarefa();
        $tarefa->itens()->create(['texto' => 'Some junto']);

        // Para o membro a parada vem antes do controller: o middleware traduz
        // DELETE em ação "excluir", e o perfil dele não a tem. A trava do
        // controller continua valendo para um perfil que ganhe `excluir` sem
        // ganhar triagem — são capacidades diferentes.
        $this->actingAs($membro)->delete(route('tarefas.destroy', $tarefa))->assertForbidden();
        $this->assertDatabaseHas('tarefas', ['id' => $tarefa->id]);

        $this->actingAs($admin)->delete(route('tarefas.destroy', $tarefa))->assertSessionMissing('erro');

        // Sumiu de verdade: com SoftDeletes, um `delete()` deixaria a linha
        // invisível no quadro E no histórico, que é o pior dos dois mundos.
        $this->assertDatabaseMissing('tarefas', ['id' => $tarefa->id]);
        $this->assertDatabaseCount('tarefa_itens', 0);
    }

    /**
     * @spec:AC-210 O excluir fica no RODAPÉ do modal, na ponta oposta do Salvar, e
     * confirma em dois passos NO PRÓPRIO BOTÃO.
     *
     * Ele já morou embaixo de tudo, obedecendo ao "rodapé do detalhe" ao pé da letra —
     * e num modal que rola, depois do checklist e da conversa inteira, rodapé virou
     * fora da tela. Ação destrutiva que ninguém acha é tão quebrada quanto uma fácil
     * demais de apertar; a largura do modal separa uma da outra.
     */
    public function test_o_excluir_confirma_em_dois_passos_no_rodape(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->criarTarefa();

        // O rodapé é do MODAL, buscado à parte desde que o quadro parou de
        // imprimir um modal por tarefa.
        $html = $this->actingAs($usuario)->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        // Um botão só, que troca de rótulo no primeiro clique.
        $this->assertStringContainsString("confirmandoExclusao ? 'Confirmar exclusão' : 'Excluir'", $html);

        // E a nota que diz a diferença para cancelar aparece junto da confirmação:
        // sem ela, "excluir" e "cancelar" são sinônimos na cabeça de quem lê, e um
        // apaga o histórico que o outro existe para guardar.
        $this->assertStringContainsString('Apaga o registro. Para encerrar com rastro, cancele pelo menu Mover.', $html);

        // Vizinhos no mesmo rodapé: entre um e outro não cabe a conversa nem o
        // checklist, que é o que os separava antes.
        $posExcluir = strpos($html, "confirmandoExclusao ? 'Confirmar exclusão'");
        $posSalvar = strpos($html, "enviando ? 'Salvando…' : 'Salvar'");

        $this->assertNotFalse($posExcluir, 'Quem triaga precisa ver o excluir.');
        $this->assertNotFalse($posSalvar);

        $entre = substr($html, $posExcluir, $posSalvar - $posExcluir);

        $this->assertStringNotContainsString('Conversa', $entre);
        $this->assertStringNotContainsString('Checklist', $entre);
    }

    /**
     * @spec:AC-211 O card abre no clique E arrasta: o clique só vale se o ponteiro andou
     * menos de 4px e não houve arraste nos últimos 300ms. Sem as duas peneiras, o começo
     * de qualquer arrasto terminava com o modal aberto por cima do gesto.
     */
    public function test_o_card_separa_o_clique_do_arrasto(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('foiClique($event)', $html);
        $this->assertStringContainsString('marcarInicioDoClique($event)', $html);
        $this->assertStringContainsString('Date.now() - this.fimDoArrasto < 300', $html);
        $this->assertStringContainsString('return andou < 4;', $html);
    }

    /**
     * @spec:AC-352 A reordenação abre o vão ao vivo: a metade do vizinho decide o lado,
     * os vizinhos deslizam em vez de saltar, o vão é espaço VAZIO (o card na mão vive
     * só no fantasma sob o cursor), soltar em qualquer ponto da coluna confirma o que a
     * tela mostra, e o gesto abandonado devolve o card à vaga original. A linha de
     * inserção saiu — ela mentia no arrasto para baixo — e a cópia meio apagada no vão
     * saiu depois: lia como um segundo card.
     */
    public function test_a_reordenacao_abre_o_vao_ao_vivo_e_sem_linha(): void
    {
        $usuario = User::factory()->create();
        $this->criarTarefa();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O arraste entrega o elemento do card, e a metade do vizinho decide o lado.
        $this->assertStringContainsString('permitirSobreCard($event, $el', $html);
        $this->assertStringContainsString('r.top + r.height / 2', $html);

        // O vão abre deslizando (FLIP), e soltar confirma a prévia — em
        // qualquer ponto da coluna, não só sobre um card.
        $this->assertStringContainsString('abrirVao(', $html);
        $this->assertStringContainsString("transition = 'transform 150ms ease'", $html);
        $this->assertStringContainsString('this.confirmarVao();', $html);

        // O gesto que morre no meio tem caminho de volta.
        $this->assertStringContainsString('vagaOriginal', $html);

        // O vão é vazio: o card some da lista um quadro após o dragstart (para
        // o fantasma ser fotografado antes) e volta quando o gesto termina.
        $this->assertStringContainsString("el.classList.add('invisible')", $html);
        $this->assertStringContainsString("classList.remove('invisible')", $html);
        $this->assertStringNotContainsString('opacity-50 shadow-card-arrasto', $html);

        // A linha de inserção saiu de cena (o estado dela era `sobreCard`).
        $this->assertStringNotContainsString('sobreCard:', $html);
        $this->assertStringNotContainsString('this.sobreCard', $html);
    }

    /**
     * @spec:AC-212 A criação rápida vive no pé da coluna Aberta, e só nela: tarefa sem
     * responsável nasce lá, e o mesmo campo no pé do Backlog entregaria um card que
     * aparece na coluna ao lado.
     */
    public function test_criacao_rapida_existe_em_aberta_e_backlog_e_declara_a_coluna(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Duas colunas de fila, dois campos: Aberta e Backlog.
        $this->assertSame(2, substr_count($html, 'Enter para criar'));

        Carbon::setTestNow(now());

        $this->actingAs($usuario)->post(route('tarefas.store'), ['titulo' => 'Conferir o boleto da Orbe'])
            ->assertSessionHasNoErrors();

        $tarefa = Tarefa::firstWhere('titulo', 'Conferir o boleto da Orbe');

        $this->assertNotNull($tarefa, 'A criação rápida manda só o título — exigir prioridade a quebraria.');
        $this->assertSame('aberta', $tarefa->status);
        $this->assertSame('media', $tarefa->prioridade);

        // O campo do Backlog DECLARA a coluna. Sem isso o `booted` decidiria
        // pelo responsável e o card nasceria em Aberta — um controle que
        // promete um lugar e entrega outro.
        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Priorizada direto', 'status' => 'backlog',
        ])->assertSessionHasNoErrors();

        $this->assertSame('backlog', Tarefa::firstWhere('titulo', 'Priorizada direto')->status);

        // E só as duas colunas de fila são destino: criar direto em Em revisão
        // pularia o trabalho que a etapa existe para examinar.
        $this->actingAs($usuario)->post(route('tarefas.store'), [
            'titulo' => 'Pulando o fluxo', 'status' => 'em_revisao',
        ])->assertSessionHasErrors('status');
    }
}
