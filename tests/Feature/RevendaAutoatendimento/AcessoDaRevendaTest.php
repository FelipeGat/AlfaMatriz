<?php

namespace Tests\Feature\RevendaAutoatendimento;

use App\Models\Cliente;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O acesso da revenda ao painel da Matriz.
 *
 * Enquanto os dois painéis operam, a revenda usa a MESMA credencial nos dois:
 * o provisionamento cria o administrador dela no AlfaGym e o acesso aqui.
 */
class AcessoDaRevendaTest extends TestCase
{
    use RefreshDatabase;

    private function cenario(): array
    {
        $sistema = Sistema::factory()->alfagym()->create([
            'slug' => 'alfagym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $revenda = Revenda::create([
            'nome' => 'Invest Soluções',
            'contato_email' => 'contato@invest.com.br',
            'ativo' => true,
        ]);

        return compact('sistema', 'revenda');
    }

    /**
     * O gym aceitando o provisionamento.
     *
     * Fica fora do `cenario()` de propósito: `Http::fake()` SOMA stubs e o
     * primeiro que casa vence, então um fake de sucesso montado no cenário
     * engoliria o fake de recusa do teste seguinte — que passaria sem nunca
     * exercitar a recusa.
     */
    private function fakeGymAceita(): void
    {
        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'dados' => [
                    'id_externo' => '42',
                    'nome' => 'Invest Soluções',
                    'admin_id_externo' => '7',
                    'email_admin' => 'admin@invest.com.br',
                ],
            ], 201),
        ]);
    }

    /** @spec:AC-105 Provisionar a revenda cria também o acesso dela ao painel. */
    public function test_provisionar_cria_o_acesso_da_revenda_ao_painel(): void
    {
        ['revenda' => $revenda] = $this->cenario();
        $this->fakeGymAceita();

        $this->actingAs(User::factory()->create());

        $this->post(route('revendas.provisionar', $revenda), [
            'nome_admin' => 'Fulano da Silva',
            'email_admin' => 'admin@invest.com.br',
            'senha_admin' => 'senha-forte-123',
        ])->assertSessionHas('status');

        $usuario = User::where('email', 'admin@invest.com.br')->first();

        $this->assertNotNull($usuario, 'O provisionamento não criou o acesso da revenda ao painel.');
        $this->assertSame('Fulano da Silva', $usuario->name);
        $this->assertSame($revenda->id, $usuario->revenda_id);
        $this->assertTrue($usuario->temEscopoDeRevenda());
        $this->assertContains('revenda', $usuario->perfis->pluck('slug')->all());

        // A senha vale de verdade: o acesso precisa servir para entrar, não só
        // existir na tabela.
        $this->assertTrue(Hash::check('senha-forte-123', $usuario->password));
    }

    /** @spec:AC-105 Acesso não é criado quando o AlfaGym recusa o provisionamento. */
    public function test_recusa_do_gym_nao_deixa_acesso_orfao(): void
    {
        ['revenda' => $revenda] = $this->cenario();

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/revendas' => Http::response([
                'contrato' => '1.0',
                'erro' => ['codigo' => 'conflito', 'mensagem' => 'CNPJ já cadastrado.'],
            ], 409),
        ]);

        $this->actingAs(User::factory()->create());

        $this->post(route('revendas.provisionar', $revenda), [
            'nome_admin' => 'Fulano da Silva',
            'email_admin' => 'admin@invest.com.br',
            'senha_admin' => 'senha-forte-123',
        ])->assertSessionHas('erro');

        // Um acesso apontando para revenda que o gym recusou seria um usuário
        // que entra e não consegue cadastrar nada.
        $this->assertDatabaseMissing('users', ['email' => 'admin@invest.com.br']);
    }

    /** @spec:AC-106 O acesso da revenda enxerga só a revenda dele. */
    public function test_acesso_da_revenda_enxerga_so_a_propria_revenda(): void
    {
        ['revenda' => $minha] = $this->cenario();
        $this->fakeGymAceita();

        $outra = Revenda::create(['nome' => 'Concorrente Ltda', 'ativo' => true]);

        Cliente::create(['nome' => 'Academia do Fulano', 'revenda_id' => $minha->id, 'ativo' => true]);
        Cliente::create(['nome' => 'Academia da Outra', 'revenda_id' => $outra->id, 'ativo' => true]);

        $this->actingAs(User::factory()->create());
        $this->post(route('revendas.provisionar', $minha), [
            'nome_admin' => 'Fulano da Silva',
            'email_admin' => 'admin@invest.com.br',
            'senha_admin' => 'senha-forte-123',
        ]);

        $usuarioDaRevenda = User::where('email', 'admin@invest.com.br')->firstOrFail();

        $resposta = $this->actingAs($usuarioDaRevenda)
            ->get(route('revendas.index', ['aba' => 'clientes']))
            ->assertOk();

        $resposta->assertSee('Academia do Fulano');
        $resposta->assertDontSee('Academia da Outra');
        // Nem no filtro: ver o nome da concorrente já entrega a carteira dela.
        $resposta->assertDontSee('Concorrente Ltda');
    }

    /** @spec:AC-106 O perfil da revenda não abre as telas do negócio da Alfa. */
    public function test_perfil_da_revenda_nao_abre_as_telas_da_alfa(): void
    {
        (new PerfilPermissaoSeeder)->run();

        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $usuario = User::factory()->semPerfil()->create(['revenda_id' => $revenda->id]);
        $usuario->perfis()->attach(Perfil::where('slug', 'revenda')->value('id'));

        // Revendas e clientes: é o trabalho dela.
        $this->actingAs($usuario)->get(route('revendas.index'))->assertOk();

        // Faturamento e leads são o negócio da Alfa com as revendas — inclusive
        // o que a Alfa cobra desta aqui.
        $this->actingAs($usuario)->get(route('faturamento.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('leads.index'))->assertForbidden();
        $this->actingAs($usuario)->get(route('sistemas.index'))->assertForbidden();
    }
}
