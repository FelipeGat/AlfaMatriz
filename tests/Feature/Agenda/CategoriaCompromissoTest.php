<?php

namespace Tests\Feature\Agenda;

use App\Models\Compromisso;
use App\Models\User;
use App\Services\AgendaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A categoria do compromisso — o que dá cor ao agendamento.
 *
 * A cor sai da categoria, e o token vem do mapa único `Compromisso::CATEGORIAS`,
 * então a grade, o chip, o modal e a legenda pintam sempre igual.
 */
class CategoriaCompromissoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_escolha_o_compromisso_e_reuniao_interna_azul(): void
    {
        $u = User::factory()->create();

        // Salvo sem categoria: cai no padrão do banco.
        $this->actingAs($u)->postJson(route('compromissos.store'), [
            'titulo' => 'Daily', 'data' => '2026-10-12', 'hora' => '09:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
        ])->assertOk();

        $c = Compromisso::first();
        $this->assertSame('interna', $c->categoria);
        $this->assertSame('exame', $c->corToken());
    }

    public function test_cada_categoria_tem_o_seu_token(): void
    {
        $esperado = [
            'interna' => 'exame',
            'cliente' => 'good',
            'deploy' => 'warn',
            'externo' => 'pergunta',
            'foco' => 'triagem',
        ];

        foreach ($esperado as $categoria => $token) {
            $c = new Compromisso(['categoria' => $categoria]);
            $this->assertSame($token, $c->corToken(), "categoria {$categoria}");
        }
    }

    public function test_a_categoria_pinta_o_bloco_da_grade(): void
    {
        $u = User::factory()->create();
        $c = Compromisso::factory()->em('2026-10-12', '09:00', 1)->create([
            'criado_por_id' => $u->id, 'categoria' => 'deploy',
        ]);
        $c->sincronizarParticipantes([$u->id]);

        $grade = (new AgendaService)->gradeSemana(Carbon::parse('2026-10-12'), Carbon::parse('2026-10-12'));
        $bloco = collect($grade['dias'])->firstWhere('data', '2026-10-12')['blocos']->first();

        $this->assertSame('warn', $bloco['token']);
    }

    public function test_categoria_invalida_e_recusada(): void
    {
        $u = User::factory()->create();

        $this->actingAs($u)->postJson(route('compromissos.store'), [
            'titulo' => 'X', 'data' => '2026-10-12', 'hora' => '09:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
            'categoria' => 'inventada',
        ])->assertStatus(422);

        $this->assertSame(0, Compromisso::count());
    }

    public function test_editar_troca_a_categoria(): void
    {
        $u = User::factory()->create();
        $c = Compromisso::factory()->em('2026-10-12', '09:00', 1)->create([
            'criado_por_id' => $u->id, 'categoria' => 'interna',
        ]);

        $this->actingAs($u)->putJson(route('compromissos.update', $c), [
            'titulo' => $c->titulo, 'categoria' => 'cliente',
            'data' => '2026-10-12', 'hora' => '09:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
        ])->assertOk();

        $this->assertSame('cliente', $c->fresh()->categoria);
    }
}
