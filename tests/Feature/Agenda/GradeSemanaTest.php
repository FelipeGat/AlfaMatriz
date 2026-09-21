<?php

namespace Tests\Feature\Agenda;

use App\Models\Compromisso;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * A Semana como grade de horários: posição pela hora, altura pela duração,
 * sobreposição em colunas, e o que não tem hora na faixa de dia inteiro.
 */
class GradeSemanaTest extends TestCase
{
    use RefreshDatabase;

    private function dia(string $data, string $hora, float $horas, int $criador): Compromisso
    {
        $c = Compromisso::factory()->em($data, $hora, $horas)->create(['criado_por_id' => $criador]);
        $c->sincronizarParticipantes([$criador]);

        return $c;
    }

    private function grade(string $de, string $ate): Collection
    {
        return collect((new AgendaService)->gradeSemana(Carbon::parse($de), Carbon::parse($ate))['dias'])
            ->keyBy('data');
    }

    public function test_compromisso_vira_bloco_posicionado_pela_hora(): void
    {
        $u = User::factory()->create();
        $this->dia('2026-09-14', '09:00', 1, $u->id);

        $bloco = $this->grade('2026-09-14', '2026-09-14')['2026-09-14']['blocos']->first();

        // 09:00 = 540 min → 540/1440 = 37,5% do dia; 1h = 60 min → 4,167%.
        $this->assertEqualsWithDelta(37.5, $bloco['topPct'], 0.01);
        $this->assertEqualsWithDelta(4.167, $bloco['altPct'], 0.01);
        $this->assertSame(1, $bloco['cols']);
    }

    /** Duração maior = bloco mais alto: 3h ocupa o triplo de 1h. */
    public function test_altura_do_bloco_acompanha_a_duracao(): void
    {
        $u = User::factory()->create();
        $curto = $this->dia('2026-09-14', '09:00', 1, $u->id);
        $longo = $this->dia('2026-09-15', '09:00', 3, $u->id);

        $g = $this->grade('2026-09-14', '2026-09-15');
        $altCurto = $g['2026-09-14']['blocos']->first()['altPct'];
        $altLongo = $g['2026-09-15']['blocos']->first()['altPct'];

        $this->assertEqualsWithDelta($altCurto * 3, $altLongo, 0.01);
    }

    /** Dois compromissos sobrepostos ficam lado a lado (cols=2). */
    public function test_compromissos_sobrepostos_repartem_a_coluna(): void
    {
        $u = User::factory()->create();
        $this->dia('2026-09-14', '09:00', 2, $u->id);  // 09–11
        $this->dia('2026-09-14', '10:00', 2, $u->id);  // 10–12, sobrepõe

        $blocos = $this->grade('2026-09-14', '2026-09-14')['2026-09-14']['blocos'];

        $this->assertCount(2, $blocos);
        $this->assertEquals([2, 2], $blocos->pluck('cols')->all());
        $this->assertEqualsCanonicalizing([0, 1], $blocos->pluck('col')->all());
    }

    /** Encostados não é sobreposto: 09–10 e 10–11 ficam cada um em coluna cheia. */
    public function test_compromissos_encostados_nao_repartem(): void
    {
        $u = User::factory()->create();
        $this->dia('2026-09-14', '09:00', 1, $u->id);  // 09–10
        $this->dia('2026-09-14', '10:00', 1, $u->id);  // 10–11

        $blocos = $this->grade('2026-09-14', '2026-09-14')['2026-09-14']['blocos'];

        $this->assertEquals([1, 1], $blocos->pluck('cols')->all());
    }

    /** Prazo de tarefa não tem hora: vai para a faixa de dia inteiro. */
    public function test_prazo_vai_para_o_dia_inteiro_e_nao_para_a_grade(): void
    {
        $u = User::factory()->create();
        Tarefa::factory()->create([
            'criado_por_id' => $u->id, 'responsavel_id' => $u->id,
            'status' => 'em_desenvolvimento', 'prazo' => '2026-09-14', 'titulo' => 'Entregar NF',
        ]);

        $dia = $this->grade('2026-09-14', '2026-09-14')['2026-09-14'];

        $this->assertCount(0, $dia['blocos']);
        $this->assertSame('Entregar NF', $dia['inteiroDia']->firstWhere('tipo', 'tarefa')['titulo']);
    }

    /**
     * Compromisso de vários dias vira bloco na régua REPETINDO a janela de hora
     * em cada dia — um Feirão 09–16 pinta 09–16 no dia 14 E no dia 15, sem
     * esticar até a meia-noite e sem cair na faixa de dia inteiro.
     */
    public function test_compromisso_de_varios_dias_repete_a_janela_na_regua(): void
    {
        $u = User::factory()->create();
        Compromisso::create([
            'titulo' => 'Feirão', 'data' => '2026-09-14', 'hora' => '09:00',
            'data_fim' => '2026-09-15', 'hora_fim' => '16:00',
            'duracao_modo' => false, 'criado_por_id' => $u->id,
        ]);

        $g = $this->grade('2026-09-13', '2026-09-19');

        foreach (['2026-09-14', '2026-09-15'] as $dia) {
            $bloco = $g[$dia]['blocos']->firstWhere('titulo', 'Feirão');

            // Bloco na régua, 09h (540/1440) de topo e 7h (420/1440) de altura.
            $this->assertNotNull($bloco, "sem bloco em {$dia}");
            $this->assertEqualsWithDelta(37.5, $bloco['topPct'], 0.01);
            $this->assertEqualsWithDelta(29.167, $bloco['altPct'], 0.01);

            // E fora da faixa de dia inteiro.
            $this->assertNull($g[$dia]['inteiroDia']->firstWhere('titulo', 'Feirão'));
        }
    }

    /**
     * Quem vira o dia de verdade (22h–02h) NÃO repete a janela: divide em dois
     * blocos — 22h→24h no primeiro dia e 0h→02h no segundo.
     */
    public function test_compromisso_que_vira_o_dia_se_divide_na_regua(): void
    {
        $u = User::factory()->create();
        Compromisso::create([
            'titulo' => 'Virada', 'data' => '2026-09-14', 'hora' => '22:00',
            'data_fim' => '2026-09-15', 'hora_fim' => '02:00',
            'duracao_modo' => false, 'criado_por_id' => $u->id,
        ]);

        $g = $this->grade('2026-09-13', '2026-09-19');

        $primeiro = $g['2026-09-14']['blocos']->firstWhere('titulo', 'Virada');
        $this->assertEqualsWithDelta(91.667, $primeiro['topPct'], 0.01); // 22h
        $this->assertEqualsWithDelta(100.0, $primeiro['topPct'] + $primeiro['altPct'], 0.01); // até 24h

        $segundo = $g['2026-09-15']['blocos']->firstWhere('titulo', 'Virada');
        $this->assertEqualsWithDelta(0.0, $segundo['topPct'], 0.01); // 0h
        $this->assertEqualsWithDelta(8.333, $segundo['altPct'], 0.01); // até 02h
    }

    public function test_linha_do_agora_so_aparece_quando_hoje_esta_na_semana(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-14 10:00'));

        $comHoje = (new AgendaService)->gradeSemana(Carbon::parse('2026-09-13'), Carbon::parse('2026-09-19'));
        $semHoje = (new AgendaService)->gradeSemana(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-07'));

        // 10:00 = 600 min → 41,667%.
        $this->assertEqualsWithDelta(41.667, $comHoje['agoraPct'], 0.01);
        $this->assertNull($semHoje['agoraPct']);

        Carbon::setTestNow();
    }
}
