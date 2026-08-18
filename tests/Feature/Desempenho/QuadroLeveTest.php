<?php

namespace Tests\Feature\Desempenho;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro imprimia o modal de edição de TODAS as tarefas: o formulário, a
 * lista de conferência, os anexos e a conversa inteira de cada card, ~45 KB por
 * tarefa. Medido em 14/08/2026, a tela pesava 382 KB com cinco tarefas e 5,5 MB
 * com cento e vinte — baixados a cada abertura para mostrar, no máximo, um
 * modal.
 *
 * O modal passou a ser buscado no clique. O que estes testes guardam é o par:
 * a tela ficou leve E a tarefa continua abrindo inteira.
 */
class QuadroLeveTest extends TestCase
{
    use RefreshDatabase;

    private function quadroCom(int $quantas, User $usuario): void
    {
        $sistema = Sistema::factory()->create();

        Tarefa::factory()->count($quantas)->create([
            'criado_por_id' => $usuario->id,
            'sistema_id' => $sistema->id,
            'status' => 'em_desenvolvimento',
        ])->each(function (Tarefa $tarefa) use ($usuario): void {
            TarefaComentario::create([
                'tarefa_id' => $tarefa->id,
                'autor_id' => $usuario->id,
                'corpo' => 'Uma frase da conversa desta tarefa.',
            ]);

            $tarefa->itens()->create(['texto' => 'Um item de conferência']);
        });
    }

    /**
     * @spec:AC-240 O HTML do quadro para de crescer com o volume: com 120
     * tarefas a tela fica abaixo de 2,1 MB, onde antes passava de 5 MB.
     *
     * O teto foi 2 MB e não 1,5 MB porque o que sobra depois dos modais é
     * card: ~16 KB cada, e cento e vinte deles dão 1,9 MB. Cortar mais exige
     * mexer em onde o painel de motivo aparece — está em Q-019, e é decisão
     * de produto.
     *
     * Recalibrado para 2,1 MB em 18/08/2026, pelo mesmo critério do teto do
     * `PainelDoMotivoTest`: o card ganhou a linha "Criada por" (pedido do
     * dono em 18/08/2026), ~180 bytes × 120 cards, e o teto antigo estava a
     * 8 KB do valor medido — perto demais para distinguir a regressão vigiada
     * (os modais de volta, +3,5 MB) de qualquer linha que o card ganhe de
     * propósito. Sobram ~86 KB de folga, e a regressão continua estourando.
     */
    public function test_o_html_do_quadro_para_de_crescer_com_o_volume(): void
    {
        $usuario = User::factory()->create();
        $this->quadroCom(120, $usuario);

        $bytes = strlen($this->actingAs($usuario)
            ->get(route('tarefas.index'))->assertOk()->getContent());

        $this->assertLessThan(2_100_000, $bytes, sprintf(
            'O quadro com 120 tarefas voltou a pesar %.1f MB — o modal de cada '.
            'tarefa voltou para o HTML da tela.', $bytes / 1_048_576
        ));
    }

