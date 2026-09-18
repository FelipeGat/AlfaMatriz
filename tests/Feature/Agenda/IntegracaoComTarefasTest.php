<?php

namespace Tests\Feature\Agenda;

use App\Models\Compromisso;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * As integrações entre a Agenda e o quadro de Tarefas.
 *
 * A que atravessa todas: **estado manda na cor, não a prioridade**. Bloqueada e
 * em retorno aparecem marcadas em toda visão, com o sufixo dito — senão o mesmo
 * dado conta histórias diferentes nas duas telas.
 */
class IntegracaoComTarefasTest extends TestCase
{
    use RefreshDatabase;

    private function tarefaComPrazo(string $prazo, array $atributos = []): Tarefa
    {
        return Tarefa::factory()->create(array_merge([
            'criado_por_id' => User::factory(),
            'status' => 'em_desenvolvimento',
            'prazo' => $prazo,
        ], $atributos));
    }

    /** O prazo aparece sozinho: tarefa com prazo não precisa de compromisso. */
    public function test_prazo_da_tarefa_aparece_na_agenda(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->tarefaComPrazo(Carbon::today()->toDateString(), [
            'titulo' => 'Corrigir importação de NF',
        ]);

        $this->actingAs($usuario)
            ->get(route('agenda.index'))
            ->assertOk()
            ->assertSee($tarefa->titulo);
    }

    /** Tarefa encerrada sai da Agenda: o prazo dela virou história. */
    public function test_tarefa_concluida_nao_aparece_na_agenda(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->tarefaComPrazo(Carbon::today()->toDateString(), [
            'titulo' => 'Trabalho que já acabou',
            'status' => 'concluida',
        ]);

        $this->actingAs($usuario)
            ->get(route('agenda.index'))
            ->assertOk()
            ->assertDontSee($tarefa->titulo);
    }

    /**
     * Prazo em até 48h sem nenhuma reunião vinculada vira risco.
     *
     * A janela é o menor prazo em que ainda dá para marcar alguma coisa:
     * avisar no dia não deixa tempo de reagir.
     */
    public function test_prazo_apertado_sem_reuniao_ganha_a_marca(): void
    {
        $tarefa = $this->tarefaComPrazo(Carbon::tomorrow()->toDateString());

        $this->assertTrue($tarefa->prazoSemReuniao());
        $this->assertSame('sem reunião marcada', $tarefa->marcaDaAgenda()['sufixo']);
    }

    public function test_prazo_apertado_com_reuniao_nao_ganha_a_marca(): void
    {
        $tarefa = $this->tarefaComPrazo(Carbon::tomorrow()->toDateString());

        Compromisso::factory()->create([
            'criado_por_id' => User::factory(),
            'tarefa_id' => $tarefa->id,
        ]);

        $this->assertFalse($tarefa->fresh()->prazoSemReuniao());
    }

    public function test_prazo_distante_sem_reuniao_nao_ganha_a_marca(): void
    {
        $tarefa = $this->tarefaComPrazo(Carbon::today()->addDays(10)->toDateString());

        $this->assertFalse($tarefa->prazoSemReuniao());
        $this->assertNull($tarefa->marcaDaAgenda());
    }

    /**
     * Travada ou em retorno EXCLUI o aviso de reunião — o problema já tem nome.
     *
     * Um segundo aviso dizendo que também falta reunião só disputaria atenção
     * com o primeiro, e a cor já está marcada por outro motivo.
     */
    public function test_tarefa_bloqueada_marca_bloqueio_e_cala_o_aviso_de_reuniao(): void
    {
        $tarefa = $this->tarefaComPrazo(Carbon::tomorrow()->toDateString());
        $tarefa->forceFill(['bloqueado_em' => now(), 'bloqueio_motivo' => 'Esperando o cliente'])->save();

        $this->assertFalse($tarefa->prazoSemReuniao());
        $this->assertSame(['sufixo' => 'Bloqueada', 'tom' => 'bloqueio'], $tarefa->marcaDaAgenda());
    }

    public function test_tarefa_em_retorno_marca_retorno(): void
    {
        $tarefa = $this->tarefaComPrazo(Carbon::tomorrow()->toDateString());
        $tarefa->forceFill(['retorno_de' => 'em_revisao', 'retorno_motivo' => 'Faltou teste'])->save();

        $this->assertFalse($tarefa->prazoSemReuniao());
        $this->assertSame(['sufixo' => 'Em retorno', 'tom' => 'retorno'], $tarefa->marcaDaAgenda());
    }

