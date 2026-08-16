<?php

namespace Tests\Feature\SinoForaDoQuadro;

use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A conta fala com o dono, e o sistema fala com os admins (US-093): senha
 * redefinida avisa quem a perdeu; tentativa recusada, papel de admin e
 * desligamento de conta avisam quem responde pelo painel.
 */
class ContaAvisaTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @spec:AC-335 A senha redefinida fala com o dono da conta: no caso normal
     * confirma o que ele pediu; no anormal, é como ele descobre antes de ser
     * trancado do lado de fora.
     */
    public function test_senha_redefinida_avisa_o_dono_da_conta(): void
    {
        $admin = User::factory()->create(['name' => 'Camila Reis']);
        $outroAdmin = User::factory()->create();
        $alvo = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $this->actingAs($admin)->post(route('usuarios.senha', $alvo));

        $aviso = Notificacao::where('tipo', 'senha')->sole();

        $this->assertSame($alvo->id, $aviso->destinatario_id);
        $this->assertStringContainsString('Camila Reis redefiniu a sua senha', $aviso->titulo);
        $this->assertSame(0, Notificacao::where('destinatario_id', $outroAdmin->id)->count());
    }

    /**
     * @spec:AC-336 A tentativa recusada contra um admin acorda todos os admins
     * ativos — o alvo entre eles. A recusa segurou a porta; o sino é o que faz
     * alguém LER que a porta foi tentada.
     */
    public function test_tentativa_recusada_contra_admin_acorda_os_admins(): void
    {
        $adminA = User::factory()->create(['name' => 'Camila Reis']);
        $adminB = User::factory()->create(['name' => 'Bruno Costa']);

        // Alguém com a permissão `usuarios` sem ser administrador — o cenário
        // para o qual a recusa existe.
        $gestor = User::factory()->semPerfil()->create(['name' => 'Sueli Prado']);
        $perfilGestor = Perfil::create(['slug' => 'gestor-de-contas', 'nome' => 'Gestor de contas']);
        $perfilGestor->permissoes()->attach(Permissao::where('recurso', 'usuarios')->value('id'), [
            'ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => true, 'excluir' => false,
        ]);
        $gestor->perfis()->attach($perfilGestor->id);

        $this->actingAs($gestor)->post(route('usuarios.senha', $adminB));

        $avisos = Notificacao::where('tipo', 'seguranca')->get();

        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            $avisos->pluck('destinatario_id')->all(),
        );

        $aviso = $avisos->first();

        $this->assertSame('critico', $aviso->nivel);
        $this->assertStringContainsString('Sueli Prado tentou redefinir a senha de Bruno Costa', $aviso->titulo);

        // E a senha do admin continua a mesma promessa: nenhum aviso de troca.
        $this->assertSame(0, Notificacao::where('tipo', 'senha')->count());
    }

    /**
     * @spec:AC-337 Virar administrador é anunciado aos admins ativos — inclusive
     * ao recém-promovido, que é como ele descobre o que ganhou. Quem promoveu
     * não recebe.
     */
    public function test_virar_admin_avisa_os_admins(): void
    {
        $adminA = User::factory()->create(['name' => 'Camila Reis']);
        $adminB = User::factory()->create(['name' => 'Bruno Costa']);
        $alvo = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $this->actingAs($adminA)->put(route('usuarios.update', $alvo), [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'perfis' => [Perfil::where('slug', 'admin')->value('id')],
        ]);

        $avisos = Notificacao::where('tipo', 'perfil')->get();

        $this->assertEqualsCanonicalizing(
            [$adminB->id, $alvo->id],
            $avisos->pluck('destinatario_id')->all(),
        );
        $this->assertStringContainsString('Rafael Lima virou administrador', $avisos->first()->titulo);
        $this->assertStringContainsString('Camila Reis', $avisos->first()->meta);
    }

    /**
     * @spec:AC-337 Troca entre perfis comuns fica na auditoria: o sino só fala
     * da posse do sistema.
     */
    public function test_troca_entre_perfis_comuns_nao_avisa(): void
    {
        $admin = User::factory()->create();
        User::factory()->create();
        $alvo = User::factory()->membro()->create();

        $this->actingAs($admin)->put(route('usuarios.update', $alvo), [
            'name' => $alvo->name,
            'email' => $alvo->email,
            'perfis' => [Perfil::where('slug', 'financeiro')->value('id')],
        ]);

        $this->assertSame(0, Notificacao::where('tipo', 'perfil')->count());
    }

    /**
     * @spec:AC-338 Desativar avisa os demais admins — e a conta recém-desativada
     * já não está entre os destinatários de nada.
     */
    public function test_desativar_conta_avisa_os_demais_admins(): void
    {
        $adminA = User::factory()->create(['name' => 'Camila Reis']);
        $adminB = User::factory()->create(['name' => 'Bruno Costa']);
        $alvo = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $this->actingAs($adminA)->post(route('usuarios.ativo', $alvo));

        $avisos = Notificacao::where('tipo', 'conta')->get();

        $this->assertSame([$adminB->id], $avisos->pluck('destinatario_id')->all());
        $this->assertSame('atencao', $avisos->first()->nivel);
        $this->assertStringContainsString('Rafael Lima não entra mais no painel', $avisos->first()->titulo);
    }

    /** @spec:AC-338 Excluir a conta segue a mesma régua, no gesto definitivo. */
    public function test_excluir_conta_avisa_os_demais_admins(): void
    {
        $adminA = User::factory()->create();
        $adminB = User::factory()->create();
        $alvo = User::factory()->membro()->create(['name' => 'Rafael Lima']);

        $this->actingAs($adminA)->delete(route('usuarios.destroy', $alvo));

        $avisos = Notificacao::where('tipo', 'conta')->get();

        $this->assertSame([$adminB->id], $avisos->pluck('destinatario_id')->all());
        $this->assertStringContainsString('Conta de Rafael Lima excluída', $avisos->first()->titulo);
    }
}