    /**
     * @spec:AC-241 Clicar no card abre a tarefa inteira: o modal buscado no
     * clique traz os mesmos campos e o mesmo conteúdo que o quadro imprimia.
     */
    public function test_o_modal_buscado_no_clique_traz_a_tarefa_inteira(): void
    {
        $usuario = User::factory()->create(['name' => 'Rafael Lima']);
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'responsavel_id' => $usuario->id,
            'sistema_id' => Sistema::factory(),
            'status' => 'em_desenvolvimento',
            'titulo' => 'Webhook de pagamento',
            'resumo' => 'Baixa automática ao receber o retorno do gateway.',
        ]);
        $tarefa->itens()->create(['texto' => 'Conferir o valor']);
        TarefaComentario::create([
            'tarefa_id' => $tarefa->id,
            'autor_id' => $usuario->id,
            'corpo' => 'A homologação passou.',
        ]);

        $modal = $this->actingAs($usuario)->get(route('tarefas.modal', $tarefa))->assertOk();

        // O invólucro que o quadro procura pelo nome para mostrar o modal.
        $modal->assertSee("editar-tarefa-{$tarefa->id}", escape: false);

        // O formulário, a lista de conferência e a conversa — as três coisas
        // que o card não tem e o modal existe para mostrar.
        $modal->assertSee('Webhook de pagamento', escape: false);
        $modal->assertSee('Baixa automática ao receber o retorno do gateway.', escape: false);
        $modal->assertSee('Conferir o valor', escape: false);
        $modal->assertSee('A homologação passou.', escape: false);
        $modal->assertSee('name="titulo"', escape: false);
    }

    /**
     * @spec:AC-241 O modal fica FORA do quadro: quem abre a tela não baixa o
     * formulário de tarefa nenhuma.
     */
    public function test_o_quadro_nao_traz_mais_o_formulario_de_cada_tarefa(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Tarefa que aparece no card',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O card continua na tela — é o quadro, afinal.
        $this->assertStringContainsString('Tarefa que aparece no card', $html);

        // O modal dela, não. A âncora é o marcador do `x-modal`, e não o nome
        // solto: `editar-tarefa-1` continua aparecendo no `@click` do card.
        $ancora = 'open-modal.window="if ($event.detail == \'editar-tarefa-'.$tarefa->id.'\'';

        // A âncora é conferida CONTRA a rota do modal antes de servir de prova de
        // ausência: `assertStringNotContainsString` passa de graça quando o texto
        // procurado deixa de existir em qualquer lugar, e foi o que aconteceu ao
        // `x-modal` ganhar o esvaziar ao abrir (AC-363) — a frase mudou de forma
        // e a asserção continuou verde sem olhar mais para nada.
        $doModal = $this->actingAs($usuario)->get(route('tarefas.modal', $tarefa))->getContent();

        $this->assertStringContainsString($ancora, $doModal,
            'A âncora não é mais a do `x-modal`: a ausência abaixo não prova nada.');

        $this->assertStringNotContainsString($ancora, $html,
            'O modal da tarefa voltou para o HTML do quadro.');
    }

    /**
     * @spec:AC-242 As ações do modal continuam funcionando: salvar grava,
     * comentar publica e o item da lista de conferência marca — e a resposta
     * continua sendo a parcial que não recarrega a tela.
     */
    public function test_as_acoes_do_modal_continuam_funcionando(): void
    {
        $usuario = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $usuario->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Antes de salvar',
        ]);
        $item = $tarefa->itens()->create(['texto' => 'Conferir o valor']);

        // Salvar, com um comentário de carona — é o mesmo envio.
        $this->actingAs($usuario)->putJson(route('tarefas.update', $tarefa), [
            'titulo' => 'Depois de salvar',
            'status' => 'em_desenvolvimento',
            'de_status' => 'em_desenvolvimento',
            'comentario' => 'Publicado junto com o Salvar.',
        ])->assertOk();

        $this->assertSame('Depois de salvar', $tarefa->fresh()->titulo);
        $this->assertSame('Publicado junto com o Salvar.', $tarefa->comentarios()->latest('id')->value('corpo'));

        // Marcar o item: continua devolvendo as regiões do modal, sem trocar o
        // bloco de modais — é o que mantém a tarefa aberta enquanto se trabalha.
        $this->actingAs($usuario)
            ->putJson(route('tarefas.itens.update', $item), ['feito' => '1'])
            ->assertOk()
            ->assertJsonPath('modais', null);

        $this->assertTrue($item->fresh()->feito);

        // E o modal, buscado de novo, já mostra o que foi salvo: ele é o estado
        // ATUAL da tarefa, e não a foto de quando a tela abriu.
        $this->actingAs($usuario)->get(route('tarefas.modal', $tarefa))
            ->assertOk()
            ->assertSee('Depois de salvar', escape: false)
            ->assertSee('Publicado junto com o Salvar.', escape: false);
    }
}
