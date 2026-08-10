<?php

namespace Tests\Feature\RevendaAutoatendimento;

use App\Models\Cliente;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Licença é decisão da Alfa.
 *
 * A revenda cadastra o cliente e acompanha o pedido; liberar, renovar,
 * suspender e reativar são do administrador da matriz. A tela esconde as ações
 * dela — e o controller recusa, porque esconder botão não é autorização.
 */
class LicencaSoDoAdminTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $alfaGym;

    private Revenda $revenda;

    private Cliente $pendente;

    private Cliente $licenciado;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfaGym = Sistema::factory()->create([
            'slug' => 'alfagym',
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $this->revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $this->pendente = Cliente::create([
            'nome' => 'Academia Pendente', 'revenda_id' => $this->revenda->id, 'ativo' => true,
        ]);
        $this->pendente->sistemas()->attach($this->alfaGym->id, [
            'ativo' => true, 'status_saas' => 'pendente',
        ]);

        $this->licenciado = Cliente::create([
            'nome' => 'Academia Licenciada', 'revenda_id' => $this->revenda->id, 'ativo' => true,
        ]);
        $this->licenciado->sistemas()->attach($this->alfaGym->id, [
            'ativo' => true, 'status_saas' => 'ativo',
            'licenca_id_externo' => '9001', 'licenca_status' => 'ativa',
        ]);
    }

    private function usuarioDaRevenda(): User
    {
        (new PerfilPermissaoSeeder)->run();

        $usuario = User::factory()->semPerfil()->create(['revenda_id' => $this->revenda->id]);
        $usuario->perfis()->attach(Perfil::where('slug', 'revenda')->value('id'));

        return $usuario;
    }

    /** @spec:AC-102 A revenda vê o estado da licença mas não a libera. */
    public function test_revenda_ve_o_estado_mas_nao_as_acoes_de_licenca(): void
    {
        $resposta = $this->actingAs($this->usuarioDaRevenda())
            ->get(route('clientes.index'))
            ->assertOk();

        // O cliente e o estado dele aparecem: ela precisa saber o que está
        // aguardando a Alfa.
        $resposta->assertSee('Academia Pendente');
        $resposta->assertSee('Academia Licenciada');

        // As decisões sobre licença, não.
        $resposta->assertDontSee('Liberar licença');
        $resposta->assertDontSee('Renovar licença');
        $resposta->assertDontSee('Suspender licença');
        $resposta->assertDontSee('Reativar licença');
    }

    /** @spec:AC-102 O admin da Alfa continua vendo as ações de licença. */
    public function test_admin_ve_as_acoes_de_licenca(): void
    {
        $resposta = $this->actingAs(User::factory()->create())
            ->get(route('clientes.index'))
            ->assertOk();

        $resposta->assertSee('Liberar licença');
        $resposta->assertSee('Renovar licença');
    }

    /** @spec:AC-103 A tentativa de liberar licença pela revenda é recusada. */
    public function test_revenda_nao_libera_licenca_nem_por_post_direto(): void
    {
        Http::fake();

        $this->actingAs($this->usuarioDaRevenda())
            ->post(route('clientes.liberarLicenca', $this->pendente), [
                'tipo' => 'mensal', 'valor' => '200.00', 'obs' => 'tentativa',
            ])
            ->assertForbidden();

        // Nenhuma licença criada ou alterada: o gym nem foi procurado, e o
        // cliente continua exatamente como estava.
        Http::assertNothingSent();

        $vinculo = $this->pendente->fresh()->sistemas()->where('sistemas.id', $this->alfaGym->id)->first();
        $this->assertSame('pendente', $vinculo->pivot->status_saas);
        $this->assertNull($vinculo->pivot->licenca_id_externo);
    }

    /** @spec:AC-103 Renovar, suspender e reativar também são recusados. */
    public function test_revenda_nao_renova_suspende_nem_reativa(): void
    {
        Http::fake();

        $usuario = $this->usuarioDaRevenda();

        $this->actingAs($usuario)
            ->post(route('clientes.renovarLicenca', $this->licenciado), ['tipo' => 'anual'])
            ->assertForbidden();

        $this->actingAs($usuario)
            ->post(route('clientes.bloquearLicenca', $this->licenciado))
            ->assertForbidden();

        $this->actingAs($usuario)
            ->post(route('clientes.desbloquearLicenca', $this->licenciado))
            ->assertForbidden();

        Http::assertNothingSent();

        // A licença do cliente segue intacta nas três tentativas.
        $vinculo = $this->licenciado->fresh()->sistemas()->where('sistemas.id', $this->alfaGym->id)->first();
        $this->assertSame('9001', $vinculo->pivot->licenca_id_externo);
        $this->assertSame('ativo', $vinculo->pivot->status_saas);
    }

    /** @spec:AC-103 A recusa vale mesmo para cliente da própria revenda. */
    public function test_recusa_nao_depende_do_cliente_ser_de_outra_revenda(): void
    {
        Http::fake();

        // O cliente É da revenda dele — o que barra não é o escopo, é a decisão
        // sobre licença ser da Alfa.
        $this->assertSame($this->revenda->id, $this->pendente->revenda_id);

        $this->actingAs($this->usuarioDaRevenda())
            ->post(route('clientes.liberarLicenca', $this->pendente), ['tipo' => 'mensal'])
            ->assertForbidden();

        Http::assertNothingSent();
    }
}
