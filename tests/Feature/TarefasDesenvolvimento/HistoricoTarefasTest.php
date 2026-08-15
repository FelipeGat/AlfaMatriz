<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O quadro fica enxuto — concluída e cancelada só mostram os últimos 30 dias
 * — mas nada se perde: o histórico continua listando tudo, sem recorte de
 * período (T-065).
 */
class HistoricoTarefasTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @spec:AC-096 Encerrar a tarefa a tira do quadro na mesma hora — sem recorte de
     * data, sem coluna terminal — e ela passa a viver no histórico.
     */
    public function test_encerrar_a_tarefa_a_tira_do_quadro(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        $prontaProducao = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'pronta_producao', 'titulo' => 'Vai concluir',
        ]);
        $recemCancelada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'cancelada', 'titulo' => 'Cancelada agora',
        ]);

        // Antes de encerrar, a tarefa na porta da produção está no quadro.
        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $this->assertContains($prontaProducao->id, $resposta->viewData('colunas')['pronta_producao']->pluck('id')->all());

        // Cancelada de hoje TAMBÉM não aparece: não é questão de idade.
        $resposta->assertDontSee('Cancelada agora');
        $this->assertArrayNotHasKey('cancelada', $resposta->viewData('colunas')->all());

        // Conclui pelo mesmo caminho da tela.
        $this->actingAs($usuario)->post(route('tarefas.mover', $prontaProducao), [
            'status' => 'concluida',
            'versao_producao' => 'v1.4.2',
        ])->assertSessionMissing('erro');

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $resposta->assertDontSee('Vai concluir');

        // E as duas estão no histórico.
        $historico = $this->actingAs($usuario)->get(route('tarefas.historico'))->assertOk();
        $historico->assertSee('Vai concluir');
        $historico->assertSee('Cancelada agora');
    }

    /**
     * @spec:AC-097 O histórico de tarefas lista a tarefa concluída há mais de 30 dias, com sistema, responsável, etapa final e data, sem recorte de período.
     */
    public function test_historico_lista_tarefa_antiga_sem_recorte_de_periodo(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $responsavel = User::factory()->create(['name' => 'Fulano Responsável']);

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));

        $antiga = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistema->id,
            'responsavel_id' => $responsavel->id,
            'status' => 'concluida',
            'titulo' => 'Tarefa concluída faz tempo',
        ]);
        $antiga->forceFill(['updated_at' => Carbon::parse('2026-08-10 10:00:00')->subDays(90)])->save();

        $resposta = $this->actingAs($usuario)->get(route('tarefas.historico'));

        $resposta->assertOk();
        $resposta->assertSee('Tarefa concluída faz tempo');
        $resposta->assertSee('AlfaGym');
        $resposta->assertSee('Fulano Responsável');
        $resposta->assertSee('Concluída');

        $tarefas = $resposta->viewData('tarefas');
        $this->assertContains($antiga->id, $tarefas->pluck('id')->all());
    }

    /**
     * @spec:AC-125 Quadro e Histórico são duas abas da mesma tela: as duas aparecem nas
     * duas telas, e a atual vem marcada como ativa — passar de uma para a outra é um clique.
     */
    public function test_quadro_e_historico_sao_abas_da_mesma_tela(): void
    {
        $usuario = User::factory()->create();

        $quadro = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // As duas abas existem no quadro, e a do quadro é a ativa.
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('tarefas.index'), '#').'"\s+aria-current="page"#u',
            $quadro,
            'No quadro, a aba Quadro precisa estar marcada como ativa.'
        );
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('tarefas.historico'), '#').'"\s+aria-current="false"#u',
            $quadro,
            'A aba Histórico precisa estar disponível a partir do quadro.'
        );

        $historico = $this->actingAs($usuario)->get(route('tarefas.historico'))->assertOk()->getContent();

        // E o espelho: no histórico, a ativa é a outra.
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('tarefas.historico'), '#').'"\s+aria-current="page"#u',
            $historico,
            'No histórico, a aba Histórico precisa estar marcada como ativa.'
        );
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('tarefas.index'), '#').'"\s+aria-current="false"#u',
            $historico,
            'A aba Quadro precisa levar de volta a partir do histórico.'
        );
    }

    /**
     * @spec:AC-131 Sem a coluna Concluída no quadro, reabrir passa a morar no histórico:
     * a concluída volta para a bancada, e a cancelada volta para a fila (AC-184).
     */
    public function test_historico_reabre_as_duas_terminais_cada_uma_para_o_seu_lugar(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();
        $dono = User::factory()->create();

        $concluida = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'status' => 'concluida', 'titulo' => 'Concluída reabrível',
        ]);
        $cancelada = Tarefa::factory()->create([
            'criado_por_id' => $criador->id, 'responsavel_id' => $dono->id,
            'status' => 'cancelada', 'titulo' => 'Cancelada por engano',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.historico'))->assertOk()->getContent();

        // As duas oferecem volta: cancelar por engano custava o histórico
        // inteiro, porque a única saída era recadastrar a tarefa do zero.
        // A concluída não envia direto — o botão abre o pedido de motivo.
        $this->assertStringContainsString('reabrir-tarefa-'.$concluida->id, $html);
        $this->assertStringContainsString(route('tarefas.mover', $concluida), $html);
        $this->assertStringContainsString(route('tarefas.mover', $cancelada), $html);

        // Reabrir sem dizer por quê é recusado: o card chegava à bancada
        // indistinguível de trabalho novo.
        $this->actingAs($usuario)->post(route('tarefas.mover', $concluida), ['status' => 'em_desenvolvimento'])
            ->assertSessionHas('erro');

        $this->assertSame('concluida', $concluida->fresh()->status);

        $this->actingAs($usuario)->post(route('tarefas.mover', $concluida), [
            'status' => 'em_desenvolvimento',
            'motivo' => 'Cliente reportou erro no fechamento.',
        ])->assertSessionMissing('erro');

        $this->assertSame('em_desenvolvimento', $concluida->fresh()->status);
        $this->assertSame('concluida', $concluida->fresh()->retorno_de);
        $this->assertSame('Cliente reportou erro no fechamento.', $concluida->fresh()->retorno_motivo);

        // A cancelada volta para a FILA, e sem dono: retomá-la é uma decisão
        // nova, provavelmente de outra pessoa (AC-130).
        $this->actingAs($usuario)->post(route('tarefas.mover', $cancelada), ['status' => 'aberta'])
            ->assertSessionMissing('erro');

        $this->assertSame('aberta', $cancelada->fresh()->status);
        $this->assertNull($cancelada->fresh()->responsavel_id);

        $colunas = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->viewData('colunas');
        $this->assertContains($concluida->id, $colunas['em_desenvolvimento']->pluck('id')->all());
        $this->assertContains($cancelada->id, $colunas['aberta']->pluck('id')->all());
    }

    /**
     * @spec:AC-133 A linha do histórico conta o que a tarefa custou: prioridade, resumo
     * e a duração do ciclo — da criação até entrar na etapa terminal. É o número que
     * justifica cronometrar cada etapa; sem ele os eventos seriam registro que ninguém lê.
     */
    public function test_historico_mostra_prioridade_resumo_e_duracao_do_ciclo(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00:00'));

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => $criador->id,
            'titulo' => 'Webhook de pagamento',
            'resumo' => 'Baixa automática ao receber o retorno do gateway.',
            'prioridade' => 'critica',
            'status' => 'pronta_producao',
        ]);
        $tarefa->forceFill(['created_at' => Carbon::parse('2026-08-10 12:00:00')->subDays(12)])->save();

        // Encerra agora: o ciclo é a distância entre a criação e este instante.
        $this->actingAs($usuario)->post(route('tarefas.mover', $tarefa), [
            'status' => 'concluida',
            'versao_producao' => 'v1.4.2',
        ])->assertSessionMissing('erro');

        $this->assertSame(12 * 86400, $tarefa->fresh()->load('eventos')->duracaoDoCiclo());

        $html = $this->actingAs($usuario)->get(route('tarefas.historico'))->assertOk()->getContent();

        $this->assertStringContainsString('Baixa automática ao receber o retorno do gateway.', $html,
            'O resumo diz o QUE era a tarefa — quem audita precisa disso, não só do nome.');
        $this->assertStringContainsString('Crítica', $html, 'A prioridade que a tarefa tinha faz parte do desfecho.');
        $this->assertStringContainsString('12d', $html, 'A duração do ciclo precisa aparecer na linha.');

        Carbon::setTestNow();
    }

    /**
     * A linha do histórico traz o mesmo número do card.
     *
     * Tarefa encerrada é a que mais se cita por número — em release note e em
     * conversa de suporte —, e é aqui que se procura o que já saiu do quadro.
     */
    public function test_a_linha_do_historico_traz_o_numero_da_tarefa(): void
    {
        $usuario = User::factory()->create();

        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => User::factory(),
            'titulo' => 'Webhook de pagamento',
            'status' => 'concluida',
        ]);

        $html = $this->actingAs($usuario)->get(route('tarefas.historico'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression(
            '/#'.$tarefa->id.'<\/span>\s*Webhook de pagamento/u',
            $html,
            'O número precisa vir antes do título, como no card.'
        );
    }
}
