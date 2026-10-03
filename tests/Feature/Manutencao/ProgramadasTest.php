<?php

namespace Tests\Feature\Manutencao;

use App\Mcp\Servers\AlfaMatrizServer;
use App\Mcp\Tools\MarcarCompromisso;
use App\Mcp\Tools\RemarcarCompromisso;
use App\Models\Compromisso;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A aba Programadas (#225): as próximas janelas da Agenda na categoria
 * Deploy / manutenção, por sistema — e o campo de sistema que a janela ganhou
 * na Agenda e no servidor MCP para isso.
 */
class ProgramadasTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-10-03 15:30:00'));
        $this->gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function janela(array $extra = []): Compromisso
    {
        return Compromisso::factory()->create(array_merge([
            'categoria' => 'deploy',
            'data' => '2026-10-05', 'hora' => '22:00',
            'data_fim' => '2026-10-05', 'hora_fim' => '23:00',
        ], $extra));
    }

    public function test_lista_as_proximas_janelas_por_sistema_e_deixa_de_fora_o_resto(): void
    {
        $tarefa = Tarefa::factory()->create(['sistema_id' => $this->gym->id]);

        $this->janela(['titulo' => 'Troca do servidor do AlfaGym', 'sistema_id' => $this->gym->id]);
        // Sem sistema escolhido, mas com tarefa do AlfaGym: cai no AlfaGym.
        $this->janela(['titulo' => 'Migração do banco', 'tarefa_id' => $tarefa->id, 'data' => '2026-10-06', 'data_fim' => '2026-10-06']);
        $this->janela(['titulo' => 'Janela sem dono']);
        $this->janela(['titulo' => 'Janela que já passou', 'sistema_id' => $this->gym->id, 'data' => '2026-10-01', 'data_fim' => '2026-10-01']);
        $this->janela(['titulo' => 'Terminou hoje cedo', 'sistema_id' => $this->gym->id, 'data' => '2026-10-03', 'data_fim' => '2026-10-03', 'hora' => '08:00', 'hora_fim' => '09:00']);
        $this->janela(['titulo' => 'Reunião de alinhamento', 'categoria' => 'interna']);

        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('manutencao.index', ['aba' => 'programadas']));

        $resposta->assertOk()
            ->assertSeeInOrder(['AlfaGym', 'Troca do servidor do AlfaGym', 'Migração do banco', 'Sem sistema', 'Janela sem dono'])
            ->assertSee($tarefa->codigo())
            ->assertDontSee('Janela que já passou')
            ->assertDontSee('Terminou hoje cedo')
            ->assertDontSee('Reunião de alinhamento');
    }

    public function test_a_janela_em_andamento_aparece_como_acontecendo(): void
    {
        $this->janela(['titulo' => 'Deploy agora', 'sistema_id' => $this->gym->id, 'data' => '2026-10-03', 'data_fim' => '2026-10-03', 'hora' => '15:00', 'hora_fim' => '16:00']);

        $this->actingAs(User::factory()->create())
            ->get(route('manutencao.index', ['aba' => 'programadas']))
            ->assertSee('Deploy agora')
            ->assertSee('acontecendo agora');
    }

    public function test_a_agenda_grava_o_sistema_so_na_janela_de_manutencao(): void
    {
        $usuario = User::factory()->create();

        $this->actingAs($usuario)->postJson(route('compromissos.store'), [
            'titulo' => 'Deploy do AlfaGym',
            'categoria' => 'deploy',
            'sistema_id' => $this->gym->id,
            'data' => '2026-10-05', 'hora' => '22:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
        ])->assertOk();

        $this->postJson(route('compromissos.store'), [
            'titulo' => 'Reunião',
            'categoria' => 'interna',
            'sistema_id' => $this->gym->id,
            'data' => '2026-10-05', 'hora' => '10:00',
            'duracao_modo' => true, 'duracao_horas' => 1,
        ])->assertOk();

        $this->assertSame($this->gym->id, Compromisso::where('titulo', 'Deploy do AlfaGym')->value('sistema_id'));
        $this->assertNull(Compromisso::where('titulo', 'Reunião')->value('sistema_id'));

        $this->get(route('agenda.index'))->assertOk()->assertSee('Nenhum sistema');
    }

    public function test_o_agente_marca_e_remarca_a_janela_com_o_sistema(): void
    {
        $dono = User::factory()->create();

        AlfaMatrizServer::actingAs($dono)->tool(MarcarCompromisso::class, [
            'titulo' => 'Janela do AlfaGym',
            'categoria' => 'deploy',
            'sistema' => 'AlfaGym',
            'data' => '2026-10-05',
            'hora' => '22:00',
        ])->assertOk();

        $janela = Compromisso::sole();
        $this->assertSame($this->gym->id, $janela->sistema_id);

        AlfaMatrizServer::actingAs($dono)->tool(RemarcarCompromisso::class, [
            'compromisso' => '#'.$janela->id,
            'hora' => '23:00',
        ])->assertOk()->assertSee('Sistema: AlfaGym');

        $this->assertSame($this->gym->id, $janela->fresh()->sistema_id, 'Remarcar sem dizer o sistema mantém o dele.');

        AlfaMatrizServer::actingAs($dono)->tool(MarcarCompromisso::class, [
            'titulo' => 'Janela de sistema que não existe',
            'categoria' => 'deploy',
            'sistema' => 'AlfaQualquer',
            'data' => '2026-10-05',
            'hora' => '22:00',
        ])->assertHasErrors();
    }
}
