<?php

namespace Tests\Feature\Licencas;

use App\Models\Cliente;
use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Licença tem porta própria (T-582): o financeiro suspende quem não paga sem
 * ganhar o cadastro do cliente. `licencas,incluir` libera e renova (levam
 * valor); `licencas,editar` suspende e reativa.
 */
class PermissaoDeLicencasTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Cliente $pendente;

    private Cliente $licenciado;

    protected function setUp(): void
    {
        parent::setUp();

        (new PerfilPermissaoSeeder)->run();

        $this->gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $this->pendente = Cliente::create(['nome' => 'Academia Pendente', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $this->pendente->sistemas()->attach($this->gym->id, ['ativo' => true, 'status_saas' => 'pendente']);

        $this->licenciado = Cliente::create(['nome' => 'Academia Licenciada', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $this->licenciado->sistemas()->attach($this->gym->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_status' => 'ativa', 'licenca_id_externo' => '77',
        ]);
    }

    private function comPerfil(string $slug): User
    {
        $usuario = User::factory()->semPerfil()->create();
        $usuario->perfis()->attach(Perfil::where('slug', $slug)->value('id'));

        return $usuario;
    }

    /** Ajusta a linha de `licencas` do perfil, como o admin faria na grade. */
    private function naGrade(string $slug, array $acoes): void
    {
        DB::table('perfil_permissao')
            ->where('perfil_id', Perfil::where('slug', $slug)->value('id'))
            ->where('permissao_id', Permissao::where('recurso', 'licencas')->value('id'))
            ->update($acoes);
    }

    public function test_financeiro_licencas_suspende_sem_editar_o_cliente(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/77/bloquear' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'dados' => ['status' => 'bloqueado'],
            ], 200),
        ]);

        $financeiro = $this->comPerfil('financeiro_licencas');

        $this->actingAs($financeiro)
            ->post(route('clientes.bloquearLicenca', [$this->licenciado, $this->gym]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        Http::assertSentCount(1);

        // O cadastro continua fechado para ele.
        $this->actingAs($financeiro)
            ->put(route('clientes.update', $this->licenciado), ['nome' => 'Outro nome'])
            ->assertForbidden();
        $this->actingAs($financeiro)
            ->delete(route('clientes.destroy', $this->licenciado))
            ->assertForbidden();

        $this->assertSame('Academia Licenciada', $this->licenciado->fresh()->nome);
    }

    public function test_financeiro_licencas_ve_as_quatro_acoes_na_lista(): void
    {
        $resposta = $this->actingAs($this->comPerfil('financeiro_licencas'))
            ->get(route('clientes.index'))
            ->assertOk();

        $resposta->assertSee('Liberar licença');
        $resposta->assertSee('Renovar licença');
        $resposta->assertSee('Suspender licença');
    }

    public function test_so_editar_suspende_mas_nao_libera_nem_renova(): void
    {
        Http::fake();
        $this->naGrade('financeiro_licencas', ['incluir' => false]);
        $usuario = $this->comPerfil('financeiro_licencas');

        $this->actingAs($usuario)
            ->post(route('clientes.liberarLicenca', [$this->pendente, $this->gym]), ['tipo' => 'mensal', 'valor' => 99])
            ->assertForbidden();
        $this->actingAs($usuario)
            ->post(route('clientes.renovarLicenca', [$this->licenciado, $this->gym]), ['tipo' => 'anual'])
            ->assertForbidden();

        Http::assertNothingSent();

        // E a tela acompanha: o que a rota recusa não aparece como botão.
        $resposta = $this->actingAs($usuario)->get(route('clientes.index'))->assertOk();
        $resposta->assertDontSee('Liberar licença');
        $resposta->assertDontSee('Renovar licença');
        $resposta->assertSee('Suspender licença');
    }

    public function test_so_incluir_libera_mas_nao_suspende_nem_reativa(): void
    {
        Http::fake();
        $this->naGrade('financeiro_licencas', ['editar' => false]);
        $usuario = $this->comPerfil('financeiro_licencas');

        $this->actingAs($usuario)
            ->post(route('clientes.bloquearLicenca', [$this->licenciado, $this->gym]))
            ->assertForbidden();
        $this->actingAs($usuario)
            ->post(route('clientes.desbloquearLicenca', [$this->licenciado, $this->gym]))
            ->assertForbidden();

        Http::assertNothingSent();

        $resposta = $this->actingAs($usuario)->get(route('clientes.index'))->assertOk();
        $resposta->assertSee('Renovar licença');
        $resposta->assertDontSee('Suspender licença');
    }

    public function test_editar_clientes_sem_licencas_nao_opera_licenca(): void
    {
        Http::fake();

        // O Financeiro de antes: lê clientes, e licença não é dele.
        $financeiro = $this->comPerfil('financeiro');

        $this->actingAs($financeiro)
            ->post(route('clientes.bloquearLicenca', [$this->licenciado, $this->gym]))
            ->assertForbidden();

        // E editar clientes, sozinho, deixou de bastar.
        $this->naGrade('operacao', ['ler' => false, 'incluir' => false, 'editar' => false]);

        $this->actingAs($this->comPerfil('operacao'))
            ->post(route('clientes.bloquearLicenca', [$this->licenciado, $this->gym]))
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_a_migracao_mantem_quem_ja_editava_clientes_e_deixa_a_revenda_de_fora(): void
    {
        $migracao = require database_path('migrations/2026_11_05_100000_permissao_de_licencas.php');
        $migracao->down();

        // Um perfil feito à mão na grade, que edita clientes.
        $proprio = Perfil::create(['slug' => 'atendimento', 'nome' => 'Atendimento']);
        $proprio->permissoes()->attach(Permissao::where('recurso', 'clientes')->value('id'), [
            'ler' => true, 'incluir' => true, 'editar' => true, 'imprimir' => false, 'excluir' => false,
        ]);

        $migracao->up();

        $linha = fn (string $slug) => DB::table('perfil_permissao')
            ->where('perfil_id', Perfil::where('slug', $slug)->value('id'))
            ->where('permissao_id', Permissao::where('recurso', 'licencas')->value('id'))
            ->first();

        foreach (['atendimento', 'operacao', 'comercial', 'admin', 'financeiro_licencas'] as $slug) {
            $this->assertNotNull($linha($slug), "{$slug} devia operar licença");
            $this->assertTrue((bool) $linha($slug)->incluir, "{$slug}: incluir");
            $this->assertTrue((bool) $linha($slug)->editar, "{$slug}: editar");
        }

        // A revenda edita clientes, mas licença é decisão da Alfa.
        $this->assertNull($linha('revenda'));
        // O Financeiro de antes só lia clientes: continua sem licença.
        $this->assertNull($linha('financeiro'));
    }
}
