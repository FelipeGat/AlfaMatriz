<?php

namespace Tests\Feature\Suporte;

use App\Models\Perfil;
use App\Models\User;
use App\Services\Suporte\Transferencia;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Estar na lista de quem administra o Pedro não abre a aba Suporte (Felipe, 06/11/2026): a conta do Alexandre é
 * "Membro do time", que não tem o recurso `suporte`, e recebia 403. A migração lhe dá um perfil só com isso.
 */
class PerfilSuporteWhatsappTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'alexandreblankl@outlook.com';

    private object $migracao;

    private User $alexandre;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.suporte_pedro.conta' => 'felipe@teste.local']);
        (new PerfilPermissaoSeeder)->run();
        $this->migracao = require database_path('migrations/2026_11_06_100000_perfil_suporte_whatsapp_para_o_alexandre.php');
        $this->alexandre = $this->membro(self::EMAIL);
    }

    private function membro(string $email): User
    {
        $conta = User::factory()->semPerfil()->create(['email' => $email]);
        $conta->perfis()->attach(Perfil::where('slug', 'membro')->value('id'));

        return $conta;
    }

    private function perfis(User $conta): array
    {
        return $conta->perfis()->orderBy('slug')->pluck('slug')->all();
    }

    public function test_a_conta_do_alexandre_passa_a_operar_o_pedro(): void
    {
        Http::preventStrayRequests();
        $this->actingAs($this->alexandre)->get(route('suporte.pedro'))->assertForbidden();
        $this->get(route('suporte.conversas'))->assertForbidden();

        $this->migracao->up();

        $this->get(route('tarefas.index'))->assertOk()->assertSee('Suporte WhatsApp');
        $this->get(route('suporte.conversas'))->assertOk();
        $this->get(route('suporte.pedro'))->assertOk()->assertSee('3109-3265');
        $this->post(route('suporte.pedro.acessos'), ['acessos_internos' => '+55 27 3109-3265 | Alexandre | suporte'])->assertSessionHasNoErrors();
        $this->assertSame(['membro', 'suporte_whatsapp'], $this->perfis($this->alexandre));
    }

    /** O perfil é a porta do Suporte, e mais nenhuma: nem o caixa, nem os usuários, nem a auditoria, nem a Central. */
    public function test_o_perfil_nao_abre_o_resto_do_sistema(): void
    {
        $this->migracao->up();

        $this->actingAs($this->alexandre)->get(route('usuarios.index'))->assertForbidden();
        $this->get(route('auditoria.index'))->assertForbidden();

        $linhas = DB::table('perfil_permissao')->join('permissoes', 'permissoes.id', '=', 'perfil_permissao.permissao_id')
            ->where('perfil_id', Perfil::where('slug', 'suporte_whatsapp')->value('id'))
            ->get(['recurso', 'ler', 'incluir', 'editar', 'imprimir', 'excluir']);

        $this->assertCount(1, $linhas);
        $this->assertSame('suporte', $linhas[0]->recurso);
        $this->assertSame([1, 0, 1, 0, 0], array_map('intval', [$linhas[0]->ler, $linhas[0]->incluir, $linhas[0]->editar, $linhas[0]->imprimir, $linhas[0]->excluir]));
    }

    public function test_outro_membro_continua_sem_a_aba(): void
    {
        $outro = $this->membro('outro@teste.local');

        $this->migracao->up();

        $this->actingAs($outro)->get(route('suporte.pedro'))->assertForbidden();
        $this->assertSame(['membro'], $this->perfis($outro));
    }

    /** Efeito de ter `suporte,editar`: ele entra entre os atendentes, como a spec já previa, e recebe a fila. */
    public function test_ele_passa_a_ser_atendente_do_suporte(): void
    {
        $this->alexandre->update(['name' => 'Alexandre Blank']);
        $this->assertNull(app(Transferencia::class)->atendentePorNome('Alexandre'));

        $this->migracao->up();

        $this->assertSame($this->alexandre->id, app(Transferencia::class)->atendentePorNome('Alexandre')?->id);
    }

    public function test_rodar_de_novo_nao_duplica_e_desfazer_mantem_a_conta(): void
    {
        $this->migracao->up();
        $this->migracao->up();

        $perfil = Perfil::where('slug', 'suporte_whatsapp')->sole();
        $this->assertSame(1, DB::table('perfil_user')->where('perfil_id', $perfil->id)->count());
        $this->assertSame(1, DB::table('perfil_permissao')->where('perfil_id', $perfil->id)->count());

        $this->migracao->down();

        $this->assertSame(0, Perfil::where('slug', 'suporte_whatsapp')->count());
        $this->assertSame(0, DB::table('perfil_user')->where('perfil_id', $perfil->id)->count());
        $this->assertSame(['membro'], $this->perfis($this->alexandre));
    }

    /** A carga de referência roda a cada publicação, depois da migração: não pode tirar o perfil de quem o recebeu. */
    public function test_a_carga_de_referencia_nao_desfaz(): void
    {
        $this->migracao->up();

        $this->artisan('alfa:semear-referencia')->assertSuccessful();

        $this->assertSame(['membro', 'suporte_whatsapp'], $this->perfis($this->alexandre));
        $this->assertTrue($this->alexandre->fresh()->canPermissao('suporte', 'editar'));
    }
}
