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

        // E ela nem chega aos portões do ciclo: não são destino dela.
        $emAndamento = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $this->assertNotContains('em_revisao', FluxoTarefaService::transicoesDe($emAndamento));
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
            $this->fail('Esperava recusa: desenvolvimento não fecha sem passar pelos portões.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-179 O tipo se anuncia no card e recorta o quadro — só a operacional
     * ganha selo, porque o que se precisa saber de relance é qual card vai pular os
     * portões do ciclo.
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
        $this->assertStringContainsString('Oper.', $this->trechoDoCard($html, $operacional->id));
        $this->assertStringNotContainsString('Oper.', $this->trechoDoCard($html, $desenvolvimento->id),
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
            $this->fluxo->bloquear($tarefa, '   ');
            $this->fail('Esperava recusa por falta de motivo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('travando a tarefa', $e->getMessage());
        }

        $this->assertFalse($tarefa->fresh()->estaBloqueada());

        $travada = $this->fluxo->bloquear($tarefa, 'Esperando o fabricante liberar o firmware.');

        $this->assertTrue($travada->estaBloqueada());
        $this->assertSame('Esperando o fabricante liberar o firmware.', $travada->bloqueio_motivo);
    }

    /**
     * @spec:AC-181 A tarefa travada NÃO sai da etapa: o bloqueio é marca, não lugar.
     * Como coluna, ele apagava onde a tarefa estava — e o fluxo tinha de reconstruir
     * isso na mão para não devolver à bancada o código que estava em teste.
     */
    public function test_a_tarefa_travada_nao_sai_da_etapa(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_revisao']);

        $travada = $this->fluxo->bloquear($tarefa, 'Esperando o cliente validar.');

        $this->assertSame('em_revisao', $travada->status);
        $this->assertTrue($travada->estaBloqueada());

        // "Bloqueada agora", e não "Bloqueada há agora": a régua curta devolve
        // a palavra "agora" no primeiro minuto, e concatenar o "há" nela dá uma
        // frase que não existe.
        $this->assertSame('Bloqueada agora', $travada->rotuloDoBloqueio());
        $this->assertSame('Bloqueada há 2d', $travada->fresh()
            ->forceFill(['bloqueado_em' => now()->subDays(2)])->rotuloDoBloqueio());

        // Nenhum evento de etapa nasce do bloqueio: `tarefa_eventos` mede
        // permanência em ETAPA, e uma linha aqui faria o cronômetro contar duas
        // passagens pela etapa onde houve uma só.
        $this->assertSame(0, $tarefa->eventos()->count());

        $solta = $this->fluxo->destravar($travada);

        $this->assertSame('em_revisao', $solta->status);
        $this->assertFalse($solta->estaBloqueada());
    }

    /**
     * @spec:AC-190 Mudar de etapa destrava. O bloqueio é sempre sobre o trabalho de uma
     * etapa — "esperando o cliente validar" é uma frase sobre Em testes —, e carregá-lo
     * adiante faria o card anunciar um impedimento que já não vale.
     */
    public function test_mover_a_tarefa_tira_a_marca_de_travada(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_revisao']);

        $this->fluxo->bloquear($tarefa, 'Esperando o cliente validar.');

        $movida = $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', [
            'motivo' => 'Reprovado na leitura: falta tratar o retorno vazio.',
        ]);

        $this->assertSame('em_desenvolvimento', $movida->status);
        $this->assertFalse($movida->estaBloqueada());
        $this->assertNull($movida->bloqueio_motivo);
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
     * @spec:AC-183 Voltar de um portão não inventa reprovação de teste.
     *
     * O critério original dizia que a volta de Em testes NÃO declarava
     * reprovação — a coluna de Ajustes era opcional, e obrigá-la sujava o sinal
     * de retrabalho. Com Ajustes eliminado, a devolução passou a ser o próprio
     * ato de reprovar e cobra o motivo (AC-087); o que sobrevive daqui é o
     * outro lado da regra: a volta não fabrica relatório de teste nenhum, e o
     * card não passa por etapa que não existe mais.
     */
    public function test_voltar_de_um_portao_nao_inventa_relatorio(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_revisao']);

        $movida = $this->fluxo->mover($tarefa, 'em_desenvolvimento', ['motivo' => 'Falta tratar o retorno vazio.']);

        $this->assertSame('em_desenvolvimento', $movida->status);

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
     * @spec:AC-186 O relatório prova ESTA passagem pelo staging: a tarefa concluída,
     * reaberta e reconcluída passava pelo portão apoiada no "aprovado" do ciclo
     * anterior — o teste que provava o código de antes valia como prova do de depois.
     */
    public function test_reliberar_depois_de_reabrir_exige_relatorio_novo(): void
    {
        $tarefa = $this->criarTarefa([
            'status' => 'backlog',
            'responsavel_id' => User::factory(),
        ]);

        $this->fluxo->mover($tarefa, 'em_desenvolvimento');
        $this->fluxo->mover($tarefa, 'em_revisao');
        $this->fluxo->mover($tarefa->fresh(), 'em_staging');

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Ciclo 1 aprovado.',
        ]);

        $this->fluxo->mover($tarefa->fresh(), 'em_producao', ['versao_producao' => 'v1.4.2']);

        // O ciclo 1 também passa pela conferência no ar: o carimbo do staging
        // ficou preso à passagem dele, e o portão da entrega lê o desta.
        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Ciclo 1 conferido no ar.',
        ]);

        $this->fluxo->mover($tarefa->fresh(), 'concluida');
        $this->assertSame('concluida', $tarefa->fresh()->status);

        // Ciclo 2: o aprovado lá de trás não vale como prova do código novo.
        $this->fluxo->mover($tarefa->fresh(), 'em_desenvolvimento', ['motivo' => 'Erro apareceu em produção.']);
        $this->fluxo->mover($tarefa->fresh(), 'em_revisao');
        $this->fluxo->mover($tarefa->fresh(), 'em_staging');

        try {
            $this->fluxo->mover($tarefa->fresh(), 'em_producao', ['versao_producao' => 'v1.5.0']);
            $this->fail('Esperava recusa: a validação aprovada é do ciclo anterior.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar o staging', $e->getMessage());
        }

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Ciclo 2 aprovado.',
        ]);

        $this->assertSame(
            'em_producao',
            $this->fluxo->mover($tarefa->fresh(), 'em_producao', ['versao_producao' => 'v1.5.0'])->status,
        );
    }

    /**
     * @spec:AC-187 A versão também prova ESTA passagem: reabrir limpa
     * `versao_producao`, senão a reconclusão passava no portão apoiada na
     * versão do ciclo anterior — a mesma fresta do relatório de teste, no
     * outro carimbo.
     */
    public function test_reconcluir_depois_de_reabrir_exige_versao_nova(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'concluida']);
        $tarefa->forceFill(['versao_producao' => 'v1.4.2'])->save();

        $this->fluxo->mover($tarefa, 'em_desenvolvimento', ['motivo' => 'Erro no fechamento do mês.']);

        $this->assertNull($tarefa->fresh()->versao_producao);

        $this->fluxo->mover($tarefa->fresh(), 'em_revisao');
        $this->fluxo->mover($tarefa->fresh(), 'em_staging');

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Reteste aprovado.',
        ]);

        // A versão é cobrada onde a tag SOBE — na entrada de Em produção.
        try {
            $this->fluxo->mover($tarefa->fresh(), 'em_producao');
            $this->fail('Esperava recusa: a versão registrada era do ciclo anterior.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('versão que subiu', $e->getMessage());
        }

        $this->fluxo->mover($tarefa->fresh(), 'em_producao', ['versao_producao' => 'v1.5.0']);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Conferido no ar.',
        ]);

        $movida = $this->fluxo->mover($tarefa->fresh(), 'concluida');

        $this->assertSame('v1.5.0', $movida->versao_producao);
    }

    /**
     * @spec:AC-090 Reabrir a operacional cobra o mesmo motivo, mas fala a
     * língua do tipo: "Voltou da produção" anunciaria uma produção que um
     * telefonema não teve.
     */
    public function test_reabrir_operacional_cobra_motivo_e_fala_a_lingua_do_tipo(): void
    {
        $tarefa = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'concluida']);

        try {
            $this->fluxo->mover($tarefa, 'em_desenvolvimento');
            $this->fail('Esperava recusa: reabrir sem dizer por quê.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('o que precisa ser corrigido', $e->getMessage());
        }

        $reaberta = $this->fluxo->mover(
            $tarefa->fresh(),
            'em_desenvolvimento',
            ['motivo' => 'Fabricante não retornou o chamado.'],
        );

        $this->assertSame('concluida', $reaberta->retorno_de);
        $this->assertSame('Reaberta', $reaberta->rotuloDoRetorno());
    }

    /**
     * @spec:AC-187 Concluída significa EM PRODUÇÃO, e a versão é o que liga a tarefa
     * à tag que subiu. Sem ela, "concluída" volta a ser uma afirmação que ninguém
     * consegue conferir depois — era o que a conclusão a partir de Em testes fazia,
     * marcando como pronta a tarefa cujo código estava só no staging.
     */
    public function test_concluir_exige_a_versao_que_subiu(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Staging conferido.',
        ]);

        // A cobrança mudou de porta junto com o fluxo: a versão é pedida onde a
        // tag SOBE. Pedida só no encerramento, a coluna da conferência não
        // dizia o que estava no ar — que é a primeira coisa que quem vai
        // conferir precisa saber.
        try {
            $this->fluxo->mover($tarefa, 'em_producao');
            $this->fail('Esperava recusa por falta da versão.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('versão que subiu', $e->getMessage());
        }

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        $noAr = $this->fluxo->mover($tarefa, 'em_producao', ['versao_producao' => 'v1.4.2']);

        $this->assertSame('em_producao', $noAr->status);
        $this->assertSame('v1.4.2', $noAr->versao_producao);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id, 'aprovado' => true, 'notas' => 'Conferido no ar.',
        ]);

        $movida = $this->fluxo->mover($tarefa->fresh(), 'concluida');

        $this->assertSame('concluida', $movida->status);
        $this->assertSame('v1.4.2', $movida->versao_producao);
    }

    /**
     * @spec:AC-187 A tarefa operacional encerra sem versão: telefonema não tem tag.
     */
    public function test_operacional_encerra_sem_versao(): void
    {
        $tarefa = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $movida = $this->fluxo->mover($tarefa, 'concluida');

        $this->assertSame('concluida', $movida->status);
        $this->assertNull($movida->versao_producao);
    }
}
