<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O valor da licença tem coluna própria.
 *
 * Não entra em `clientes.valor_mensal`: aquele é o acordo comercial entre a
 * Alfa e o cliente, preenchido por quem vende, e alimenta o ticket médio.
 * Sobrescrevê-lo com o preço registrado no sistema de origem faria o KPI passar
 * a medir outra coisa sem ninguém perceber.
 */
class ValorDaLicencaTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $control;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();

        // Com `sincroniza_licencas`: este teste é sobre ler o valor da licença.
        // O AlfaControl real ainda não tem essa capacidade — renovar lá encadeia
        // licenças ativas, e espelhar isso faria a Matriz faturar sobre o defeito.
        $this->control = Sistema::factory()->alfacontrol()->create([
            'token' => 'chave-control',
            'capacidades' => ['sincroniza', 'sincroniza_licencas', 'sincroniza_modulos'],
        ]);

        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $revenda->ancorarEm($this->control, '900');

        $this->cliente = Cliente::create([
            'nome' => 'Condomínio Central',
            'revenda_id' => $revenda->id,
            'ativo' => true,
            'tipo_cliente' => 'AVULSO',
        ]);
        $this->cliente->ancorarEm($this->control, '501');
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    private function fakeComLicenca(array $licenca): void
    {
        Http::preventStrayRequests();
        Http::fake(function ($request) use ($licenca) {
            $dados = str_contains($request->url(), '/licencas') ? [$licenca] : [];

            return Http::response(['contrato' => '1.0', 'sistema' => 'alfacontrol', 'dados' => $dados]);
        });
    }

    /**
     * @spec:AC-160 O valor que a origem informa é gravado no vínculo.
     */
    public function test_o_valor_da_licenca_e_gravado(): void
    {
        $this->fakeComLicenca([
            'id_externo' => '1', 'cliente_id_externo' => '501', 'status' => 'ativa',
            'plano' => 'mensal', 'inicio_em' => '2026-08-01',
            'fim_em' => now()->addMonths(2)->toDateString(), 'valor' => 349.00,
        ]);

        (new SincronizadorSistemaService($this->control))->sincronizar();

        $vinculo = $this->cliente->fresh()->sistemas()->first()->pivot;

        $this->assertSame(349.0, $vinculo->valorDaLicenca());
    }

    /**
     * @spec:AC-160 O comercial do cliente NÃO é tocado. É o ponto da decisão:
     * o ticket médio da Matriz continua medindo o que sempre mediu.
     */
    public function test_o_comercial_do_cliente_nao_e_sobrescrito(): void
    {
        $this->fakeComLicenca([
            'id_externo' => '1', 'cliente_id_externo' => '501', 'status' => 'ativa',
            'plano' => 'mensal', 'inicio_em' => '2026-08-01',
            'fim_em' => now()->addMonths(2)->toDateString(), 'valor' => 349.00,
        ]);

        (new SincronizadorSistemaService($this->control))->sincronizar();

        $cliente = $this->cliente->fresh();

        $this->assertNull($cliente->valor_mensal, 'O valor da licença não pode virar o valor do contrato.');
        $this->assertSame('AVULSO', $cliente->tipo_cliente);
    }

    /**
     * @spec:AC-161 Sistema que não informa valor deixa a coluna vazia, em vez
     * de mostrar zero. O contrato do AlfaGym ainda não expõe `valor`, e nada
     * precisa mudar lá para esta coluna existir.
     */
    public function test_sistema_que_nao_informa_valor_fica_vazio(): void
    {
        $gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $this->cliente->ancorarEm($gym, '128');

        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $dados = str_contains($request->url(), '/licencas')
                // Exatamente o formato do AlfaGym: sem `valor`.
                ? [['id_externo' => '91', 'cliente_id_externo' => '128', 'status' => 'ativa',
                    'plano' => 'mensal', 'inicio_em' => '2026-08-01',
                    'fim_em' => now()->addMonths(2)->toDateString()]]
                : [];

            return Http::response(['contrato' => '1.0', 'sistema' => 'alfagym', 'dados' => $dados]);
        });

        (new SincronizadorSistemaService($gym))->sincronizar();

        $vinculo = $this->cliente->fresh()->sistemas()->where('sistemas.id', $gym->id)->first()->pivot;

        $this->assertNull($vinculo->valorDaLicenca());
    }

    /**
     * @spec:AC-161 Licença suspensa não é receita corrente: não soma.
     */
    public function test_licenca_suspensa_nao_soma(): void
    {
        $this->cliente->sistemas()->syncWithoutDetaching([$this->control->id => [
            'ativo' => true, 'status_saas' => 'bloqueado',
            'licenca_id_externo' => '1', 'licenca_valor' => 349.00,
        ]]);

        $vinculo = $this->cliente->fresh()->sistemas()->first()->pivot;

        $this->assertSame('suspensa', $vinculo->estado());
        $this->assertNull($vinculo->valorDaLicenca());
    }

    /**
     * @spec:AC-162 A tela mostra a coluna com o valor, e o cliente sem valor
     * informado aparece com travessão em vez de R$ 0,00.
     */
    public function test_a_tela_mostra_a_coluna(): void
    {
        $this->cliente->sistemas()->syncWithoutDetaching([$this->control->id => [
            'ativo' => true, 'status_saas' => 'ativo', 'licenca_id_externo' => '1',
            'licenca_valor' => 349.00, 'licenca_fim_em' => now()->addMonths(2)->toDateString(),
        ]]);

        $semValor = Cliente::create(['nome' => 'Sem licença informada', 'ativo' => true]);
        $semValor->sistemas()->attach($this->control->id, ['ativo' => true, 'status_saas' => 'ativo']);

        $resposta = $this->actingAs($this->admin())->get(route('clientes.index'));

        $resposta->assertOk();
        $resposta->assertSee('Licença', escape: false);
        $resposta->assertSee('349,00', escape: false);
        $resposta->assertSee('—', escape: false);
    }
}
