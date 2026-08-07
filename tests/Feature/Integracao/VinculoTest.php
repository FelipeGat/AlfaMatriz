<?php

namespace Tests\Feature\Integracao;

use App\Models\Cliente;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaRevenda;
use App\Services\Integracao\VinculadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VinculoTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private VinculadorService $vinculador;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $this->vinculador = app(VinculadorService::class);
    }

    private function clienteDoSistema(array $atributos = []): SistemaCliente
    {
        return SistemaCliente::create(array_merge([
            'sistema_id' => $this->sistema->id,
            'id_externo' => (string) fake()->unique()->numberBetween(100, 999),
            'nome' => 'Academia de Teste',
        ], $atributos));
    }

    /**
     * @spec:AC-091 Documento que corresponde a exatamente um cliente da matriz
     * é ligado sozinho — inclusive quando um lado está formatado e o outro não.
     */
    public function test_documento_com_um_unico_par_e_ligado_sozinho(): void
    {
        $daMatriz = Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);

        $resumo = $this->vinculador->vincularClientes($this->sistema);

        $this->assertSame(1, $resumo['ligados']);
        $this->assertSame($daMatriz->id, $doSistema->refresh()->cliente_id);
        $this->assertSame('automatico', $doSistema->vinculo_origem);
    }

    /**
     * @spec:AC-091 Nenhum candidato deixa sem vínculo, para virar pendência —
     * nunca um chute. Vínculo errado é pior que vínculo nenhum: passa a
     * faturar o cliente errado e ninguém percebe.
     */
    public function test_sem_par_na_matriz_fica_sem_vinculo(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '11111111111111']);
        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);

        $resumo = $this->vinculador->vincularClientes($this->sistema);

        $this->assertSame(0, $resumo['ligados']);
        $this->assertSame(1, $resumo[VinculadorService::SEM_PAR]);
        $this->assertNull($doSistema->refresh()->cliente_id);
        $this->assertSame(VinculadorService::SEM_PAR, $this->vinculador->motivoDeNaoVincular($doSistema));
    }

    /**
     * @spec:AC-091 Mais de um candidato também fica sem vínculo: escolher "o
     * primeiro" seria escolher por ordem de inserção, que não tem nada a ver
     * com qual é o certo.
     */
    public function test_mais_de_um_candidato_fica_sem_vinculo(): void
    {
        // Duplicata existe de verdade: a tela impede documento repetido, mas o
        // banco não tem restrição, e carga inicial, importação e correção
        // direta já deixaram duplicata em bases assim.
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);

        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);

        $resumo = $this->vinculador->vincularClientes($this->sistema);

        $this->assertSame(0, $resumo['ligados']);
        $this->assertSame(1, $resumo[VinculadorService::VARIOS_CANDIDATOS]);
        $this->assertNull($doSistema->refresh()->cliente_id);
        $this->assertCount(2, $this->vinculador->candidatosParaCliente($doSistema));
    }

    /**
     * @spec:AC-091 Cliente apagado da matriz não é candidato: ele não existe
     * mais para o negócio, e ressuscitá-lo por um casamento automático traria
     * de volta um cadastro que alguém decidiu remover.
     */
    public function test_cliente_apagado_nao_e_candidato(): void
    {
        $apagado = Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        $apagado->delete();

        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);

        $resumo = $this->vinculador->vincularClientes($this->sistema);

        $this->assertSame(0, $resumo['ligados']);
        $this->assertSame(1, $resumo[VinculadorService::SEM_PAR]);
        $this->assertNull($doSistema->refresh()->cliente_id);
    }

    /**
     * @spec:AC-091 Sem documento na origem não há como casar, e a ausência tem
     * motivo próprio: é o caso que precisa de decisão humana, não de melhora no
     * algoritmo.
     */
    public function test_sem_documento_na_origem_tem_motivo_proprio(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => null]);

        $resumo = $this->vinculador->vincularClientes($this->sistema);

        $this->assertSame(1, $resumo[VinculadorService::SEM_DOCUMENTO]);
        $this->assertSame(VinculadorService::SEM_DOCUMENTO, $this->vinculador->motivoDeNaoVincular($doSistema));
    }

    /**
     * @spec:AC-091 Um vínculo feito à mão nunca é desfeito nem trocado por uma
     * execução automática — quem decidiu foi gente, e a máquina não revoga.
     */
    public function test_vinculo_manual_nunca_e_sobrescrito(): void
    {
        $escolhido = Cliente::factory()->create(['cpf_cnpj' => '11111111111111']);
        $queCasariaSozinho = Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);

        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);
        $this->vinculador->vincularClienteManualmente($doSistema, $escolhido);

        $this->vinculador->vincularClientes($this->sistema);

        $doSistema->refresh();
        $this->assertSame($escolhido->id, $doSistema->cliente_id, 'A escolha humana prevalece.');
        $this->assertNotSame($queCasariaSozinho->id, $doSistema->cliente_id);
        $this->assertSame('manual', $doSistema->vinculo_origem);
    }

    /**
     * @spec:AC-091 Rodar de novo não refaz o que já está ligado: o vínculo
     * automático também é preservado.
     */
    public function test_rodar_de_novo_nao_refaz_o_que_ja_esta_ligado(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        $doSistema = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);

        $primeira = $this->vinculador->vincularClientes($this->sistema);
        $segunda = $this->vinculador->vincularClientes($this->sistema);

        $this->assertSame(1, $primeira['ligados']);
        $this->assertSame(0, $segunda['ligados'], 'Nada a ligar na segunda vez.');
        $this->assertNotNull($doSistema->refresh()->cliente_id);
    }

    /**
     * @spec:AC-091 A revenda casa pelo CNPJ mesmo com o cadastro da matriz
     * guardando o documento FORMATADO — o cadastro de revendas não normaliza,
     * ao contrário do de clientes. Uma comparação por igualdade no banco
     * funcionaria para clientes e falharia em silêncio aqui.
     */
    public function test_revenda_casa_mesmo_com_o_documento_formatado_na_matriz(): void
    {
        $daMatriz = Revenda::factory()->create(['cnpj' => '12.345.678/0001-99']);

        $doSistema = SistemaRevenda::create([
            'sistema_id' => $this->sistema->id,
            'id_externo' => '3',
            'nome' => 'Invest Soluções',
            'cnpj' => '12345678000199',
        ]);

        $resumo = $this->vinculador->vincularRevendas($this->sistema);

        $this->assertSame(1, $resumo['ligados']);
        $this->assertSame($daMatriz->id, $doSistema->refresh()->revenda_id);
    }

    /**
     * @spec:AC-091 O casamento é por sistema: dois sistemas apontando para o
     * mesmo cliente da matriz é o esperado, não um conflito.
     */
    public function test_dois_sistemas_podem_apontar_para_o_mesmo_cliente_da_matriz(): void
    {
        $daMatriz = Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        $outroSistema = Sistema::factory()->create(['nome' => 'AlfaControl']);

        $noGym = $this->clienteDoSistema(['cpf_cnpj' => '98765432000155']);
        $noControl = SistemaCliente::create([
            'sistema_id' => $outroSistema->id,
            'id_externo' => '55',
            'nome' => 'Mesma empresa, outro produto',
            'cpf_cnpj' => '98765432000155',
        ]);

        $this->vinculador->vincularClientes($this->sistema);
        $this->vinculador->vincularRevendas($outroSistema);
        $this->vinculador->vincularClientes($outroSistema);

        $this->assertSame($daMatriz->id, $noGym->refresh()->cliente_id);
        $this->assertSame($daMatriz->id, $noControl->refresh()->cliente_id);
    }

    /**
     * @spec:AC-091 O casamento só olha o sistema pedido: rodar para um sistema
     * não pode ligar registros de outro.
     */
    public function test_o_casamento_so_olha_o_sistema_pedido(): void
    {
        Cliente::factory()->create(['cpf_cnpj' => '98765432000155']);
        $outroSistema = Sistema::factory()->create();

        $doOutro = SistemaCliente::create([
            'sistema_id' => $outroSistema->id,
            'id_externo' => '9',
            'nome' => 'De outro sistema',
            'cpf_cnpj' => '98765432000155',
        ]);

        $this->vinculador->vincularClientes($this->sistema);

        $this->assertNull($doOutro->refresh()->cliente_id);
    }
}
