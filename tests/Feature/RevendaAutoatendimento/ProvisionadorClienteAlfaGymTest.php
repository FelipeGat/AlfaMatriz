<?php

namespace Tests\Feature\RevendaAutoatendimento;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\ProvisionadorClienteAlfaGymService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O cliente da Matriz nascendo no AlfaGym pelo contrato de escrita.
 *
 * Nasce aguardando licença, como nasceria se a revenda tivesse cadastrado pelo
 * painel do gym — quem libera continua sendo o administrador da Alfa.
 */
class ProvisionadorClienteAlfaGymTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private Revenda $revenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->alfagym()->create([
            'slug' => 'alfagym',
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $this->revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::create([
            'nome' => 'Academia Corpo em Movimento',
            'cpf_cnpj' => '98765432000110',
            'cidade' => 'Belo Horizonte',
            'uf' => 'MG',
            'revenda_id' => $this->revenda->id,
            'ativo' => true,
        ]);

        $cliente->telefones()->create(['telefone' => '3188887777', 'principal' => true]);

        return $cliente->fresh();
    }

    /** @return array{nome_admin: string, email_admin: string, senha_admin: string} */
    private function admin(): array
    {
        return [
            'nome_admin' => 'Ciclana de Souza',
            'email_admin' => 'ciclana@corpoemmovimento.com.br',
            'senha_admin' => 'senha-forte-456',
        ];
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

    /** @spec:AC-099 O cliente cadastrado pela revenda nasce aguardando licença. */
    public function test_cliente_nasce_no_gym_pendente_de_licenca_e_ancorado(): void
    {
        $this->revenda->ancorarEm($this->sistema, '42');
        $this->fakeGymAceita();

        $cliente = $this->cliente();

        (new ProvisionadorClienteAlfaGymService($this->sistema))->provisionar($cliente, $this->admin());

        // O que foi enviado: os campos que o formulário do gym exige, com a
        // revenda apontada pela âncora dela (não pelo id local).
        Http::assertSent(function ($request) {
            return $request->method() === 'POST'
                && str_contains($request->url(), '/api/matriz/v1/clientes')
                && $request->hasHeader('X-Matriz-Key', 'chave-de-teste')
                && $request['revenda_id_externo'] === '42'
                && $request['nome'] === 'Academia Corpo em Movimento'
                && $request['cnpj'] === '98765432000110'
                && $request['telefone'] === '3188887777'
                && $request['cidade'] === 'Belo Horizonte'
                && $request['uf'] === 'MG'
                && $request['email_admin'] === 'ciclana@corpoemmovimento.com.br';
        });

        // Ancorado: sem isso o próximo ciclo do sync criaria um SEGUNDO cliente
        // para a mesma academia.
        $this->assertSame('501', $cliente->fresh()->idExternoNoSistema($this->sistema));

        // E o estado já no vínculo, para a tela mostrar "pendente de licença"
        // sem esperar o sync.
        $vinculo = $cliente->fresh()->sistemas()->where('sistemas.id', $this->sistema->id)->first();
        $this->assertNotNull($vinculo);
        $this->assertSame('pendente', $vinculo->pivot->status_saas);
    }

    /** @spec:AC-100 Recusa do AlfaGym vira erro com o motivo, sem ancorar nada. */
    public function test_recusa_do_gym_nao_ancora_o_cliente(): void
    {
        $this->revenda->ancorarEm($this->sistema, '42');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes' => Http::response([
                'contrato' => '1.0',
                'erro' => ['codigo' => 'conflito', 'mensagem' => 'E-mail já cadastrado na plataforma.'],
            ], 409),
        ]);

        $cliente = $this->cliente();

        try {
            (new ProvisionadorClienteAlfaGymService($this->sistema))->provisionar($cliente, $this->admin());
            $this->fail('A recusa do AlfaGym deveria ter interrompido o provisionamento.');
        } catch (\RuntimeException $e) {
            // O motivo do gym chega ao admin, não um "respondeu 409" genérico.
            $this->assertStringContainsString('E-mail já cadastrado na plataforma.', $e->getMessage());
        }

        $this->assertNull($cliente->fresh()->idExternoNoSistema($this->sistema));
    }

    /** @spec:AC-099 Revenda sem âncora no gym recusa antes de tentar criar o cliente. */
    public function test_revenda_nao_provisionada_recusa_com_mensagem_clara(): void
    {
        Http::fake();

        $cliente = $this->cliente();

        try {
            (new ProvisionadorClienteAlfaGymService($this->sistema))->provisionar($cliente, $this->admin());
            $this->fail('Cliente de revenda não provisionada não deveria ser aceito.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('não está provisionada no AlfaGym', $e->getMessage());
        }

        // Nem tentou: o gym não teria a quem vincular a academia.
        Http::assertNothingSent();
    }

    /** @spec:AC-099 Cliente já existente no gym não é criado de novo. */
    public function test_cliente_ja_ancorado_nao_e_criado_outra_vez(): void
    {
        $this->revenda->ancorarEm($this->sistema, '42');
        Http::fake();

        $cliente = $this->cliente();
        $cliente->ancorarEm($this->sistema, '501');

        try {
            (new ProvisionadorClienteAlfaGymService($this->sistema))->provisionar($cliente, $this->admin());
            $this->fail('Cliente já ancorado não deveria ser provisionado de novo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('já existe no AlfaGym', $e->getMessage());
        }

        Http::assertNothingSent();
    }
}
