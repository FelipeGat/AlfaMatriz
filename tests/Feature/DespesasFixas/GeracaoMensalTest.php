<?php

namespace Tests\Feature\DespesasFixas;

use App\Models\CentroCusto;
use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\Fornecedor;
use App\Services\DespesaFixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeracaoMensalTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETENCIA = '2026-08';

    /**
     * @spec:AC-020 A despesa fixa vigente vira uma conta a pagar em aberto do
     * mês, marcada como fixa, ligada ao cadastro que a originou e carregando
     * todos os dados dele — é o que dispensa relançar as mesmas contas todo mês.
     */
    public function test_a_despesa_fixa_vigente_vira_conta_a_pagar_do_mes(): void
    {
        $centro = CentroCusto::factory()->create(['nome' => 'Alfa Tecnologia']);
        $fornecedor = Fornecedor::factory()->create(['razao_social' => 'Hostinger']);

        $despesa = ContaFixaPagar::factory()->create([
            'centro_custo_id' => $centro->id,
            'fornecedor_id' => $fornecedor->id,
            'descricao' => 'Hospedagem AlfaHome',
            'valor' => 108.99,
            'dia_vencimento' => 4,
            'forma_pagamento' => 'boleto',
        ]);

        $resultado = app(DespesaFixaService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $conta = ContaPagar::where('conta_fixa_pagar_id', $despesa->id)->firstOrFail();

        $this->assertSame('em_aberto', $conta->status);
        $this->assertSame('fixa', $conta->tipo, 'A parcela precisa se distinguir de uma despesa avulsa.');
        $this->assertSame(self::COMPETENCIA, $conta->competencia);
        $this->assertSame('Hospedagem AlfaHome', $conta->descricao);
        $this->assertEqualsWithDelta(108.99, (float) $conta->valor, 0.001);
        $this->assertSame($centro->id, $conta->centro_custo_id);
        $this->assertSame($fornecedor->id, $conta->fornecedor_id);
        $this->assertSame('boleto', $conta->forma_pagamento);
        $this->assertSame('2026-08-04', $conta->data_vencimento->toDateString());

        $linha = collect($resultado)->firstWhere('descricao', 'Hospedagem AlfaHome');
        $this->assertSame('gerado', $linha['status']);
        $this->assertEqualsWithDelta(108.99, $linha['valor'], 0.001);
    }

    /**
     * @spec:AC-021 Despesa desativada não gera parcela: desativar é como se
     * para de pagar sem apagar o histórico.
     */
    public function test_despesa_desativada_nao_gera_parcela(): void
    {
        ContaFixaPagar::factory()->desativada()->create(['descricao' => 'Serviço cancelado']);

        app(DespesaFixaService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(0, ContaPagar::count());
    }

    /**
     * @spec:AC-021 Despesa que só começa depois da competência não gera
     * parcela retroativa.
     */
    public function test_despesa_que_ainda_nao_comecou_nao_gera_parcela(): void
    {
        ContaFixaPagar::factory()
            ->comecandoDepoisDe(self::COMPETENCIA)
            ->create(['descricao' => 'Contrato novo']);

        app(DespesaFixaService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(0, ContaPagar::count());
    }

    /**
     * @spec:AC-021 Despesa já encerrada antes da competência não continua
     * gerando parcela — contrato que acabou para de cobrar.
     */
    public function test_despesa_ja_encerrada_nao_gera_parcela(): void
    {
        ContaFixaPagar::factory()
            ->encerradaAntesDe(self::COMPETENCIA)
            ->create(['descricao' => 'Contrato encerrado']);

        app(DespesaFixaService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(0, ContaPagar::count());
    }

    /**
     * @spec:AC-021 Com as quatro situações lado a lado, só a vigente gera —
     * é o cenário real de um cadastro que acumulou contratos ao longo do tempo.
     */
    public function test_entre_quatro_situacoes_so_a_vigente_gera(): void
    {
        ContaFixaPagar::factory()->create(['descricao' => 'Vigente']);
        ContaFixaPagar::factory()->desativada()->create(['descricao' => 'Desativada']);
        ContaFixaPagar::factory()->comecandoDepoisDe(self::COMPETENCIA)->create(['descricao' => 'Futura']);
        ContaFixaPagar::factory()->encerradaAntesDe(self::COMPETENCIA)->create(['descricao' => 'Encerrada']);

        app(DespesaFixaService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(['Vigente'], ContaPagar::pluck('descricao')->all());
    }

    /**
     * @spec:AC-021 A vigência é avaliada contra a competência pedida, não
     * contra hoje: gerar um mês passado usa o que valia naquele mês.
     */
    public function test_a_vigencia_e_avaliada_contra_a_competencia_pedida(): void
    {
        // Encerrada em julho: entra na competência de julho, não na de agosto.
        ContaFixaPagar::factory()->create([
            'descricao' => 'Encerrou em julho',
            'data_inicio' => '2026-01-01',
            'data_fim' => '2026-07-31',
        ]);

        $servico = app(DespesaFixaService::class);

        $servico->gerarParaCompetencia('2026-07');
        $this->assertSame(1, ContaPagar::where('competencia', '2026-07')->count());

        $servico->gerarParaCompetencia('2026-08');
        $this->assertSame(0, ContaPagar::where('competencia', '2026-08')->count());
    }
}
