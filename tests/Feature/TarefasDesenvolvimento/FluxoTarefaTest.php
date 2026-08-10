<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Tarefa;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use App\Services\FluxoTarefaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O motor do fluxo: mapa de transições permitidas, o que cada uma exige, e o
 * registro de entrada/saída/duração por etapa (T-059).
 */
class FluxoTarefaTest extends TestCase
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
        $criador = User::factory()->create();

        return Tarefa::create(array_merge([
            'titulo' => 'Tarefa de teste',
            'criado_por_id' => $criador->id,
        ], $atributos));
    }

    /**
     * @spec:AC-085 Movimento fora do fluxo é recusado: Backlog não vai direto para Concluída.
     */
    public function test_movimento_fora_do_fluxo_e_recusado(): void
    {
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $responsavel->id]);
        $this->assertSame('backlog', $tarefa->status);

        try {
            $this->fluxo->mover($tarefa, 'concluida');
            $this->fail('Esperava recusa de transição inválida.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        $this->assertSame('backlog', $tarefa->fresh()->status);
    }

    /**
     * @spec:AC-086 Direcionar para o Backlog exige responsável.
     */
    public function test_direcionar_para_backlog_exige_responsavel(): void
    {
        $tarefa = $this->criarTarefa();
        $this->assertSame('aberta', $tarefa->status);
        $this->assertNull($tarefa->responsavel_id);

        try {
            $this->fluxo->mover($tarefa, 'backlog');
            $this->fail('Esperava recusa por falta de responsável.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('direcionar a tarefa para alguém', $e->getMessage());
        }

        $this->assertSame('aberta', $tarefa->fresh()->status);

        $responsavel = User::factory()->create();
        $tarefa->update(['responsavel_id' => $responsavel->id]);

        $movida = $this->fluxo->mover($tarefa, 'backlog');

        $this->assertSame('backlog', $movida->status);
    }

    /**
     * @spec:AC-087 Devolver para ajustes exige dizer o que corrigir.
     */
    public function test_devolver_para_ajustes_exige_motivo(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        try {
            $this->fluxo->mover($tarefa, 'ajustes_necessarios');
            $this->fail('Esperava recusa por falta de descrição.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('descrever o que precisa ser corrigido', $e->getMessage());
        }

        $this->assertSame('em_testes', $tarefa->fresh()->status);

        $movida = $this->fluxo->mover($tarefa, 'ajustes_necessarios', ['motivo' => 'Falhou no cenário de CPF duplicado.']);

        $this->assertSame('ajustes_necessarios', $movida->status);
    }

    /**
     * @spec:AC-088 Cancelar exige motivo.
     */
    public function test_cancelar_exige_motivo(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_desenvolvimento']);

        try {
            $this->fluxo->mover($tarefa, 'cancelada');
            $this->fail('Esperava recusa por falta de motivo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('motivo do cancelamento', $e->getMessage());
        }

        $this->assertSame('em_desenvolvimento', $tarefa->fresh()->status);

        $movida = $this->fluxo->mover($tarefa, 'cancelada', ['motivo' => 'Escopo descontinuado.']);

        $this->assertSame('cancelada', $movida->status);
    }

    /**
     * @spec:AC-089 Concluir exige relatório de teste aprovado.
     */
    public function test_concluir_exige_relatorio_de_teste_aprovado(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_testes']);

        try {
            $this->fluxo->mover($tarefa, 'concluida');
            $this->fail('Esperava recusa por falta de relatório de teste.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('relatório de teste aprovado', $e->getMessage());
        }

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        try {
            $this->fluxo->mover($tarefa, 'concluida');
            $this->fail('Esperava recusa: o último relatório está reprovado.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('relatório de teste aprovado', $e->getMessage());
        }

        $this->assertSame('em_testes', $tarefa->fresh()->status);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => true,
            'notas' => 'Tudo certo no reteste.',
        ]);

        $movida = $this->fluxo->mover($tarefa, 'concluida');

        $this->assertSame('concluida', $movida->status);
    }

    /**
     * @spec:AC-090 Tarefa concluída pode ser reaberta para desenvolvimento; cancelada não sai de lugar nenhum.
     */
    public function test_tarefa_concluida_pode_ser_reaberta_e_cancelada_nao_tem_saida(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'concluida']);

        $reaberta = $this->fluxo->mover($tarefa, 'em_desenvolvimento');

        $this->assertSame('em_desenvolvimento', $reaberta->status);

        $cancelada = $this->criarTarefa(['status' => 'cancelada']);

        try {
            $this->fluxo->mover($cancelada, 'em_desenvolvimento');
            $this->fail('Esperava recusa: cancelada não sai de lugar nenhum.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transição inválida', $e->getMessage());
        }

        $this->assertSame('cancelada', $cancelada->fresh()->status);
    }

    /**
     * @spec:AC-091 Cada mudança de etapa registra entrada, saída e duração.
     */
    public function test_cada_mudanca_de_etapa_registra_entrada_saida_e_duracao(): void
    {
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $responsavel->id]);
        $this->assertSame('backlog', $tarefa->status);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));
        $this->fluxo->mover($tarefa, 'em_desenvolvimento');

        Carbon::setTestNow(Carbon::parse('2026-08-10 11:30:00'));
        $this->fluxo->mover($tarefa, 'em_testes');
        Carbon::setTestNow();

        $eventos = $tarefa->eventos()->orderBy('id')->get();
        $this->assertCount(2, $eventos);

        $primeiro = $eventos[0];
        $this->assertSame('backlog', $primeiro->de_status);
        $this->assertSame('em_desenvolvimento', $primeiro->para_status);
        $this->assertNotNull($primeiro->saiu_em);
        $this->assertSame(9000, $primeiro->duracao_segundos);

        $segundo = $eventos[1];
        $this->assertSame('em_desenvolvimento', $segundo->de_status);
        $this->assertSame('em_testes', $segundo->para_status);
        $this->assertNull($segundo->saiu_em);
        $this->assertNull($segundo->duracao_segundos);
    }
}
