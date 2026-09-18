<?php

namespace Tests\Feature\Agenda;

use App\Models\Compromisso;
use App\Models\Notificacao;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Os lembretes da Agenda: o compromisso perto da hora e o prazo de manhã.
 *
 * Os dois escrevem no sino. A regra que atravessa: o aviso não repete. O
 * compromisso se protege com `lembrete_enviado_em`; o prazo, com a cadência
 * diária. E remarcar o compromisso rearma o aviso — reunião movida avisa de
 * novo.
 */
class LembreteTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function compromisso(string $data, string $hora, array $participantes): Compromisso
    {
        $c = Compromisso::factory()->em($data, $hora)->create([
            'criado_por_id' => $participantes[0],
        ]);
        $c->sincronizarParticipantes($participantes);

        return $c;
    }

    /* ---------- compromisso ---------- */

    public function test_compromisso_perto_da_hora_avisa_os_participantes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00'));

        $ana = User::factory()->create();
        $beto = User::factory()->create();
        $c = $this->compromisso('2026-10-12', '10:20', [$ana->id, $beto->id]);

        $this->artisan('agenda:lembrar-compromissos')->assertSuccessful();

        foreach ([$ana, $beto] as $u) {
            $this->assertDatabaseHas('notificacoes', [
                'destinatario_id' => $u->id,
                'tipo' => 'lembrete',
            ]);
        }

        $this->assertNotNull($c->fresh()->lembrete_enviado_em);
    }

    /** Quem MARCOU também é lembrado — o lembrete não tem "autor" a poupar. */
    public function test_lembrete_de_compromisso_nao_pula_quem_marcou(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00'));

        $ana = User::factory()->create();
        $this->compromisso('2026-10-12', '10:20', [$ana->id]);

        $this->artisan('agenda:lembrar-compromissos')->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $ana->id,
            'tipo' => 'lembrete',
        ]);
    }

    public function test_lembrete_nao_repete_na_segunda_passada(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00'));

        $ana = User::factory()->create();
        $this->compromisso('2026-10-12', '10:20', [$ana->id]);

        $this->artisan('agenda:lembrar-compromissos');
        $this->artisan('agenda:lembrar-compromissos');

        $this->assertSame(1, Notificacao::where('destinatario_id', $ana->id)->count());
    }

    public function test_compromisso_distante_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00'));

        $ana = User::factory()->create();
        // Duas horas à frente: fora da janela de 30 min.
        $this->compromisso('2026-10-12', '12:00', [$ana->id]);

        $this->artisan('agenda:lembrar-compromissos');

        $this->assertSame(0, Notificacao::where('destinatario_id', $ana->id)->count());
    }

    public function test_compromisso_que_ja_comecou_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00'));

        $ana = User::factory()->create();
        // Começou às 09:50, já passou: "em -10 min" não é lembrete.
        $this->compromisso('2026-10-12', '09:50', [$ana->id]);

        $this->artisan('agenda:lembrar-compromissos');

        $this->assertSame(0, Notificacao::where('destinatario_id', $ana->id)->count());
    }

    /** Remarcar o início rearma o aviso; editar só o título, não. */
    public function test_remarcar_rearma_o_lembrete(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 10:00'));

        $ana = User::factory()->create();
        $c = $this->compromisso('2026-10-12', '10:20', [$ana->id]);
        $c->update(['lembrete_enviado_em' => now()]);

        // Editar só o título mantém o carimbo.
        $this->actingAs($ana)->putJson(route('compromissos.update', $c), [
            'titulo' => 'Outro título',
            'data' => '2026-10-12', 'hora' => '10:20',
            'duracao_modo' => true, 'duracao_horas' => 1,
            'participantes' => [$ana->id],
        ])->assertOk();
        $this->assertNotNull($c->fresh()->lembrete_enviado_em);

        // Mudar o início zera o carimbo.
        $this->actingAs($ana)->putJson(route('compromissos.update', $c), [
            'titulo' => 'Outro título',
            'data' => '2026-10-12', 'hora' => '15:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
            'participantes' => [$ana->id],
        ])->assertOk();
        $this->assertNull($c->fresh()->lembrete_enviado_em);
    }

    /* ---------- prazo ---------- */

    public function test_prazo_de_hoje_avisa_o_responsavel_de_manha(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));

        $ana = User::factory()->create();
        Tarefa::factory()->create([
            'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
            'status' => 'em_desenvolvimento', 'prazo' => '2026-10-12', 'titulo' => 'Entregar o relatório',
        ]);

        $this->artisan('agenda:lembrar-prazos')->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $ana->id,
            'tipo' => 'lembrete',
            'titulo' => 'Vence hoje: Entregar o relatório',
        ]);
    }

    /** Várias tarefas do mesmo dono viram um aviso só. */
    public function test_varios_prazos_do_mesmo_dono_agrupam(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));

        $ana = User::factory()->create();
        foreach (['Uma', 'Outra', 'Mais uma'] as $t) {
            Tarefa::factory()->create([
                'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
                'status' => 'em_desenvolvimento', 'prazo' => '2026-10-12', 'titulo' => $t,
            ]);
        }

        $this->artisan('agenda:lembrar-prazos');

        $this->assertSame(1, Notificacao::where('destinatario_id', $ana->id)->count());
        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $ana->id,
            'titulo' => 'Vencem hoje: 3 tarefas',
        ]);
    }

    public function test_prazo_de_outro_dia_ou_tarefa_encerrada_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));

        $ana = User::factory()->create();
        // Amanhã: não é hoje.
        Tarefa::factory()->create([
            'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
            'status' => 'em_desenvolvimento', 'prazo' => '2026-10-13',
        ]);
        // Vence hoje mas está concluída: o prazo virou história.
        Tarefa::factory()->create([
            'criado_por_id' => $ana->id, 'responsavel_id' => $ana->id,
            'status' => 'concluida', 'prazo' => '2026-10-12',
        ]);

        $this->artisan('agenda:lembrar-prazos');

        $this->assertSame(0, Notificacao::where('destinatario_id', $ana->id)->count());
    }

    public function test_prazo_sem_responsavel_nao_tem_a_quem_avisar(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));

        Tarefa::factory()->create([
            'criado_por_id' => User::factory(), 'responsavel_id' => null,
            'status' => 'em_desenvolvimento', 'prazo' => '2026-10-12',
        ]);

        $this->artisan('agenda:lembrar-prazos');

        $this->assertSame(0, Notificacao::count());
    }
}
