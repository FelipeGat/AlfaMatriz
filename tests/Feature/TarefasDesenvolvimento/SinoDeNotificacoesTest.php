<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Notificacao;
use App\Models\Revenda;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O sino da sidebar (§17): eventos gravados, não estado derivado.
 *
 * Aqui vivem os EVENTOS — o que aconteceu num instante e tem dono. As
 * CONDIÇÕES continuam na fila de ação do Centro de Controle, e o rodapé do
 * painel é a ponte entre as duas listas.
 */
class SinoDeNotificacoesTest extends TestCase
{
    use RefreshDatabase;

    private FluxoTarefaService $fluxo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fluxo = new FluxoTarefaService;
    }

    /**
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
            'titulo' => 'Webhook de pagamento',
        ]);

        return [$tarefa, $dev, $revisor];
    }

    /**
     * @spec:AC-201 Perguntar avisa quem deve a resposta — é o evento que existe para
     * a pessoa não precisar estar olhando o quadro.
     */
    public function test_perguntar_notifica_quem_deve_a_resposta(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Esse retorno vazio acontece?');

        $aviso = Notificacao::where('destinatario_id', $dev->id)->sole();

        $this->assertSame('pergunta', $aviso->tipo);
        $this->assertSame($tarefa->id, $aviso->tarefa_id);
        $this->assertStringContainsString('Camila Reis perguntou', $aviso->titulo);
        $this->assertStringContainsString('Webhook de pagamento', $aviso->titulo);
        $this->assertTrue($aviso->naoLida());

        // Quem perguntou não é avisado da própria pergunta: notificar o autor
        // enche o sino de eco e ensina a ignorá-lo.
        $this->assertSame(0, Notificacao::where('destinatario_id', $revisor->id)->count());
    }

    /**
     * @spec:AC-201 Responder devolve a bola, e o aviso é o que traz de volta quem
     * perguntou — senão ele ficaria voltando ao card para conferir.
     */
    public function test_responder_notifica_quem_perguntou(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Pergunta.');
        $this->fluxo->responder($tarefa->fresh(), $dev, 'Não acontece.');

        $aviso = Notificacao::where('destinatario_id', $revisor->id)->sole();

        $this->assertSame('resposta', $aviso->tipo);
        $this->assertStringContainsString('Rafael Lima respondeu', $aviso->titulo);
    }

    /**
     * @spec:AC-201 Ser devolvido é a notícia que menos pode esperar: a tarefa voltou
     * para a bancada e o trabalho recomeça. O aviso nomeia o portão que reprovou.
     */
    public function test_devolver_para_correcao_notifica_o_responsavel(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();
        $tarefa->update(['status' => 'em_staging']);

        $this->actingAs($revisor)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Quebrou ao subir.',
        ])->assertSessionMissing('erro');

        $aviso = Notificacao::where('destinatario_id', $dev->id)->sole();

        $this->assertSame('retorno', $aviso->tipo);
        $this->assertSame('atencao', $aviso->nivel);
        $this->assertStringContainsString('voltou para correção', $aviso->titulo);
        $this->assertSame('Voltou do staging', $aviso->meta);
    }

    /**
     * @spec:AC-202 O badge conta as não lidas, e o painel lista o que aconteceu.
     */
    public function test_o_badge_conta_as_nao_lidas_e_o_painel_lista(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Uma dúvida.');

        $html = $this->actingAs($dev)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Notificações', $html);
        $this->assertStringContainsString('Camila Reis perguntou', $html);
        $this->assertStringContainsString('Marcar lidas', $html);
        $this->assertStringContainsString('Ver tudo que exige ação', $html);

        // Quem não tem aviso nenhum não ganha badge — nem o botão "Marcar
        // lidas", que sobre uma lista vazia só ensina que ele não faz nada.
        $doRevisor = $this->actingAs($revisor)->get(route('tarefas.index'))->getContent();
        $this->assertStringNotContainsString('Marcar lidas', $doRevisor);
        $this->assertStringContainsString('Nada de novo por aqui', $doRevisor);
    }

    /**
     * @spec:AC-202 "Marcar lidas" fala do painel inteiro: é o botão do cabeçalho, e
     * marcar uma a uma seria a ação de outro desenho.
     */
    public function test_marcar_lidas_zera_o_badge_sem_apagar_o_historico(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Uma dúvida.');
        $this->assertSame(1, Notificacao::naoLidasDe($dev->id)->count());

        $this->actingAs($dev)->post(route('notificacoes.lidas'))->assertRedirect();

        $this->assertSame(0, Notificacao::naoLidasDe($dev->id)->count());

        // Lida não é apagada: o painel continua contando o que aconteceu.
        $this->assertSame(1, Notificacao::where('destinatario_id', $dev->id)->count());
    }

    /**
     * @spec:AC-202 Marcar lidas é só das SUAS: um POST não pode dar por lido o sino
     * de outra pessoa.
     */
    public function test_marcar_lidas_nao_alcanca_o_sino_alheio(): void
    {
        [$tarefa, $dev, $revisor] = $this->emRevisao();

        $this->fluxo->perguntar($tarefa, $revisor, 'Uma dúvida.');

        $this->actingAs($revisor)->post(route('notificacoes.lidas'))->assertRedirect();

        $this->assertSame(1, Notificacao::naoLidasDe($dev->id)->count());
    }

    /**
     * @spec:AC-202 O rodapé leva ao Centro de Controle só para quem o alcança: o menu
     * é montado a partir do que a pessoa PODE ver, e um endereço vazando pelo painel
     * contaria a ela sobre uma tela que não existe do lado dela.
     */
    public function test_o_rodape_nao_vaza_o_centro_de_controle_para_a_revenda(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);
        $daRevenda = User::factory()->create(['revenda_id' => $revenda->id]);

        $html = $this->actingAs($daRevenda)->get(route('clientes.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Notificações', $html);
        $this->assertStringNotContainsString('Ver tudo que exige ação', $html);
    }
}
