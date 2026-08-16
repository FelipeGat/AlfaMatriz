<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

/**
 * O movimento livre de quem faz triagem (US-079).
 *
 * O mapa do fluxo educa quem executa e continua mandando nele; quem organiza
 * o quadro é quem o conserta quando ele e a realidade divergem, e a correção
 * não pode depender de arrastar o card por etapas que ninguém fez. Só o mapa
 * afrouxa: as exigências de chegada valem para todos.
 */
class MovimentoLivreTest extends TestCase
{
    use RefreshDatabase;

    private function criarTarefa(array $atributos = []): Tarefa
    {
        $criador = User::factory()->create();

        return Tarefa::create(array_merge([
            'titulo' => 'Tarefa de teste',
            'criado_por_id' => $criador->id,
        ], $atributos));
    }

    /**
     * @spec:AC-281 Quem triaga pula etapa e recua de onde não há recuo — e o
     * cronômetro continua honesto: o movimento livre registra evento como
     * qualquer outro.
     */
    public function test_quem_triaga_move_para_qualquer_etapa(): void
    {
        $admin = User::factory()->create();
        $camila = User::factory()->create(['name' => 'Camila Reis']);
        $tarefa = $this->criarTarefa(['responsavel_id' => $camila->id]);
        $this->assertSame('backlog', $tarefa->status);

        // Backlog → Em staging não existe no mapa de nenhum tipo.
        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), ['status' => 'em_staging'])
            ->assertSessionMissing('erro');

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        $this->assertDatabaseHas('tarefa_eventos', [
            'tarefa_id' => $tarefa->id,
            'de_status' => 'backlog',
            'para_status' => 'em_staging',
        ]);

        // E o recuo que o mapa não oferece: Em staging → Backlog.
        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), ['status' => 'backlog'])
            ->assertSessionMissing('erro');

        $this->assertSame('backlog', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-281 O mapa continua mandando em quem executa: o mesmo salto,
     * pedido por um membro com a tarefa em mãos, é recusado.
     */
    public function test_quem_nao_triaga_continua_preso_ao_mapa(): void
    {
        $membro = User::factory()->membro()->create();
        $tarefa = $this->criarTarefa(['status' => 'em_desenvolvimento', 'responsavel_id' => $membro->id]);

        $this->actingAs($membro)->post(route('tarefas.mover', $tarefa), ['status' => 'em_staging'])
            ->assertSessionHas('erro');

        $this->assertStringContainsString('Transição inválida', session('erro'));
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-282 Livre é sobre a ordem das etapas, não sobre a informação que
     * cada chegada registra: motivo, validação do staging e versão continuam
     * sendo cobrados — e, atendidos, o salto passa.
     */
    public function test_o_movimento_livre_nao_afrouxa_as_exigencias_de_chegada(): void
    {
        $admin = User::factory()->create();

        // Cancelar de onde o mapa nem oferece cancelamento segue exigindo o
        // motivo: a frase do histórico não depende de quem move.
        $concluida = $this->criarTarefa(['status' => 'concluida']);

        $this->actingAs($admin)->post(route('tarefas.mover', $concluida), ['status' => 'cancelada'])
            ->assertSessionHas('erro');
        $this->assertStringContainsString('motivo do cancelamento', session('erro'));
        $this->assertSame('concluida', $concluida->fresh()->status);

        // O portão da produção não se fura por ser livre: sem a validação do
        // staging carimbada, a fila do admin não recebe.
        $aberta = $this->criarTarefa(['status' => 'aberta']);

        $this->actingAs($admin)->post(route('tarefas.mover', $aberta), ['status' => 'pronta_producao'])
            ->assertSessionHas('erro');
        $this->assertStringContainsString('validar o staging', session('erro'));
        $this->assertSame('aberta', $aberta->fresh()->status);

        // Concluir a de desenvolvimento cobra a versão — e, com ela, o salto
        // inteiro passa.
        $backlog = $this->criarTarefa(['responsavel_id' => $admin->id]);

        $this->actingAs($admin)->post(route('tarefas.mover', $backlog), ['status' => 'concluida'])
            ->assertSessionHas('erro');
        $this->assertStringContainsString('versão que subiu', session('erro'));

        $this->actingAs($admin)->post(route('tarefas.mover', $backlog), [
            'status' => 'concluida', 'versao_producao' => 'v2.0.1',
        ])->assertSessionMissing('erro');

        $this->assertSame('concluida', $backlog->fresh()->status);
        $this->assertSame('v2.0.1', $backlog->fresh()->versao_producao);
    }

    /**
     * @spec:AC-282 A volta para a revisão vinda de mais adiante — o recuo que
     * só o livre oferece — é reprovação como a devolução para a bancada: cobra
     * o motivo e carimba de onde a tarefa voltou. Sem isso, o admin devolvia
     * do staging em silêncio e o card chegava ao revisor como um PR novo.
     */
    public function test_devolver_para_a_revisao_vindo_de_mais_adiante_cobra_motivo(): void
    {
        $admin = User::factory()->create();
        $camila = User::factory()->create(['name' => 'Camila Reis']);
        $tarefa = $this->criarTarefa(['status' => 'em_staging', 'responsavel_id' => $camila->id]);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), ['status' => 'em_revisao'])
            ->assertSessionHas('erro');
        $this->assertStringContainsString('o que precisa ser corrigido', session('erro'));
        $this->assertSame('em_staging', $tarefa->fresh()->status);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), [
            'status' => 'em_revisao', 'motivo' => 'O teste do staging desmentiu a revisão.',
        ])->assertSessionMissing('erro');

        $devolvida = $tarefa->fresh();

        $this->assertSame('em_revisao', $devolvida->status);
        $this->assertSame('em_staging', $devolvida->retorno_de);
        $this->assertSame('Voltou do staging', $devolvida->rotuloDoRetorno());
        $this->assertSame('O teste do staging desmentiu a revisão.', $devolvida->retorno_motivo);

        // A responsável fica sabendo pelo sino — e a volta é anunciada como o
        // que é: o PR voltou ao exame, não à bancada dela.
        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $camila->id,
            'tipo' => 'retorno',
            'titulo' => '"Tarefa de teste" voltou para revisão',
        ]);
    }

    /**
     * @spec:AC-282 A fila da produção e a própria produção devolvem para a
     * revisão sob a mesma regra — e a reabertura solta a versão, como a volta
     * para a bancada: guardada, a reconclusão passava apoiada no ciclo
     * anterior.
     */
    public function test_a_volta_da_producao_para_a_revisao_tambem_e_reprovacao(): void
    {
        $admin = User::factory()->create();

        $daFila = $this->criarTarefa(['status' => 'pronta_producao']);

        $this->actingAs($admin)->post(route('tarefas.mover', $daFila), ['status' => 'em_revisao'])
            ->assertSessionHas('erro');
        $this->assertStringContainsString('o que precisa ser corrigido', session('erro'));
        $this->assertSame('pronta_producao', $daFila->fresh()->status);

        $concluida = $this->criarTarefa(['status' => 'concluida']);
        $concluida->forceFill(['versao_producao' => 'v1.4.2'])->save();

        $this->actingAs($admin)->post(route('tarefas.mover', $concluida), [
            'status' => 'em_revisao', 'motivo' => 'Saiu errado no cliente; reexaminar o PR.',
        ])->assertSessionMissing('erro');

        $reaberta = $concluida->fresh();

        $this->assertSame('em_revisao', $reaberta->status);
        $this->assertSame('concluida', $reaberta->retorno_de);
        $this->assertSame('Voltou da produção', $reaberta->rotuloDoRetorno());
        $this->assertNull($reaberta->versao_producao);
    }

    /**
     * @spec:AC-283 O quadro oferece a quem triaga o que vai aceitar: o card
     * entrega ao arrasto todas as etapas menos a atual — é a mesma lista que
     * acende as colunas, alimenta o menu "Mover ▾" e o teclado.
     */
    public function test_o_quadro_oferece_o_quadro_inteiro_a_quem_triaga(): void
    {
        $admin = User::factory()->create();
        $this->criarTarefa(['status' => 'em_revisao', 'titulo' => 'Na leitura']);

        $html = $this->actingAs($admin)->get(route('tarefas.index'))->assertOk()->getContent();
        $numaLinha = preg_replace('/\s+/', ' ', $html);

        $this->assertStringContainsString(
            'pegar( $el, '.Tarefa::first()->id.', '
                .Js::from(['aberta', 'backlog', 'em_desenvolvimento', 'em_staging', 'pronta_producao', 'concluida', 'cancelada'])->toHtml()
                .", 'desenvolvimento', false, 'em_revisao' )",
            $numaLinha,
            'Para quem triaga, o card precisa entregar todas as etapas menos a atual.'
        );
    }

    /**
     * @spec:AC-281 A operacional não entra nos portões nem no movimento livre:
     * eles examinam código, e um telefonema em "Em revisão · PR" faria a
     * coluna mentir. Livre é sobre a ordem das etapas, não sobre o vocabulário
     * do tipo.
     */
    public function test_a_operacional_nao_entra_nos_portoes_nem_no_movimento_livre(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->criarTarefa(['tipo' => 'operacional', 'status' => 'em_desenvolvimento']);

        $this->actingAs($admin)->post(route('tarefas.mover', $tarefa), ['status' => 'em_revisao'])
            ->assertSessionHas('erro');

        $this->assertStringContainsString('Transição inválida', session('erro'));
        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $this->assertSame(
            ['aberta', 'backlog', 'concluida', 'cancelada'],
            FluxoTarefaService::transicoesLivres($tarefa->fresh()),
        );
    }

    /**
     * @spec:AC-281 O livre é a saída da etapa aposentada: uma tarefa parada em
     * `em_testes` não está em mapa nenhum, e para todo mundo mais não tem
     * destino algum — é exatamente o card que só quem organiza consegue
     * devolver ao quadro.
     */
    public function test_o_livre_e_a_saida_da_etapa_aposentada(): void
    {
        $admin = User::factory()->create();
        $presa = $this->criarTarefa(['status' => 'em_testes']);

        $this->actingAs($admin)->post(route('tarefas.mover', $presa), ['status' => 'em_revisao'])
            ->assertSessionMissing('erro');

        $this->assertSame('em_revisao', $presa->fresh()->status);
    }
}
