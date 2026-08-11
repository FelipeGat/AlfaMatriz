<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O quadro deixou de ser só do ciclo de desenvolvimento: o tipo da tarefa
 * escolhe por onde ela anda (US-054), existe uma parada explícita (US-055), o
 * recuo deixou de precisar de permissão (US-056) e o relatório de teste passou
 * a provar A PASSAGEM ATUAL, não a tarefa inteira (US-057).
 */
class TipoEFluxoTarefaTest extends TestCase
{
    use RefreshDatabase;

    private FluxoTarefaService $fluxo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fluxo = new FluxoTarefaService;
    }

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
        ], $atributos));
    }

    /**
     * @spec:AC-177 A tarefa operacional fecha direto de Em andamento, sem passar por
     * testes e sem relatório: exigir teste de um telefonema só ensinaria a marcar como
     * testado o que não foi.
     */
    public function test_tarefa_operacional_fecha_direto_de_em_andamento(): void
    {
        $tarefa = $this->criarTarefa([
            'tipo' => 'operacional',
            'titulo' => 'Entrar em contato com o fabricante do equipamento',
            'status' => 'em_desenvolvimento',
        ]);

        $movida = $this->fluxo->mover($tarefa, 'concluida');

        $this->assertSame('concluida', $movida->status);
        $this->assertDatabaseCount('tarefa_relatorios_teste', 0);

        // E ela nem chega a Em testes: a coluna não é destino dela.
        $emAndamento = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $this->assertNotContains('em_testes', FluxoTarefaService::transicoesDe($emAndamento));
    }

    /**
     * @spec:AC-178 O tipo abre um caminho, não afrouxa o outro: a tarefa de
     * desenvolvimento continua sem atalho de Em andamento para Concluída.
     */
    public function test_tarefa_de_desenvolvimento_nao_fecha_direto_de_em_andamento(): void
    {
        $tarefa = $this->criarTarefa([
            'tipo' => 'desenvolvimento',
            'status' => 'em_desenvolvimento',
        ]);

        try {
            $this->fluxo->mover($tarefa, 'concluida');
            $this->fail('Esperava recusa: desenvolvimento não fecha sem passar por Em testes.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-179 O tipo se anuncia no card e recorta o quadro — só a operacional
     * ganha selo, porque o que se precisa saber de relance é qual card vai pular a
     * coluna de testes.
     */
    public function test_tipo_aparece_no_card_e_filtra_o_quadro(): void
    {
        $usuario = User::factory()->create();

        $operacional = $this->criarTarefa([
            'tipo' => 'operacional', 'titulo' => 'Renovar o certificado', 'status' => 'backlog',
            'responsavel_id' => User::factory(),
        ]);
        $desenvolvimento = $this->criarTarefa([
            'tipo' => 'desenvolvimento', 'titulo' => 'Corrigir o boleto vencido', 'status' => 'backlog',
            'responsavel_id' => User::factory(),
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // Recorta o card de cada tarefa: a palavra "Operacional" também aparece
        // na explicação do tipo dentro do modal de cadastro, então procurá-la na
        // página inteira acusaria selo onde há só texto de ajuda.
        $this->assertStringContainsString('Operacional', $this->trechoDoCard($html, $operacional->id));
        $this->assertStringNotContainsString('Operacional', $this->trechoDoCard($html, $desenvolvimento->id),
            'Só a operacional se anuncia: um selo "Desenvolvimento" em quase todo card não diria nada.');

        $colunas = $this->actingAs($usuario)
            ->get(route('tarefas.index', ['tipo' => 'operacional']))
            ->assertOk()
            ->viewData('colunas');

        $ids = $colunas['backlog']->pluck('id')->all();
        $this->assertContains($operacional->id, $ids);
        $this->assertNotContains($desenvolvimento->id, $ids);
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
     * @spec:AC-180 Bloquear exige dizer o que trava: parar sem motivo só trocaria a
     * coluna em que o card apodrece, e é o motivo que permite a outra pessoa destravar
     * a tarefa depois.
     */
    public function test_bloquear_exige_dizer_o_que_trava(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_desenvolvimento']);

        try {
            $this->fluxo->mover($tarefa, 'bloqueada');
            $this->fail('Esperava recusa por falta de motivo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('travando a tarefa', $e->getMessage());
        }

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $movida = $this->fluxo->mover($tarefa, 'bloqueada', [
            'motivo' => 'Esperando o fabricante liberar o firmware.',
        ]);

        $this->assertSame('bloqueada', $movida->status);
        $this->assertDatabaseHas('tarefa_eventos', [
            'tarefa_id' => $tarefa->id,
            'para_status' => 'bloqueada',
            'motivo' => 'Esperando o fabricante liberar o firmware.',
        ]);
    }

    /**
     * @spec:AC-181 A tarefa bloqueada volta para a etapa de onde parou, e o tempo que
     * ficou esperando fica cronometrado — é o número que a etapa existe para produzir.
     */
    public function test_bloqueada_volta_para_onde_parou_e_o_tempo_parado_fica_registrado(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        $this->fluxo->mover($tarefa, 'bloqueada', ['motivo' => 'Esperando o cliente validar.']);

        // Quem bloqueou esperando validação estava em Em testes: devolver essa
        // tarefa para Em andamento diria que o código voltou para a bancada.
        $this->assertContains('em_testes', FluxoTarefaService::transicoesDe($tarefa->fresh()));

        $this->fluxo->mover($tarefa->fresh(), 'em_testes');

        $this->assertSame('em_testes', $tarefa->fresh()->status);

        $bloqueio = $tarefa->eventos()->where('para_status', 'bloqueada')->first();
        $this->assertNotNull($bloqueio->saiu_em);
        $this->assertNotNull($bloqueio->duracao_segundos);
    }

    /**
     * @spec:AC-182 De Em andamento a tarefa volta ao Backlog: quem começou e travou
     * precisa ter onde estacionar, senão o card fica apodrecendo numa coluna que diz
     * que alguém está trabalhando nele.
     */
    public function test_de_em_andamento_a_tarefa_volta_ao_backlog(): void
    {
        $tarefa = $this->criarTarefa([
            'status' => 'em_desenvolvimento',
            'responsavel_id' => User::factory(),
        ]);

        $movida = $this->fluxo->mover($tarefa, 'backlog');

        $this->assertSame('backlog', $movida->status);
    }

    /**
     * @spec:AC-183 De Em testes a tarefa volta a Em andamento sem declarar reprovação:
     * obrigar toda volta a virar "ajustes necessários" sujava o sinal de retrabalho —
     * a coluna deixava de dizer "a qualidade está ruim" e passava a dizer "alguém
     * clicou errado".
     */
    public function test_de_em_testes_a_tarefa_volta_sem_declarar_reprovacao(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        $movida = $this->fluxo->mover($tarefa, 'em_desenvolvimento');

        $this->assertSame('em_desenvolvimento', $movida->status);

        // A volta seca não inventa reprovação nenhuma: nada de relatório, e
        // nenhuma passagem por Ajustes necessários.
        $this->assertDatabaseCount('tarefa_relatorios_teste', 0);
        $this->assertSame(0, $tarefa->eventos()->where('para_status', 'ajustes_necessarios')->count());
    }

    /**
     * @spec:AC-184 A cancelada volta para a fila, e sem dono: cancelar é um clique, e
     * cancelar por engano custava o histórico inteiro, porque a única saída era
     * recadastrar a tarefa do zero.
     */
    public function test_cancelada_volta_para_a_fila_sem_dono(): void
    {
        $tarefa = $this->criarTarefa([
            'status' => 'cancelada',
            'responsavel_id' => User::factory(),
        ]);

        $movida = $this->fluxo->mover($tarefa, 'aberta');

        $this->assertSame('aberta', $movida->status);
        $this->assertNull($movida->responsavel_id);

        // Volta para a fila, não para o meio do fluxo: retomá-la é decisão nova.
        $this->assertNotContains('em_desenvolvimento', FluxoTarefaService::transicoesDe(
            $this->criarTarefa(['status' => 'cancelada'])
        ));
    }

    /**
     * @spec:AC-185 Direcionar move a tarefa, e tirar o dono a devolve para a fila: na
     * criação, escolher responsável já fazia a tarefa nascer no Backlog, mas na edição
     * o mesmo gesto a deixava em Aberta — o mesmo fato com dois comportamentos.
     */
    public function test_direcionar_move_para_o_backlog_e_tirar_o_dono_devolve_para_aberta(): void
    {
        $usuario = User::factory()->create();
        $dono = User::factory()->create();
        $tarefa = $this->criarTarefa(['status' => 'aberta']);

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'prioridade' => $tarefa->prioridade,
            'responsavel_id' => $dono->id,
        ])->assertSessionHasNoErrors();

        $this->assertSame('backlog', $tarefa->fresh()->status);

        // Pelo motor do fluxo, e não por um `update` cru: um card que troca de
        // coluna sem evento seria tempo de Aberta contado como tempo de Backlog.
        $this->assertDatabaseHas('tarefa_eventos', [
            'tarefa_id' => $tarefa->id,
            'de_status' => 'aberta',
            'para_status' => 'backlog',
        ]);

        $this->actingAs($usuario)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'prioridade' => $tarefa->prioridade,
            'responsavel_id' => null,
        ])->assertSessionHasNoErrors();

        $this->assertSame('aberta', $tarefa->fresh()->status);
        $this->assertNull($tarefa->fresh()->responsavel_id);
    }

    /**
     * @spec:AC-186 O relatório prova ESTA passagem por Em testes: a tarefa concluída,
     * reaberta e reconcluída passava pelo portão apoiada no "aprovado" do ciclo
     * anterior — o teste que provava o código de antes valia como prova do de depois.
     */
    public function test_reconcluir_depois_de_reabrir_exige_relatorio_novo(): void
    {
        $tarefa = $this->criarTarefa([
            'status' => 'backlog',
            'responsavel_id' => User::factory(),
        ]);

        $this->fluxo->mover($tarefa, 'em_desenvolvimento');
        $this->fluxo->mover($tarefa, 'em_testes');

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Ciclo 1 aprovado.',
        ]);

        $this->fluxo->mover($tarefa, 'concluida');
        $this->assertSame('concluida', $tarefa->fresh()->status);

        // Ciclo 2: o aprovado lá de trás não vale como prova do código novo.
        $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento');
        $this->fluxo->mover($tarefa->fresh(), 'em_testes');

        try {
            $this->fluxo->mover($tarefa->fresh(), 'concluida');
            $this->fail('Esperava recusa: o relatório aprovado é do ciclo anterior.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('relatório de teste aprovado', $e->getMessage());
        }

        $this->assertSame('em_testes', $tarefa->fresh()->status);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Ciclo 2 aprovado.',
        ]);

        $this->assertSame('concluida', $this->fluxo->mover($tarefa->fresh(), 'concluida')->status);
    }
}
