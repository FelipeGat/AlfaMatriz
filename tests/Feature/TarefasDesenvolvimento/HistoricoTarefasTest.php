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
     * @spec:AC-096 Concluídas e canceladas há mais de 30 dias saem do quadro, que avisa quantas ficaram fora.
     */
    public function test_concluidas_e_canceladas_antigas_saem_do_quadro(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));

        $antiga = Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'concluida']);
        $antiga->forceFill(['updated_at' => Carbon::parse('2026-08-10 10:00:00')->subDays(31)])->save();

        $recente = Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'concluida']);
        $recente->forceFill(['updated_at' => Carbon::parse('2026-08-10 10:00:00')->subDay()])->save();

        $resposta = $this->actingAs($usuario)->get(route('tarefas.index'));

        $resposta->assertOk();

        $colunas = $resposta->viewData('colunas');
        $idsNaColuna = $colunas['concluida']->pluck('id')->all();

        $this->assertContains($recente->id, $idsNaColuna);
        $this->assertNotContains($antiga->id, $idsNaColuna);

        // O aviso mora em `ocultas`, não mais concatenado no rótulo: dentro da
        // coluna de 276px o texto junto truncava justamente no número (AC-112).
        $etapas = collect($resposta->viewData('etapas'))->keyBy('chave');
        $this->assertSame(1, $etapas['concluida']['quantidade']);
        $this->assertSame('Concluída', $etapas['concluida']['label']);
        $this->assertSame(1, $etapas['concluida']['ocultas']);
        $resposta->assertSee('fora dos últimos 30 dias');

        $resposta->assertSee($recente->titulo);
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
     * @spec:AC-112 Do quadro se chega ao histórico: o aviso do recorte aparece em linha
     * própria (sem ser truncado dentro do rótulo da coluna) e leva ao histórico completo,
     * e o cabeçalho oferece o caminho mesmo quando nada está fora do recorte.
     */
    public function test_quadro_leva_ao_historico_e_o_aviso_do_recorte_e_legivel(): void
    {
        $usuario = User::factory()->create();
        $criador = User::factory()->create();

        Carbon::setTestNow(Carbon::parse('2026-08-10 10:00:00'));

        $antiga = Tarefa::factory()->create(['criado_por_id' => $criador->id, 'status' => 'concluida']);
        $antiga->forceFill(['updated_at' => Carbon::parse('2026-08-10 10:00:00')->subDays(31)])->save();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        // O rótulo da coluna fica limpo: o aviso não é mais concatenado nele.
        $this->assertStringNotContainsString('Concluída · 1 fora', $html);

        // O aviso existe, com o número, e é um link para o histórico.
        $this->assertMatchesRegularExpression(
            '#<a href="'.preg_quote(route('tarefas.historico'), '#').'"[^>]*>\s*\+1 fora dos últimos 30 dias\s*</a>#u',
            $html,
            'O aviso do recorte precisa ser um link para o histórico completo.'
        );
    }

    /**
     * @spec:AC-112 O caminho para o histórico não depende de haver algo fora do recorte.
     */
    public function test_cabecalho_do_quadro_leva_ao_historico_mesmo_sem_recorte(): void
    {
        $usuario = User::factory()->create();

        $html = $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('fora dos últimos 30 dias', $html);
        $this->assertStringContainsString('href="'.route('tarefas.historico').'"', $html);
    }
}
