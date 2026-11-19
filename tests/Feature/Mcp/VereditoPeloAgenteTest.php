<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\ListarTarefas;
use App\Mcp\Tools\VerTarefa;
use App\Models\Tarefa;
use App\Models\TarefaEvento;
use App\Models\TarefaRelatorioTeste;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O veredito pela porta do agente (T-629).
 *
 * O carimbo "Staging aprovado por…" só aparecia na tela; o `ver_tarefa` e o
 * `listar_tarefas` não o traziam, e na T-616 uma central respondeu sobre uma
 * tarefa em produção sem saber que ela já estava aprovada. O que se prova:
 * o agente lê o mesmo selo que o card mostra — recortado pela passagem, para
 * o aprovado antigo não valer de novo — e cada veredito já registrado.
 */
class VereditoPeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_ver_tarefa_mostra_o_veredito_desta_etapa_e_o_historico_de_vereditos(): void
    {
        $admin = User::factory()->create(['name' => 'Admin Teste']);
        $testadora = User::factory()->create(['name' => 'Ana Testadora']);
        $tarefa = Tarefa::factory()->create(['status' => 'em_producao', 'tipo' => 'desenvolvimento']);

        // Primeira passagem pelo staging: reprovada. Segunda: aprovada. Agora
        // em produção, aprovada no ar.
        $staging1 = $this->passagem($tarefa, 'em_revisao', 'em_staging', '2026-11-10 09:00', '2026-11-10 12:00');
        $staging2 = $this->passagem($tarefa, 'em_revisao', 'em_staging', '2026-11-11 09:00', '2026-11-12 10:00');
        $producao = $this->passagem($tarefa, 'em_staging', 'em_producao', '2026-11-12 10:00', null);

        $this->relatorio($tarefa, $staging1, $testadora, false, 'O boleto saiu sem desconto.', '2026-11-10 11:00');
        $this->relatorio($tarefa, $staging2, $testadora, true, null, '2026-11-11 15:00');
        $this->relatorio($tarefa, $producao, $admin, true, 'Conferi no ar.', '2026-11-12 16:30');

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertOk()
            ->assertSee('Veredito desta etapa: Produção aprovada por Admin Teste em 12/11/2026 16:30 — Conferi no ar.')
            ->assertSee('Vereditos registrados:')
            ->assertSee('- 10/11/2026 11:00 Staging reprovado por Ana Testadora — O boleto saiu sem desconto.')
            ->assertSee('- 11/11/2026 15:00 Staging aprovado por Ana Testadora')
            ->assertSee('- 12/11/2026 16:30 Produção aprovada por Admin Teste — Conferi no ar. (vale agora)');
    }

    public function test_o_aprovado_de_uma_passagem_anterior_nao_vale_para_a_atual(): void
    {
        $admin = User::factory()->create();
        $testadora = User::factory()->create(['name' => 'Ana Testadora']);
        $tarefa = Tarefa::factory()->create(['status' => 'em_staging', 'tipo' => 'desenvolvimento']);

        $antiga = $this->passagem($tarefa, 'em_revisao', 'em_staging', '2026-11-10 09:00', '2026-11-10 12:00');
        $this->passagem($tarefa, 'em_revisao', 'em_staging', '2026-11-11 09:00', null);
        $this->relatorio($tarefa, $antiga, $testadora, true, null, '2026-11-10 11:00');

        AlfaMatrizServer::actingAs($admin)
            ->tool(VerTarefa::class, ['tarefa' => '#'.$tarefa->id])
            ->assertOk()
            ->assertSee('Veredito desta etapa: nenhum registrado nesta passagem por Em staging.')
            ->assertSee('- 10/11/2026 11:00 Staging aprovado por Ana Testadora')
            ->assertDontSee('(vale agora)');

        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, ['situacao' => 'em_staging'])
            ->assertOk()
            ->assertSee('[sem veredito]');
    }

    public function test_listar_tarefas_traz_o_selo_so_nos_portoes_de_veredito(): void
    {
        $admin = User::factory()->create();
        $testadora = User::factory()->create(['name' => 'Ana Testadora']);

        $reprovada = Tarefa::factory()->create(['status' => 'em_staging', 'tipo' => 'desenvolvimento', 'titulo' => 'Reprovada no staging']);
        $evento = $this->passagem($reprovada, 'em_revisao', 'em_staging', '2026-11-18 09:00', null);
        $this->relatorio($reprovada, $evento, $testadora, false, 'Quebrou o filtro.', '2026-11-18 10:00');

        $aprovada = Tarefa::factory()->create(['status' => 'em_producao', 'tipo' => 'bug', 'titulo' => 'Aprovada no ar']);
        $evento = $this->passagem($aprovada, 'em_staging', 'em_producao', '2026-11-18 09:00', null);
        $this->relatorio($aprovada, $evento, $testadora, true, null, '2026-11-19 08:00');

        Tarefa::factory()->create(['status' => 'em_revisao', 'tipo' => 'desenvolvimento', 'titulo' => 'Na revisão']);

        // A tarefa em Em revisão está na lista, mas sem marca: fora dos portões
        // de veredito, nem selo nem "sem veredito".
        AlfaMatrizServer::actingAs($admin)
            ->tool(ListarTarefas::class, ['situacao' => 'abertas'])
            ->assertOk()
            ->assertSee('Na revisão')
            ->assertSee('[Staging reprovado por Ana Testadora em 18/11]')
            ->assertSee('[Produção aprovada por Ana Testadora em 19/11]')
            ->assertDontSee('[sem veredito]');
    }

    private function passagem(Tarefa $tarefa, string $de, string $para, string $entrou, ?string $saiu): TarefaEvento
    {
        return TarefaEvento::create([
            'tarefa_id' => $tarefa->id,
            'de_status' => $de,
            'para_status' => $para,
            'entrou_em' => $entrou,
            'saiu_em' => $saiu,
        ]);
    }

    private function relatorio(Tarefa $tarefa, TarefaEvento $evento, User $autor, bool $aprovado, ?string $notas, string $quando): void
    {
        $relatorio = TarefaRelatorioTeste::create([
            'tarefa_id' => $tarefa->id,
            'tarefa_evento_id' => $evento->id,
            'user_id' => $autor->id,
            'aprovado' => $aprovado,
            'notas' => $notas,
        ]);

        $relatorio->forceFill(['created_at' => $quando])->save();
    }
}
