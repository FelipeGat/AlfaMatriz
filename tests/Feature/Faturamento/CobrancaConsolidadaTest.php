<?php

namespace Tests\Feature\Faturamento;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrancaConsolidadaTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETENCIA = '2026-08';

    /**
     * @spec:AC-013 Uma revenda com clientes em dois sistemas recebe UMA
     * cobrança na competência, com o valor somado e o detalhamento por sistema
     * dentro — não uma cobrança por cliente nem uma por sistema.
     */
    public function test_a_revenda_recebe_uma_cobranca_so_com_o_detalhamento_por_sistema(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);

        $academias = $this->sistemaComTierFechado('AlfaGym', preco: 249.00, limite: 10);
        $condominios = $this->sistemaComTierFechado('AlfaControl', preco: 99.00, limite: 10);

        $this->clientesAtivos($revenda, $academias, 3);
        $this->clientesAtivos($revenda, $condominios, 2);

        $resultado = app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $cobrancas = Cobranca::where('revenda_id', $revenda->id)
            ->where('competencia', self::COMPETENCIA)
            ->get();

        $this->assertCount(1, $cobrancas, 'A revenda recebe uma conta consolidada, não uma por sistema.');

        $cobranca = $cobrancas->first();
        $this->assertSame('locacao_sistema', $cobranca->tipo);
        $this->assertSame('pendente', $cobranca->status);
        $this->assertEqualsWithDelta(348.00, (float) $cobranca->valor, 0.001, 'O valor é a soma dos dois sistemas.');

        // O detalhamento é o que a revenda vê para conferir a conta.
        $detalhamento = collect($cobranca->detalhamento);
        $this->assertCount(2, $detalhamento);

        $gym = $detalhamento->firstWhere('sistema', 'AlfaGym');
        $this->assertSame(3, $gym['clientes_ativos']);
        $this->assertEqualsWithDelta(249.00, (float) $gym['valor'], 0.001);
        $this->assertArrayHasKey('tier', $gym, 'O detalhamento precisa dizer qual faixa foi aplicada.');

        $this->assertArrayHasKey('cobranca_id', $resultado['Invest Soluções']);
        $this->assertEqualsWithDelta(348.00, $resultado['Invest Soluções']['total'], 0.001);
    }

    /**
     * @spec:AC-014 Só entra quem está ativo: cliente desativado, vínculo com o
     * sistema cancelado e sistema desativado ficam de fora do volume — e é o
     * volume que escolhe a faixa de preço.
     */
    public function test_cliente_inativo_vinculo_cancelado_e_sistema_desativado_ficam_de_fora(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaComTierMetrado('AlfaHome', porUnidade: 10.00);

        // Um ativo de verdade.
        $this->clientesAtivos($revenda, $sistema, 1);

        // Cliente desativado no cadastro, mas com vínculo ativo.
        $inativo = Cliente::factory()->inativo()->create(['revenda_id' => $revenda->id]);
        $inativo->sistemas()->attach($sistema->id, ['ativo' => true, 'ativado_em' => now()]);

        // Cliente ativo, mas que cancelou este sistema.
        $cancelou = Cliente::factory()->create(['revenda_id' => $revenda->id]);
        $cancelou->sistemas()->attach($sistema->id, ['ativo' => false, 'cancelado_em' => now()]);

        // Sistema desativado, com cliente ativo dentro: não pode ser cobrado.
        $descontinuado = $this->sistemaComTierMetrado('AlfaDescontinuado', porUnidade: 500.00);
        $descontinuado->update(['ativo' => false]);
        $this->clientesAtivos($revenda, $descontinuado, 1);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();

        $this->assertEqualsWithDelta(
            10.00,
            (float) $cobranca->valor,
            0.001,
            'Só o cliente ativo com vínculo ativo, num sistema ativo, entra no volume.'
        );

        $sistemasCobrados = collect($cobranca->detalhamento)->pluck('sistema');
        $this->assertContains('AlfaHome', $sistemasCobrados);
        $this->assertNotContains(
            'AlfaDescontinuado',
            $sistemasCobrados,
            'Sistema desativado não entra na cobrança de forma alguma.'
        );
    }

    /**
     * @spec:AC-015 Revenda sem nada elegível não gera cobrança nenhuma — nem
     * uma cobrança de valor zero, que entraria no contas a receber e teria de
     * ser cancelada à mão todo mês.
     */
    public function test_revenda_sem_cliente_ativo_nao_gera_cobranca(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Revenda Parada']);
        $sistema = $this->sistemaComTierFechado('AlfaGym', preco: 249.00, limite: 10);

        // Tem cliente, mas todos cancelaram o sistema.
        $cliente = Cliente::factory()->create(['revenda_id' => $revenda->id]);
        $cliente->sistemas()->attach($sistema->id, ['ativo' => false, 'cancelado_em' => now()]);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(
            0,
            Cobranca::where('revenda_id', $revenda->id)->count(),
            'Sem nada a cobrar, nenhuma cobrança é criada.'
        );
    }

    /**
     * @spec:AC-015 Revenda desativada não é faturada, mesmo tendo cliente
     * ativo: quem saiu do contrato para de receber conta.
     */
    public function test_revenda_desativada_nao_e_faturada(): void
    {
        $revenda = Revenda::factory()->inativa()->create();
        $sistema = $this->sistemaComTierFechado('AlfaGym', preco: 249.00, limite: 10);
        $this->clientesAtivos($revenda, $sistema, 2);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(0, Cobranca::where('revenda_id', $revenda->id)->count());
    }

    /**
     * @spec:AC-014 Cliente direto (sem revenda) não entra no faturamento das
     * revendas — é cobrado à mão pela tela de Receitas (ASM-007).
     */
    public function test_cliente_direto_nao_entra_no_faturamento_das_revendas(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaComTierMetrado('AlfaHome', porUnidade: 10.00);

        $this->clientesAtivos($revenda, $sistema, 1);

        $direto = Cliente::factory()->create(['revenda_id' => null]);
        $direto->sistemas()->attach($sistema->id, ['ativo' => true, 'ativado_em' => now()]);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();

        $this->assertEqualsWithDelta(
            10.00,
            (float) $cobranca->valor,
            0.001,
            'O cliente direto não pode inflar o volume da revenda.'
        );
        $this->assertSame(
            1,
            Cobranca::count(),
            'O cliente direto não gera cobrança automática nenhuma.'
        );
    }

    private function sistemaComTierFechado(string $nome, float $preco, int $limite): Sistema
    {
        $sistema = Sistema::factory()->create(['nome' => $nome]);

        PrecoAtacado::factory()->fechado()->create([
            'sistema_id' => $sistema->id,
            'nome' => 'Faixa única',
            'preco_base' => $preco,
            'unidades_inclusas' => $limite,
            'limite_unidades' => $limite,
            'ordem' => 1,
        ]);

        return $sistema;
    }

    private function sistemaComTierMetrado(string $nome, float $porUnidade): Sistema
    {
        $sistema = Sistema::factory()->create(['nome' => $nome]);

        PrecoAtacado::factory()->metrado($porUnidade)->create([
            'sistema_id' => $sistema->id,
            'preco_base' => 0.00,
            'ordem' => 1,
        ]);

        return $sistema;
    }

    private function clientesAtivos(Revenda $revenda, Sistema $sistema, int $quantidade): void
    {
        Cliente::factory()
            ->count($quantidade)
            ->create(['revenda_id' => $revenda->id])
            ->each(fn (Cliente $cliente) => $cliente->sistemas()->attach($sistema->id, [
                'ativo' => true,
                'ativado_em' => now(),
            ]));
    }
}
