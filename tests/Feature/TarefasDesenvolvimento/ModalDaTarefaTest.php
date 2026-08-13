<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O modal da tarefa, contra o `design/AlfaMatriz Tarefas.dc.html`.
 *
 * Ele é a segunda tela mais densa do quadro e a única que junta cadastro,
 * checklist, conversa e ações destrutivas no mesmo lugar — cada seção com um
 * dono diferente. Sem estas asserções, a ordem dos campos e o lugar das ações
 * voltam a andar sozinhos a cada mexida.
 */
class ModalDaTarefaTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Tarefa, 1: User} */
    private function tarefaCompleta(): array
    {
        $dono = User::factory()->create(['name' => 'Rafael Lima']);
        $outro = User::factory()->create(['name' => 'Camila Reis']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $outro->id,
            'responsavel_id' => $dono->id,
            'sistema_id' => Sistema::factory(),
            'status' => 'em_revisao',
            'titulo' => 'Webhook de pagamento',
            'resumo' => 'Baixa automática ao receber o retorno do gateway.',
        ]);

        return [$tarefa, $dono];
    }

    private function modalDe(Tarefa $tarefa, User $usuario): string
    {
        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Âncora no marcador DO MODAL, e não no nome solto: `editar-tarefa-1`
        // aparece antes, no `@click` do card que abre o modal — ancorar ali
        // devolveria um pedaço do quadro em vez do formulário.
        $marca = 'open-modal.window="$event.detail == \'editar-tarefa-'.$tarefa->id.'\'';
        $inicio = strpos($html, $marca);
        $this->assertNotFalse($inicio, "O modal da tarefa {$tarefa->id} não apareceu.");

        return substr($html, $inicio, 30000);
    }

    /**
     * @spec:AC-215 O cabeçalho diz ONDE a tarefa está e HÁ QUANTO TEMPO — a primeira
     * pergunta de quem abre uma tarefa que se perdeu de vista.
     */
    public function test_o_cabecalho_traz_etapa_e_tempo(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();

        $modal = $this->modalDe($tarefa, $dono);

        $this->assertStringContainsString('Editar tarefa', $modal);
        $this->assertMatchesRegularExpression('/Em revisão · há \w+/u', $modal);

        // O ponto de 7px na cor da etapa, como no cabeçalho da coluna.
        $this->assertStringContainsString('h-[7px] w-[7px] shrink-0 rounded-full', $modal);
    }

    /**
     * @spec:AC-215 Os dois banners ficam LOGO ABAIXO do cabeçalho: eles respondem "por
     * que esta tarefa está parada" antes de qualquer campo. Enterrados no meio do
     * formulário, seriam lidos depois de a pessoa já ter decidido o que veio fazer.
     */
    public function test_os_banners_vem_antes_dos_campos(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();
        $fluxo = app(FluxoTarefaService::class);

        $fluxo->perguntar($tarefa, $tarefa->criadoPor, 'Isso acontece em produção?');
        $fluxo->bloquear($tarefa->fresh(), 'Esperando a credencial do financeiro.');

        $modal = $this->modalDe($tarefa, $dono);

        $pergunta = strpos($modal, 'Aguardando resposta de');
        $bloqueio = strpos($modal, 'Esperando a credencial do financeiro.');
        $titulo = strpos($modal, 'name="titulo"');

        $this->assertNotFalse($pergunta);
        $this->assertNotFalse($bloqueio);
        $this->assertLessThan($titulo, $pergunta, 'A pergunta em aberto vem antes do primeiro campo.');
        $this->assertLessThan($titulo, $bloqueio, 'O bloqueio vem antes do primeiro campo.');

        // E o destravar mora no próprio banner, ao lado do motivo que deixou de valer.
        $this->assertStringContainsString('Destravar', $modal);
    }

    /**
     * @spec:AC-216 A ordem dos campos: Título, Resumo, e então a grade Tipo,
     * Prioridade, Sistema, Responsável. O resumo faltava — e é ele que o card mostra
     * embaixo do título, então a única forma de preenchê-lo era pelo banco.
     */
    public function test_a_ordem_dos_campos_e_o_resumo_que_faltava(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();

        $modal = $this->modalDe($tarefa, $dono);

        $posicoes = [];
        foreach (['titulo', 'resumo', 'tipo', 'prioridade', 'sistema_id', 'responsavel_id'] as $campo) {
            $posicoes[$campo] = strpos($modal, 'name="'.$campo.'"');
            $this->assertNotFalse($posicoes[$campo], "O campo {$campo} precisa existir no modal.");
        }

        $this->assertSame(array_keys($posicoes), array_keys($posicoes));
        $this->assertTrue(
            $posicoes['titulo'] < $posicoes['resumo']
                && $posicoes['resumo'] < $posicoes['tipo']
                && $posicoes['tipo'] < $posicoes['prioridade']
                && $posicoes['prioridade'] < $posicoes['sistema_id']
                && $posicoes['sistema_id'] < $posicoes['responsavel_id'],
            'A ordem é Título, Resumo, Tipo, Prioridade, Sistema, Responsável.'
        );

        // O resumo é textarea, não input: ele quebra em duas linhas no cadastro
        // mesmo aparecendo em uma só no card.
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="resumo"/u', $modal);

        // E o resumo gravado volta preenchido — sem isso, salvar a tarefa por
        // qualquer outro motivo apagaria o que já estava lá.
        $this->assertStringContainsString('Baixa automática ao receber o retorno do gateway.', $modal);
    }

    /**
     * @spec:AC-217 Checklist com barra de progresso, alça de arrastar, edição no lugar
     * e a nota que o separa de subtarefa.
     */
    public function test_o_checklist_tem_progresso_alca_e_a_nota(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();
        $tarefa->itens()->create(['texto' => 'Primeiro', 'feito' => true]);
        $tarefa->itens()->create(['texto' => 'Segundo']);

        $modal = $this->modalDe($tarefa, $dono);

        $this->assertStringContainsString('Checklist', $modal);
        $this->assertStringContainsString('1/2', $modal);
        $this->assertStringContainsString('Arraste para reordenar', $modal);
        $this->assertStringContainsString('Remover item', $modal);
        $this->assertStringContainsString('Checklist não é subtarefa', $modal);
    }

    /**
     * @spec:AC-218 A seção chama-se Conversa, e cada comentário é um cartão com avatar,
     * autor e quando. "Comentários" descreveria um mural, onde ninguém deve nada a
     * ninguém — aqui a vez tem dono.
     */
    public function test_a_conversa_traz_cartoes_com_autor(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();
        $tarefa->comentarios()->create(['autor_id' => $dono->id, 'corpo' => 'Comentário meu']);
        $tarefa->comentarios()->create(['autor_id' => $tarefa->criado_por_id, 'corpo' => 'Comentário dela']);

        $modal = $this->modalDe($tarefa, $dono);

        $this->assertStringContainsString('Conversa', $modal);
        $this->assertStringNotContainsString('>Comentários<', $modal);

        $this->assertStringContainsString('Rafael Lima', $modal);
        $this->assertStringContainsString('Camila Reis', $modal);
        $this->assertStringContainsString('Comentário meu', $modal);

        // Corrigir e apagar só nos próprios: o botão some, e a rota também
        // recusa — a tela e o servidor dizem a mesma coisa.
        $this->assertSame(1, substr_count($modal, 'Corrigir este comentário'));
        $this->assertSame(1, substr_count($modal, 'Apagar este comentário'));
    }

    /**
     * @spec:AC-219 O rodapé: bloquear à esquerda, excluir em dois passos, sair à
     * direita. Travar revela o campo do motivo em vez de enviar — um POST sem texto
     * seria recusado com uma frase que a pessoa não pediu.
     */
    public function test_o_rodape_bloqueia_e_exclui_em_dois_passos(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();

        $modal = $this->modalDe($tarefa, $dono);

        $this->assertStringContainsString('Marcar como bloqueada', $modal);
        $this->assertStringContainsString('O que está travando?', $modal);
        $this->assertStringContainsString("confirmandoExclusao ? 'Confirmar exclusão' : 'Excluir'", $modal);
        $this->assertStringContainsString('Apaga o registro. Para encerrar com rastro, cancele pelo menu Mover.', $modal);
    }

    /**
     * @spec:AC-220 Sem triagem, Prioridade e Responsável SOMEM — não aparecem
     * desabilitados. Campo travado à vista é um convite recusado toda vez que se olha
     * para ele, e a tela passaria a conversar sobre uma permissão em vez de sobre a
     * tarefa. A ausência é dita uma vez, no lugar onde os campos estariam.
     */
    public function test_sem_triagem_os_dois_campos_somem_com_a_ausencia_dita(): void
    {
        [$tarefa] = $this->tarefaCompleta();
        $membro = User::factory()->membro()->create();

        $modal = $this->modalDe($tarefa, $membro);

        $this->assertStringNotContainsString('name="prioridade"', $modal);
        $this->assertStringNotContainsString('name="responsavel_id"', $modal);
        // "Somem" e não "desabilitados": campo travado à vista é um convite
        // recusado toda vez que se olha para ele. O `disabled` do Salvar, que
        // existe para o duplo clique, não conta — a asserção mira em CAMPO.
        $this->assertDoesNotMatchRegularExpression('/<(select|input)[^>]*\sdisabled/u', $modal);

        $this->assertStringContainsString('definidos na triagem', $modal);

        // E os campos que continuam sendo dele seguem lá.
        $this->assertStringContainsString('name="titulo"', $modal);
        $this->assertStringContainsString('name="resumo"', $modal);

        // Excluir é só de quem triaga.
        $this->assertStringNotContainsString("'Confirmar exclusão'", $modal);
    }

    /**
     * O cabeçalho abre com o número da tarefa: é daqui que ele é copiado para
     * pedi-la a alguém, e a tela que mostra a tarefa inteira era justamente a
     * que não dizia como ela se chama.
     */
    public function test_o_cabecalho_abre_com_o_numero_da_tarefa(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();

        $modal = $this->modalDe($tarefa, $dono);

        $this->assertStringContainsString('#'.$tarefa->id.' · Em revisão', $modal);
    }
}
