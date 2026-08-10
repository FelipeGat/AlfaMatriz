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
 * A revenda cadastrando o próprio cliente pela Matriz — o fluxo que antes ela
 * fazia só no painel do AlfaGym, e que o controller recusava aqui.
 */
class CadastroPelaRevendaTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $alfaGym;

    private Revenda $minhaRevenda;

    private Revenda $outraRevenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfaGym = Sistema::factory()->alfagym()->create([
            'slug' => 'alfagym',
            'nome' => 'AlfaGym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $this->minhaRevenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $this->outraRevenda = Revenda::create(['nome' => 'Concorrente Ltda', 'ativo' => true]);
    }

    private function usuarioDaRevenda(Revenda $revenda): User
    {
        (new PerfilPermissaoSeeder)->run();

        $usuario = User::factory()->semPerfil()->create(['revenda_id' => $revenda->id]);
        $usuario->perfis()->attach(Perfil::where('slug', 'revenda')->value('id'));

        return $usuario;
    }

    private function fakeGymAceita(): void
    {
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'dados' => [
                    'id_externo' => '501',
                    'nome' => 'Academia Corpo em Movimento',
                    'status' => 'pendente',
                    'revenda_id_externo' => '42',
                    'admin_id_externo' => '902',
                    'slug' => 'academia-corpo-em-movimento',
                ],
            ], 201),
        ]);
    }

    /** @return array<string, mixed> */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'revenda_id' => $this->minhaRevenda->id,
            'tipo_pessoa' => 'PJ',
            'razao_social' => 'Corpo em Movimento Ltda',
            'nome_fantasia' => 'Academia Corpo em Movimento',
            'cpf_cnpj' => '98765432000110',
            'tipo_cliente' => 'AVULSO',
            'cidade' => 'Belo Horizonte',
            'uf' => 'MG',
            'sistemas' => [$this->alfaGym->id],
            'telefones' => [['telefone' => '3188887777', 'principal' => 1]],
            // Um bloco de admin por sistema que o exige — o AlfaControl, por
            // exemplo, cria o usuário depois, no painel dele.
            'admins' => [$this->alfaGym->id => [
                'nome' => 'Ciclana de Souza',
                'email' => 'ciclana@corpoemmovimento.com.br',
                'senha' => 'senha-forte-456',
            ]],
        ], $extra);
    }

    /** @spec:AC-098 A revenda cadastra o cliente pela Matriz. */
    public function test_revenda_abre_o_cadastro_de_cliente(): void
    {
        $usuario = $this->usuarioDaRevenda($this->minhaRevenda);

        // Pela tela que o menu alcança, não pelo endpoint solto: o que
        // interessa é que a revenda CONSIGA CHEGAR ao cadastro.
        $resposta = $this->actingAs($usuario)
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertOk();

        $resposta->assertSee(route('clientes.store'), escape: false);

        // A revenda dele aparece; a de outra revenda, não — não há o que escolher.
        $resposta->assertSee('Invest Soluções');
        $resposta->assertDontSee('Concorrente Ltda');
    }

    /** @spec:AC-099 O cliente cadastrado pela revenda nasce aguardando licença. */
    public function test_cliente_da_revenda_nasce_aguardando_licenca(): void
    {
        $this->minhaRevenda->ancorarEm($this->alfaGym, '42');
        $this->fakeGymAceita();

        $usuario = $this->usuarioDaRevenda($this->minhaRevenda);

        $this->actingAs($usuario)
            ->post(route('clientes.store'), $this->formulario())
            ->assertRedirect(route('clientes.index'));

        $cliente = Cliente::where('nome', 'Academia Corpo em Movimento')->firstOrFail();

        $this->assertSame($this->minhaRevenda->id, $cliente->revenda_id);
        $this->assertSame('501', $cliente->idExternoNoSistema($this->alfaGym));

        $vinculo = $cliente->sistemas()->where('sistemas.id', $this->alfaGym->id)->first();
        $this->assertSame('pendente', $vinculo->pivot->status_saas);
    }

    /** @spec:AC-100 Recusa do AlfaGym não deixa cliente órfão na Matriz. */
    public function test_recusa_do_gym_nao_grava_cliente_na_matriz(): void
    {
        $this->minhaRevenda->ancorarEm($this->alfaGym, '42');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes' => Http::response([
                'contrato' => '1.0',
                'erro' => ['codigo' => 'conflito', 'mensagem' => 'E-mail já cadastrado na plataforma.'],
            ], 409),
        ]);

        $usuario = $this->usuarioDaRevenda($this->minhaRevenda);

        $this->actingAs($usuario)
            ->post(route('clientes.store'), $this->formulario())
            ->assertSessionHasErrors('integracao');

        // Nada pela metade: um cliente que existe aqui e não existe lá não teria
        // como ser licenciado depois.
        $this->assertDatabaseMissing('clientes', ['nome' => 'Academia Corpo em Movimento']);
        $this->assertSame(0, Cliente::count());
    }

    /** @spec:AC-101 A revenda não cadastra cliente para outra revenda. */
    public function test_revenda_nao_cadastra_para_outra_revenda(): void
    {
        $this->minhaRevenda->ancorarEm($this->alfaGym, '42');
        $this->fakeGymAceita();

        $usuario = $this->usuarioDaRevenda($this->minhaRevenda);

        // O formulário vem adulterado apontando a concorrente.
        $this->actingAs($usuario)
            ->post(route('clientes.store'), $this->formulario(['revenda_id' => $this->outraRevenda->id]))
            ->assertRedirect(route('clientes.index'));

        $cliente = Cliente::where('nome', 'Academia Corpo em Movimento')->firstOrFail();

        // A revenda vem do escopo do usuário, nunca do que ele enviou.
        $this->assertSame($this->minhaRevenda->id, $cliente->revenda_id);
        $this->assertNotSame($this->outraRevenda->id, $cliente->revenda_id);
    }

    /** @spec:AC-101 Cliente cadastrado pela revenda nasce avulso. */
    public function test_cliente_da_revenda_nasce_avulso(): void
    {
        $this->minhaRevenda->ancorarEm($this->alfaGym, '42');
        $this->fakeGymAceita();

        $usuario = $this->usuarioDaRevenda($this->minhaRevenda);

        // Mesmo que o formulário venha com contrato e valor, o comercial é da
        // Alfa: entra quando a licença é liberada.
        $this->actingAs($usuario)->post(route('clientes.store'), $this->formulario([
            'tipo_cliente' => 'CONTRATO',
            'valor_mensal' => '999.00',
            'dia_vencimento' => 10,
        ]));

        $cliente = Cliente::where('nome', 'Academia Corpo em Movimento')->firstOrFail();

        $this->assertSame('AVULSO', $cliente->tipo_cliente);
        $this->assertNull($cliente->valor_mensal);
        $this->assertNull($cliente->dia_vencimento);
    }

    /** @spec:AC-104 O admin escolhe a revenda no cadastro do cliente. */
    public function test_admin_escolhe_a_revenda_e_o_cliente_nao_vira_venda_direta(): void
    {
        $this->outraRevenda->ancorarEm($this->alfaGym, '43');
        $this->fakeGymAceita();

        $admin = User::factory()->create(); // sem revenda_id: usuário da matriz

        $resposta = $this->actingAs($admin)
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertOk();

        $resposta->assertSee(route('clientes.store'), escape: false);

        // O admin vê todas as revendas ativas para escolher.
        $resposta->assertSee('Invest Soluções');
        $resposta->assertSee('Concorrente Ltda');

        $this->actingAs($admin)
            ->post(route('clientes.store'), $this->formulario(['revenda_id' => $this->outraRevenda->id]))
            ->assertRedirect(route('clientes.index'));

        $cliente = Cliente::where('nome', 'Academia Corpo em Movimento')->firstOrFail();

        // Licenciado para a revenda escolhida, não como venda direta da Alfa.
        $this->assertSame($this->outraRevenda->id, $cliente->revenda_id);
    }

    /** @spec:AC-104 Sem o AlfaGym marcado, o cadastro não chama o gym. */
    public function test_cliente_sem_alfagym_nao_provisiona_nada(): void
    {
        Http::fake();

        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('clientes.store'), $this->formulario([
            'sistemas' => [],
            'admins' => [],
        ]))->assertRedirect(route('clientes.index'));

        $this->assertDatabaseHas('clientes', ['nome' => 'Academia Corpo em Movimento']);
        Http::assertNothingSent();
    }
}
