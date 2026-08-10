<?php

namespace Tests\Feature\TarefasDesenvolvimento;

use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Estrutura de dados do ciclo de desenvolvimento: a tarefa em si, o registro
 * de cada mudança de etapa (para medir tempo depois) e o relatório de teste
 * que a transição para Concluída vai exigir (T-059).
 */
class EstruturaTarefaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-083 Tarefa sem responsável nasce Aberta.
     */
    public function test_tarefa_sem_responsavel_nasce_aberta(): void
    {
        $criador = User::factory()->create();

        $tarefa = Tarefa::create([
            'titulo' => 'Corrigir tela de login',
            'criado_por_id' => $criador->id,
        ]);

        $this->assertSame('aberta', $tarefa->status);
        $this->assertNull($tarefa->responsavel_id);
    }

    /**
     * @spec:AC-083 Tarefa com responsável nasce direto no Backlog.
     */
    public function test_tarefa_com_responsavel_nasce_no_backlog(): void
    {
        $criador = User::factory()->create();
        $responsavel = User::factory()->create();

        $tarefa = Tarefa::create([
            'titulo' => 'Ajustar relatório financeiro',
            'criado_por_id' => $criador->id,
            'responsavel_id' => $responsavel->id,
        ]);

        $this->assertSame('backlog', $tarefa->status);
    }

    /**
     * @spec:AC-084 A tarefa é vinculada a um sistema já cadastrado.
     */
    public function test_tarefa_e_vinculada_a_sistema_ja_cadastrado(): void
    {
        $criador = User::factory()->create();
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $tarefa = Tarefa::create([
            'titulo' => 'Nova tela de estoque',
            'criado_por_id' => $criador->id,
            'sistema_id' => $sistema->id,
        ]);

        $this->assertTrue($tarefa->sistema->is($sistema));
        $this->assertSame('AlfaGym', $tarefa->sistema->nome);
    }

    /**
     * @spec:AC-084 O vínculo com sistema é opcional (ASM-034): tarefa interna
     * nasce sem sistema sem que nada quebre.
     */
    public function test_tarefa_pode_nascer_sem_sistema(): void
    {
        $criador = User::factory()->create();

        $tarefa = Tarefa::create([
            'titulo' => 'Revisar infraestrutura de deploy',
            'criado_por_id' => $criador->id,
        ]);

        $this->assertNull($tarefa->sistema_id);
        $this->assertNull($tarefa->sistema);
    }

    /**
     * @spec:AC-091 Cada mudança de etapa registra entrada, saída e duração:
     * o evento anterior fecha com saída e duração em segundos, e o novo
     * evento abre sem saída.
     */
    public function test_evento_de_etapa_registra_entrada_saida_e_duracao(): void
    {
        $criador = User::factory()->create();
        $tarefa = Tarefa::create([
            'titulo' => 'Preparar ambiente de testes',
            'criado_por_id' => $criador->id,
            'responsavel_id' => $criador->id,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 09:00:00'));
        $emDesenvolvimento = TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'backlog',
            'para_status' => 'em_desenvolvimento',
            'entrou_em' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-08-10 11:30:00'));
        $emDesenvolvimento->update([
            'saiu_em' => now(),
            'duracao_segundos' => $emDesenvolvimento->entrou_em->diffInSeconds(now()),
        ]);
        $emTestes = TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => 'em_desenvolvimento',
            'para_status' => 'em_testes',
            'entrou_em' => now(),
        ]);
        Carbon::setTestNow();

        $emDesenvolvimento->refresh();
        $this->assertNotNull($emDesenvolvimento->saiu_em);
        $this->assertSame(9000, $emDesenvolvimento->duracao_segundos);

        $this->assertNull($emTestes->saiu_em);
        $this->assertNull($emTestes->duracao_segundos);
        $this->assertSame('em_desenvolvimento', $emTestes->de_status);
        $this->assertSame('em_testes', $emTestes->para_status);
    }

    public function test_relatorio_de_teste_pertence_a_tarefa_e_guarda_aprovacao(): void
    {
        $criador = User::factory()->create();
        $tarefa = Tarefa::create([
            'titulo' => 'Cobrir cadastro de clientes com testes',
            'criado_por_id' => $criador->id,
        ]);

        $relatorio = TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'aprovado' => false,
            'notas' => 'Falhou no cenário de CPF duplicado.',
        ]);

        $this->assertFalse($relatorio->aprovado);
        $this->assertTrue($relatorio->tarefa->is($tarefa));
        $this->assertTrue($tarefa->relatoriosTeste->first()->is($relatorio));
    }
}
