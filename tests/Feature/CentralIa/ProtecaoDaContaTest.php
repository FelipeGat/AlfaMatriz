<?php

namespace Tests\Feature\CentralIa;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conta com a Central de IA segue a regra de contas de sempre: o
 * administrador desativa, troca a senha, altera e exclui — inclusive a do
 * dono da Central.
 *
 * Dono do produto, 14/10/2026 (T-368): "admin precisa ter acesso total a
 * tudo". A branch da Central trazia uma trava que deixava essas contas só
 * nas mãos da própria pessoa e do dono (`CENTRAL_IA_DONO`); com ela, uma
 * conta comprometida ou de quem saiu da empresa não tinha quem a cortasse
 * pela tela. O que protege a máquina da pessoa é a confirmação fora de banda
 * do runner, e não a conta no AlfaMatriz.
 */
class ProtecaoDaContaTest extends TestCase
{
    use RefreshDatabase;

    /** O factory padrão já nasce administrador. */
    private function admin(): User
    {
        return User::factory()->create();
    }

    private function comCentral(User $u): User
    {
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $u->fresh();
    }

    public function test_admin_administra_a_conta_do_dono_da_central(): void
    {
        $felipe = $this->comCentral($this->admin());
        config(['central_ia.dono' => $felipe->email]);
        $rossini = $this->admin();
        $hash = $felipe->password;

        $this->actingAs($rossini)->post(route('usuarios.senha', $felipe))->assertSessionHas('senha_gerada');
        $this->assertNotSame($hash, $felipe->fresh()->password);

        $this->post(route('usuarios.ativo', $felipe))->assertSessionMissing('erro');
        $this->assertFalse((bool) $felipe->fresh()->ativo);
    }

    public function test_admin_desativa_e_exclui_conta_com_central(): void
    {
        $alexandre = $this->comCentral(User::factory()->semPerfil()->create());

        $this->actingAs($this->admin())->post(route('usuarios.ativo', $alexandre))->assertSessionMissing('erro');
        $this->assertFalse((bool) $alexandre->fresh()->ativo);

        $this->delete(route('usuarios.destroy', $alexandre))->assertSessionMissing('erro');
        $this->assertNull(User::find($alexandre->id));
    }

    public function test_conta_sem_central_segue_a_regra_de_sempre(): void
    {
        $comum = User::factory()->semPerfil()->create();
        $this->actingAs($this->admin())->post(route('usuarios.senha', $comum))->assertSessionHas('senha_gerada');
    }
}
