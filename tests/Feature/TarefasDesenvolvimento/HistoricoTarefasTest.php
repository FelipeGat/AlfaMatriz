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

        $etapas = collect($resposta->viewData('etapas'))->keyBy('chave');
        $this->assertSame(1, $etapas['concluida']['quantidade']);
        $this->assertStringContainsString('1 fora dos últimos 30 dias', $etapas['concluida']['label']);

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
}
