<?php

namespace Tests\Feature\Financeiro;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\ContaFixaPagar;
use App\Models\ContaPagar;
use App\Models\Fornecedor;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Projeção de receitas e despesas (Felipe, 18/10/2026) e despesas fixas lançadas no dia 01. */
class ProjecaoFinanceiraTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-18 10:00', 'America/Sao_Paulo'));
        $this->admin = User::factory()->create();
        $hostinger = Fornecedor::create(['razao_social' => 'Hostinger LTDA', 'nome_fantasia' => 'Hostinger', 'ativo' => true]);
        ContaFixaPagar::create(['fornecedor_id' => $hostinger->id, 'descricao' => 'Hospedagem VPS AlfaGym', 'valor' => 108.99, 'dia_vencimento' => 29,
            'data_inicio' => '2026-08-01', 'ativo' => true]);
        ContaFixaPagar::create(['descricao' => '1ª Quinzena do Pró-labore - Rossini', 'valor' => 1405.25, 'dia_vencimento' => 15, 'data_inicio' => '2026-08-01', 'ativo' => true]);
    }

    public function test_despesas_fixas_do_mes_que_vem_aparecem_como_projecao_e_somem_quando_lancadas(): void
    {
        $r = $this->actingAs($this->admin)->get(route('contas-pagar.index', ['periodo' => 'proximo_mes']))->assertOk();
        $r->assertSee('Previstas para o período')->assertSee('Hospedagem VPS AlfaGym')->assertSee('Hostinger')->assertSee('Pró-labore')
            ->assertSee('inclui R$ 1.514,24 projetado')->assertSee('29/11/2026');

        $this->artisan('despesas:gerar-fixas', ['competencia' => '2026-11'])->assertSuccessful();
        $this->assertSame(2, ContaPagar::where('competencia', '2026-11')->count());
        $this->get(route('contas-pagar.index', ['periodo' => 'proximo_mes']))->assertOk()->assertDontSee('Previstas para o período')
            ->assertSee('Hospedagem VPS AlfaGym');
    }

    public function test_dia_01_lanca_as_fixas_do_mes_corrente_sem_duplicar(): void
    {
        $this->artisan('despesas:gerar-fixas')->assertSuccessful();
        $this->artisan('despesas:gerar-fixas')->assertSuccessful();
        $this->assertSame(['2026-10-15', '2026-10-29'], ContaPagar::where('competencia', '2026-10')->orderBy('data_vencimento')->get()
            ->map(fn ($c) => $c->data_vencimento->toDateString())->all());
    }

    public function test_cobranca_da_revenda_por_cliente_projetada_antes_do_fechamento(): void
    {
        $invest = Revenda::create(['nome' => 'INVEST', 'ativo' => true, 'cobranca_por_cliente' => true, 'dia_vencimento' => 11]);
        $gym = Sistema::factory()->create(['nome' => 'AlfaGym', 'slug' => 'alfagym', 'natureza' => 'produto']);
        PrecoAtacado::create(['sistema_id' => $gym->id, 'revenda_id' => $invest->id, 'nome' => 'Por cliente', 'preco_base' => 0, 'unidades_inclusas' => 0,
            'valor_excedente_unidade' => 79.90, 'ordem' => 0, 'vigencia_inicio' => '2026-10-01']);
        foreach (['Boa Forma' => true, 'R1' => true, 'Vitta' => false] as $nome => $faturavel) {
            Cliente::create(['nome' => $nome, 'revenda_id' => $invest->id, 'ativo' => true])->sistemas()->attach($gym->id, ['ativo' => true, 'faturavel' => $faturavel]);
        }

        $this->actingAs($this->admin)->get(route('cobrancas.index', ['periodo' => 'proximo_mes']))->assertOk()
            ->assertSee('Previstas para o período')->assertSee('competência 10/2026')->assertSee('11/11/2026')->assertSee('inclui R$ 159,80 projetado');

        Cobranca::create(['revenda_id' => $invest->id, 'descricao' => 'Licenciamento', 'valor' => 159.80, 'data_vencimento' => '2026-11-11',
            'status' => 'pendente', 'tipo' => 'locacao_sistema', 'competencia' => '2026-10']);
        $this->get(route('cobrancas.index', ['periodo' => 'proximo_mes']))->assertOk()->assertDontSee('Previstas para o período');
    }
}
