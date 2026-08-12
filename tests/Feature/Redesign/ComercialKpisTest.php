<?php

namespace Tests\Feature\Redesign;

use App\Models\Cliente;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\IndicadoresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As contas por trás do painel Comercial.
 *
 * A tela contradizia a si mesma em dois lugares: um card dizia quantos
 * clientes existem e o ranking logo abaixo somava outro número sob o mesmo
 * nome, porque contava VÍNCULOS; e um card dizia quantos sistemas estão
 * ativos enquanto os rankings listavam também os desativados — que ainda por
 * cima entravam no MRR, que o fechamento nunca cobraria.
 */
class ComercialKpisTest extends TestCase
{
    use RefreshDatabase;

    private function operador(): User
    {
        return User::factory()->create();
    }

    private function comercial()
    {
        $resposta = $this->actingAs($this->operador())->get(route('comercial'));
        $resposta->assertOk();

        return $resposta;
    }

    private function sistemaComTier(Sistema $sistema, float $preco): Sistema
    {
        PrecoAtacado::create([
            'sistema_id' => $sistema->id,
            'revenda_id' => null,
            'nome' => 'Único',
            'preco_base' => $preco,
            'unidades_inclusas' => 100,
            'limite_unidades' => null,
            'ordem' => 1,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);

        return $sistema;
    }

    private function clienteCom(Sistema ...$sistemas): Cliente
    {
        $cliente = Cliente::create(['nome' => 'Cliente '.uniqid(), 'ativo' => true]);

        foreach ($sistemas as $s) {
            $cliente->sistemas()->attach($s->id, ['ativo' => true]);
        }

        return $cliente;
    }

    /**
     * @spec:AC-062 Um cliente com dois sistemas é UM cliente. O total do
     * ranking soma o nº de clientes de cada produto, então ele conta vínculos
     * — e chamá-lo de "Clientes ativos" punha dois números diferentes sob o
     * mesmo nome na mesma tela.
     */
    public function test_o_total_do_ranking_de_produtos_nao_se_chama_clientes_ativos(): void
    {
        $gym = $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 100.00);
        $control = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(), 100.00);

        // Um cliente com DOIS sistemas: 1 cliente, 2 vínculos.
        $this->clienteCom($gym, $control);
        // E um cliente ativo sem sistema nenhum: 1 cliente, 0 vínculos.
        $this->clienteCom();

        $resposta = $this->comercial();

        $this->assertSame(2, $resposta->viewData('totalClientesAtivos'));

        // O ranking soma 2 vínculos — coincide em valor, mas não em conceito.
        $this->assertEqualsWithDelta(2.0, $resposta->viewData('rankingClientes')['total'], 0.01);

