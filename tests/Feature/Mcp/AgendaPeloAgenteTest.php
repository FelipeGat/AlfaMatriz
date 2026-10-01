<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\DesmarcarCompromisso;
use App\Mcp\Tools\RemarcarCompromisso;
use App\Mcp\Tools\VerCompromisso;
use App\Models\Compromisso;
use App\Models\Notificacao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O agente confere, altera e desmarca o que está na agenda.
 *
 * Nasceu de uma queixa do dono (01/10/2026): o agente marcava um compromisso a
 * pedido dele e depois não conseguia corrigi-lo nem relê-lo — só existiam
 * `ver_agenda` e `marcar_compromisso`.
 */
class AgendaPeloAgenteTest extends TestCase
{
    use RefreshDatabase;

    private function marcado(User $dono, string $data, string $hora, float $horas = 1, array $extras = []): Compromisso
    {
        $compromisso = Compromisso::factory()->em($data, $hora, $horas)->create(['criado_por_id' => $dono->id] + $extras);
        $compromisso->sincronizarParticipantes([$dono->id]);

        return $compromisso;
    }

    public function test_ver_compromisso_mostra_a_ficha_e_se_a_pessoa_pode_alterar(): void
    {
        $dono = User::factory()->create(['name' => 'Rossini']);
        $membro = User::factory()->membro()->create();
        $compromisso = $this->marcado($dono, '2026-10-02', '23:00', 2, ['titulo' => 'Janela de deploy', 'categoria' => 'deploy']);

        AlfaMatrizServer::actingAs($dono)
            ->tool(VerCompromisso::class, ['compromisso' => '#'.$compromisso->id])
            ->assertOk()
            ->assertSee('Janela de deploy')
            ->assertSee('02/10/2026 23:00 até 03/10/2026 01:00 (vira o dia)')
            ->assertSee('Deploy / manutenção')
            ->assertSee('Participantes: Rossini')
            ->assertSee('Você pode alterar ou desmarcar');

        // Quem não marcou e não triaga lê, mas é avisado de que não altera.
        AlfaMatrizServer::actingAs($membro)
            ->tool(VerCompromisso::class, ['compromisso' => (string) $compromisso->id])
            ->assertOk()
            ->assertSee('Você não pode alterá-lo');
    }

