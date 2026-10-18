<?php

namespace Tests\Feature\CobrancaRevendas;

use App\Models\Cliente;
use App\Models\ClienteContrato;
use App\Models\Cobranca;
use App\Models\ContaFinanceira;
use App\Models\Perfil;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\FaturamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Cobrança da revenda por cliente, com boleto e baixa automática (docs/specs/cobranca-revendas-boleto). */
class CobrancaRevendasTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $admin;
    private Revenda $invest;
    private ContaFinanceira $conta;
    private Sistema $gym;
    private Sistema $control;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-31 20:00', 'America/Sao_Paulo'));
        config(['services.mercadopago.access_token' => 'token-de-teste']);
        $this->admin = User::factory()->create(['name' => 'Rossini Admin']);
        $this->felipe = User::factory()->create(['name' => 'Felipe']);
        $this->felipe->perfis()->attach(Perfil::where('slug', 'cobranca_revendas')->value('id'));
        $this->conta = ContaFinanceira::create(['nome' => 'Mercado Pago (Invest)', 'tipo' => 'corrente']);
        $this->invest = Revenda::create(['nome' => 'INVEST SOLUCOES', 'cnpj' => '12.345.678/0001-90', 'ativo' => true, 'cobranca_por_cliente' => true, 'dia_vencimento' => 11,
            'email_financeiro' => 'financeiro@invest.com.br', 'cep' => '29100-000', 'logradouro' => 'Rua A', 'numero' => '10', 'bairro' => 'Centro',
            'cidade' => 'Vitória', 'uf' => 'ES', 'conta_financeira_id' => $this->conta->id]);
        $this->gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'natureza' => 'produto']);
        $this->control = Sistema::factory()->create(['nome' => 'AlfaControl', 'slug' => 'alfacontrol', 'natureza' => 'produto']);
    }

    private function cliente(string $nome, Sistema $s, bool $faturavel = true, ?float $contrato = null): Cliente
    {
        $c = Cliente::create(['nome' => $nome, 'revenda_id' => $this->invest->id, 'ativo' => true]);
        $c->sistemas()->attach($s->id, ['ativo' => true, 'faturavel' => $faturavel]);
        if ($contrato !== null) {
            ClienteContrato::create(['cliente_id' => $c->id, 'sistema_id' => $s->id, 'plano' => 'Premium', 'valor_mensal' => $contrato, 'status' => 'ativo', 'data_inicio' => '2026-08-01']);
        }

        return $c;
    }

    private function cenario(): void
    {
        $this->cliente('Boa Forma', $this->gym, contrato: 79.90);
        $this->cliente('R1', $this->gym);
        $this->cliente('Vitta', $this->gym, faturavel: false);
        $this->cliente('Jaragua', $this->control, contrato: 169.30);
        $this->cliente('TAJ', $this->control, faturavel: false);
        $this->actingAs($this->felipe)->put(route('cobranca-revendas.precos', $this->invest), ['precos' => [$this->gym->id => '79.90', $this->control->id => '99.90']])
            ->assertSessionHas('status');
    }

    /** O status que a consulta devolve (um fake só por teste: o primeiro vale até o fim). */
    private string $statusMp = 'pending';

    private function fakeMercadoPago(string $status = 'pending'): void
    {
        $this->statusMp = $status;
        Http::fake([
            'api.mercadopago.com/v1/payments' => Http::response(['id' => 987654, 'status' => 'pending', 'status_detail' => 'pending_waiting_payment',
                'barcode' => ['content' => '23791111111111111111111111111111111111111111'],
                'transaction_details' => ['external_resource_url' => 'https://www.mercadopago.com.br/payments/987654/ticket', 'digitable_line' => '23790.12345 60000.000000 00000.000000 1 99990000025780']], 201),
            'api.mercadopago.com/v1/payments/987654' => fn () => Http::response(['id' => 987654, 'status' => $this->statusMp, 'status_detail' => $this->statusMp === 'approved' ? 'accredited' : 'pending_waiting_payment',
                'transaction_amount' => 259.70, 'transaction_details' => ['total_paid_amount' => 259.70], 'date_approved' => '2026-11-10T14:30:00.000-03:00']),
        ]);
    }

    public function test_fechamento_cobra_por_cliente_faturavel_vence_dia_11_e_nao_cobra_o_cliente_final(): void
    {
        $this->cenario();
        $svc = app(FaturamentoService::class);
        $svc->gerarParaCompetencia('2026-10');
        $svc->gerarCobrancasDeClientesParaCompetencia('2026-10');

        $c = Cobranca::where('tipo', 'locacao_sistema')->sole();
        // Gym: Boa Forma + R1 (Vitta não faturável) × 79,90 = 159,80; Control: Jaraguá (TAJ fora) × 99,90 — contrato não tira ninguém.
        $this->assertSame('259.70', (string) $c->valor);
        $this->assertSame('2026-11-11', $c->data_vencimento->toDateString());
        $this->assertSame($this->conta->id, $c->conta_financeira_id);
        $this->assertSame(0, Cobranca::where('tipo', 'locacao_cliente')->count(), 'A Alfa não cobra o cliente final da revenda por cliente');
    }

    public function test_so_quem_tem_o_perfil_entra_admin_nao(): void
    {
        $this->actingAs($this->admin)->get(route('cobranca-revendas.index'))->assertForbidden();
        $this->get(route('tarefas.index'))->assertDontSee(route('cobranca-revendas.index'));
        $this->actingAs($this->felipe)->get(route('cobranca-revendas.index'))->assertOk()->assertSee('Cobrança de revendas')->assertSee(route('cobranca-revendas.index'));
    }

    public function test_tela_mostra_previa_e_chave_faturavel_muda_o_total(): void
    {
        $this->cenario();
        $this->actingAs($this->felipe)->get(route('cobranca-revendas.index'))->assertOk()->assertSee('R$ 259,70')->assertSee('2 de 3 faturáveis');
        $vitta = Cliente::where('nome', 'Vitta')->sole();
        $this->post(route('cobranca-revendas.faturavel', $this->invest), ['cliente_id' => $vitta->id, 'sistema_id' => $this->gym->id, 'faturavel' => 1])->assertSessionHas('status');
        $this->get(route('cobranca-revendas.index'))->assertSee('R$ 339,60');
    }

    public function test_boleto_emite_uma_vez_com_os_dados_da_revenda(): void
    {
        $this->cenario();
        app(FaturamentoService::class)->gerarParaCompetencia('2026-10');
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00', 'America/Sao_Paulo'));
        $this->fakeMercadoPago();

        $this->artisan('cobrancas:emitir-boletos-revendas')->assertSuccessful();
        $c = Cobranca::where('tipo', 'locacao_sistema')->sole();
        $this->assertSame('987654', $c->mp_payment_id);
        $this->assertStringStartsWith('https://', $c->mp_ticket_url);
        $this->assertNotNull($c->mp_linha_digitavel);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['payment_method_id'] === 'bolbradesco' && $r['transaction_amount'] == 259.70
            && $r['payer']['identification'] === ['type' => 'CNPJ', 'number' => '12345678000190'] && $r['payer']['address']['federal_unit'] === 'ES'
            && str_starts_with($r['date_of_expiration'], '2026-11-11T23:59:59') && $r->hasHeader('X-Idempotency-Key', 'matriz-cobranca-'.$c->id)
            && $r->hasHeader('Authorization', 'Bearer token-de-teste'));

        $this->artisan('cobrancas:emitir-boletos-revendas')->assertSuccessful();
        $this->actingAs($this->felipe)->post(route('cobranca-revendas.emitir', $c->id))->assertSessionHas('erro', 'Esta cobrança já tem boleto.');
        Http::assertSentCount(1);
    }

    public function test_sem_dados_da_revenda_nao_emite_e_diz_o_que_falta(): void
    {
        $this->cenario();
        app(FaturamentoService::class)->gerarParaCompetencia('2026-10');
        $this->invest->update(['cep' => null, 'email_financeiro' => null]);
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00', 'America/Sao_Paulo'));
        Http::fake();

        $this->artisan('cobrancas:emitir-boletos-revendas')->assertSuccessful();
        Http::assertNothingSent();
        $this->assertSame('Falta na revenda: e-mail financeiro, CEP.', Cobranca::where('tipo', 'locacao_sistema')->value('mp_erro'));
    }

    public function test_conferencia_da_baixa_pelo_que_o_mercado_pago_confirma_uma_vez(): void
    {
        $this->cenario();
        app(FaturamentoService::class)->gerarParaCompetencia('2026-10');
        Carbon::setTestNow(Carbon::parse('2026-11-01 08:00', 'America/Sao_Paulo'));
        $this->fakeMercadoPago('pending');
        $this->artisan('cobrancas:emitir-boletos-revendas');
        $this->artisan('cobrancas:conferir-boletos')->assertSuccessful();
        $this->assertSame('pendente', Cobranca::where('tipo', 'locacao_sistema')->value('status'));

        $this->statusMp = 'approved';
        $this->artisan('cobrancas:conferir-boletos')->assertSuccessful();
        $c = Cobranca::where('tipo', 'locacao_sistema')->sole();
        $this->assertSame(['pago', '259.70', '2026-11-10'], [$c->status, (string) $c->valor_pago, $c->data_pagamento->toDateString()]);
        $this->assertSame(1, $this->conta->movimentacoes()->count());
        $this->assertSame('259.70', (string) $this->conta->fresh()->saldo);
        $this->assertSame(1, \App\Models\Notificacao::where('destinatario_id', $this->felipe->id)->where('titulo', 'like', 'Boleto pago%')->count());

        $this->artisan('cobrancas:conferir-boletos')->assertSuccessful();
        $this->assertSame(1, $this->conta->movimentacoes()->count(), 'Pago não volta a ser conferido nem baixado');
    }

    public function test_revenda_que_nao_e_por_cliente_segue_igual(): void
    {
        $this->invest->update(['cobranca_por_cliente' => false, 'dia_vencimento' => null]);
        $this->cliente('Boa Forma', $this->gym);
        \App\Models\PrecoAtacado::create(['sistema_id' => $this->gym->id, 'revenda_id' => null, 'nome' => 'Start', 'preco_base' => 99, 'unidades_inclusas' => 10,
            'limite_unidades' => 10, 'ordem' => 1, 'vigencia_inicio' => '2026-08-01']);
        app(FaturamentoService::class)->gerarParaCompetencia('2026-10');
        $c = Cobranca::where('tipo', 'locacao_sistema')->sole();
        $this->assertSame(['99.00', '2026-11-05'], [(string) $c->valor, $c->data_vencimento->toDateString()]);
    }
}
