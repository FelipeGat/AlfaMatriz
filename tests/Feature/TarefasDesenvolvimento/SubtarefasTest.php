<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Subtarefa: tarefa inteira pendurada em outra.
 *
 * O caso que a pediu: revisar uma atualização e achar oito bugs. Cada um é
 * trabalho de verdade — precisa de dono, etapa e print —, e por isso não cabe
 * no checklist, cujo item não tem nenhum dos três. E não é irmandade: os oito
 * só existem por causa daquela revisão, e o vínculo simétrico não sabe dizer
 * isso.
 *
 * A migração do vínculo listou as quatro perguntas que a hierarquia obriga a
 * responder. Estes testes são as quatro respostas, uma por vez: a mãe é
 * guarda-chuva e não sai do quadro com filha aberta; a filha conta no WIP; o
 * responsável é dela; e cancelar a mãe é recusado pela mesma regra do encerrar.
 */
class SubtarefasTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        return Tarefa::create(array_merge([
            'titulo' => 'Revisar atualização v2.1',
            'criado_por_id' => User::factory()->create()->id,
        ], $atributos));
    }

    /**
     * O formulário abre com o que a mãe já sabe: sistema e tipo escolhidos.
     *
     * É palpite, e não decisão — os dois selects continuam trocáveis. Era o
     * único campo que a criação rápida herdava, e ele não se perdeu quando ela
     * saiu; o resto (resumo, prioridade, responsável, anexos) é da filha, e é
     * exatamente isso que a separa do item de checklist.
     */
    public function test_o_formulario_abre_com_o_sistema_e_o_tipo_da_mae(): void
    {
        $admin = User::factory()->create();
        $sistema = Sistema::factory()->create();
        $mae = $this->criarTarefa(['sistema_id' => $sistema->id, 'tipo' => 'operacional']);

        $html = $this->actingAs($admin)->get(route('tarefas.subtarefas.form', $mae))->assertOk()->getContent();
        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString('value="'.$sistema->id.'" selected', $numaLinha);
        $this->assertStringContainsString('value="operacional" selected', $numaLinha);
    }

    /**
     * Sem gate de perfil, como o checklist e o comentário: quem revisa uma
     * atualização e acha um bug precisa poder registrá-lo. Travar isso não
     * impede o bug de existir — impede de REGISTRÁ-LO, e o quadro passa a
     * mentir sobre o que a revisão produziu.
     *
     * E quem não triaga cai na fila, como em toda criação: a filha nasce em
     * Aberta, sem dono e com prioridade "A definir".
     */
    public function test_quem_nao_triaga_tambem_reporta_e_cai_na_fila(): void
    {
        $membro = User::factory()->membro()->create();
        $mae = $this->criarTarefa();

        $this->actingAs($membro)->get(route('tarefas.subtarefas.form', $mae))->assertOk();

        $this->actingAs($membro)->post(route('tarefas.store'), [
            'titulo' => 'Extrato duplica a linha do estorno',
            'tarefa_pai_id' => $mae->id,
            // Mesmo mandando os dois, quem não triaga não os define.
            'prioridade' => 'critica',
            'responsavel_id' => $membro->id,
        ])->assertSessionMissing('erro');

        $filha = Tarefa::where('titulo', 'Extrato duplica a linha do estorno')->sole();

        $this->assertSame($mae->id, $filha->tarefa_pai_id);
        $this->assertSame('aberta', $filha->status, 'Vai para a fila de triagem.');
        $this->assertNull($filha->responsavel_id);
        $this->assertSame('nao_definida', $filha->prioridade);
        $this->assertSame($membro->id, $filha->criado_por_id);
        $this->assertTrue($filha->ehSubtarefa());
    }

    /**
     * A primeira das quatro perguntas: a mãe é guarda-chuva e não conclui com
     * filha aberta. A recusa vem antes das outras exigências — com filha
     * aberta, "falta a versão" mandaria preencher um campo que não é o
     * problema.
     */
    public function test_a_mae_nao_conclui_com_filha_aberta(): void
    {
        $fluxo = new FluxoTarefaService;
        $mae = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        try {
            $fluxo->mover($mae->fresh(), 'concluida');
            $this->fail('Esperava recusa: a filha está aberta.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('1 subtarefa em aberto', $e->getMessage());
        }

        $this->assertSame('em_desenvolvimento', $mae->fresh()->status);
    }

    /**
     * A quarta pergunta: cancelar a mãe cai na MESMA recusa. Concluir afirma
     * que o trabalho acabou e cancelar afirma que ele não vai acontecer — com
     * filha aberta as duas afirmações são falsas sobre parte do que a tarefa
     * carrega.
     */
    public function test_a_mae_tambem_nao_cancela_com_filha_aberta(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa(['status' => 'em_desenvolvimento']);

        $filha = $this->criarTarefa(['titulo' => 'Filtro perde a data']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $this->actingAs($admin)->post(route('tarefas.mover', $mae), [
            'status' => 'cancelada', 'de_status' => 'em_desenvolvimento',
            'motivo' => 'Não vamos mais atualizar.',
        ])->assertSessionHas('erro');

        $this->assertStringContainsString('subtarefa em aberto', session('erro'));
        $this->assertSame('em_desenvolvimento', $mae->fresh()->status);
    }

    /**
     * A terceira porta de saída, guardada pela mesma regra: excluir a mãe
     * soltaria as filhas de uma vez, e é o único gesto do quadro sem desfazer.
     */
    public function test_a_mae_nao_e_excluida_com_filha_aberta(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa();

        $filha = $this->criarTarefa(['titulo' => 'Extrato duplica linha']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $this->actingAs($admin)->delete(route('tarefas.destroy', $mae))->assertSessionHas('erro');

        $this->assertStringContainsString('subtarefa em aberto', session('erro'));
        $this->assertDatabaseHas('tarefas', ['id' => $mae->id]);
    }

    /**
     * Cancelar a filha é a saída, e não um contorno: ela sai da conta sem ter
     * sido feita, que é o destino honesto do bug que o time decidiu não
     * corrigir. Sem isso, a mãe ficaria presa para sempre à primeira filha que
     * ninguém vai pegar.
     */
    public function test_cancelar_a_filha_destrava_a_mae(): void
    {
        $fluxo = new FluxoTarefaService;
        $mae = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $feita = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);
        $descartada = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        foreach ([$feita, $descartada] as $filha) {
            $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();
        }

        $this->assertNotNull($mae->fresh()->motivoParaNaoEncerrar());

        $fluxo->mover($feita, 'concluida');
        $fluxo->mover($descartada, 'cancelada', ['motivo' => 'Não vamos corrigir este.']);

        $mae = $mae->fresh()->load('subtarefas');

        $this->assertNull($mae->motivoParaNaoEncerrar(), 'A cancelada sai da conta como a concluída.');
        $this->assertSame(['feitas' => 2, 'total' => 2], $mae->progressoDasSubtarefas());
        $this->assertSame('concluida', $fluxo->mover($mae, 'concluida')->status);
    }

    /**
     * Duas mães que não recebem, e pelo mesmo botão: a que já é filha (um nível
     * só — profundidade livre multiplicaria as quatro perguntas por quantos
     * níveis alguém criar) e a encerrada (pendurar trabalho novo numa mãe que
     * saiu do quadro criaria uma filha que ninguém vê e uma mãe que a recusa do
     * encerramento não alcança mais).
     *
     * Nem chegam a mostrar o botão: `podeReceberSubtarefa` decide o que a seção
     * oferece, e é o mesmo método que a rota confere.
     */
    public function test_quem_nao_pode_ser_mae_nao_oferece_o_botao(): void
    {
        $mae = $this->criarTarefa();

        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $encerrada = $this->criarTarefa(['status' => 'concluida']);

        $this->assertTrue($mae->podeReceberSubtarefa());
        $this->assertFalse($filha->fresh()->podeReceberSubtarefa(), 'Um nível só.');
        $this->assertFalse($encerrada->podeReceberSubtarefa(), 'Encerrada não recebe.');

        $html = $this->actingAs(User::factory()->create())
            ->get(route('tarefas.modal', $filha->fresh()))->assertOk()->getContent();

        $this->assertStringNotContainsString('Nova subtarefa', $html);
    }

    /**
     * A segunda pergunta: a filha conta no WIP. É trabalho de verdade, e uma
     * revisão que gera oito bugs gerou oito trabalhos — se isso estoura o
     * limite, é o quadro dizendo a verdade sobre o que o time recebeu.
     */
    public function test_a_filha_e_card_no_quadro_e_conta_no_wip(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa(['status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id]);

        // Três filhas em Em andamento, mais a mãe: o limite de 3 estoura.
        foreach (['Boleto', 'Extrato', 'Filtro'] as $titulo) {
            $filha = $this->criarTarefa([
                'titulo' => $titulo, 'status' => 'em_desenvolvimento', 'responsavel_id' => $admin->id,
            ]);
            $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();
        }

        $etapas = $this->actingAs($admin)->get(route('tarefas.index'))->assertOk()->viewData('etapas');
        $andamento = collect($etapas)->firstWhere('chave', 'em_desenvolvimento');

        $this->assertSame(4, $andamento['quantidade'], 'A filha é card como qualquer outro.');
        $this->assertSame(4, $andamento['andando'], 'E entra na conta do trabalho em curso.');
        $this->assertTrue($andamento['acimaDoLimite']);
    }

    /**
     * O card da filha nomeia a MÃE, e não só o número dela.
     *
     * É o que faz irmãs se reconhecerem numa coluna: três subtarefas de
     * revisões diferentes, com três `#nnn` pequenos em mono, não dizem que são
     * três famílias — quem lê a coluna de cima para baixo não percebe
     * agrupamento nenhum. Com o título repetido, percebe.
     *
     * Vale em QUALQUER coluna, que é o ponto: a filha nasce em Aberta e a mãe
     * quase sempre está em Em andamento, então elas praticamente nunca dividem
     * coluna. Uma identação sob a mãe existiu aqui e saiu por isso mesmo —
     * ligava 2 vezes em 11 na massa real, e só furando a régua da coluna.
     */
    public function test_o_card_da_filha_nomeia_a_mae_em_qualquer_coluna(): void
    {
        $admin = User::factory()->create();

        $mae = $this->criarTarefa([
            'titulo' => 'Revisar a atualização v2.1', 'status' => 'em_desenvolvimento',
            'prioridade' => 'alta', 'responsavel_id' => $admin->id,
        ]);

        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código', 'status' => 'aberta']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $html = $this->actingAs($admin)->get(route('tarefas.index'))->assertOk()->getContent();
        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString(
            'title="Subtarefa de #'.$mae->id.' · Revisar a atualização v2.1"',
            $numaLinha,
            'A faixa nomeia a mãe por extenso.'
        );
        $this->assertStringContainsString(
            '<span class="text-ink-mute">Revisar a atualização v2.1</span>',
            $numaLinha,
            'E o título da mãe é IMPRESSO no card, não só no title — é ele que faz as irmãs se reconhecerem na coluna.'
        );

        // E a ordem da coluna não é mexida por causa do parentesco: a régua
        // continua sendo gravidade → retorno → mais parado.
        $outra = $this->criarTarefa([
            'titulo' => 'Trabalho sem parentesco', 'status' => 'em_desenvolvimento',
            'prioridade' => 'critica', 'responsavel_id' => $admin->id,
        ]);
        $irma = $this->criarTarefa([
            'titulo' => 'Extrato duplica', 'status' => 'em_desenvolvimento',
            'prioridade' => 'baixa', 'responsavel_id' => $admin->id,
        ]);
        $irma->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $colunas = $this->actingAs($admin)->get(route('tarefas.index'))->assertOk()->viewData('colunas');

        $this->assertSame(
            [$outra->id, $mae->id, $irma->id],
            $colunas['em_desenvolvimento']->pluck('id')->all(),
            'A filha de prioridade baixa fica onde a régua a coloca, e não colada na mãe.'
        );
    }

    /**
     * O card diz de que lado da hierarquia ele está, e o placar da mãe fica
     * âmbar enquanto prende: descobrir a recusa só no clique do Concluir é a
     * recusa sem aviso que o quadro evita em todo o resto.
     */
    public function test_o_card_mostra_a_hierarquia_dos_dois_lados(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa(['titulo' => 'Revisar atualização']);

        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $html = $this->actingAs($admin)->get(route('tarefas.index'))->assertOk()->getContent();
        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString('0 de 1 subtarefas encerradas · esta tarefa não encerra antes', $numaLinha);
        $this->assertStringContainsString('Subtarefa de #'.$mae->id, $numaLinha);
    }

    /**
     * A seção no detalhe é a porta de entrada: campo de uma linha, para
     * despejar oito bugs em oito Enters. E ela avisa que prende — o contrário
     * do que a seção de vínculos, logo abaixo, promete.
     */
    public function test_o_detalhe_traz_a_secao_com_o_botao_e_o_aviso(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa();

        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $html = $this->actingAs($admin)->get(route('tarefas.modal', $mae))->assertOk()->getContent();

        $this->assertStringContainsString('Subtarefas', $html);
        $this->assertStringContainsString('Nova subtarefa', $html, 'A porta é uma só, e é o formulário.');
        $this->assertStringNotContainsString('Enter para criar', $html, 'O campo de uma linha saiu.');
        $this->assertStringContainsString('Boleto sem código', $html);
        $this->assertStringContainsString('só encerra depois que todas forem concluídas ou canceladas', $html);
        $this->assertStringContainsString('1 subtarefa em aberto', $html);
    }

    /**
     * A outra porta: o formulário INTEIRO, com a mãe amarrada.
     *
     * É o caminho de quem acabou de achar um bug e quer descrevê-lo e colar o
     * print ali mesmo — criar só o título e reabrir para o resto é o trabalho
     * em dois tempos que a seção existe para evitar.
     */
    public function test_o_formulario_inteiro_vem_com_a_mae_amarrada(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa(['titulo' => 'Revisar a atualização v2.1']);

        $html = $this->actingAs($admin)->get(route('tarefas.subtarefas.form', $mae))->assertOk()->getContent();

        $this->assertStringContainsString('Nova subtarefa', $html);
        $this->assertStringContainsString('de '.$mae->codigo().' · Revisar a atualização v2.1', $html);
        $this->assertStringContainsString('name="tarefa_pai_id" value="'.$mae->id.'"', $html);

        // E o formulário é o inteiro: os campos que o de uma linha não tem.
        $this->assertStringContainsString('name="resumo"', $html);
        $this->assertStringContainsString('name="prioridade"', $html);
        $this->assertStringContainsString('name="responsavel_id"', $html);
        $this->assertStringContainsString('name="anexos[]"', $html);
        $this->assertStringContainsString('name="itens[]"', $html);
    }

    /** E o que ele envia nasce completo, pendurado na mãe. */
    public function test_o_formulario_inteiro_cria_a_filha_com_tudo(): void
    {
        $admin = User::factory()->create();
        $dono = User::factory()->create();
        $mae = $this->criarTarefa();

        $this->actingAs($admin)->post(route('tarefas.store'), [
            'titulo' => 'Boleto sai sem o código de barras',
            'resumo' => 'Só nos boletos com desconto.',
            'prioridade' => 'critica',
            'responsavel_id' => $dono->id,
            'tarefa_pai_id' => $mae->id,
        ])->assertSessionMissing('erro');

        $filha = Tarefa::where('titulo', 'Boleto sai sem o código de barras')->sole();

        $this->assertSame($mae->id, $filha->tarefa_pai_id);
        $this->assertSame('Só nos boletos com desconto.', $filha->resumo);
        $this->assertSame('critica', $filha->prioridade);
        $this->assertSame($dono->id, $filha->responsavel_id);
        $this->assertNotNull($mae->fresh()->load('subtarefas')->motivoParaNaoEncerrar());
    }

    /**
     * Mãe que não pode receber não abre formulário: 404 em vez de um formulário
     * que só seria recusado no envio, depois de a pessoa escrever tudo.
     */
    public function test_o_formulario_nao_abre_para_mae_que_nao_recebe(): void
    {
        $admin = User::factory()->create();
        $encerrada = $this->criarTarefa(['status' => 'concluida']);

        $mae = $this->criarTarefa();
        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        $this->actingAs($admin)->get(route('tarefas.subtarefas.form', $encerrada))->assertNotFound();
        $this->actingAs($admin)->get(route('tarefas.subtarefas.form', $filha->fresh()))->assertNotFound();
    }

    /**
     * O campo escondido é sugestão de tela, e tela não guarda regra: se a mãe
     * não serve, a tarefa nasce SOLTA em vez de não nascer. O texto e os prints
     * já foram digitados, e recusar o envio inteiro por causa do vínculo
     * jogaria fora o trabalho para consertar a parte menor dele.
     */
    public function test_mae_invalida_no_campo_escondido_gera_tarefa_solta(): void
    {
        $admin = User::factory()->create();
        $mae = $this->criarTarefa();

        $filha = $this->criarTarefa(['titulo' => 'Boleto sem código']);
        $filha->forceFill(['tarefa_pai_id' => $mae->id])->save();

        // Pendurar na FILHA seria um segundo nível, que não existe.
        $this->actingAs($admin)->post(route('tarefas.store'), [
            'titulo' => 'Neta que não pode existir',
            'tarefa_pai_id' => $filha->id,
        ])->assertSessionMissing('erro');

        $solta = Tarefa::where('titulo', 'Neta que não pode existir')->sole();

        $this->assertNull($solta->tarefa_pai_id, 'Nasce solta, e não deixa de nascer.');
    }
}