    public function test_mudar_so_o_inicio_mantem_a_duracao_rearma_o_lembrete_e_avisa(): void
    {
        $dono = User::factory()->create();
        $ana = User::factory()->membro()->create(['name' => 'Ana Lima']);
        $compromisso = $this->marcado($dono, '2026-10-02', '10:00', 1.5);
        $compromisso->sincronizarParticipantes([$dono->id, $ana->id]);
        $compromisso->update(['lembrete_enviado_em' => now()]);

        AlfaMatrizServer::actingAs($dono)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'hora' => '15:00'])
            ->assertOk()
            ->assertSee('02/10/2026 15:00 até 16:30');

        $compromisso->refresh();

        $this->assertSame('16:30', Carbon::parse($compromisso->hora_fim)->format('H:i'));
        // Quem foi avisado do horário antigo precisa do novo.
        $this->assertNull($compromisso->lembrete_enviado_em);
        $this->assertTrue(Notificacao::where('destinatario_id', $ana->id)->where('titulo', 'like', '%remarcou%')->exists());
    }

    public function test_mudar_so_o_titulo_nao_mexe_no_horario_nem_no_lembrete(): void
    {
        $dono = User::factory()->create();
        $compromisso = $this->marcado($dono, '2026-10-02', '10:00');
        $compromisso->update(['lembrete_enviado_em' => now()]);

        AlfaMatrizServer::actingAs($dono)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'titulo' => 'Alinhamento do release'])
            ->assertOk()
            ->assertSee('Alinhamento do release')
            ->assertSee('02/10/2026 10:00 até 11:00');

        $this->assertNotNull($compromisso->fresh()->lembrete_enviado_em);
    }

    public function test_antecipar_um_compromisso_que_vira_o_dia_leva_o_termino_junto(): void
    {
        $dono = User::factory()->create();
        $compromisso = $this->marcado($dono, '2026-10-02', '23:00', 2);

        AlfaMatrizServer::actingAs($dono)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'hora' => '21:00'])
            ->assertOk()
            ->assertSee('02/10/2026 21:00 até 23:00');

        $this->assertSame('2026-10-02', Carbon::parse($compromisso->fresh()->data_fim)->toDateString());
    }

    public function test_trocar_os_participantes_substitui_a_lista_e_mantem_quem_comanda(): void
    {
        $dono = User::factory()->create(['name' => 'Rossini']);
        $ana = User::factory()->membro()->create(['name' => 'Ana Lima']);
        $bruno = User::factory()->membro()->create(['name' => 'Bruno Costa']);
        $compromisso = $this->marcado($dono, '2026-10-02', '10:00');
        $compromisso->sincronizarParticipantes([$dono->id, $ana->id]);

        AlfaMatrizServer::actingAs($dono)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'participantes' => ['Bruno Costa']])
            ->assertOk();

        $this->assertEqualsCanonicalizing(
            [$dono->id, $bruno->id],
            $compromisso->fresh()->participantes->pluck('id')->all(),
        );
    }

    public function test_termino_antes_do_inicio_e_recusado_com_a_frase_da_tela(): void
    {
        $dono = User::factory()->create();
        $compromisso = $this->marcado($dono, '2026-10-02', '10:00');

        AlfaMatrizServer::actingAs($dono)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'hora_fim' => '09:00'])
            ->assertHasErrors()
            ->assertSee('O término precisa ser depois do início.');

        $this->assertSame('11:00', Carbon::parse($compromisso->fresh()->hora_fim)->format('H:i'));
    }

    public function test_quem_nao_marcou_e_nao_triaga_nao_altera_nem_desmarca(): void
    {
        $dono = User::factory()->create();
        $membro = User::factory()->membro()->create();
        $compromisso = $this->marcado($dono, '2026-10-02', '10:00');

        AlfaMatrizServer::actingAs($membro)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'hora' => '15:00'])
            ->assertHasErrors()
            ->assertSee('Só quem marcou este compromisso');

        AlfaMatrizServer::actingAs($membro)
            ->tool(DesmarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id])
            ->assertHasErrors()
            ->assertSee('pode desmarcá-lo');

        $this->assertSame('10:00', Carbon::parse($compromisso->fresh()->hora)->format('H:i'));
    }

    public function test_o_membro_altera_e_desmarca_o_que_ele_mesmo_marcou(): void
    {
        $membro = User::factory()->membro()->create(['name' => 'Ana Lima']);
        $outro = User::factory()->membro()->create();
        $compromisso = $this->marcado($membro, '2026-10-02', '10:00', 1, ['titulo' => 'Revisão de sprint']);
        $compromisso->sincronizarParticipantes([$membro->id, $outro->id]);

        AlfaMatrizServer::actingAs($membro)
            ->tool(RemarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id, 'data' => '2026-10-05'])
            ->assertOk()
            ->assertSee('05/10/2026 10:00 até 11:00');

        AlfaMatrizServer::actingAs($membro)
            ->tool(DesmarcarCompromisso::class, ['compromisso' => '#'.$compromisso->id])
            ->assertOk()
            ->assertSee('Revisão de sprint')
            ->assertSee('desmarcado');

        $this->assertNull(Compromisso::find($compromisso->id));
        $this->assertTrue(Notificacao::where('destinatario_id', $outro->id)->where('titulo', 'like', '%desmarcou%')->exists());
    }

    public function test_numero_que_nao_existe_e_dito(): void
    {
        $dono = User::factory()->create();

        foreach ([VerCompromisso::class, RemarcarCompromisso::class, DesmarcarCompromisso::class] as $ferramenta) {
            AlfaMatrizServer::actingAs($dono)
                ->tool($ferramenta, ['compromisso' => '#999'])
                ->assertHasErrors()
                ->assertSee('Não há compromisso #999');
        }
    }
}
