<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\SincronizadorSistemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A primeira carga de um sistema novo é o momento de maior risco da
 * implantação.
 *
 * A âncora `origens_externas` é por sistema: a revenda "Invest Soluções" que
 * veio do AlfaGym não é encontrada quando o AlfaControl a envia com outro id
 * externo. Sem reconciliar, a Matriz criaria um gêmeo de cada revenda e cada
 * cliente que já tem — e a revenda passaria a ser cobrada duas vezes.
 */
class ReconciliacaoPorDocumentoTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $gym;

    private Sistema $control;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gym = Sistema::factory()->alfagym()->create(['token' => 'chave-gym']);
        $this->control = Sistema::factory()->alfacontrol()->create(['token' => 'chave-control']);
    }

    /**
     * @param  array<int, array<string, mixed>>  $revendas
     * @param  array<int, array<string, mixed>>  $clientes
     */
    private function fakeDoControl(array $revendas = [], array $clientes = []): void
    {
        Http::preventStrayRequests();
        Http::fake([
            '*control.alfasolucoes.cloud/api/matriz/v1/revendas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfacontrol', 'dados' => $revendas,
            ]),
            '*control.alfasolucoes.cloud/api/matriz/v1/clientes*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfacontrol', 'dados' => $clientes,
            ]),
            '*control.alfasolucoes.cloud/api/matriz/v1/licencas*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfacontrol', 'dados' => [],
            ]),
            // O AlfaControl declara `sincroniza_modulos`, então o ciclo também
            // consulta o catálogo e as contratações.
            '*control.alfasolucoes.cloud/api/matriz/v1/modulos*' => Http::response([
                'contrato' => '1.0', 'sistema' => 'alfacontrol', 'dados' => [],
            ]),
        ]);
    }

    private function sincronizarControl(): array
    {
        return (new SincronizadorSistemaService($this->control))->sincronizar();
    }

    /**
     * @spec:AC-137 A revenda que já existe na Matriz é ancorada, não
     * duplicada — mesmo que o documento venha formatado diferente.
     */
    public function test_revenda_existente_e_ancorada_e_nao_duplicada(): void
    {
        $existente = Revenda::create([
            'nome' => 'Invest Soluções', 'cnpj' => '12345678000199', 'ativo' => true,
        ]);
        $existente->ancorarEm($this->gym, '42');

        $this->fakeDoControl(revendas: [[
            'id_externo' => '900', 'nome' => 'Invest Soluções LTDA',
            'cnpj' => '12.345.678/0001-99', 'email' => null, 'telefone' => null, 'ativo' => true,
        ]]);

        $this->assertTrue($this->sincronizarControl()['ok']);

        $this->assertSame(1, Revenda::count(), 'A revenda não podia ser duplicada.');

        $existente->refresh();
        $this->assertSame('900', $existente->idExternoNoSistema($this->control));
        $this->assertSame('42', $existente->idExternoNoSistema($this->gym), 'A âncora do gym continua valendo.');
    }

    /**
     * @spec:AC-137 O mesmo vale para clientes.
     */
    public function test_cliente_existente_e_ancorado_e_nao_duplicado(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $revenda->ancorarEm($this->control, '900');

        $cliente = Cliente::create([
            'nome' => 'Condomínio Central', 'cpf_cnpj' => '98765432000110',
            'revenda_id' => $revenda->id, 'ativo' => true,
        ]);
        $cliente->ancorarEm($this->gym, '128');

        $this->fakeDoControl(clientes: [[
            'id_externo' => '501', 'nome' => 'Condomínio Central',
            'cpf_cnpj' => '98.765.432/0001-10', 'cidade' => 'Bauru', 'uf' => 'SP',
            'ativo' => true, 'status' => 'ativo', 'revenda_id_externo' => '900',
        ]]);

        $this->assertTrue($this->sincronizarControl()['ok']);

        $this->assertSame(1, Cliente::count());
        $this->assertSame('501', $cliente->refresh()->idExternoNoSistema($this->control));
    }

    /**
     * @spec:AC-137 Documento ambíguo não casa com ninguém: cria novo.
     * Duplicar é ruim; fundir dois clientes distintos é pior e não tem
     * desfazer.
     *
     * O caso é real em clientes: `clientes.cpf_cnpj` é indexado, mas não é
     * único (matriz e filial compartilham raiz de CNPJ). Em revendas o banco
     * já impede o empate — `revendas.cnpj` é `unique`.
     */
    public function test_documento_ambiguo_nao_adivinha(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $revenda->ancorarEm($this->control, '900');

        Cliente::create(['nome' => 'Condomínio A', 'cpf_cnpj' => '98765432000110', 'ativo' => true]);
        Cliente::create(['nome' => 'Condomínio B', 'cpf_cnpj' => '98765432000110', 'ativo' => true]);

        $this->fakeDoControl(clientes: [[
            'id_externo' => '501', 'nome' => 'Condomínio', 'cpf_cnpj' => '98765432000110',
            'cidade' => null, 'uf' => null, 'ativo' => true, 'status' => 'ativo',
            'revenda_id_externo' => '900',
        ]]);

        $this->sincronizarControl();

        $this->assertSame(3, Cliente::count(), 'Com documento ambíguo, criar novo é a saída segura.');
    }

    /**
     * @spec:AC-137 Documento vazio ou incompleto não casa: a Matriz tem
     * clientes sem documento, e casar por documento em branco fundiria todos
     * num só.
     */
    public function test_documento_vazio_ou_incompleto_nao_casa(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $revenda->ancorarEm($this->control, '900');

        Cliente::create(['nome' => 'Sem documento', 'cpf_cnpj' => null, 'ativo' => true]);
        Cliente::create(['nome' => 'Documento curto', 'cpf_cnpj' => '123', 'ativo' => true]);

        $this->fakeDoControl(clientes: [
            ['id_externo' => '501', 'nome' => 'Novo A', 'cpf_cnpj' => null, 'cidade' => null,
                'uf' => null, 'ativo' => true, 'status' => 'ativo', 'revenda_id_externo' => '900'],
            ['id_externo' => '502', 'nome' => 'Novo B', 'cpf_cnpj' => '123', 'cidade' => null,
                'uf' => null, 'ativo' => true, 'status' => 'ativo', 'revenda_id_externo' => '900'],
        ]);

        $this->sincronizarControl();

        $this->assertSame(4, Cliente::count());
    }

    /**
     * @spec:AC-137 Quem já está ancorado em OUTRO id externo do MESMO sistema
     * fica de fora: dois registros de lá não podem colidir num só aqui.
     */
    public function test_nao_casa_com_quem_ja_esta_ancorado_no_mesmo_sistema(): void
    {
        $revenda = Revenda::create(['nome' => 'Invest', 'cnpj' => '12345678000199', 'ativo' => true]);
        $revenda->ancorarEm($this->control, '900');

        $ancorado = Cliente::create(['nome' => 'Condomínio Central', 'cpf_cnpj' => '98765432000110', 'ativo' => true]);
        $ancorado->ancorarEm($this->control, '500');

        $this->fakeDoControl(clientes: [[
            'id_externo' => '501', 'nome' => 'Condomínio Homônimo', 'cpf_cnpj' => '98765432000110',
            'cidade' => null, 'uf' => null, 'ativo' => true, 'status' => 'ativo',
            'revenda_id_externo' => '900',
        ]]);

        $this->sincronizarControl();

        $this->assertSame(2, Cliente::count());
        $this->assertSame('500', $ancorado->refresh()->idExternoNoSistema($this->control));
    }

    /**
     * @spec:AC-137 Rodar de novo continua não duplicando: a segunda passada
     * acha pela âncora, não pelo documento.
     */
    public function test_segunda_execucao_nao_duplica(): void
    {
        Revenda::create(['nome' => 'Invest Soluções', 'cnpj' => '12345678000199', 'ativo' => true]);

        $this->fakeDoControl(revendas: [[
            'id_externo' => '900', 'nome' => 'Invest Soluções', 'cnpj' => '12345678000199',
            'email' => null, 'telefone' => null, 'ativo' => true,
        ]]);

        $this->sincronizarControl();
        $this->sincronizarControl();

        $this->assertSame(1, Revenda::count());
    }

    /**
     * @spec:AC-138 O ensaio relata o que faria e não grava nada. Ler este
     * relatório é o portão da virada.
     */
    public function test_ensaio_nao_grava_e_relata_o_que_faria(): void
    {
        $this->fakeDoControl(revendas: [[
            'id_externo' => '900', 'nome' => 'Revenda Nova', 'cnpj' => '11222333000144',
            'email' => null, 'telefone' => null, 'ativo' => true,
        ]]);

        $this->artisan('alfa:sincronizar-sistemas', ['--sistema' => 'alfacontrol', '--simular' => true])
            ->expectsOutputToContain('ENSAIO')
            ->expectsOutputToContain('Revendas: 1 criadas, 0 atualizadas.')
            ->assertSuccessful();

        $this->assertSame(0, Revenda::count(), 'O ensaio não pode gravar.');
    }
}
