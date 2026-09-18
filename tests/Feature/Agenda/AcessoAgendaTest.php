<?php

namespace Tests\Feature\Agenda;

use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quem entra na Agenda — e quem não entra.
 *
 * A porta é `permissao:agenda`, e não `permissao:tarefas`, porque a tela mostra
 * nome, horário e pauta de reunião do time. Os dois perfis que a régua separa
 * são o de exibição (o monitor da parede, que lê o quadro) e o de revenda.
 */
class AcessoAgendaTest extends TestCase
{
    use RefreshDatabase;

    public function test_membro_do_time_abre_a_agenda(): void
    {
        $usuario = User::factory()->membro()->create();

        $this->actingAs($usuario)->get(route('agenda.index'))->assertOk();
    }

    public function test_usuario_de_revenda_toma_403_na_agenda(): void
    {
        $revenda = Revenda::create(['nome' => 'Alpha Rev', 'ativo' => true]);

        // Perfil admin de fábrica carrega TODAS as permissões: o bloqueio
        // precisa vencer mesmo assim, só pelo escopo — como no quadro.
        $usuario = User::factory()->create(['revenda_id' => $revenda->id]);

        $this->actingAs($usuario)->get(route('agenda.index'))->assertForbidden();
    }

    /**
     * O painel de parede lê o quadro e NÃO lê a agenda.
     *
     * É a diferença que justifica a permissão própria: aquela conta fica aberta
     * o dia todo num monitor da sala, e a agenda do time exposta ali é o que
     * ninguém negociou ao pendurar o monitor.
     */
    public function test_perfil_de_exibicao_nao_alcanca_a_agenda(): void
    {
        (new PerfilPermissaoSeeder)->run();

        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach(Perfil::where('slug', 'exibicao')->value('id'));

        $this->actingAs($usuario)->get(route('tarefas.index'))->assertOk();
        $this->actingAs($usuario)->get(route('agenda.index'))->assertForbidden();
    }

    /**
     * A Agenda mora em DESENVOLVIMENTO, ao lado de Tarefas.
     *
     * O desenho (§19) a punha num grupo "Pessoal", com as Notas; decisão do
     * dono do produto em 18/09/2026 mudou o lugar. O teste fixa o grupo porque
     * é ele que o AC-094 usa: revenda não vê Desenvolvimento no menu, e a
     * Agenda entrar ali significa entrar nessa regra.
     */
    public function test_menu_mostra_a_agenda_em_desenvolvimento(): void
    {
        $usuario = User::factory()->membro()->create();

        $resposta = $this->actingAs($usuario)->get(route('agenda.index'));

        $resposta->assertOk()
            ->assertSee('Desenvolvimento')
            ->assertSee('Prazos de tarefas e compromissos do time')
            // O grupo que o desenho previa não existe mais: deixá-lo para trás
            // seria um rótulo de seção sozinho na barra.
            ->assertDontSee('>Pessoal<', false);

        // Ao lado de Tarefas, e não em qualquer lugar do menu.
        $menu = $resposta->getContent();
        $this->assertGreaterThan(
            strpos($menu, 'Desenvolvimento'),
            strpos($menu, 'agenda'),
        );
    }
}
