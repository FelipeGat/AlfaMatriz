<?php

namespace Tests\Feature\Agenda;

use App\Models\Compromisso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O compromisso: os dois modos de término, a validação e quem pode mexer.
 *
 * O modo é o coração da tela — `duracao_modo` e `duracao_horas` são gravados de
 * propósito, e não derivados de `hora_fim - hora`, para que reabrir devolva o
 * compromisso no modo em que ele foi criado.
 */
class CompromissoTest extends TestCase
{
    use RefreshDatabase;

    public function test_modo_duracao_calcula_o_termino_no_servidor(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson(route('compromissos.store'), [
            'titulo' => 'Alinhamento do faturamento',
            'data' => '2026-10-12',
            'hora' => '10:00',
            'duracao_modo' => true,
            'duracao_horas' => 1.5,
        ])->assertOk();

        $compromisso = Compromisso::first();

        $this->assertSame('11:30', Carbon::parse($compromisso->hora_fim)->format('H:i'));
        $this->assertSame('2026-10-12', Carbon::parse($compromisso->data_fim)->toDateString());
        $this->assertTrue($compromisso->duracao_modo);
        $this->assertSame('1.50', (string) $compromisso->duracao_horas);
    }

    /**
     * Cruzando a meia-noite, o término cai no DIA SEGUINTE.
     *
     * É o caso que justifica `data_fim` existir como coluna: sem ela, um
     * compromisso das 23h com duas horas terminaria "à 01:00" do mesmo dia —
     * antes do próprio começo.
     */
    public function test_duracao_que_cruza_a_meia_noite_cai_no_dia_seguinte(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson(route('compromissos.store'), [
            'titulo' => 'Janela de deploy',
            'data' => '2026-10-12',
            'hora' => '23:00',
            'duracao_modo' => true,
            'duracao_horas' => 2,
        ])->assertOk();

        $compromisso = Compromisso::first();

        $this->assertSame('2026-10-13', Carbon::parse($compromisso->data_fim)->toDateString());
        $this->assertSame('01:00', Carbon::parse($compromisso->hora_fim)->format('H:i'));
        $this->assertTrue($compromisso->viraODia());
    }

    /**
     * No modo Horário livre a duração NÃO é gravada.
     *
     * Gravar a subtração aqui reintroduziria a ambiguidade que a coluna existe
     * para resolver: ao editar o início, o sistema não saberia qual dos dois
     * lados é a fonte da verdade.
     */
    public function test_modo_horario_livre_nao_grava_duracao(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson(route('compromissos.store'), [
            'titulo' => 'Retrospectiva',
            'data' => '2026-10-12',
            'hora' => '14:00',
            'duracao_modo' => false,
            'data_fim' => '2026-10-12',
            'hora_fim' => '15:45',
        ])->assertOk();

        $compromisso = Compromisso::first();

        $this->assertFalse($compromisso->duracao_modo);
        $this->assertNull($compromisso->duracao_horas);
        $this->assertSame('15:45', Carbon::parse($compromisso->hora_fim)->format('H:i'));
    }

    public function test_termino_antes_do_inicio_e_recusado_com_a_frase(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson(route('compromissos.store'), [
            'titulo' => 'Impossível',
            'data' => '2026-10-12',
            'hora' => '15:00',
            'duracao_modo' => false,
            'data_fim' => '2026-10-12',
            'hora_fim' => '14:00',
        ])->assertStatus(422)->assertJson(['erro' => 'O término precisa ser depois do início.']);

        $this->assertSame(0, Compromisso::count());
    }

    /**
     * Membro edita o que CRIOU — não o que apenas frequenta.
     *
     * A régua é ter marcado: reunião com quatro pessoas daria a quatro pessoas
     * o poder de remarcá-la, e quem só participa não é quem combinou.
     */
    public function test_membro_nao_edita_compromisso_de_outra_pessoa(): void
    {
        $dono = User::factory()->create();
        $outro = User::factory()->membro()->create();

        $compromisso = Compromisso::factory()->create(['criado_por_id' => $dono->id]);
        $compromisso->sincronizarParticipantes([$outro->id]);

        $this->actingAs($outro)->putJson(route('compromissos.update', $compromisso), [
            'titulo' => 'Remarcado por quem não marcou',
            'data' => '2026-10-20',
            'hora' => '08:00',
            'duracao_modo' => true,
            'duracao_horas' => 1,
        ])->assertStatus(422);

        $this->assertNotSame('Remarcado por quem não marcou', $compromisso->fresh()->titulo);
    }

