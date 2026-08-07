<?php

namespace Tests\Feature\DespesasFixas;

use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Services\DespesaFixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VencimentoEIdempotenciaTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETENCIA = '2026-08';

    /**
     * @spec:AC-022 Gerar a mesma competência de novo não cria parcela
     * duplicada, e o resultado avisa quais já existiam. É o que permite o
     * agendamento e a tela dispararem a geração sem medo.
     */
    public function test_gerar_duas_vezes_a_mesma_competencia_nao_duplica(): void
    {
        ContaFixaPagar::factory()->create(['descricao' => 'Contabilidade']);

        $servico = app(DespesaFixaService::class);

        $servico->gerarParaCompetencia(self::COMPETENCIA);
        $segundaVez = $servico->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(1, ContaPagar::count(), 'A mesma despesa não pode virar duas contas no mês.');

        $linha = collect($segundaVez)->firstWhere('descricao', 'Contabilidade');
        $this->assertSame(
            'ja_gerado',
            $linha['status'],
            'A reexecução precisa avisar que já existia, em vez de ficar em silêncio.'
        );
    }

    /**
     * @spec:AC-022 A idempotência vale também pelo caminho do comando, que é
     * como o agendamento do servidor dispara o fechamento.
     */
    public function test_o_comando_de_fechamento_rodado_duas_vezes_nao_duplica(): void
    {
        ContaFixaPagar::factory()->create(['descricao' => 'Contabilidade']);

        $this->artisan('app:fechar-competencia-mensal', ['competencia' => self::COMPETENCIA])
            ->assertSuccessful();
        $this->artisan('app:fechar-competencia-mensal', ['competencia' => self::COMPETENCIA])
            ->assertSuccessful();

        $this->assertSame(1, ContaPagar::where('competencia', self::COMPETENCIA)->count());
    }

    /**
     * @spec:AC-022 Competências diferentes geram parcelas diferentes: a
     * idempotência é por competência, não um travamento da despesa.
     */
    public function test_competencias_diferentes_geram_parcelas_diferentes(): void
    {
        ContaFixaPagar::factory()->create(['descricao' => 'Contabilidade']);

        $servico = app(DespesaFixaService::class);
        $servico->gerarParaCompetencia('2026-07');
        $servico->gerarParaCompetencia('2026-08');

        $this->assertSame(
            ['2026-07', '2026-08'],
            ContaPagar::orderBy('competencia')->pluck('competencia')->all()
        );
    }

    /**
     * @spec:AC-023 Dia 31 numa competência de fevereiro cai no último dia do
     * mês, em vez de virar uma data inválida.
     */
    public function test_dia_que_nao_existe_no_mes_cai_no_ultimo_dia(): void
    {
        ContaFixaPagar::factory()->create([
            'descricao' => 'Aluguel',
            'dia_vencimento' => 31,
            'data_inicio' => '2026-01-01',
        ]);

        app(DespesaFixaService::class)->gerarParaCompetencia('2026-02');

        $conta = ContaPagar::firstOrFail();

        // 2026 não é bissexto: fevereiro termina no dia 28.
        $this->assertSame('2026-02-28', $conta->data_vencimento->toDateString());
    }

    /**
     * @spec:AC-023 Em ano bissexto o mesmo dia 31 cai no 29 — o cálculo olha o
     * tamanho real do mês, não um número fixo.
     */
    public function test_em_ano_bissexto_o_dia_31_cai_no_29_de_fevereiro(): void
    {
        $despesa = ContaFixaPagar::factory()->create([
            'dia_vencimento' => 31,
            'data_inicio' => '2024-01-01',
        ]);

        $this->assertSame(
            '2024-02-29',
            $despesa->dataVencimentoParaCompetencia('2024-02')->toDateString()
        );
    }

    /**
     * @spec:AC-023 Num mês que tem o dia, o vencimento é o dia cadastrado —
     * o ajuste só acontece quando o dia não existe.
     */
    public function test_mes_que_tem_o_dia_usa_o_dia_cadastrado(): void
    {
        $despesa = ContaFixaPagar::factory()->create(['dia_vencimento' => 31]);

        $this->assertSame('2026-01-31', $despesa->dataVencimentoParaCompetencia('2026-01')->toDateString());
        $this->assertSame('2026-04-30', $despesa->dataVencimentoParaCompetencia('2026-04')->toDateString());
    }
}
