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
     * @spec:AC-087 Devolver de um portão para a bancada exige dizer o que corrigir.
     */
    public function test_devolver_para_a_bancada_exige_motivo(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_revisao']);

        try {
            $this->fluxo->mover($tarefa, 'em_desenvolvimento');
            $this->fail('Esperava recusa por falta de descrição.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('o que precisa ser corrigido', $e->getMessage());
        }

        $this->assertSame('em_revisao', $tarefa->fresh()->status);

        $movida = $this->fluxo->mover($tarefa, 'em_desenvolvimento', ['motivo' => 'Falhou no cenário de CPF duplicado.']);

        $this->assertSame('em_desenvolvimento', $movida->status);

        // A devolução não é um recuo qualquer: ela carimba de qual portão a
        // tarefa voltou, que é o que a coluna única de Ajustes achatava.
        $this->assertSame('em_revisao', $movida->retorno_de);
        $this->assertSame('Voltou da revisão', $movida->rotuloDoRetorno());
        $this->assertSame('Falhou no cenário de CPF duplicado.', $movida->retorno_motivo);
    }

    /**
     * @spec:AC-087 Voltar do Backlog para a bancada não é reprovação e não pede motivo.
     */
    public function test_comecar_a_trabalhar_nao_pede_motivo(): void
    {
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa([
            'status' => 'backlog',
            'responsavel_id' => $responsavel->id,
        ]);

        $movida = $this->fluxo->mover($tarefa, 'em_desenvolvimento');

        $this->assertSame('em_desenvolvimento', $movida->status);
        $this->assertNull($movida->retorno_de);
    }

    /**
     * @spec:AC-087 Andar para frente apaga a tarja de retorno.
     */
    public function test_a_tarja_de_retorno_some_quando_a_tarefa_anda(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);

        $devolvida = $this->fluxo->mover($tarefa, 'em_desenvolvimento', ['motivo' => 'Quebrou ao subir.']);
        $this->assertSame('em_staging', $devolvida->retorno_de);

        $seguinte = $this->fluxo->mover($devolvida, 'em_revisao');

        $this->assertNull($seguinte->retorno_de);
        $this->assertNull($seguinte->retorno_motivo);
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
     * @spec:AC-089 Subir para produção exige a validação do staging.
     *
     * O portão mudou de porta quando a fila do admin saiu do quadro: ele
     * guardava a entrada nela, e agora guarda a própria subida da tag. O lugar
     * é outro, o que ele protege é o mesmo — código não validado não vai ao ar.
     */
    public function test_subir_para_producao_exige_validacao_do_staging(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'em_staging']);

        try {
            $this->fluxo->mover($tarefa, 'em_producao', ['versao_producao' => 'v1.4.2']);
            $this->fail('Esperava recusa por falta de validação.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar o staging', $e->getMessage());
        }

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        try {
            $this->fluxo->mover($tarefa, 'em_producao', ['versao_producao' => 'v1.4.2']);
            $this->fail('Esperava recusa: a última validação está reprovada.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('validar o staging', $e->getMessage());
        }

        $this->assertSame('em_staging', $tarefa->fresh()->status);

        TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => true,
            'notas' => 'Tudo certo no reteste.',
        ]);

        $movida = $this->fluxo->mover($tarefa, 'em_producao', ['versao_producao' => 'v1.4.2']);

        $this->assertSame('em_producao', $movida->status);
    }

    /**
     * @spec:AC-090 Tarefa concluída pode ser reaberta para desenvolvimento; cancelada não sai de lugar nenhum.
     */
    public function test_tarefa_concluida_pode_ser_reaberta_e_cancelada_nao_tem_saida(): void
    {
        $tarefa = $this->criarTarefa(['status' => 'concluida']);
        $tarefa->forceFill(['versao_producao' => 'v1.4.2'])->save();

        // Reabrir é reprovação de quem usou o que subiu: sem motivo, o card
        // chegava à bancada indistinguível de trabalho novo, e quem o recebia
        // não tinha como saber por que a tarefa voltou.
        try {
            $this->fluxo->mover($tarefa, 'em_desenvolvimento');
            $this->fail('Esperava recusa: reabrir sem dizer por quê.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('o que precisa ser corrigido', $e->getMessage());
        }

        $this->assertSame('concluida', $tarefa->fresh()->status);

        $reaberta = $this->fluxo->mover(
            $tarefa->fresh(),
            'em_desenvolvimento',
            ['motivo' => 'Relatório sai zerado no cliente.'],
        );

        $this->assertSame('em_desenvolvimento', $reaberta->status);

        // A volta carimba de onde veio e por quê, como as dos portões — é a
        // tarja que o card exibe na bancada.
        $this->assertSame('concluida', $reaberta->retorno_de);
        $this->assertSame('Voltou da produção', $reaberta->rotuloDoRetorno());
        $this->assertSame('Relatório sai zerado no cliente.', $reaberta->retorno_motivo);

        // A versão pertence ao ciclo que subiu: guardada, a reconclusão
        // passava no portão apoiada na versão do ciclo anterior.
        $this->assertNull($reaberta->versao_producao);

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
        $this->fluxo->mover($tarefa, 'em_revisao');
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
        $this->assertSame('em_revisao', $segundo->para_status);
        $this->assertNull($segundo->saiu_em);
        $this->assertNull($segundo->duracao_segundos);
    }

    /**
     * @spec:AC-124 Devolver do Backlog para Aberta solta o responsável.
     */
    public function test_devolver_do_backlog_para_aberta_solta_o_responsavel(): void
    {
        $responsavel = User::factory()->create();
        $tarefa = $this->criarTarefa(['responsavel_id' => $responsavel->id, 'status' => 'backlog']);

        $movida = $this->fluxo->mover($tarefa, 'aberta');

        $this->assertSame('aberta', $movida->status);
        $this->assertNull($movida->responsavel_id);
    }
}
