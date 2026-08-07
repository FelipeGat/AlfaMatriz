<?php

namespace Tests\Feature\Integracao;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaRevenda;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\ErroIntegracao;
use App\Services\Integracao\FabricaDeConector;
use App\Services\Integracao\ImportacaoService;
use App\Services\Integracao\VinculadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

class ImportacaoTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private ConectorFalso $conector;

    private ImportacaoService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $this->conector = Amostras::conector();
        $this->app->instance(FabricaDeConector::class, (new FabricaFalsa)->registrar($this->sistema, $this->conector));
        $this->servico = app(ImportacaoService::class);
    }

    /**
     * @spec:AC-091 O casamento automático acontece quando o documento
     * corresponde a exatamente um cliente da matriz — e só nesse caso.
     */
    public function test_importacao_liga_quando_ha_exatamente_um_par(): void
    {
        $daMatriz = Cliente::factory()->create(['nome' => 'Corpo em Movimento', 'cpf_cnpj' => '98765432000155']);

        $resultado = $this->servico->importar($this->sistema);

        $registro = SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first();
        $this->assertSame(1, $resultado['clientes']['ligados']);
        $this->assertSame($daMatriz->id, $registro->cliente_id);
        $this->assertSame('automatico', $registro->vinculo_origem);
        $this->assertNotNull($this->sistema->refresh()->importado_em);
    }

    /**
     * @spec:AC-093 A importação NUNCA cria cliente sozinha: quem não tem par na
     * matriz vira pendência, não cliente novo — criar mudaria o faturamento da
     * empresa sem ninguém decidir.
     */
    public function test_importacao_nunca_cria_cliente_sozinha(): void
    {
        Cliente::factory()->count(3)->create();
        $antes = Cliente::count();

        $resultado = $this->servico->importar($this->sistema);

        $this->assertSame($antes, Cliente::count(), 'Nenhum cliente pode ter nascido da importação.');
        $this->assertSame(0, $resultado['clientes']['ligados']);
        $this->assertSame(3, $resultado['clientes'][VinculadorService::SEM_PAR], 'Quem não tem par vira pendência.');
        $this->assertNotNull($this->sistema->refresh()->importado_em);
    }

    /**
     * @spec:AC-091 Mais de um candidato na matriz não gera vínculo: escolher
     * "o primeiro" seria escolher por ordem de cadastro, não pela verdade.
     */
    public function test_mais_de_um_candidato_nao_liga_ninguem(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);

        $resultado = $this->servico->importar($this->sistema);

        $this->assertSame(0, $resultado['clientes']['ligados']);
        $this->assertSame(1, $resultado['clientes'][VinculadorService::VARIOS_CANDIDATOS]);
        $this->assertNull(SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first()->cliente_id);
    }

    /**
     * @spec:AC-092 As pendências são separadas por motivo — sem par, mais de um
     * candidato, sem documento na origem e repetido dentro do próprio sistema —
     * porque cada uma pede uma ação diferente.
     */
    public function test_importacao_separa_as_pendencias_por_motivo(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '22222222222222']);
        Cliente::factory()->create(['cpf_cnpj' => '22222222222222']);
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);

        // A origem entrega uma duplicata do cliente 128 (mesmo documento) e um
        // cliente cujo documento corresponde a dois cadastros da matriz. Os
        // dois vêm DO SISTEMA — criar direto no retrato antes da importação
        // faria a varredura tratá-los como ausentes na origem.
        $clientes = Amostras::ler('v1', 'clientes');
        $clientes[] = [
            'id_externo' => '600',
            'nome' => 'Sósia do Corpo em Movimento',
            'cpf_cnpj' => '98765432000155',
            'ativo' => true,
            'status' => 'ativo',
            'unidades_ativas' => 1,
        ];
        $clientes[] = [
            'id_externo' => '700',
            'nome' => 'Quem é você na fila do pão?',
            'cpf_cnpj' => '22222222222222',
            'ativo' => true,
            'status' => 'ativo',
            'unidades_ativas' => 1,
        ];
        $this->conector->com('clientes', $clientes);

        $resultado = $this->servico->importar($this->sistema);

        $this->assertSame(0, $resultado['clientes']['ligados']);
        $this->assertSame(2, $resultado['clientes'][VinculadorService::REPETIDO_NO_SISTEMA], '128 e 600 dividem o mesmo documento.');
        $this->assertSame(1, $resultado['clientes'][VinculadorService::VARIOS_CANDIDATOS], '700 corresponde a dois cadastros da matriz.');
        $this->assertSame(1, $resultado['clientes'][VinculadorService::SEM_DOCUMENTO], 'Cliente 130 vem sem documento nas amostras.');
        $this->assertSame(2, $resultado['clientes'][VinculadorService::SEM_PAR], '129 e 131 não têm par na matriz.');
    }

    /**
     * @spec:AC-091 A revenda também casa pelo documento, quando há um único par
     * — e a sem documento fica como pendência.
     */
    public function test_a_revenda_casa_pelo_documento(): void
    {
        Revenda::factory()->create(['nome' => 'Invest Soluções', 'cnpj' => '12345678000199']);

        $resultado = $this->servico->importar($this->sistema);

        $registro = SistemaRevenda::doSistema($this->sistema)->where('id_externo', '3')->first();
        $this->assertSame(1, $resultado['revendas']['ligados']);
        $this->assertNotNull($registro->revenda_id);
        $this->assertSame(1, $resultado['revendas'][VinculadorService::SEM_DOCUMENTO], 'Revenda 7 não tem documento.');
    }

    /**
     * @spec:AC-091 Importar com o sistema fora do ar não marca a importação: em
     * cima de um retrato velho ou pela metade a importação ligaria gente
     * errada, então ela recusa com o código da falha.
     */
    public function test_importar_com_o_sistema_fora_do_ar_recusa_e_nao_marca(): void
    {
        $this->conector->falharCom('indisponivel', 503);

        try {
            $this->servico->importar($this->sistema);
            $this->fail('Importar com o sistema fora do ar precisa recusar.');
        } catch (ErroIntegracao $erro) {
            $this->assertSame('indisponivel', $erro->codigo);
        }

        $this->assertNull($this->sistema->refresh()->importado_em);
        $this->assertSame(0, Cliente::count());
    }

    /**
     * @spec:AC-093 Importar duas vezes não muda nada de novo: a segunda vez
     * encontra tudo já ligado e não cria mais nada.
     */
    public function test_importar_de_novo_e_idempotente(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);

        $this->servico->importar($this->sistema);
        $segunda = $this->servico->importar($this->sistema);

        $this->assertSame(0, $segunda['clientes']['ligados'], 'Nada novo a ligar na segunda vez.');
        $this->assertSame(1, Cliente::count());
        $this->assertSame(1, SistemaCliente::doSistema($this->sistema)->whereNotNull('cliente_id')->count());
    }
}
