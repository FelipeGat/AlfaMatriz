<?php

namespace Tests\Feature\Telas;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cliente sem revenda não pode derrubar tela.
 *
 * `groupBy('revenda_id')` devolve a chave como TEXTO, e o cliente sem revenda
 * vira a chave vazia — que não é null. Passada direto para
 * `tierParaVolume(int, ?int)`, ela estoura TypeError e a tela responde 500.
 *
 * O projeto já tinha o remédio (`Sistema::chaveDeRevenda`) e dois pontos
 * esqueceram de usá-lo: o Painel Comercial e a tela de Sistemas. Com três
 * clientes de teste sem revenda no banco, as duas quebravam.
 *
 * O cenário aqui é justamente esse: uma revenda com cliente e um cliente
 * SOLTO. Sem o cliente solto, o teste passa com o defeito de volta.
 */
class ClienteSemRevendaNaoDerrubaTelaTest extends TestCase
{
    use RefreshDatabase;

    private Sistema $sistema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sistema = Sistema::factory()->create([
            'slug' => 'alfagym', 'nome' => 'AlfaGym', 'ativo' => true,
        ]);

        // Um tier padrão (sem revenda), que é o que a venda direta usa.
        PrecoAtacado::create([
            'sistema_id' => $this->sistema->id,
            'revenda_id' => null,
            'nome' => 'Padrão',
            'ate_unidades' => 100,
            'preco_base' => 500.00,
            'preco_por_unidade_excedente' => 0,
            'ordem' => 1,
            'vigencia_inicio' => now()->subMonth()->toDateString(),
        ]);

        $revenda = Revenda::create(['nome' => 'Invest Soluções', 'ativo' => true]);

        $comRevenda = Cliente::create([
            'nome' => 'Academia com revenda', 'revenda_id' => $revenda->id, 'ativo' => true,
        ]);
        $comRevenda->sistemas()->attach($this->sistema->id, ['ativo' => true]);

        // O cliente que derrubava as telas.
        $semRevenda = Cliente::create(['nome' => 'Academia sem revenda', 'ativo' => true]);
        $semRevenda->sistemas()->attach($this->sistema->id, ['ativo' => true]);
    }

    public function test_painel_comercial_abre_com_cliente_sem_revenda(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('comercial'))
            ->assertOk();
    }

    public function test_tela_de_sistemas_abre_com_cliente_sem_revenda(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('sistemas.index'))
            ->assertOk();
    }

    public function test_centro_de_controle_abre_com_cliente_sem_revenda(): void
    {
        // Este já normalizava; o teste garante que continue normalizando.
        $this->actingAs(User::factory()->create())
            ->get(route('centro-controle'))
            ->assertOk();
    }

    public function test_a_venda_direta_entra_no_calculo_pelo_tier_padrao(): void
    {
        // Não basta não quebrar: o cliente sem revenda precisa CONTAR. Antes,
        // a exceção interrompia a soma e o número nunca aparecia.
        $mrr = $this->sistema->fresh()->mrrEstimado();

        $this->assertGreaterThan(0, $mrr, 'O cliente sem revenda não entrou no cálculo.');
    }

    public function test_a_chave_vazia_do_agrupamento_vira_null(): void
    {
        $sistema = $this->sistema;

        // O contrato do remédio, direto: é isto que os dois pontos esqueciam.
        $this->assertNull($sistema->chaveDeRevenda(''));
        $this->assertNull($sistema->chaveDeRevenda(null));
        $this->assertSame(7, $sistema->chaveDeRevenda('7'));
        $this->assertSame(7, $sistema->chaveDeRevenda(7));
    }
}
