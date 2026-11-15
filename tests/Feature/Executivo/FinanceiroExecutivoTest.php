<?php

namespace Tests\Feature\Executivo;

use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\MovimentacaoFinanceira;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O bloco `financeiro` da API Executiva, com dados fictícios. Os números aqui
 * são de teste: não dizem nada sobre o caixa real da Alfa. Sábado, 14/11/2026.
 */
class FinanceiroExecutivoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-14 10:00', 'America/Sao_Paulo'));
        config(['services.executivo.conta' => 'felipe@alfa.test']);
        $this->felipe = User::factory()->create(['email' => 'felipe@alfa.test']);
        $this->felipe->perfis()->syncWithoutDetaching([Perfil::where('slug', 'admin')->value('id')]);
    }

    private function fin(string $query = '')
    {
        app('auth')->forgetGuards();

        return $this->withToken($this->felipe->createToken('t', ['executivo:ler'])->plainTextToken)
            ->getJson('/api/executivo/financeiro'.$query);
    }

    private function conta(bool $ativo = true, string $nome = 'Banco A'): ContaFinanceira
    {
        return ContaFinanceira::create(['nome' => $nome, 'tipo' => 'corrente', 'saldo' => 0, 'ativo' => $ativo]);
    }

    private function mov(ContaFinanceira $c, string $tipo, float $valor, string $data = '2026-11-05'): MovimentacaoFinanceira
    {
        $m = $c->movimentacoes()->create(['tipo' => $tipo, 'descricao' => 'x', 'valor' => $valor, 'saldo_resultante' => 0, 'data' => $data]);
        $c->reprocessarSaldo();

        return $m;
    }

    private function cobranca(array $o = []): Cobranca
    {
        return Cobranca::create($o + ['descricao' => 'Licença', 'valor' => 100, 'data_vencimento' => '2026-11-20', 'status' => 'pendente', 'tipo' => 'avulsa', 'competencia' => '2026-11']);
    }

    private function despesa(array $o = []): ContaPagar
    {
        return ContaPagar::create($o + ['descricao' => 'Hospedagem', 'valor' => 50, 'data_vencimento' => '2026-11-20', 'status' => 'em_aberto', 'tipo' => 'avulsa', 'competencia' => '2026-11']);
    }

    public function test_realizado_so_entrada_e_saida_de_conta_ativa_e_resultado_nao_e_lucro(): void
    {
        $a = $this->conta();
        $inativa = $this->conta(false, 'Antiga');
        $this->mov($a, 'ajuste', 1000, '2026-11-01');           // saldo inicial: não é receita
        $this->mov($a, 'entrada', 300);
        $this->mov($a, 'entrada', 200, '2026-11-10');
        $this->mov($a, 'saida', 120);
        $this->mov($a, 'transferencia', 70);
        $this->mov($a, 'entrada', 999, '2026-10-31');           // fora do período
        $this->mov($inativa, 'entrada', 5000);                  // conta inativa

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->assertOk()->json('dados.realizado');

        $this->assertSame(50000, $r['entradas_realizadas_centavos']);
        $this->assertSame(12000, $r['saidas_realizadas_centavos']);
        $this->assertSame(38000, $r['resultado_de_caixa_centavos']);
        $this->assertSame(100000, $r['fora_do_resultado']['ajustes_liquido_centavos']);
        $this->assertSame(7000, $r['fora_do_resultado']['transferencias_liquido_centavos']);
        $this->assertSame(1, $r['contas_ativas']);
        // saldo = só a conta ativa: 1000+300+200-120+70+999 (a movimentação de outubro também é saldo)
        $this->assertSame(244900, $r['saldo_das_contas_ativas_centavos']);
        $this->assertNull($this->fin()->json('dados.lucro'));
    }

    public function test_sem_conta_ativa_e_ausente_e_nao_zero(): void
    {
        $this->conta(false);

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->assertOk()->json('dados.realizado');

        $this->assertNull($r['entradas_realizadas_centavos']);
        $this->assertNull($r['saidas_realizadas_centavos']);
        $this->assertNull($r['saldo_das_contas_ativas_centavos']);
        $this->assertSame(0, $r['contas_ativas']);
    }

    public function test_conta_ativa_sem_movimento_e_zero_real(): void
    {
        $this->conta();

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados.realizado');

        $this->assertSame(0, $r['entradas_realizadas_centavos']);
        $this->assertSame(0, $r['saidas_realizadas_centavos']);
        $this->assertSame(0, $r['saldo_das_contas_ativas_centavos']);
    }

    public function test_valor_negativo_vai_para_possiveis_estornos_e_nao_para_o_realizado(): void
    {
        $a = $this->conta();
        $this->mov($a, 'entrada', 100);
        $this->mov($a, 'entrada', -30);
        $this->mov($a, 'saida', -10);

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados.realizado');

        $this->assertSame(10000, $r['entradas_realizadas_centavos']);
        $this->assertSame(0, $r['saidas_realizadas_centavos']);
        $this->assertSame(2, $r['possiveis_estornos']['quantidade']);
        $this->assertSame(-3000, $r['possiveis_estornos']['entradas_negativas_centavos']);
    }

    public function test_aponta_candidatas_a_transferencia_interna_sem_descontar(): void
    {
        $a = $this->conta();
        $b = $this->conta(true, 'Banco B');
        $this->mov($a, 'saida', 400, '2026-11-08');
        $this->mov($b, 'entrada', 400, '2026-11-08');
        $this->mov($b, 'entrada', 90, '2026-11-09');           // sem par

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados.realizado');

        $this->assertSame(1, $r['possiveis_transferencias_internas']['pares']);
        $this->assertSame(40000, $r['possiveis_transferencias_internas']['valor_centavos']);
        $this->assertSame(49000, $r['entradas_realizadas_centavos'], 'candidata não é descontada');
    }

    public function test_baixa_real_gera_movimento_e_a_conciliacao_aponta_o_lancamento_manual(): void
    {
        $a = $this->conta();
        $this->cobranca(['valor' => 100, 'conta_financeira_id' => $a->id])->baixar(100, '2026-11-06');
        $this->despesa(['valor' => 40, 'conta_financeira_id' => $a->id])->baixar(40, '2026-11-07');
        $this->mov($a, 'entrada', 25, '2026-11-09');            // manual, sem baixa

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados.realizado.conciliacao');

        $this->assertSame(10000, $r['recebido_por_baixas_centavos']);
        $this->assertSame(4000, $r['pago_por_baixas_centavos']);
        $this->assertSame(2500, $r['entradas_sem_baixa_correspondente_centavos']);
        $this->assertSame(0, $r['saidas_sem_baixa_correspondente_centavos']);
    }

    public function test_a_receber_e_a_pagar_separam_vencido_e_a_vencer_sem_pago_nem_cancelado(): void
    {
        $this->cobranca(['valor' => 100, 'data_vencimento' => '2026-11-10']);                       // vencida
        $this->cobranca(['valor' => 60, 'data_vencimento' => '2026-11-14']);                        // vence na referência: a vencer
        $this->cobranca(['valor' => 40, 'data_vencimento' => '2026-12-01']);                        // a vencer
        $this->cobranca(['valor' => 500, 'data_vencimento' => '2026-11-01', 'status' => 'cancelado']);
        $this->cobranca(['valor' => 700, 'data_vencimento' => '2026-11-01', 'status' => 'pago', 'valor_pago' => 700]);
        $this->cobranca(['valor' => 0, 'data_vencimento' => '2026-11-01']);                         // sem obrigação
        $this->despesa(['valor' => 30, 'data_vencimento' => '2026-11-02']);
        $this->despesa(['valor' => 80, 'data_vencimento' => '2026-11-30']);
        $this->despesa(['valor' => 900, 'data_vencimento' => '2026-11-02', 'status' => 'cancelado']);

        $d = $this->fin('?de=2026-11-01&ate=2026-11-30&referencia=2026-11-14')->json('dados');

        $this->assertSame(20000, $d['contas_a_receber']['em_aberto_centavos']);
        $this->assertSame(10000, $d['contas_a_receber']['vencido_centavos']);
        $this->assertSame(10000, $d['contas_a_receber']['a_vencer_centavos']);
        $this->assertSame(1, $d['contas_a_receber']['vencido_quantidade']);
        $this->assertSame(1, $d['contas_a_receber']['excluidos']['cancelados_total_historico']);
        $this->assertSame(3000, $d['contas_a_pagar']['vencido_centavos']);
        $this->assertSame(8000, $d['contas_a_pagar']['a_vencer_centavos']);
    }

    public function test_a_data_de_referencia_muda_o_que_e_vencido(): void
    {
        $this->cobranca(['valor' => 100, 'data_vencimento' => '2026-11-20']);

        $antes = $this->fin('?referencia=2026-11-19')->json('dados.contas_a_receber');
        $depois = $this->fin('?referencia=2026-11-21')->json('dados.contas_a_receber');

        $this->assertSame(0, $antes['vencido_centavos']);
        $this->assertSame(10000, $antes['a_vencer_centavos']);
        $this->assertSame(10000, $depois['vencido_centavos']);
        $this->assertSame(0, $depois['a_vencer_centavos']);
    }

    public function test_despesa_fixa_ja_gerada_nao_e_duplicada_na_projecao(): void
    {
        $gerada = ContaFixaPagar::create(['descricao' => 'Servidor', 'valor' => 200, 'dia_vencimento' => 25, 'data_inicio' => '2026-01-01', 'ativo' => true]);
        ContaFixaPagar::create(['descricao' => 'Domínio', 'valor' => 30, 'dia_vencimento' => 28, 'data_inicio' => '2026-01-01', 'ativo' => true]);
        $this->despesa(['conta_fixa_pagar_id' => $gerada->id, 'valor' => 200, 'tipo' => 'fixa', 'data_vencimento' => '2026-11-25']);

        $p = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados.previsto');

        $this->assertSame(20000, $p['registrado']['a_pagar_centavos']);
        $this->assertTrue($p['projecao_estimada']['estimativa']);
        $this->assertSame(3000, $p['projecao_estimada']['a_pagar_despesas_fixas_centavos'], 'só a fixa SEM conta na competência');
        $this->assertSame(1, $p['projecao_estimada']['a_pagar_despesas_fixas_quantidade']);
    }

    public function test_projecao_nao_e_calculada_para_referencia_diferente_de_hoje(): void
    {
        $p = $this->fin('?referencia=2026-11-01')->json('dados.previsto.projecao_estimada');

        $this->assertNull($p['a_pagar_centavos']);
        $this->assertNull($p['a_receber_centavos']);
    }

    public function test_mrr_faturado_e_contratado_separados_e_faturado_ausente_nao_e_zero(): void
    {
        $r = $this->fin()->json('dados.mrr');
        $this->assertNull($r['faturado_centavos']);
        $this->assertNotNull($r['faturado_motivo']);
        $this->assertIsInt($r['contratado_centavos']);

        $revenda = Revenda::create(['nome' => 'Rev Teste', 'ativo' => true]);
        $this->cobranca(['tipo' => 'locacao_sistema', 'revenda_id' => $revenda->id, 'valor' => 300]);
        $this->cobranca(['tipo' => 'locacao_sistema', 'revenda_id' => $revenda->id, 'valor' => 900, 'status' => 'cancelado']);

        $r = $this->fin()->json('dados.mrr');
        $this->assertSame(30000, $r['faturado_centavos']);
        $this->assertNull($this->fin('?referencia=2026-09-10')->json('dados.mrr.contratado_centavos'), 'competência passada não tem contratado');
    }

    public function test_receita_recorrente_nao_vira_recebimento_previsto_por_conta_propria(): void
    {
        $revenda = Revenda::create(['nome' => 'Rev Teste', 'ativo' => true]);
        $this->cobranca(['tipo' => 'locacao_sistema', 'revenda_id' => $revenda->id, 'valor' => 300, 'data_vencimento' => '2026-12-15']);

        $d = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados');

        $this->assertSame(30000, $d['mrr']['faturado_centavos']);
        $this->assertSame(0, $d['previsto']['registrado']['a_receber_centavos'], 'o MRR não entra no previsto; só a cobrança com vencimento no período');
    }

    public function test_periodo_anterior_ao_matriz_vem_como_desatualizado_com_motivo(): void
    {
        $this->fin('?de=2026-09-01&ate=2026-09-30')->assertOk()->assertJsonPath('situacao', 'desatualizado');
        $this->fin('?de=2026-10-01&ate=2026-10-31')->assertOk()->assertJsonPath('situacao', 'ok');
    }

    public function test_envelope_e_parametros_invalidos(): void
    {
        $this->fin()->assertOk()
            ->assertJsonStructure(['fonte', 'periodo', 'consultado_em', 'atualizado_em', 'situacao', 'motivo', 'dados' => ['criterios', 'realizado', 'contas_a_receber', 'contas_a_pagar', 'previsto', 'mrr', 'lucro']]);
        $this->fin('?de=2026-11-30&ate=2026-11-01')->assertStatus(422);
        $this->fin('?de=2025-01-01&ate=2026-11-01')->assertOk()->assertJsonPath('situacao', 'falha');
        $this->fin('?de=ontem')->assertStatus(422);
    }

    public function test_realizado_nao_conta_lancamento_datado_no_futuro(): void
    {
        $a = $this->conta();
        $this->mov($a, 'entrada', 100, '2026-11-05');
        $this->mov($a, 'entrada', 70, '2026-11-20');   // depois de hoje (14/11)
        $this->mov($a, 'saida', 30, '2026-11-25');

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados.realizado');

        $this->assertSame(10000, $r['entradas_realizadas_centavos']);
        $this->assertSame(0, $r['saidas_realizadas_centavos']);
        $this->assertSame('2026-11-14', $r['ate_efetivo']);
        $this->assertSame(['quantidade' => 2, 'entradas_centavos' => 7000, 'saidas_centavos' => 3000], array_intersect_key($r['datados_depois_de_hoje'], array_flip(['quantidade', 'entradas_centavos', 'saidas_centavos'])));
    }

    public function test_pagamento_a_menor_e_exposto_nas_duas_listas(): void
    {
        $a = $this->conta();
        $this->cobranca(['valor' => 100, 'conta_financeira_id' => $a->id])->baixar(60, '2026-11-06');
        $this->despesa(['valor' => 50, 'conta_financeira_id' => $a->id])->baixar(45, '2026-11-07');
        $this->despesa(['valor' => 20, 'conta_financeira_id' => $a->id])->baixar(20, '2026-11-07');

        $d = $this->fin('?de=2026-11-01&ate=2026-11-30')->json('dados');

        $this->assertSame(1, $d['contas_a_receber']['pagos_a_menor']['quantidade']);
        $this->assertSame(4000, $d['contas_a_receber']['pagos_a_menor']['diferenca_centavos']);
        $this->assertSame(1, $d['contas_a_pagar']['pagos_a_menor']['quantidade']);
        $this->assertSame(500, $d['contas_a_pagar']['pagos_a_menor']['diferenca_centavos']);
    }

    public function test_periodo_invertido_e_recusado_mesmo_com_so_o_inicio(): void
    {
        $this->fin('?de=2026-12-05')->assertStatus(422);
        $this->fin('?ate=2026-10-05&referencia=2026-11-14&de=2026-11-01')->assertStatus(422);
    }

    public function test_sem_permissao_financeira_nada_e_exposto(): void
    {
        $this->felipe->perfis()->detach();
        $this->felipe->esquecerPermissoes();
        $this->conta();

        $r = $this->fin()->assertOk()->assertJsonPath('situacao', 'falha')->json();

        $this->assertNull($r['dados']);
    }

    public function test_so_leitura_nada_e_gravado(): void
    {
        $a = $this->conta();
        $this->mov($a, 'entrada', 10);
        $antes = [MovimentacaoFinanceira::count(), Cobranca::count(), ContaPagar::count(), ContaFinanceira::first()->saldo];

        $this->fin('?de=2026-11-01&ate=2026-11-30')->assertOk();

        $this->assertSame($antes, [MovimentacaoFinanceira::count(), Cobranca::count(), ContaPagar::count(), ContaFinanceira::first()->saldo]);
    }

    public function test_nao_le_nada_do_gestor_nem_expoe_descricao_ou_nome(): void
    {
        $a = $this->conta();
        $this->cobranca(['descricao' => 'Cliente Sigiloso LTDA', 'valor' => 10, 'conta_financeira_id' => $a->id]);
        $this->despesa(['descricao' => 'Fornecedor Secreto', 'valor' => 10]);

        $json = $this->fin('?de=2026-11-01&ate=2026-11-30')->getContent();

        $this->assertStringNotContainsString('Sigiloso', $json);
        $this->assertStringNotContainsString('Secreto', $json);
        $this->assertStringNotContainsString('Banco A', $json);
    }

    public function test_atualizado_em_considera_cobrancas_e_contas_a_pagar_e_nao_so_o_livro(): void
    {
        $this->cobranca(['valor' => 10, 'data_vencimento' => '2026-11-20']);
        $d = $this->despesa(['valor' => 10, 'data_vencimento' => '2026-11-20']);
        \Illuminate\Support\Facades\DB::table('contas_pagar')->where('id', $d->id)->update(['updated_at' => '2030-01-02 03:04:05']);

        $r = $this->fin('?de=2026-11-01&ate=2026-11-30')->json();

        $this->assertStringStartsWith('2030-01-02', $r['atualizado_em']);
    }
}
