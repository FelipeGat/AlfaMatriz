<?php

namespace Tests\Feature\AcessoVixcard;

use App\Models\Compromisso;
use App\Models\Perfil;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\TarefaComentario;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Acesso VixCard (docs/specs/acesso-vixcard): a conta com o perfil `vixcard`
 * vê e opera só o que é da VixCard. Cada teste é uma porta que poderia vazar.
 */
class AcessoVixcardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $victor;
    private User $rossini;
    private Sistema $vixId;
    private Sistema $gym;
    private Tarefa $daVix;
    private Tarefa $daAlfa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Felipe Admin']);
        (new PerfilPermissaoSeeder)->run();
        $this->rossini = User::factory()->create(['name' => 'Rossini Alfa']);
        $this->victor = User::factory()->semPerfil()->create(['name' => 'Victor VixCard']);
        $this->victor->perfis()->attach(Perfil::where('slug', 'vixcard')->value('id'));

        $this->vixId = Sistema::factory()->create(['nome' => 'Vix-ID', 'slug' => 'vix-id', 'natureza' => 'vixcard', 'categoria' => null, 'unidade_cobranca' => null]);
        $this->gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'natureza' => 'produto']);
        $this->daVix = Tarefa::factory()->create(['titulo' => 'Coletor C72 lendo etiquetas', 'resumo' => 'Inventário UHF para o gestor',
            'sistema_id' => $this->vixId->id, 'responsavel_id' => $this->admin->id, 'status' => 'em_desenvolvimento', 'prazo' => now()->subDay()->toDateString()]);
        $this->daAlfa = Tarefa::factory()->create(['titulo' => 'Wellhub segredo da academia', 'sistema_id' => $this->gym->id, 'responsavel_id' => $this->rossini->id]);
    }

    public function test_cai_no_painel_dos_projetos_e_ve_so_a_vixcard(): void
    {
        $this->assertSame(route('vixcard.projetos', absolute: false), $this->victor->telaInicial());
        $this->actingAs($this->victor)->get(route('vixcard.projetos'))->assertOk()
            ->assertSee('Vix-ID')->assertSee('Inventário UHF para o gestor')->assertSee('Atrasado')->assertSee('Coletor C72 lendo etiquetas')
            ->assertDontSee('AlfaGym')->assertDontSee('Wellhub')->assertDontSee('Projetos Alfa Soluções');
        // A pasta da Alfa não abre para ele nem pelo endereço.
        $this->get(route('vixcard.projetos', ['pasta' => 'alfa']))->assertOk()->assertSee('Vix-ID')->assertDontSee('AlfaGym');
        // O menu: só o que ele pode, nada da casa.
        $this->get(route('tarefas.index'))->assertOk()->assertSee(route('vixcard.projetos'), false)->assertDontSee('Centro de Controle')->assertDontSee('Revendas e clientes');
    }

    /** Comercial › Projetos (19/10/2026): o time tem as duas pastas, VixCard e Alfa Soluções. */
    public function test_time_ve_as_duas_pastas_de_projetos(): void
    {
        $this->actingAs($this->admin)->get(route('vixcard.projetos'))->assertOk()
            ->assertSee('Projetos VixCard')->assertSee('Projetos Alfa Soluções')->assertSee('Vix-ID')->assertDontSee('Wellhub segredo');
        $this->get(route('vixcard.projetos', ['pasta' => 'alfa']))->assertOk()->assertSee('AlfaGym')->assertDontSee('Coletor C72 lendo etiquetas');
    }

    public function test_quadro_e_listas_nao_trazem_nada_de_fora(): void
    {
        $this->actingAs($this->victor)->get(route('tarefas.index'))->assertOk()
            ->assertSee('Coletor C72 lendo etiquetas')->assertDontSee('Wellhub segredo da academia')
            ->assertDontSee('AlfaGym')->assertDontSee('Rossini Alfa')->assertSee('Felipe Admin');
        $this->get(route('tarefas.historico'))->assertOk()->assertDontSee('Wellhub');
    }

    public function test_tarefa_de_fora_da_404_em_toda_porta(): void
    {
        $this->actingAs($this->victor);
        $this->get(route('tarefas.modal', $this->daAlfa))->assertNotFound();
        $this->put(route('tarefas.update', $this->daAlfa), ['titulo' => 'x'])->assertNotFound();
        $this->post(route('tarefas.comentarios.store', $this->daAlfa), ['texto' => 'oi'])->assertNotFound();
        $this->post(route('tarefas.itens.store', $this->daAlfa), ['texto' => 'x'])->assertNotFound();
        $c = TarefaComentario::withoutGlobalScopes()->forceCreate(['tarefa_id' => $this->daAlfa->id, 'autor_id' => $this->rossini->id, 'corpo' => 'segredo']);
        $this->delete(route('tarefas.comentarios.destroy', $c))->assertNotFound();
        $this->get(route('tarefas.modal', $this->daVix))->assertOk()->assertSee('Coletor C72');
    }

    public function test_cria_e_edita_so_em_produto_vixcard(): void
    {
        $this->actingAs($this->victor);
        $this->post(route('tarefas.store'), ['titulo' => 'Tarefa no Gym', 'tipo' => 'desenvolvimento', 'sistema_id' => $this->gym->id])->assertSessionHasErrors('sistema_id');
        $this->post(route('tarefas.store'), ['titulo' => 'Sem produto', 'tipo' => 'desenvolvimento'])->assertSessionHasErrors('sistema_id');
        $this->post(route('tarefas.store'), ['titulo' => 'Mãe de fora', 'tipo' => 'desenvolvimento', 'sistema_id' => $this->vixId->id, 'tarefa_pai_id' => $this->daAlfa->id])->assertSessionHasErrors('tarefa_pai_id');
        $this->post(route('tarefas.store'), ['titulo' => 'Para o Rossini', 'tipo' => 'desenvolvimento', 'sistema_id' => $this->vixId->id, 'responsavel_id' => $this->rossini->id])->assertSessionHasErrors('responsavel_id');
        $this->assertSame(0, Tarefa::withoutGlobalScopes()->whereIn('titulo', ['Tarefa no Gym', 'Sem produto', 'Mãe de fora', 'Para o Rossini'])->count());

        $this->post(route('tarefas.store'), ['titulo' => 'Etiqueta Zebra ZT231R', 'tipo' => 'desenvolvimento', 'sistema_id' => $this->vixId->id])->assertSessionHasNoErrors();
        $nova = Tarefa::where('titulo', 'Etiqueta Zebra ZT231R')->sole();
        $this->put(route('tarefas.update', $nova), ['titulo' => 'Etiqueta Zebra', 'sistema_id' => $this->gym->id])->assertSessionHasErrors('sistema_id');
        $this->assertSame($this->vixId->id, $nova->fresh()->sistema_id);
    }

    public function test_agenda_so_com_compromissos_da_vixcard_ou_dele(): void
    {
        Compromisso::factory()->create(['titulo' => 'Reunião Vix-ID', 'tarefa_id' => $this->daVix->id, 'criado_por_id' => $this->admin->id, 'data' => now()->toDateString()]);
        Compromisso::factory()->create(['titulo' => 'Diretoria Alfa sigilosa', 'criado_por_id' => $this->rossini->id, 'data' => now()->toDateString()]);
        $this->actingAs($this->victor)->get(route('agenda.index', ['visao' => 'lista']))->assertOk()
            ->assertSee('Reunião Vix-ID')->assertDontSee('Diretoria Alfa sigilosa')->assertDontSee('Wellhub')->assertDontSee('Rossini Alfa');
        $this->post(route('compromissos.store'), ['titulo' => 'Com o Rossini', 'data' => now()->addDay()->toDateString(), 'hora' => '10:00',
            'participantes' => [$this->rossini->id]])->assertSessionHasErrors('participantes');
    }

    public function test_sem_o_perfil_nada_muda(): void
    {
        $this->actingAs($this->admin)->get(route('tarefas.index'))->assertOk()->assertSee('Wellhub segredo da academia')->assertSee('Coletor C72');
        $this->assertSame(2, Tarefa::count());
        $this->get(route('vixcard.projetos'))->assertOk()->assertSee('Vix-ID')->assertDontSee('AlfaGym');
    }

    public function test_mcp_recusa_a_conta_vixcard(): void
    {
        $this->expectException(\RuntimeException::class);
        \App\Mcp\Servers\AlfaMatrizServer::conferir($this->victor);
    }
}
