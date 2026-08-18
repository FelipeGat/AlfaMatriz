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

    /**
     * O modal vem da rota dele, e não fatiado do HTML do quadro.
     *
     * O quadro imprimia o modal de todas as tarefas, e este ajudante achava o
     * pedaço certo procurando o marcador do `x-modal`. Isso acabou: a tela
     * pesava 5,5 MB com 120 tarefas, e o modal passou a ser buscado no clique.
     * O que se lê aqui é o mesmo HTML da mesma partial — só que sem carregar o
     * quadro inteiro para chegar nele.
     */
    private function modalDe(Tarefa $tarefa, User $usuario): string
    {
        return $this->actingAs($usuario)
            ->get(route('tarefas.modal', $tarefa))
            ->assertOk()
            ->getContent();
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
     * @spec:AC-215 O retorno é o terceiro banner, e o modal é onde o motivo aparece
     * INTEIRO: na tarja do card ele é clamp de duas linhas, e quem abre a tarefa está
     * abrindo justamente para ler o que reprovou.
     */
    public function test_o_retorno_traz_o_portao_e_o_motivo_por_extenso(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();

        $motivo = 'A migração não roda com dado antigo: a coluna nova entra NOT NULL '
            .'e as linhas de 2024 ficam sem valor. Precisa de default ou de backfill antes.';

        app(FluxoTarefaService::class)->mover($tarefa, 'em_desenvolvimento', ['motivo' => $motivo]);

        $modal = $this->modalDe($tarefa->fresh(), $dono);

        $retorno = strpos($modal, 'Voltou da revisão');
        $this->assertNotFalse($retorno, 'O modal não nomeia o portão que reprovou.');
        $this->assertLessThan(strpos($modal, 'name="titulo"'), $retorno, 'O retorno vem antes do primeiro campo.');

        // Sem clamp: é a frase toda, e não o pedaço que coube no card.
        $this->assertStringContainsString(e($motivo), $modal);
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

    /**
     * @spec:AC-363 Desistir de uma tarefa não deixa rascunho para a próxima: o "nova
     * tarefa" é o único modal que o servidor nunca redesenha, e o que foi digitado e
     * abandonado continuava de pé no HTML até a abertura seguinte.
     */
    public function test_a_criacao_se_esvazia_ao_reabrir_e_a_edicao_nao(): void
    {
        [$tarefa, $dono] = $this->tarefaCompleta();

        $quadro = $this->actingAs($dono)->get(route('tarefas.index'))->assertOk()->getContent();

        // A marca é do FORMULÁRIO — `[^>]*` não atravessa o `>`, então o que
        // casa aqui é um atributo da própria tag, e não o seletor que o
        // `x-modal` imprime logo acima dela.
        $this->assertMatchesRegularExpression('/<form[^>]*data-esvazia-ao-abrir/', $quadro,
            'O formulário de nova tarefa não pede para ser esvaziado.');

        // A outra metade do contrato, e a razão de o teste existir: renomear um
        // lado sem o outro não quebra nada na tela — volta o rascunho, em
        // silêncio. `! show` entra na asserção porque é o que impede a tecla `n`
        // de apagar o que está sendo digitado num modal já aberto.
        $this->assertMatchesRegularExpression(
            '/open-modal\.window="if \([^"]*! show\)[^"]*form\[data-esvazia-ao-abrir\]/',
            $quadro,
            'O esvaziar não está preso à abertura de um modal que estava fechado.'
        );

        // A edição fica fora: ali os campos JÁ são o que está gravado, e o mesmo
        // `reset()` desfaria na tela a edição que acabou de ser salva.
        $this->assertDoesNotMatchRegularExpression(
            '/<form[^>]*data-esvazia-ao-abrir/',
            $this->modalDe($tarefa, $dono),
            'O formulário de edição não pode ser esvaziado ao abrir.'
        );
    }
}