    /* ---------- o primeiro prazo vem do formulário do quadro ---------- */

    /**
     * O campo existe no formulário do quadro, e não só na Agenda.
     *
     * Sem ele não haveria por onde dar o PRIMEIRO prazo: a Agenda só mostra
     * tarefa que já tem um, e o arraste remarca em vez de marcar — uma tarefa
     * sem prazo nunca apareceria para ser arrastada.
     */
    public function test_quem_triaga_define_o_prazo_ao_criar_pelo_quadro(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('tarefas.store'), [
            'titulo' => 'Tarefa com prazo combinado',
            'prazo' => '2026-10-30',
        ]);

        $tarefa = Tarefa::where('titulo', 'Tarefa com prazo combinado')->first();

        $this->assertNotNull($tarefa);
        $this->assertSame('2026-10-30', Carbon::parse($tarefa->prazo)->toDateString());
    }

    public function test_quem_triaga_apaga_o_prazo_ao_editar(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefaComPrazo('2026-10-12');

        $this->actingAs($admin)->put(route('tarefas.update', $tarefa), [
            'titulo' => $tarefa->titulo,
            'prazo' => null,
        ]);

        $this->assertNull($tarefa->fresh()->prazo);
    }

    /** Combinar data é triagem: envio forjado de quem não triaga é descartado. */
    public function test_membro_nao_define_prazo_nem_por_envio_forjado(): void
    {
        $membro = User::factory()->membro()->create();

        $this->actingAs($membro)->post(route('tarefas.store'), [
            'titulo' => 'Tarefa que tentou trazer prazo',
            'prazo' => '2026-10-30',
        ]);

        $tarefa = Tarefa::where('titulo', 'Tarefa que tentou trazer prazo')->first();

        $this->assertNotNull($tarefa);
        $this->assertNull($tarefa->prazo);
    }

    /* ---------- reagendar por arraste ---------- */

    public function test_quem_faz_triagem_reagenda_o_prazo(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefaComPrazo('2026-10-12');

        $this->actingAs($admin)->postJson(route('agenda.reagendar', $tarefa), [
            'prazo' => '2026-10-15',
            'de_prazo' => '2026-10-12',
        ])->assertOk();

        $this->assertSame('2026-10-15', Carbon::parse($tarefa->fresh()->prazo)->toDateString());
    }

    /** Mudar prazo é triagem, mesma regra de prioridade e responsável. */
    public function test_membro_nao_reagenda_o_prazo(): void
    {
        $membro = User::factory()->membro()->create();
        $tarefa = $this->tarefaComPrazo('2026-10-12');

        $this->actingAs($membro)->postJson(route('agenda.reagendar', $tarefa), [
            'prazo' => '2026-10-15',
            'de_prazo' => '2026-10-12',
        ])->assertStatus(422);

        $this->assertSame('2026-10-12', Carbon::parse($tarefa->fresh()->prazo)->toDateString());
    }

    /**
     * O mesmo contrato de `de_status` do quadro, aplicado ao prazo.
     *
     * É fácil esquecê-lo num endpoint novo, e sem ele a última tela a soltar
     * ganha em silêncio — nem quem moveu primeiro nem quem moveu depois sabe
     * que houve disputa.
     */
    public function test_reagendar_sobre_remarcacao_alheia_e_recusado(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefaComPrazo('2026-10-12');

        // Alguém já remarcou enquanto o card estava na mão do outro.
        $tarefa->update(['prazo' => '2026-10-20']);

        $resposta = $this->actingAs($admin)->postJson(route('agenda.reagendar', $tarefa), [
            'prazo' => '2026-10-15',
            'de_prazo' => '2026-10-12',
        ])->assertStatus(422);

        $this->assertStringContainsString('Alguém já remarcou', $resposta->json('erro'));
        $this->assertSame('2026-10-20', Carbon::parse($tarefa->fresh()->prazo)->toDateString());
    }

    /** Tarefa que ainda não tinha prazo sai de null — o primeiro arrasto. */
    public function test_primeiro_prazo_e_aceito_com_de_prazo_nulo(): void
    {
        $admin = User::factory()->create();
        $tarefa = Tarefa::factory()->create([
            'criado_por_id' => User::factory(),
            'status' => 'em_desenvolvimento',
        ]);

        $this->actingAs($admin)->postJson(route('agenda.reagendar', $tarefa), [
            'prazo' => '2026-10-15',
            'de_prazo' => null,
        ])->assertOk();

        $this->assertSame('2026-10-15', Carbon::parse($tarefa->fresh()->prazo)->toDateString());
    }

    /* ---------- as duas conversões ---------- */

    public function test_virar_tarefa_cria_card_com_prazo_e_vincula(): void
    {
        $admin = User::factory()->create();
        $compromisso = Compromisso::factory()->em('2026-10-12', '14:00')->create([
            'criado_por_id' => $admin->id,
            'titulo' => 'Revisar contrato da revenda',
        ]);

        $this->actingAs($admin)
            ->postJson(route('compromissos.virar-tarefa', $compromisso))
            ->assertOk();

        $tarefa = Tarefa::where('titulo', 'Revisar contrato da revenda')->first();

        $this->assertNotNull($tarefa);
        $this->assertSame('2026-10-12', Carbon::parse($tarefa->prazo)->toDateString());
        $this->assertSame($tarefa->id, $compromisso->fresh()->tarefa_id);
    }

    /** O vínculo é de um para um: o segundo clique geraria duplicata. */
    public function test_compromisso_ja_vinculado_nao_vira_tarefa_de_novo(): void
    {
        $admin = User::factory()->create();
        $tarefa = $this->tarefaComPrazo('2026-10-12');
        $compromisso = Compromisso::factory()->create([
            'criado_por_id' => $admin->id,
            'tarefa_id' => $tarefa->id,
        ]);

        $this->actingAs($admin)
            ->postJson(route('compromissos.virar-tarefa', $compromisso))
            ->assertStatus(422);

        $this->assertSame(1, Tarefa::count());
    }

    /** Quem não triaga converte, e o card cai na fila — como toda criação. */
    public function test_membro_que_vira_tarefa_cai_na_fila_de_triagem(): void
    {
        $membro = User::factory()->membro()->create();
        $compromisso = Compromisso::factory()->create([
            'criado_por_id' => $membro->id,
            'titulo' => 'Conversa sobre o módulo novo',
        ]);

        $this->actingAs($membro)
            ->postJson(route('compromissos.virar-tarefa', $compromisso))
            ->assertOk();

        $tarefa = Tarefa::where('titulo', 'Conversa sobre o módulo novo')->first();

        $this->assertSame('nao_definida', $tarefa->prioridade);
        $this->assertNull($tarefa->responsavel_id);
    }

    /**
     * Reservar tempo só PRÉ-PREENCHE — não cria nada.
     *
     * A hora é a única coisa que não sai da tarefa, e criar no clique poria uma
     * reunião na agenda de duas pessoas num horário que ninguém escolheu.
     */
    public function test_reservar_tempo_devolve_rascunho_sem_criar(): void
    {
        $admin = User::factory()->create();
        $responsavel = User::factory()->create();

        $tarefa = $this->tarefaComPrazo('2026-10-12', [
            'titulo' => 'Migrar o relatório velho',
            'responsavel_id' => $responsavel->id,
        ]);

        $resposta = $this->actingAs($admin)
            ->getJson(route('agenda.reservar', $tarefa))
            ->assertOk();

        $this->assertSame('Migrar o relatório velho', $resposta->json('titulo'));
        $this->assertSame('2026-10-12', $resposta->json('data'));
        $this->assertSame($tarefa->id, $resposta->json('tarefa_id'));
        $this->assertEqualsCanonicalizing(
            [$responsavel->id, $admin->id],
            $resposta->json('participantes')
        );

        $this->assertSame(0, Compromisso::count());
    }

    /** As reuniões vinculadas aparecem no detalhe, com o intervalo completo. */
    public function test_detalhe_da_tarefa_lista_as_reunioes_vinculadas(): void
    {
        $usuario = User::factory()->create();
        $tarefa = $this->tarefaComPrazo('2026-10-12');

        Compromisso::factory()->em('2026-10-10', '14:00')->create([
            'criado_por_id' => $usuario->id,
            'tarefa_id' => $tarefa->id,
            'titulo' => 'Alinhamento prévio',
        ]);

        $resposta = $this->actingAs($usuario)
            ->getJson(route('agenda.tarefa', $tarefa))
            ->assertOk();

        $this->assertSame('Alinhamento prévio', $resposta->json('reunioes.0.titulo'));
        $this->assertSame('10/10 · 14:00–15:00', $resposta->json('reunioes.0.quando'));
    }
}
