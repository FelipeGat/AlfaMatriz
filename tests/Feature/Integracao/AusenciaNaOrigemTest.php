<?php

namespace Tests\Feature\Integracao;

use App\Models\Sistema;
use App\Models\SistemaCliente;
use App\Models\SistemaLicenca;
use App\Models\SistemaPlano;
use App\Models\SistemaRevenda;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\FabricaDeConector;
use App\Services\Integracao\SincronizacaoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

class AusenciaNaOrigemTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    private ConectorFalso $conector;

    private SincronizacaoService $servico;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->integrado()->create(['nome' => 'AlfaGym']);
        $this->conector = Amostras::conector();
        $this->app->instance(FabricaDeConector::class, (new FabricaFalsa)->registrar($this->sistema, $this->conector));
        $this->servico = app(SincronizacaoService::class);

        // O retrato começa completo: é a base para medir o que some.
        $this->servico->sincronizar($this->sistema);
    }

    /**
     * @spec:AC-086 Cliente que deixa de aparecer no sistema é MARCADO como
     * ausente, com a data, e continua no retrato local — apagar levaria junto o
     * vínculo com o cliente da matriz e o histórico do que ele representou.
     */
    public function test_cliente_que_sumiu_e_marcado_ausente_e_nao_apagado(): void
    {
        $this->conector->com('clientes', Amostras::sem(Amostras::ler('v1', 'clientes'), '128'));

        $execucao = $this->servico->sincronizar($this->sistema);

        $cliente = SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first();
        $this->assertNotNull($cliente, 'O registro não pode ter sido apagado.');
        $this->assertTrue($cliente->ausenteNaOrigem());
        $this->assertNotNull($cliente->ausente_em_origem_em);
        $this->assertFalse($cliente->ativo, 'Ausente na origem para de contar como ativo.');
        $this->assertSame(1, $execucao->itens_ausentes);
        $this->assertSame(3, SistemaCliente::doSistema($this->sistema)->presentes()->count());
        $this->assertSame(4, SistemaCliente::doSistema($this->sistema)->count(), 'Nada foi apagado.');
    }

    /**
     * @spec:AC-086 O mesmo vale para os outros escopos do retrato: plano,
     * revenda, usuário e licença obedecem à mesma regra — quem some é marcado,
     * nunca apagado.
     */
    public function test_a_ausencia_marca_todos_os_escopos_do_retrato(): void
    {
        $this->conector->com('revendas', Amostras::sem(Amostras::ler('v1', 'revendas'), '3'));
        $this->conector->com('planos', Amostras::sem(Amostras::ler('v1', 'planos'), '3'));

        $this->servico->sincronizar($this->sistema);

        $revenda = SistemaRevenda::doSistema($this->sistema)->where('id_externo', '3')->first();
        $plano = SistemaPlano::doSistema($this->sistema)->where('id_externo', '3')->first();

        $this->assertTrue($revenda->ausenteNaOrigem());
        $this->assertTrue($plano->ausenteNaOrigem());
        $this->assertSame(2, SistemaRevenda::count());
        $this->assertSame(3, SistemaPlano::count());
    }

    /**
     * @spec:AC-086 Registro que reaparece volta ao normal sozinho: sumir da
     * origem pode ter sido um soluço da API, e a volta não pode exigir
     * intervenção.
     */
    public function test_registro_que_reaparece_volta_ao_normal(): void
    {
        $this->conector->com('clientes', Amostras::sem(Amostras::ler('v1', 'clientes'), '128'));
        $this->servico->sincronizar($this->sistema);

        $this->conector->com('clientes', Amostras::ler('v1', 'clientes'));
        $this->servico->sincronizar($this->sistema);

        $cliente = SistemaCliente::doSistema($this->sistema)->where('id_externo', '128')->first();
        $this->assertFalse($cliente->ausenteNaOrigem());
        $this->assertNull($cliente->ausente_em_origem_em);
        $this->assertTrue($cliente->ativo, 'A varredura restaura a situação que o sistema declara.');
    }

    /**
     * @spec:AC-086 A ausência vale também para a licença: como a tabela dela
     * não tem `ativo`, marcar ausente só registra a data e preserva o `status`
     * da última leitura — em vez de quebrar tentando desligar uma coluna que
     * não existe.
     */
    public function test_a_licenca_ausente_e_marcada_sem_tocar_na_situacao(): void
    {
        $this->conector->com('licencas', Amostras::sem(Amostras::ler('v1', 'licencas'), '91'));

        $execucao = $this->servico->sincronizar($this->sistema);

        $licenca = SistemaLicenca::doSistema($this->sistema)->where('id_externo', '91')->first();
        $this->assertNotNull($licenca);
        $this->assertTrue($licenca->ausenteNaOrigem());
        $this->assertNotNull($licenca->ausente_em_origem_em);
        $this->assertSame('ativa', $licenca->status);
        $this->assertSame(1, $execucao->itens_ausentes);
        $this->assertSame(4, SistemaLicenca::count(), 'Nada foi apagado.');
    }

    /**
     * @spec:AC-087 Varredura interrompida no meio NÃO desativa quem nem chegou
     * a ser lido: a ausência só é marcada depois do escopo inteiro, e a
     * execução fica registrada como parcial, com o que já tinha entrado
     * preservado.
     */
    public function test_varredura_interrompida_nao_desativa_quem_nao_foi_lido(): void
    {
        $licenca = SistemaLicenca::doSistema($this->sistema)->where('id_externo', '91')->first();

        $this->conector->falharNoEscopo('licencas', 'erro_interno');
        $execucao = $this->servico->sincronizar($this->sistema);

        $this->assertTrue($execucao->foiParcial());
        $this->assertSame('erro_interno', $execucao->erro_codigo);

        $licenca->refresh();
        $this->assertFalse($licenca->ausenteNaOrigem(), 'Licença não lida não pode virar ausente.');
        $this->assertSame('ativa', $licenca->status, 'A situação que o sistema declarou fica como estava.');

        // Os escopos anteriores continuaram e entraram normalmente.
        $this->assertSame(4, SistemaCliente::doSistema($this->sistema)->presentes()->count());
        $this->assertSame(4, SistemaLicenca::count(), 'Nada foi apagado pela interrupção.');
    }

    /**
     * @spec:AC-087 Uma execução interrompida conta como falha do sistema: a
     * tela passa a dizer "fora do ar desde" em vez de fingir que tudo correu.
     */
    public function test_varredura_interrompida_conta_como_falha_do_sistema(): void
    {
        $this->conector->falharNoEscopo('licencas', 'erro_interno');

        $this->servico->sincronizar($this->sistema);

        $this->assertSame(1, $this->sistema->refresh()->falhas_consecutivas);
    }
}
