<?php

namespace Tests\Feature\Integracao;

use App\Models\Sistema;
use App\Models\SistemaContador;
use App\Models\SistemaLicenca;
use App\Services\Integracao\ConectorFalso;
use App\Services\Integracao\FabricaDeConector;
use App\Services\Integracao\SincronizacaoService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Amostras;
use Tests\Support\FabricaFalsa;
use Tests\TestCase;

class SincronizacaoLicencasContadoresTest extends TestCase
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
    }

    /**
     * @spec:AC-084 A licença entra com o que a tela precisa: plano, início,
     * fim, se bloqueia o acesso e o cliente a que pertence — nada de valor
     * financeiro (ASM-031).
     */
    public function test_a_licenca_entra_com_plano_e_vigencia_e_o_cliente(): void
    {
        $this->servico->sincronizar($this->sistema);

        $licenca = SistemaLicenca::where('sistema_id', $this->sistema->id)->where('id_externo', '91')->first();

        $this->assertSame('ativa', $licenca->status);
        $this->assertSame('Growth', $licenca->plano);
        $this->assertSame('3', $licenca->plano_id_externo);
        $this->assertSame('mensal', $licenca->tipo);
        $this->assertSame('2026-07-01', $licenca->inicio_em->toDateString());
        $this->assertSame('2026-09-01', $licenca->fim_em->toDateString());
        $this->assertTrue($licenca->bloqueia_acesso);

        $this->assertSame(
            $licenca->sistemaCliente->id_externo,
            '128',
            'A licença fica pendurada no cliente do sistema a que pertence.'
        );
    }

    /**
     * @spec:AC-089 A contagem da unidade de cobrança fica guardada por
     * competência: sincronizar em meses diferentes guarda cada mês, e
     * sincronizar o mesmo mês de novo atualiza sem duplicar — é o que permite
     * comparar meses depois.
     */
    public function test_a_contagem_fica_guardada_por_competencia_sem_duplicar(): void
    {
        Carbon::setTestNow('2026-07-15 10:00:00');

        $this->servico->sincronizar($this->sistema);
        $this->servico->sincronizar($this->sistema);

        $this->assertSame(1, SistemaContador::where('sistema_id', $this->sistema->id)->count(), 'Mesmo mês, uma linha só.');

        Carbon::setTestNow('2026-08-05 10:00:00');

        $this->servico->sincronizar($this->sistema);

        $julho = SistemaContador::where('sistema_id', $this->sistema->id)->where('competencia', '2026-07')->first();
        $agosto = SistemaContador::where('sistema_id', $this->sistema->id)->where('competencia', '2026-08')->first();

        $this->assertNotNull($julho);
        $this->assertNotNull($agosto);
        $this->assertSame(2, SistemaContador::where('sistema_id', $this->sistema->id)->count());
        $this->assertSame('academia ativa', $julho->unidade_cobranca);

        Carbon::setTestNow();
    }

    /**
     * @spec:AC-089 A quebra por revenda vem junto com a contagem, pronta para a
     * tela de divergências comparar sem somar milhares de linhas do lado da
     * matriz.
     */
    public function test_a_quebra_por_revenda_entra_junto(): void
    {
        $this->servico->sincronizar($this->sistema);

        $contador = SistemaContador::where('sistema_id', $this->sistema->id)->where('competencia', now()->format('Y-m'))->first();

        $this->assertSame(2, $contador->unidadesDaRevenda('3'));
        $this->assertSame(0, $contador->unidadesDaRevenda('7'));
        $this->assertNull($contador->unidadesDaRevenda('99'));
        $this->assertSame(2, $contador->unidades_ativas);
        $this->assertSame(2, $contador->licencas_ativas);
    }

    /**
     * @spec:AC-084 A sincronização não fala com nada além dos endereços do
     * contrato: nenhum escopo financeiro é chamado — o dinheiro vive na matriz
     * (ASM-031).
     */
    public function test_a_sincronizacao_nao_chama_nenhum_endereco_financeiro(): void
    {
        $this->servico->sincronizar($this->sistema);

        $chamados = array_unique(array_column($this->conector->chamadas(), 'escopo'));

        $this->assertSame(
            ['planos', 'revendas', 'clientes', 'usuarios', 'licencas', 'contadores'],
            $chamados
        );
    }
}
