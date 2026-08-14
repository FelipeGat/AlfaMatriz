<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A pergunta da revisão: um ponteiro que diz DE QUEM É A VEZ, e não uma fila de
 * perguntas. Dúvida não é bloqueio (o PR continua aberto e a tarefa continua no
 * WIP) nem correção, e a contagem de rodadas vive fora do ponteiro para
 * sobreviver à resposta que o apaga.
 */
class PerguntaNaRevisaoTest extends TestCase
{
    use RefreshDatabase;

    private FluxoTarefaService $fluxo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fluxo = new FluxoTarefaService;
    }

    /**
     * Uma tarefa em revisão com dev e revisor já definidos.
     *
     * @return array{0: Tarefa, 1: User, 2: User}
     */
    private function emRevisao(): array
    {
        $dev = User::factory()->create(['name' => 'Rafael Lima']);
        $revisor = User::factory()->create(['name' => 'Camila Reis']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $revisor->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_revisao',
        ]);

        return [$tarefa, $dev, $revisor];
    }

    /**
     * @spec:AC-194 Perguntar aponta para o outro lado sem escolher destinatário:
     * numa revisão só há dois lados.
     */
    public function test_perguntar_passa_a_bola_para_o_outro_lado(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $comentario = $this->fluxo->perguntar($tarefa, $revisor, 'Esse retorno vazio pode acontecer em produção?');

        $tarefa->refresh();

        $this->assertSame($revisor->id, $tarefa->pergunta_de_id);
        $this->assertSame($dev->id, $tarefa->pergunta_para_id);
        $this->assertNotNull($tarefa->pergunta_em);
        $this->assertTrue($tarefa->esperaRespostaDe($dev));
        $this->assertFalse($tarefa->esperaRespostaDe($revisor));

        // A pergunta vira comentário marcado: sem a marca, a linha do tempo
        // mostra a pergunta como comentário comum e a resposta perde a que ela
        // responde.
        $this->assertTrue($comentario->pergunta);
        $this->assertSame('Esse retorno vazio pode acontecer em produção?', $comentario->corpo);
    }

    /**
     * @spec:AC-195 A tarefa NÃO sai da etapa e NÃO conta como travada: responder é
     * rápido, e fingir que ela saiu de circulação seria mentira. Uma dúvida de vinte
     * minutos diluiria o sinal de um bloqueio de seis dias.
     */
    public function test_perguntar_nao_move_a_tarefa_nem_a_trava(): void
    {
        [$tarefa, , $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Dá para simplificar esse trecho?');

        $tarefa->refresh();

        $this->assertSame('em_revisao', $tarefa->status);
        $this->assertFalse($tarefa->estaBloqueada());
        $this->assertSame(0, $tarefa->eventos()->count());
    }

    /**
     * @spec:AC-196 A rodada só anda quando a bola estava com quem pergunta.
     *
     * Cinco dúvidas mandadas de uma vez são uma rodada, e insistir sem ter
     * recebido resposta é a MESMA rodada — senão quem cobra retorno inflaria
     * sozinho um contador que existe para medir idas E voltas.
     */
    public function test_insistir_sem_resposta_nao_abre_rodada_nova(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        // Sem pergunta aberta, a bola é de quem fala: primeira rodada.
        $this->fluxo->perguntar($tarefa, $revisor, 'Primeira dúvida.');
        $this->assertSame(1, $tarefa->fresh()->rodadas);

        // O mesmo revisor insiste antes de qualquer resposta: mesma rodada.
        $this->fluxo->perguntar($tarefa->fresh(), $revisor, 'E mais uma coisa.');
        $this->fluxo->perguntar($tarefa->fresh(), $revisor, 'Ah, e outra.');
        $this->assertSame(1, $tarefa->fresh()->rodadas);

        // O dev responde: a bola volta para o revisor.
        $this->fluxo->responder($tarefa->fresh(), $dev, 'Não acontece: o gateway sempre devolve o campo.');
        $tarefa->refresh();

        $this->assertNull($tarefa->pergunta_em);
        $this->assertNull($tarefa->pergunta_para_id);
        $this->assertFalse($tarefa->temPergunta());

        // A contagem sobrevive à resposta que apagou o ponteiro — é para isso
        // que ela mora fora dele.
        $this->assertSame(1, $tarefa->rodadas);

        // E a próxima pergunta, agora com a bola de quem pergunta, abre rodada.
        $this->fluxo->perguntar($tarefa->fresh(), $revisor, 'Então e no caso do estorno?');
        $this->assertSame(2, $tarefa->fresh()->rodadas);
    }

    /**
     * @spec:AC-196 Responder é de quem deve a resposta, e só.
     */
    public function test_so_responde_quem_esta_com_a_bola(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        try {
            $this->fluxo->responder($tarefa, $dev, 'Respondendo sem pergunta.');
            $this->fail('Esperava recusa: não há pergunta aberta.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Não há pergunta aberta', $e->getMessage());
        }

        $this->fluxo->perguntar($tarefa, $revisor, 'Por que esse índice?');

        try {
            $this->fluxo->responder($tarefa->fresh(), $revisor, 'Respondendo a mim mesmo.');
            $this->fail('Esperava recusa: a pergunta não é para quem perguntou.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('não é para você', $e->getMessage());
        }

        $this->assertTrue($tarefa->fresh()->esperaRespostaDe($dev));
    }

    /**
     * @spec:AC-197 Na terceira rodada o quadro acende: três idas e voltas quer dizer
     * que o PR está grande demais ou que a tarefa foi mal especificada. O sinal vive
     * na contagem de RODADAS, e não na de comentários — cinco dúvidas mandadas de uma
     * vez são uma rodada só, e não são sintoma de nada.
     */
    public function test_terceira_rodada_acende_o_sinal(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        foreach (['Primeira.', 'Segunda.', 'Terceira.'] as $indice => $pergunta) {
            $this->fluxo->perguntar($tarefa->fresh(), $revisor, $pergunta);

            $this->assertSame($indice + 1, $tarefa->fresh()->rodadas);

            if ($indice < 2) {
                $this->fluxo->responder($tarefa->fresh(), $dev, 'Respondido.');
            }
        }

        $this->assertTrue($tarefa->fresh()->conversaEmpacada());
    }

    /**
     * @spec:AC-197 Devolver para correção resolve o impasse e zera a contagem: é
     * exatamente o que o alerta de terceira rodada sugere fazer, e manter o número
     * deixaria o card vermelho para sempre avisando de algo já tratado.
     */
    public function test_devolver_para_correcao_zera_as_rodadas(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Primeira.');
        $this->fluxo->responder($tarefa->fresh(), $dev, 'Respondido.');
        $this->fluxo->perguntar($tarefa->fresh(), $revisor, 'Segunda.');

        $this->assertSame(2, $tarefa->fresh()->rodadas);

        $devolvida = $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', [
            'motivo' => 'O PR está grande demais; quebre em dois.',
        ]);

        $this->assertSame(0, $devolvida->rodadas);
        $this->assertFalse($devolvida->conversaEmpacada());
    }

    /**
     * @spec:AC-195 Mudar de etapa apaga o ponteiro — a dúvida era sobre o trabalho
     * daquela etapa —, mas o interlocutor fica: esquecer com quem se estava falando é
     * o que persistir esse campo existe para evitar.
     */
    public function test_mudar_de_etapa_apaga_o_ponteiro_e_guarda_o_interlocutor(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Isso não deveria estar no service?');

        $movida = $this->fluxo->mover($tarefa->fresh(), 'em_staging');

        $this->assertNull($movida->pergunta_em);
        $this->assertNull($movida->pergunta_de_id);
        $this->assertNull($movida->pergunta_para_id);
        $this->assertSame($dev->id, $movida->interlocutor_id);
    }

    /**
     * @spec:AC-194 O chip "N p/ você" é a caixa de entrada de quem abre o quadro: sem
     * ele, saber que alguém espera por você exigiria abrir os cards um a um.
     */
    public function test_o_escopo_lista_so_o_que_espera_por_voce(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Cabe um teste para esse caso?');

        $outra = Tarefa::factory()->create([
            'criado_por_id' => $revisor->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_revisao',
        ]);

        $this->assertSame(
            [$tarefa->id],
            Tarefa::esperandoRespostaDe($dev->id)->pluck('id')->all()
        );
        $this->assertSame([], Tarefa::esperandoRespostaDe($revisor->id)->pluck('id')->all());
        $this->assertNotContains($outra->id, Tarefa::esperandoRespostaDe($dev->id)->pluck('id')->all());
    }

    /**
     * @spec:AC-194 A rota é uma só para os dois sentidos, como a de bloquear: na tela
     * é uma tarja com um botão que alterna conforme de quem é a vez.
     */
    public function test_a_rota_pergunta_ou_responde_conforme_de_quem_e_a_vez(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->actingAs($revisor)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Esse retorno vazio acontece?',
        ])->assertSessionMissing('erro');

        $this->assertTrue($tarefa->fresh()->esperaRespostaDe($dev));

        // O mesmo endereço, agora do outro lado, responde em vez de perguntar.
        $this->actingAs($dev)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Não acontece.',
        ])->assertSessionMissing('erro');

        $this->assertFalse($tarefa->fresh()->temPergunta());
        $this->assertSame(1, $tarefa->fresh()->rodadas);
        $this->assertSame(2, $tarefa->comentarios()->count());
    }

    /**
     * @spec:AC-194 Pergunta vazia é recusada com a frase dita, e não com um 422 cru.
     */
    public function test_pergunta_vazia_e_recusada(): void
    {
        [$tarefa, , $revisor] = $this->emRevisao();

        $this->actingAs($revisor)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => '   ',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('escrever a pergunta', session('erro'));
        $this->assertFalse($tarefa->fresh()->temPergunta());
    }

    /**
     * @spec:AC-198 A tarja de pergunta no card: o nome de quem deve a resposta ocupa
     * LINHA PRÓPRIA. Na primeira linha cabem o ícone e o selo de rodada, e
     * "Aguardando resposta de Camila" truncado ali some justamente a informação
     * inteira da tarja.
     */
    public function test_a_tarja_de_pergunta_nomeia_quem_deve_a_resposta(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Esse retorno vazio acontece em produção?');

        $html = $this->actingAs($revisor)->get(route('tarefas.index'))->assertOk()->getContent();
        $card = $this->trechoDoCard($html, $tarefa->id);

        // O rótulo e o NOME ficam em linhas separadas: na primeira cabem o
        // ícone e o tempo, e "Aguardando resposta de Rafael Lima" ali seria
        // truncado justamente na parte que importa.
        $this->assertStringContainsString('Aguardando resposta', $card);
        $this->assertStringContainsString('Rafael Lima', $card);
        $this->assertStringContainsString('Esse retorno vazio acontece em produção?', $card);
        $this->assertStringContainsString('1ª rodada', $card);

        // A tarja é da cor da marca, e não do alerta: perguntar não é problema,
        // e pintá-la de âmbar junto do bloqueio ensinaria que é.
        $this->assertStringContainsString('border-color: rgb(var(--brand))', $card);
    }

    /**
     * @spec:AC-197 Na terceira rodada o selo vira crítico — é o quadro dizendo que a
     * conversa deixou de ser conversa.
     */
    public function test_o_selo_de_rodada_acende_na_terceira(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Primeira.');
        $cardCalmo = $this->trechoDoCard(
            $this->actingAs($revisor)->get(route('tarefas.index'))->getContent(), $tarefa->id
        );
        $this->assertStringContainsString('color: rgb(var(--ink-mute))', $cardCalmo);

        $this->fluxo->responder($tarefa->fresh(), $dev, 'Respondido.');
        $this->fluxo->perguntar($tarefa->fresh(), $revisor, 'Segunda.');
        $this->fluxo->responder($tarefa->fresh(), $dev, 'Respondido.');
        $this->fluxo->perguntar($tarefa->fresh(), $revisor, 'Terceira.');

        $cardAceso = $this->trechoDoCard(
            $this->actingAs($revisor)->get(route('tarefas.index'))->getContent(), $tarefa->id
        );

        $this->assertStringContainsString('3ª rodada', $cardAceso);
        $this->assertStringContainsString('color: rgb(var(--crit))', $cardAceso);
        $this->assertStringContainsString('devolver para correção', $cardAceso);
    }

    /**
     * @spec:AC-198 Responder abre o campo NO CARD — a resposta de uma dúvida é curta,
     * e obrigar a abrir a tarefa para escrever duas linhas é o atrito que faz a
     * pergunta ficar sem resposta. E o botão é só de quem está com a bola.
     */
    public function test_responder_abre_no_card_e_so_para_quem_deve_a_resposta(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Cabe um teste aqui?');

        $doDev = $this->trechoDoCard(
            $this->actingAs($dev)->get(route('tarefas.index'))->getContent(), $tarefa->id
        );
        $this->assertStringContainsString(route('tarefas.conversar', $tarefa), $doDev);
        $this->assertStringContainsString('Sua resposta…', $doDev);

        // Para quem perguntou, a tarja informa e não oferece campo: responder a
        // si mesmo é a ação que o motor do fluxo já recusa.
        $doRevisor = $this->trechoDoCard(
            $this->actingAs($revisor)->get(route('tarefas.index'))->getContent(), $tarefa->id
        );
        $this->assertStringContainsString('Aguardando resposta', $doRevisor);
        $this->assertStringNotContainsString('Sua resposta…', $doRevisor);
    }

    /**
     * @spec:AC-199 A tarja de retorno nomeia o PORTÃO que reprovou: "Voltou do staging"
     * e "Voltou da revisão" descrevem recuperações diferentes, e era esse detalhe que
     * a coluna única de Ajustes achatava.
     */
    public function test_a_tarja_de_retorno_nomeia_o_portao(): void
    {
        [$tarefa, , $revisor] = $this->emRevisao();
        $tarefa->update(['status' => 'em_staging']);

        $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', [
            'motivo' => 'Quebrou ao subir: a migração não roda com dado antigo.',
        ]);

        $card = $this->trechoDoCard(
            $this->actingAs($revisor)->get(route('tarefas.index'))->getContent(), $tarefa->id
        );

        $this->assertStringContainsString('Voltou do staging', $card);
        $this->assertStringContainsString('a migração não roda com dado antigo', $card);
        $this->assertStringNotContainsString('Voltou da revisão', $card);
    }

    /**
     * @spec:AC-200 O chip "N p/ você" é o PRIMEIRO do cabeçalho do quadro e é a caixa
     * de entrada da pessoa: sem ele, saber que alguém espera uma resposta sua dependia
     * de olhar a coluna certa. Ele conta o quadro INTEIRO — caixa de entrada que
     * esconde mensagem porque há um filtro ligado deixa de ser caixa de entrada.
     */
    public function test_o_chip_conta_o_quadro_inteiro_e_filtra_ao_ser_clicado(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $outra = Tarefa::factory()->create([
            'criado_por_id' => $revisor->id,
            'responsavel_id' => $dev->id,
            'status' => 'em_desenvolvimento',
            'titulo' => 'Sem conversa nenhuma',
        ]);

        $this->fluxo->perguntar($tarefa, $revisor, 'Pergunta.');

        // Zerado o chip não some — fica apagado. Some, o cabeçalho mudaria de
        // forma conforme o dia, e quem abre o quadro numa terça calma não
        // descobriria que o recorte existe. Para o revisor ele diz ZERO.
        $this->actingAs($revisor)->get(route('tarefas.index'))->assertOk()->assertSee('0 p/ você');

        $doDev = $this->actingAs($dev)->get(route('tarefas.index'))->assertOk();
        $doDev->assertSee('1 p/ você');
        $doDev->assertSee('situacao=esperando_mim', escape: false);

        // Mesmo com um filtro que esconde a tarefa, o chip continua anunciando.
        $this->actingAs($dev)
            ->get(route('tarefas.index', ['busca' => 'Sem conversa nenhuma']))
            ->assertOk()
            ->assertSee('1 p/ você');

        // E o recorte do chip mostra só o que espera por ele.
        $colunas = $this->actingAs($dev)
            ->get(route('tarefas.index', ['situacao' => 'esperando_mim']))
            ->assertOk()
            ->viewData('colunas');

        $ids = collect($colunas)->flatMap->pluck('id')->all();
        $this->assertContains($tarefa->id, $ids);
        $this->assertNotContains($outra->id, $ids);
    }

    /**
     * O HTML de um card só, do `data-tarefa` dele até o fim do `<article>`.
     */
    private function trechoDoCard(string $html, int $tarefaId): string
    {
        $inicio = strpos($html, 'data-tarefa="'.$tarefaId.'"');
        $this->assertNotFalse($inicio, "A tarefa {$tarefaId} não apareceu no quadro.");

        $fim = strpos($html, '</article>', $inicio);

        return substr($html, $inicio, $fim - $inicio);
    }

    /**
     * @spec:AC-206 Sem outro lado, o botão Perguntar APARECE e pergunta a quem passar
     * a vez — nunca some em silêncio.
     *
     * O caso é comum e não é erro: a tarefa é sua e ninguém entrou na conversa ainda.
     * Esconder o botão tiraria o único caminho de quem mais precisa dele, e sem dizer
     * por quê.
     */
    public function test_sem_outro_lado_a_tela_pergunta_a_quem_passar_a_vez(): void
    {
        $dono = User::factory()->create(['name' => 'Rafael Lima']);
        $outra = User::factory()->create(['name' => 'Camila Reis']);

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dono->id,
            'responsavel_id' => $dono->id,
            'status' => 'em_revisao',
        ]);

        $this->assertNull($tarefa->outroLadoDe($dono), 'Sem responsável alheio nem interlocutor, não há lado.');

        // A escolha de destinatário mora no modal, que é buscado à parte desde
        // que o quadro parou de imprimir um modal por tarefa.
        $html = $this->actingAs($dono)->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringContainsString('Perguntar', $html);
        $this->assertStringContainsString('Perguntar a quem…', $html);
        $this->assertStringContainsString('esta tarefa ainda não tem outro lado', $html);
        $this->assertStringContainsString('Camila Reis', $html);

        // A escolha vale: a pergunta vai para quem foi escolhido.
        $this->actingAs($dono)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Consegue olhar este trecho comigo?',
            'pergunta_para_id' => $outra->id,
        ])->assertSessionMissing('erro');

        $tarefa->refresh();

        $this->assertSame($outra->id, $tarefa->pergunta_para_id);
        $this->assertSame($outra->id, $tarefa->interlocutor_id);
        $this->assertSame(1, $tarefa->rodadas);
    }

    /**
     * @spec:AC-206 Com outro lado, o quadro aponta SOZINHO: numa revisão só há dois
     * lados, e oferecer escolha onde não há escolha abriria a porta para mandar a
     * pergunta a quem não está na conversa.
     */
    public function test_com_outro_lado_nao_ha_escolha_e_a_escolhida_e_ignorada(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();
        $estranho = User::factory()->create(['name' => 'Quem passava por ali']);

        $html = $this->actingAs($revisor)->get(route('tarefas.modal', $tarefa))->assertOk()->getContent();

        $this->assertStringNotContainsString('Perguntar a quem…', $html);
        $this->assertStringContainsString('passa a vez para o outro lado', $html);

        // Mesmo mandando um destinatário à mão, o lado conhecido manda.
        $this->actingAs($revisor)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Dúvida.',
            'pergunta_para_id' => $estranho->id,
        ])->assertSessionMissing('erro');

        $this->assertSame($dev->id, $tarefa->fresh()->pergunta_para_id);
    }

    /**
     * @spec:AC-206 Sem lado e sem escolha, a recusa DIZ o que falta — e o que falta é
     * uma informação que a tela pede, não um erro de quem escreveu.
     */
    public function test_sem_lado_e_sem_escolha_a_recusa_diz_o_que_falta(): void
    {
        $dono = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $dono->id,
            'responsavel_id' => $dono->id,
            'status' => 'em_revisao',
        ]);

        $this->actingAs($dono)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Uma dúvida solta.',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('Escolha para quem vai a pergunta', session('erro'));
        $this->assertFalse($tarefa->fresh()->temPergunta());

        // E não dá para perguntar a si mesmo.
        $this->actingAs($dono)->post(route('tarefas.conversar', $tarefa), [
            'corpo' => 'Uma dúvida solta.',
            'pergunta_para_id' => $dono->id,
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('ir para outra pessoa', session('erro'));
    }

    /**
     * @spec:AC-194 Tarefa encerrada não tem conversa em aberto: a bola não fica com
     * ninguém depois que o card sai do quadro.
     */
    public function test_tarefa_encerrada_nao_recebe_pergunta(): void
    {
        [$tarefa, , $revisor] = $this->emRevisao();
        $tarefa->update(['status' => 'concluida']);

        try {
            $this->fluxo->perguntar($tarefa, $revisor, 'E aquilo lá?');
            $this->fail('Esperava recusa: tarefa encerrada.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Tarefa encerrada', $e->getMessage());
        }
    }
}
