<?php

namespace Tests\Feature\Faturamento;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\FaturamentoSnapshot;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FechamentoIdempotenteTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETENCIA = '2026-08';

    /**
     * @spec:AC-018 Rodar a mesma competência de novo não cria segunda cobrança
     * nem nova apuração, e o resultado avisa que já existia. É o que permite o
     * agendamento e a tela dispararem o fechamento sem medo de cobrar duas
     * vezes a mesma revenda.
     */
    public function test_reexecutar_a_mesma_competencia_nao_duplica(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);
        $sistema = $this->sistemaFaturavel();
        $this->clientesAtivos($revenda, $sistema, 3);

        $servico = app(FaturamentoService::class);

        $servico->gerarParaCompetencia(self::COMPETENCIA);
        $segundaVez = $servico->gerarParaCompetencia(self::COMPETENCIA);

        $this->assertSame(
            1,
            Cobranca::where('revenda_id', $revenda->id)->where('competencia', self::COMPETENCIA)->count(),
            'A revenda não pode receber duas contas da mesma competência.'
        );
        $this->assertSame(
            1,
            FaturamentoSnapshot::where('competencia', self::COMPETENCIA)->count(),
            'A apuração da competência também não pode duplicar.'
        );

        $avisos = collect($segundaVez['Invest Soluções'])->pluck('status');
        $this->assertTrue(
            $avisos->contains('ja_gerado') || $avisos->contains('cobranca_ja_existe'),
            'A reexecução precisa avisar que já existia, em vez de ficar em silêncio.'
        );
    }

    /**
     * @spec:AC-018 A idempotência vale também pelo caminho do comando, que é
     * como o agendamento do servidor dispara o fechamento.
     */
    public function test_o_comando_de_fechamento_rodado_duas_vezes_nao_duplica(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaFaturavel();
        $this->clientesAtivos($revenda, $sistema, 3);

        $this->artisan('app:fechar-competencia-mensal', ['competencia' => self::COMPETENCIA])
            ->assertSuccessful();
        $this->artisan('app:fechar-competencia-mensal', ['competencia' => self::COMPETENCIA])
            ->assertSuccessful();

        $this->assertSame(1, Cobranca::where('competencia', self::COMPETENCIA)->count());
    }

    /**
     * @spec:AC-019 O vencimento é o último dia da competência mais cinco dias
     * — a revenda recebe a conta depois do mês fechado, com folga para pagar.
     */
    public function test_o_vencimento_cai_cinco_dias_depois_do_fim_da_competencia(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaFaturavel();
        $this->clientesAtivos($revenda, $sistema, 3);

        app(FaturamentoService::class)->gerarParaCompetencia('2026-08');

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();

        // Agosto termina no dia 31; mais cinco dias cai em 5 de setembro.
        $this->assertSame('2026-09-05', $cobranca->data_vencimento->toDateString());
    }

    /**
     * @spec:AC-019 A regra vale para mês curto: fevereiro de um ano não
     * bissexto termina no dia 28, e o vencimento cai em 5 de março — sem
     * inventar um 31 de fevereiro.
     */
    public function test_o_vencimento_respeita_o_tamanho_real_do_mes(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaFaturavel();
        $this->clientesAtivos($revenda, $sistema, 3);

        app(FaturamentoService::class)->gerarParaCompetencia('2026-02');

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();

        $this->assertSame('2026-03-05', $cobranca->data_vencimento->toDateString());
    }

    /**
     * @spec:AC-018 Competências diferentes geram cobranças diferentes: a
     * idempotência é por competência, não um travamento geral da revenda.
     */
    public function test_competencias_diferentes_geram_cobrancas_diferentes(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaFaturavel();
        $this->clientesAtivos($revenda, $sistema, 3);

        $servico = app(FaturamentoService::class);
        $servico->gerarParaCompetencia('2026-07');
        $servico->gerarParaCompetencia('2026-08');

        $this->assertSame(2, Cobranca::where('revenda_id', $revenda->id)->count());
        $this->assertSame(
            ['2026-07', '2026-08'],
            Cobranca::where('revenda_id', $revenda->id)->orderBy('competencia')->pluck('competencia')->all()
        );
    }

    /**
     * @spec:AC-013 A apuração de cada sistema fica ligada à cobrança que a
     * consolidou — é o que permite reconstruir depois de onde veio o valor.
     */
    public function test_a_apuracao_fica_ligada_a_cobranca_que_a_consolidou(): void
    {
        $revenda = Revenda::factory()->create();
        $sistema = $this->sistemaFaturavel();
        $this->clientesAtivos($revenda, $sistema, 3);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();
        $apuracao = FaturamentoSnapshot::where('competencia', self::COMPETENCIA)->firstOrFail();

        $this->assertSame($cobranca->id, $apuracao->cobranca_id);
        $this->assertSame(3, $apuracao->clientes_ativos);
    }

    private function sistemaFaturavel(): Sistema
    {
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        PrecoAtacado::factory()->fechado()->create([
            'sistema_id' => $sistema->id,
            'nome' => 'Growth',
            'preco_base' => 249.00,
            'unidades_inclusas' => 10,
            'limite_unidades' => 10,
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
