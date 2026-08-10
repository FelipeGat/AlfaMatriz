<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Nem todo sistema cria o usuário administrador junto com o cliente.
 *
 * O AlfaGym exige — sem conta, a academia não entra lá. O AlfaControl não: o
 * usuário é criado depois, no painel dele, e durante a implantação é lá que o
 * cadastro acontece. O formulário precisa enxergar essa diferença sem saber o
 * nome do produto.
 */
class AdminPorCapacidadeTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Sistema $control;

    private Revenda $revenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $this->control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);

        $this->revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $this->revenda->ancorarEm($this->gym, '42');
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    /** @param  array<string, mixed>  $extra */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'tipo_pessoa' => 'PJ',
            'razao_social' => 'Condomínio Central LTDA',
            'nome_fantasia' => 'Condomínio Central',
            'cpf_cnpj' => '98765432000110',
            'tipo_cliente' => 'AVULSO',
            'cidade' => 'Belo Horizonte',
            'uf' => 'MG',
            'revenda_id' => $this->revenda->id,
        ], $extra);
    }

    /**
     * @spec:AC-143 O formulário mostra um bloco de admin por sistema que o
     * exige — e não mostra para os outros.
     */
    public function test_o_formulario_pede_admin_so_para_quem_exige(): void
    {
        $resposta = $this->actingAs($this->admin())->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertSee('Usuário administrador no AlfaGym', escape: false);
        $resposta->assertSee('admins['.$this->gym->id.'][nome]', escape: false);

        $resposta->assertDontSee('Usuário administrador no AlfaControl', escape: false);
        $resposta->assertDontSee('admins['.$this->control->id.'][nome]', escape: false);
    }

    /**
     * @spec:AC-144 Marcar só um sistema que a Matriz não provisiona grava o
     * cliente localmente e não dispara chamada nenhuma. É o cenário da
     * implantação: o vínculo é comercial, e quem cadastra lá é o painel dele.
     */
    public function test_sistema_so_de_leitura_nao_dispara_provisionamento(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('clientes.store'), $this->formulario([
                'sistemas' => [$this->control->id],
            ]))
            ->assertRedirect(route('clientes.index'));

        $this->assertDatabaseHas('clientes', ['nome_fantasia' => 'Condomínio Central']);
        Http::assertNothingSent();
    }

    /**
     * @spec:AC-144 E o vínculo com esse sistema fica registrado, para o
     * faturamento e para o sync reconhecerem o cliente.
     */
    public function test_o_vinculo_comercial_e_gravado_mesmo_sem_provisionar(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('clientes.store'), $this->formulario(['sistemas' => [$this->control->id]]));

        $cliente = Cliente::where('nome_fantasia', 'Condomínio Central')->firstOrFail();

        $this->assertTrue(
            $cliente->sistemas()->where('sistemas.id', $this->control->id)->exists(),
            'O cliente precisa aparecer como usuário do AlfaControl na Matriz.'
        );
    }

    /**
     * @spec:AC-145 O admin enviado vai para o sistema certo, no formato que o
     * contrato daquele sistema espera.
     */
    public function test_o_admin_vai_para_o_sistema_que_o_exige(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfagym',
                'dados' => ['id_externo' => '501', 'status' => 'pendente'],
            ], 201),
        ]);

        $this->actingAs($this->admin())
            ->post(route('clientes.store'), $this->formulario([
                'sistemas' => [$this->gym->id, $this->control->id],
                'admins' => [$this->gym->id => [
                    'nome' => 'Ciclana', 'email' => 'ciclana@central.com.br', 'senha' => 'senha-forte-456',
                ]],
            ]))
            ->assertRedirect(route('clientes.index'));

        // Só o gym recebeu chamada, e com o admin daquele bloco.
        Http::assertSent(fn ($req) => $req->url() === 'https://gym.alfasolucoes.cloud/api/matriz/v1/clientes'
            && $req['nome_admin'] === 'Ciclana'
            && $req['email_admin'] === 'ciclana@central.com.br');

        Http::assertNotSent(fn ($req) => str_contains($req->url(), 'control.alfasolucoes.cloud'));
    }

    /**
     * @spec:AC-143 Sistema que exige admin sem os dados preenchidos recusa o
     * cadastro, apontando o campo do bloco daquele sistema.
     */
    public function test_admin_faltando_recusa_o_cadastro(): void
    {
        Http::preventStrayRequests();
        Http::fake();

        $this->actingAs($this->admin())
            ->post(route('clientes.store'), $this->formulario([
                'sistemas' => [$this->gym->id],
            ]))
            ->assertSessionHasErrors("admins.{$this->gym->id}.nome");

        $this->assertDatabaseMissing('clientes', ['nome_fantasia' => 'Condomínio Central']);
        Http::assertNothingSent();
    }
}
