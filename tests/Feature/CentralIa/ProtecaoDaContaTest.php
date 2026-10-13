<?php

namespace Tests\Feature\CentralIa;

use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conta com a Central de IA só muda pela própria pessoa (ou pelo dono da
 * Central, para os outros). Dono, 12/10/2026: um admin redefinir a senha do
 * Felipe e entrar como ele "não pode ocorrer" — a Central dele aciona
 * agentes com acesso total na máquina dele.
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

    public function test_admin_nao_redefine_senha_nem_altera_a_conta_do_dono(): void
    {
        $felipe = $this->comCentral($this->admin());
        config(['central_ia.dono' => $felipe->email]);
        $rossini = $this->admin();
        $hash = $felipe->password;

        $this->actingAs($rossini)->post(route('usuarios.senha', $felipe))->assertSessionHas('erro');
        $this->assertSame($hash, $felipe->fresh()->password);

        $this->put(route('usuarios.update', $felipe), ['name' => 'X', 'email' => 'rossini-controla@exemplo.test', 'perfis' => [Perfil::where('slug', 'admin')->value('id')]])
            ->assertSessionHas('erro');
        $this->assertSame($felipe->email, $felipe->fresh()->email);

        $this->post(route('usuarios.ativo', $felipe))->assertSessionHas('erro');
        $this->assertTrue((bool) $felipe->fresh()->ativo);

        $this->delete(route('usuarios.destroy', $felipe))->assertSessionHas('erro');
        $this->assertNotNull($felipe->fresh());

        // A tentativa chega ao sino do alvo.
        $this->assertDatabaseHas('notificacoes', ['destinatario_id' => $felipe->id, 'tipo' => 'seguranca']);
    }

    public function test_conta_com_central_de_outra_pessoa_tambem_e_protegida_de_admin_comum(): void
    {
        $alexandre = $this->comCentral(User::factory()->semPerfil()->create());
        $rossini = $this->admin();
        $hash = $alexandre->password;

        $this->actingAs($rossini)->post(route('usuarios.senha', $alexandre))->assertSessionHas('erro');
        $this->assertSame($hash, $alexandre->fresh()->password);
    }

    public function test_dono_administra_a_conta_de_outro_usuario_com_central(): void
    {
        $felipe = $this->comCentral($this->admin());
        config(['central_ia.dono' => $felipe->email]);
        $alexandre = $this->comCentral(User::factory()->semPerfil()->create());
        $hash = $alexandre->password;

        $this->actingAs($felipe)->post(route('usuarios.senha', $alexandre))->assertSessionHas('senha_gerada');
        $this->assertNotSame($hash, $alexandre->fresh()->password);
    }

    public function test_conta_sem_central_segue_a_regra_de_sempre(): void
    {
        $comum = User::factory()->semPerfil()->create();
        $this->actingAs($this->admin())->post(route('usuarios.senha', $comum))->assertSessionHas('senha_gerada');
    }
}