        // O rótulo do total precisa dizer o que ele mede.
        $html = $resposta->getContent();
        $this->assertStringContainsString('Licenças ativas', $html);
        $this->assertStringNotContainsString('rotuloTotal="Clientes ativos"', $html);
    }

    /**
     * @spec:AC-062 Produto desativado sai dos rankings: o card acima deles
     * conta só os ativos, e a tela não pode listar mais produtos do que o
     * próprio card diz existir.
     */
    public function test_produto_desativado_sai_dos_rankings_e_da_categoria(): void
    {
        $vivo = $this->sistemaComTier(Sistema::factory()->alfagym()->create(['ativo' => true]), 100.00);
        $morto = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(['ativo' => false]), 900.00);

        $this->clienteCom($vivo);
        $this->clienteCom($morto);

        $resposta = $this->comercial();

        $this->assertSame(1, $resposta->viewData('totalSistemasAtivos'));

        $nomes = collect($resposta->viewData('rankingClientes')['itens'])->pluck('nome');
        $this->assertContains($vivo->nome, $nomes);
        $this->assertNotContains($morto->nome, $nomes, 'Produto desativado apareceu num ranking sob um card que só conta os ativos.');

        // A categoria também: o painel de portfólio conta os mesmos produtos.
        $totalCategorias = collect($resposta->viewData('rankingCategorias')['itens'])->sum('valor');
        $this->assertEqualsWithDelta(1.0, $totalCategorias, 0.01);
    }

    /**
     * @spec:AC-151 Produto desativado não vale receita: o fechamento pula ele,
     * então somá-lo no MRR era anunciar dinheiro que a fatura nunca produz.
     */
    public function test_produto_desativado_nao_soma_no_mrr_de_atacado(): void
    {
        $vivo = $this->sistemaComTier(Sistema::factory()->alfagym()->create(['ativo' => true]), 100.00);
        $morto = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(['ativo' => false]), 900.00);

        $this->clienteCom($vivo);
        $this->clienteCom($morto);

        $this->assertEqualsWithDelta(100.0, app(IndicadoresService::class)->mrrAtacado(), 0.01);
        $this->assertEqualsWithDelta(0.0, $morto->fresh()->mrrEstimado(), 0.01);

        // E as duas telas que mostram o número seguem mostrando o mesmo.
        $comercial = $this->comercial();
        $sistemas = $this->actingAs($this->operador())->get(route('sistemas.index'));

        $this->assertEqualsWithDelta(100.0, (float) $comercial->viewData('mrrEstimado'), 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $sistemas->viewData('mrrAtacado'), 0.01);
    }

    /**
     * @spec:AC-062 Duas revendas de mesmo nome são duas linhas. Agrupar por
     * nome somava a base das duas sob um rótulo que não distingue qual é qual.
     */
    public function test_revendas_homonimas_nao_viram_uma_linha_so(): void
    {
        $a = Revenda::create(['nome' => 'Invest', 'ativo' => true]);
        $b = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        Cliente::create(['nome' => 'Cliente A', 'revenda_id' => $a->id, 'ativo' => true]);
        Cliente::create(['nome' => 'Cliente B1', 'revenda_id' => $b->id, 'ativo' => true]);
        Cliente::create(['nome' => 'Cliente B2', 'revenda_id' => $b->id, 'ativo' => true]);

        $itens = collect($this->comercial()->viewData('rankingRevendas')['itens']);

        $this->assertCount(2, $itens, 'As duas revendas homônimas colapsaram numa linha só.');
        $this->assertEqualsWithDelta(2.0, $itens->firstWhere('valor', 2.0)['valor'] ?? 0, 0.01);
        $this->assertEqualsWithDelta(3.0, $itens->sum('valor'), 0.01);
    }

    /**
     * @spec:AC-062 O total de "Clientes por revenda" fecha com o card de
     * clientes ativos — inclusive o cliente de revenda desativada, que segue
     * sendo um cliente ativo da base.
     */
    public function test_clientes_por_revenda_fecha_com_o_card_de_clientes(): void
    {
        $viva = Revenda::create(['nome' => 'Ativa', 'ativo' => true]);
        $morta = Revenda::create(['nome' => 'Encerrada', 'ativo' => false]);

        Cliente::create(['nome' => 'De revenda ativa', 'revenda_id' => $viva->id, 'ativo' => true]);
        Cliente::create(['nome' => 'De revenda morta', 'revenda_id' => $morta->id, 'ativo' => true]);
        Cliente::create(['nome' => 'Direto', 'ativo' => true]);

        $resposta = $this->comercial();

        $this->assertSame(3, $resposta->viewData('totalClientesAtivos'));
        $this->assertEqualsWithDelta(
            3.0,
            $resposta->viewData('rankingRevendas')['total'],
            0.01,
            'A soma da lista por revenda precisa fechar com o card de clientes ativos.'
        );

        // O card de revendas conta só as ATIVAS — é outra pergunta, e pode
        // legitimamente ser menor que o nº de linhas da lista.
        $this->assertSame(1, $resposta->viewData('totalRevendasAtivas'));
    }

    /**
     * @spec:AC-040 O card de clientes traz a curva, e ela é a MESMA do Centro
     * de Controle — as duas telas desenham o mesmo indicador.
     */
    public function test_a_curva_de_clientes_e_a_mesma_nas_duas_telas(): void
    {
        Cliente::create([
            'nome' => 'Antigo', 'ativo' => true,
            'data_cadastro' => now()->subMonths(4)->toDateString(),
        ]);
        Cliente::create([
            'nome' => 'Novo', 'ativo' => true,
            'data_cadastro' => now()->startOfMonth()->addDay()->toDateString(),
        ]);

        $operador = $this->operador();
        $comercial = $this->actingAs($operador)->get(route('comercial'));
        $centro = $this->actingAs($operador)->get(route('centro-controle'));

        $serieComercial = $comercial->viewData('serieClientes');
        $cardCentro = collect($centro->viewData('cards'))->firstWhere('rotulo', 'Clientes ativos');

        $this->assertCount(6, $serieComercial);
        $this->assertSame($cardCentro['serie'], $serieComercial, 'As duas telas desenham curvas diferentes do mesmo indicador.');

        // E o delta é o mesmo número nas duas.
        $this->assertSame(2, $comercial->viewData('totalClientesAtivos'));
        $this->assertSame(1, $comercial->viewData('novosClientes'));
        $this->assertStringContainsString('+1 no mês', $cardCentro['delta']);
    }

    /**
     * @spec:AC-062 Os cards sem histórico no banco não ganham curva inventada.
     * Sistemas e Revendas só têm `created_at`, que é o dia da importação para
     * toda a base — uma curva feita dele seria um degrau.
     */
    public function test_cards_sem_historico_nao_ganham_curva(): void
    {
        $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 100.00);
        Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $dados = $this->comercial()->original->getData();

        $this->assertArrayHasKey('serieClientes', $dados);
        foreach (['serieSistemas', 'serieRevendas', 'serieMrr'] as $inventada) {
            $this->assertArrayNotHasKey($inventada, $dados, "A tela passou a desenhar $inventada sem o banco guardar esse histórico.");
        }
    }

    /**
     * @spec:AC-062 A tela não carrega dado que ninguém usa. Seis variáveis
     * eram calculadas e passadas à view sem nenhuma delas ser desenhada —
     * `porRevenda` chegava a carregar todo cliente ativo para a memória.
     */
    public function test_a_tela_nao_passa_variavel_que_ninguem_desenha(): void
    {
        $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 100.00);
        $this->clienteCom();

        $dados = $this->comercial()->original->getData();

        foreach (['porQuantidade', 'porValor', 'maxQuantidade', 'maxValor'] as $morta) {
            $this->assertArrayNotHasKey($morta, $dados, "A variável $morta voltou, e nenhuma view a desenha.");
        }
    }
}