    /** Quem faz triagem organiza o trabalho dos outros — inclusive a agenda. */
    public function test_quem_faz_triagem_edita_compromisso_alheio(): void
    {
        $dono = User::factory()->membro()->create();
        $admin = User::factory()->create();

        $compromisso = Compromisso::factory()->create(['criado_por_id' => $dono->id]);

        $this->actingAs($admin)->putJson(route('compromissos.update', $compromisso), [
            'titulo' => 'Remarcado pela triagem',
            'data' => '2026-10-20',
            'hora' => '08:00',
            'duracao_modo' => true,
            'duracao_horas' => 1,
        ])->assertOk();

        $this->assertSame('Remarcado pela triagem', $compromisso->fresh()->titulo);
    }

    /**
     * Remarcar move a data que os participantes repetem.
     *
     * A `data` está duplicada em `compromisso_participantes` para a carga por
     * pessoa não precisar de join. Duplicata só é segura enquanto um lugar a
     * mantém — se ela ficar no dia antigo, o drawer conta o dia errado.
     */
    public function test_remarcar_move_a_data_dos_participantes(): void
    {
        $usuario = User::factory()->create();
        $participante = User::factory()->create();

        $compromisso = Compromisso::factory()->em('2026-10-12', '09:00')->create([
            'criado_por_id' => $usuario->id,
        ]);
        $compromisso->sincronizarParticipantes([$participante->id]);

        $this->actingAs($usuario)->putJson(route('compromissos.update', $compromisso), [
            'titulo' => $compromisso->titulo,
            'data' => '2026-10-19',
            'hora' => '09:00',
            'duracao_modo' => true,
            'duracao_horas' => 1,
            'participantes' => [$participante->id],
        ])->assertOk();

        $this->assertDatabaseHas('compromisso_participantes', [
            'compromisso_id' => $compromisso->id,
            'user_id' => $participante->id,
            'data' => '2026-10-19',
        ]);
        $this->assertDatabaseMissing('compromisso_participantes', [
            'compromisso_id' => $compromisso->id,
            'data' => '2026-10-12',
        ]);
    }

    /**
     * Salvar por JSON deixa a confirmação na sessão — a tela recarrega e o
     * toast aparece.
     *
     * Antes a mensagem ia só no corpo do JSON, que o reload jogava fora: quem
     * marcava um compromisso não via confirmação nenhuma ("marquei e não vi
     * nada").
     */
    public function test_salvar_deixa_a_confirmacao_flashada_para_o_reload(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson(route('compromissos.store'), [
            'titulo' => 'Alinhamento',
            'data' => '2026-10-12', 'hora' => '10:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
        ])->assertOk();

        $this->assertNotNull(session('status'));
        $this->assertStringContainsString('Compromisso marcado', session('status'));
    }

    public function test_participantes_sao_avisados_no_sino_menos_quem_marcou(): void
    {
        $autor = User::factory()->create();
        $convidado = User::factory()->create();

        $this->actingAs($autor)->postJson(route('compromissos.store'), [
            'titulo' => 'Planejamento',
            'data' => '2026-10-12',
            'hora' => '09:00',
            'duracao_modo' => true,
            'duracao_horas' => 1,
            'participantes' => [$autor->id, $convidado->id],
        ])->assertOk();

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $convidado->id,
            'tipo' => 'compromisso',
        ]);

        // Quem age já sabe o que fez: notificar o autor enche o sino de eco.
        $this->assertDatabaseMissing('notificacoes', [
            'destinatario_id' => $autor->id,
            'tipo' => 'compromisso',
        ]);
    }
}
