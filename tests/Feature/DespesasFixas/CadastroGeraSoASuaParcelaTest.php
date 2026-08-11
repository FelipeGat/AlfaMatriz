<?php

namespace Tests\Feature\DespesasFixas;

use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\User;
use App\Services\DespesaFixaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Cadastrar uma despesa fixa materializa a parcela dela — e só a dela.
 *
 * O cadastro chamava `gerarParaCompetencia`, que fecha o mês inteiro. Com o
 * contas a pagar vazio e onze despesas fixas cadastradas, criar a décima
 * segunda fazia as onze aparecerem de uma vez, competência fechada semanas
 * antes da hora. O sintoma parecia dado fantasma; a causa era o escopo da
 * geração.
 */
class CadastroGeraSoASuaParcelaTest extends TestCase
{
    use RefreshDatabase;

    private function fixa(string $descricao, string $dataInicio, ?string $dataFim = null, bool $ativo = true): ContaFixaPagar
    {
        return ContaFixaPagar::create([
            'descricao' => $descricao,
            'valor' => 100.00,
            'dia_vencimento' => 10,
            'data_inicio' => $dataInicio,
            'data_fim' => $dataFim,
            'ativo' => $ativo,
        ]);
    }

    private function cadastrar(array $campos = []): TestResponse
    {
        return $this->actingAs(User::factory()->create())->post(route('contas-fixas-pagar.store'), array_merge([
            'descricao' => 'Nova despesa',
            'valor' => 250.00,
            'dia_vencimento' => 10,
            'data_inicio' => now()->startOfMonth()->toDateString(),
        ], $campos));
    }

    public function test_cadastrar_nao_gera_as_parcelas_das_outras_despesas_fixas(): void
    {
        $vizinha = $this->fixa('Aluguel', now()->subMonths(6)->toDateString());

        $this->cadastrar()->assertRedirect(route('contas-pagar.index'));

        $this->assertSame(1, ContaPagar::count(), 'O cadastro só pode materializar a parcela da despesa criada.');
        $this->assertSame('Nova despesa', ContaPagar::sole()->descricao);
        $this->assertSame(
            0,
            ContaPagar::where('conta_fixa_pagar_id', $vizinha->id)->count(),
            'A despesa que já existia só entra no fechamento do mês, não de carona num cadastro.'
        );
    }

    public function test_a_parcela_criada_carrega_a_competencia_e_o_vinculo_da_despesa(): void
    {
        $this->cadastrar(['descricao' => 'Contabilidade', 'valor' => 890.00]);

        $parcela = ContaPagar::sole();
        $fixa = ContaFixaPagar::where('descricao', 'Contabilidade')->sole();

        $this->assertSame($fixa->id, $parcela->conta_fixa_pagar_id);
        $this->assertSame(now()->format('Y-m'), $parcela->competencia);
        $this->assertSame('fixa', $parcela->tipo);
        $this->assertSame('em_aberto', $parcela->status);
        $this->assertEqualsWithDelta(890.00, (float) $parcela->valor, 0.01);
    }

    public function test_despesa_que_so_comeca_em_competencia_futura_nao_gera_parcela(): void
    {
        $this->cadastrar(['data_inicio' => now()->addMonth()->startOfMonth()->toDateString()]);

        $this->assertSame(0, ContaPagar::count(), 'Fora da competência atual não há parcela a materializar.');
        $this->assertSame(1, ContaFixaPagar::count(), 'O cadastro em si continua valendo — só a parcela não nasce.');
    }

    public function test_despesa_que_comeca_no_fim_do_mes_ja_gera_a_parcela_da_competencia(): void
    {
        // A vigência é do mês, não do dia: começar dia 28 não adia a parcela
        // pra competência seguinte. Medir por dia fazia o cadastro não gerar
        // nada e o fechamento do dia 31 gerar — os dois discordavam.
        $this->cadastrar(['data_inicio' => now()->endOfMonth()->toDateString()]);

        $this->assertSame(1, ContaPagar::count());
        $this->assertSame(now()->format('Y-m'), ContaPagar::sole()->competencia);
    }

    public function test_cadastrar_nao_duplica_parcela_que_o_fechamento_ja_havia_gerado(): void
    {
        $this->cadastrar(['descricao' => 'Servidores']);
        $fixa = ContaFixaPagar::sole();

        // Simula o fechamento mensal passando depois: a idempotência é por
        // (despesa fixa, competência), então nada novo pode nascer.
        app(DespesaFixaService::class)->gerarParaCompetencia(now()->format('Y-m'));

        $this->assertSame(1, ContaPagar::where('conta_fixa_pagar_id', $fixa->id)->count());
    }
}
