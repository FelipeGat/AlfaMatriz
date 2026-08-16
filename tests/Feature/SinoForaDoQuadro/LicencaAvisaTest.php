<?php

namespace Tests\Feature\SinoForaDoQuadro;

use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\GerenciadorLicencaService;
use App\Services\ProvisionadorClienteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * O eixo do pedido de licença anda pelo sino nos dois sentidos (US-092): a
 * revenda cadastra e a matriz fica sabendo que há um pendente; a matriz decide
 * e a revenda fica sabendo o que foi decidido.
 */
class LicencaAvisaTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private Revenda $revenda;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->alfagym()->create([
            'base_url' => 'https://gym.alfasolucoes.cloud',
            'token' => 'chave-de-teste',
            'ativo' => true,
        ]);

        $this->revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);
        $this->revenda->ancorarEm($this->sistema, '42');
    }

    private function cliente(): Cliente
    {
        $cliente = Cliente::create([
            'nome' => 'Academia Corpo em Movimento',
            'cpf_cnpj' => '98765432000110',
            'revenda_id' => $this->revenda->id,
            'ativo' => true,
        ]);

        $cliente->telefones()->create(['telefone' => '3188887777', 'principal' => true]);

        return $cliente->fresh();
    }

    /**
     * @spec:AC-333 O cliente que nasce pendente é um pedido esperando a Alfa —
     * e quem edita clientes na matriz fica sabendo sem varrer a lista. Quem só
     * lê (financeiro) e quem é da revenda não recebem.
     */
    public function test_cliente_pendente_avisa_quem_edita_clientes_na_matriz(): void
    {
        $admin = User::factory()->create(['name' => 'Camila Reis']);

        $financeiro = User::factory()->semPerfil()->create();
        $financeiro->perfis()->attach(Perfil::where('slug', 'financeiro')->value('id'));

        $daRevenda = User::factory()->create(['revenda_id' => $this->revenda->id, 'name' => 'Ciclana Souza']);

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/clientes' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'dados' => ['id_externo' => '501', 'status' => 'pendente'],
            ], 201),
        ]);

        $this->actingAs($daRevenda);

        (new ProvisionadorClienteService($this->sistema))->provisionar($this->cliente(), [
            'nome_admin' => 'Ciclana de Souza',
            'email_admin' => 'ciclana@corpo.com.br',
            'senha_admin' => 'senha-forte-456',
        ]);

        $avisos = Notificacao::where('tipo', 'licenca_pendente')->get();

        $this->assertSame([$admin->id], $avisos->pluck('destinatario_id')->all());

        $aviso = $avisos->first();

        $this->assertStringContainsString('aguarda liberação de licença', $aviso->titulo);
        $this->assertStringContainsString('AlfaGym', $aviso->meta);
        $this->assertStringContainsString('Invest Soluções', $aviso->meta);
    }

    /**
     * @spec:AC-334 Bloquear o acesso avisa as contas ativas da revenda dona do
     * cliente — não o admin que decidiu, não a outra revenda, não a conta
     * desativada. A rota é a lista de clientes, a tela que a revenda alcança.
     */
    public function test_bloquear_licenca_avisa_a_revenda_do_cliente(): void
    {
        $admin = User::factory()->create(['name' => 'Camila Reis']);
        $daRevenda = User::factory()->create(['revenda_id' => $this->revenda->id]);
        $desativado = User::factory()->desativado()->create(['revenda_id' => $this->revenda->id]);

        $outraRevenda = Revenda::create(['nome' => 'Beta Rev', 'ativo' => true]);
        $deOutraRevenda = User::factory()->create(['revenda_id' => $outraRevenda->id]);

        $cliente = $this->cliente();
        $cliente->ancorarEm($this->sistema, '501');
        $cliente->sistemas()->syncWithoutDetaching([$this->sistema->id => ['licenca_id_externo' => '91']]);

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas/91/bloquear' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'dados' => ['status' => 'bloqueado'],
            ]),
        ]);

        $this->actingAs($admin);

        (new GerenciadorLicencaService($this->sistema))->bloquear($cliente);

        $avisos = Notificacao::where('tipo', 'licenca')->get();

        $this->assertSame([$daRevenda->id], $avisos->pluck('destinatario_id')->all());

        $aviso = $avisos->first();

        $this->assertSame('atencao', $aviso->nivel);
        $this->assertStringContainsString('bloqueado', $aviso->titulo);
        $this->assertStringContainsString('Academia Corpo em Movimento', $aviso->titulo);
        $this->assertStringContainsString('clientes', $aviso->rota);

        $this->assertSame(0, Notificacao::where('destinatario_id', $deOutraRevenda->id)->count());
        $this->assertSame(0, Notificacao::where('destinatario_id', $desativado->id)->count());
    }

    /** @spec:AC-334 Liberar é a notícia boa: nível de marca, mesmo destino. */
    public function test_liberar_licenca_avisa_como_marca(): void
    {
        $admin = User::factory()->create();
        $daRevenda = User::factory()->create(['revenda_id' => $this->revenda->id]);

        $cliente = $this->cliente();
        $cliente->ancorarEm($this->sistema, '501');

        Http::fake([
            '*gym.alfasolucoes.cloud/api/matriz/v1/licencas' => Http::response([
                'contrato' => '1.0',
                'sistema' => 'alfagym',
                'dados' => ['status' => 'ativa', 'plano' => 'Growth', 'inicio_em' => '2026-08-01', 'fim_em' => '2026-09-01', 'id_externo' => '91'],
            ], 201),
        ]);

        $this->actingAs($admin);

        (new GerenciadorLicencaService($this->sistema))->liberar($cliente, [
            'tipo' => 'mensal',
            'valor' => 199.0,
            'obs' => null,
        ]);

        $aviso = Notificacao::where('tipo', 'licenca')->sole();

        $this->assertSame($daRevenda->id, $aviso->destinatario_id);
        $this->assertSame('marca', $aviso->nivel);
        $this->assertStringContainsString('liberada', $aviso->titulo);
    }
}
