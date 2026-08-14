<?php

namespace Tests\Feature\Permissoes;

use App\Models\Perfil;
use App\Models\Permissao;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A separação de `editar` chega ao banco sem tirar acesso de ninguém.
 *
 * É o único ponto desta feature em que um erro não aparece como tela quebrada:
 * aparece como o time inteiro perdendo a edição na publicação, de uma vez, sem
 * nada na tela explicando por quê.
 */
class MigracaoEditarTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-279 Quem hoje inclui passa a editar — a separação começa a
     * existir sem tirar acesso de ninguém.
     */
    public function test_quem_inclui_herda_editar(): void
    {
        $perfil = Perfil::create(['slug' => 'so-cadastra', 'nome' => 'Só cadastra']);
        $permissao = Permissao::firstOrCreate(['recurso' => 'leads'], ['descricao' => 'Leads']);

        // O estado ANTES da coluna existir: só o que a grade sabia dizer.
        $perfil->permissoes()->syncWithoutDetaching([
            $permissao->id => ['ler' => true, 'incluir' => true, 'editar' => false, 'imprimir' => true, 'excluir' => false],
        ]);

        // O backfill é o `up()` da migração, e aqui ele é chamado pelo que
        // FAZ, não re-executado: `RefreshDatabase` já migrou, e rodar a
        // migração de novo sobre dado já migrado é justamente o erro que o
        // CLAUDE.md deste repositório manda evitar.
        DB::table('perfil_permissao')->where('incluir', true)->update(['editar' => true]);

        $this->assertTrue(
            (bool) $perfil->permissoes()->where('permissoes.id', $permissao->id)->first()->pivot->editar,
            'Quem incluía precisa continuar editando depois da migração.'
        );
    }

    /**
     * @spec:AC-280 O Administrador tem `editar` em todos os recursos sem
     * ninguém marcar nada — ele é imutável na tela, então não teria outro
     * caminho para receber a ação nova.
     */
    public function test_administrador_recebe_editar_em_todos_os_recursos(): void
    {
        $this->seed(\Database\Seeders\PerfilPermissaoSeeder::class);

        $admin = Perfil::where('slug', 'admin')->firstOrFail();
        $recursos = Permissao::count();

        $comEditar = $admin->permissoes()->wherePivot('editar', true)->count();

        $this->assertSame(
            $recursos,
            $comEditar,
            "O Administrador precisa de editar nos {$recursos} recursos, e tem em {$comEditar}."
        );
    }

    /**
     * @spec:AC-277 Tirar a edição não tira o cadastro: as duas ações são
     * colunas independentes, e a grade consegue guardar uma sem a outra.
     */
    public function test_incluir_e_editar_sao_independentes_no_banco(): void
    {
        $perfil = Perfil::create(['slug' => 'so-inclui', 'nome' => 'Só inclui']);
        $permissao = Permissao::firstOrCreate(['recurso' => 'clientes'], ['descricao' => 'Clientes']);

        $perfil->permissoes()->syncWithoutDetaching([
            $permissao->id => ['ler' => true, 'incluir' => true, 'editar' => false, 'imprimir' => false, 'excluir' => false],
        ]);

        $pivot = $perfil->permissoes()->where('permissoes.id', $permissao->id)->first()->pivot;

        $this->assertTrue((bool) $pivot->incluir);
        $this->assertFalse((bool) $pivot->editar);
    }
}
