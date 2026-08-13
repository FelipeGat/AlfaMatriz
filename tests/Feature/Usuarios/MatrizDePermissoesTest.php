<?php

namespace Tests\Feature\Usuarios;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A grade de permissões de cada perfil.
 *
 * O perfil `admin` não se edita, e é essa trava que torna a tela segura: sem
 * ela, o único administrador poderia tirar o recurso `usuarios` do próprio
 * perfil e ninguém reabriria a tela — nem ele. A trava é do servidor, não do
 * `disabled` da view, e é isso que o teste do envio forjado guarda.
 */
class MatrizDePermissoesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Semeia explicitamente: a factory de usuário também semeia, mas estes
        // testes procuram o perfil ANTES de criar alguém, e dependeriam de um
        // efeito colateral para existir.
        (new PerfilPermissaoSeeder)->run();
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    /** @return array<string, array<string, string>> */
    private function grade(array $recursos): array
    {
        return collect($recursos)
            ->mapWithKeys(fn (string $recurso) => [$recurso => ['ler' => '1', 'incluir' => '1']])
            ->all();
    }

    public function test_a_aba_lista_todos_os_perfis_e_recursos(): void
    {
        $resposta = $this->actingAs($this->admin())
            ->get(route('usuarios.index', ['aba' => 'perfis']))
            ->assertOk();

        $resposta->assertSee('Administrador');
        $resposta->assertSee('Membro do time');
        $resposta->assertSee('Usuários do sistema');
        $resposta->assertSee('Triagem de tarefas (priorizar e direcionar)');
    }

    public function test_salvar_a_grade_de_um_perfil_troca_o_que_ele_alcanca(): void
    {
        $operacao = Perfil::where('slug', 'operacao')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('perfis.permissoes', $operacao), ['grade' => $this->grade(['clientes'])])
            ->assertSessionHasNoErrors();

        $operador = User::factory()->semPerfil()->create();
        $operador->perfis()->attach($operacao->id);

        $this->assertTrue($operador->canPermissao('clientes', 'ler'));
        // O que não veio no envio foi DESMARCADO: caixa desligada não viaja em
        // formulário HTML, então sincronizar só o recebido faria desmarcar não
        // desmarcar coisa alguma.
        $this->assertFalse($operador->canPermissao('leads', 'ler'));
        $this->assertFalse($operador->canPermissao('clientes', 'excluir'));
    }

    public function test_o_menu_perde_o_item_que_o_perfil_deixou_de_alcancar(): void
    {
        $operacao = Perfil::where('slug', 'operacao')->firstOrFail();
        $operador = User::factory()->semPerfil()->create();
        $operador->perfis()->attach($operacao->id);

        $this->actingAs($operador)->get(route('leads.index'))->assertOk();

        $this->actingAs($this->admin())
            ->put(route('perfis.permissoes', $operacao), ['grade' => $this->grade(['clientes', 'revendas'])]);

        $this->actingAs($operador)->get(route('leads.index'))->assertForbidden();
        $this->actingAs($operador)->get(route('clientes.index'))->assertOk()->assertDontSee('Funil de Vendas');
    }

    public function test_a_grade_do_administrador_nao_se_edita_nem_por_envio_forjado(): void
    {
        $admin = Perfil::where('slug', 'admin')->firstOrFail();
        $usuarios = Permissao::where('recurso', 'usuarios')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('perfis.permissoes', $admin), ['grade' => $this->grade(['clientes'])])
            ->assertForbidden();

        $pivô = $admin->fresh()->permissoes->firstWhere('id', $usuarios->id)->pivot;

        $this->assertTrue((bool) $pivô->ler, 'O admin não pode perder a chave da própria tela.');
        $this->assertTrue((bool) $pivô->incluir);
        $this->assertTrue((bool) $pivô->excluir);
    }

    public function test_a_tela_ainda_abre_depois_de_uma_tentativa_no_perfil_administrador(): void
    {
        $admin = Perfil::where('slug', 'admin')->firstOrFail();

        $this->actingAs($this->admin())
            ->put(route('perfis.permissoes', $admin), ['grade' => []]);

        $this->actingAs($this->admin())
            ->get(route('usuarios.index', ['aba' => 'perfis']))
            ->assertOk();
    }
}
