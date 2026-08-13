<?php

namespace Tests\Feature\Telas;

use App\Models\Cliente;
use App\Models\ContaFixaPagar;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\Tarefa;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * As cinco listagens que passaram a paginar.
 *
 * O teste que importa aqui não é "vieram 20 linhas" — é que TOTAL e PENDÊNCIA
 * continuam falando do recorte inteiro depois do corte. Somar depois de fatiar
 * é o defeito clássico de paginar tela com rodapé de total: o número encolhe a
 * cada clique em "próxima", e quem lê acha que a base diminuiu.
 *
 * Por isso quase toda asserção compara a PÁGINA 1 com a PÁGINA 2: se o número
 * fosse tirado da página, as duas discordariam. Comparar as duas dispensa
 * fixar o valor esperado e pega o defeito independentemente da ordenação.
 */
class PaginacaoDasListagensTest extends TestCase
{
    use RefreshDatabase;

    /** Uma página cheia e uma sobra: o mínimo para existir "página 2". */
    private const CADASTROS = 25;

    private const POR_PAGINA = 20;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_revendas_pagina_de_vinte_em_vinte(): void
    {
        $this->criarRevendas();

        $this->assertPaginada(route('revendas.index'), 'linhas');
    }

    public function test_o_total_de_revendas_e_o_do_recorte_e_nao_o_da_pagina(): void
    {
        $this->criarRevendas();

        $primeira = $this->dadosDaTela(route('revendas.index'), 'totais');
        $segunda = $this->dadosDaTela(route('revendas.index', ['page' => 2]), 'totais');

        // Sem clientes, o total seria 0 nas duas páginas e o teste passaria
        // com o defeito de volta. O cenário garante número diferente de zero.
        $this->assertGreaterThan(0, $primeira['clientes'], 'O cenário não tem clientes: o teste não provaria nada.');
        $this->assertSame($primeira['clientes'], $segunda['clientes'], 'O total de clientes encolheu na página 2 — está somando a página.');
        $this->assertSame($primeira['mrr'], $segunda['mrr']);
    }

    public function test_a_barra_de_base_usa_a_mesma_regua_nas_duas_paginas(): void
    {
        $this->criarRevendas();

        // O divisor da barra é o maior do recorte. Se fosse o maior da página,
        // a mesma revenda mudaria de tamanho conforme onde aparecesse.
        $this->assertSame(
            $this->dadosDaTela(route('revendas.index'), 'maiorBase'),
            $this->dadosDaTela(route('revendas.index', ['page' => 2]), 'maiorBase'),
            'A régua da barra mudou entre as páginas.',
        );
    }

    public function test_o_filtro_de_revendas_sobrevive_a_troca_de_pagina(): void
    {
        $this->criarRevendas();

        $linhas = $this->dadosDaTela(route('revendas.index', ['status' => 'ativo', 'page' => 2]), 'linhas');

        // Sem `withQueryString`, o link da página 2 perde o recorte e a lista
        // volta inteira — o filtro parece "desligar sozinho" ao paginar.
        $this->assertStringContainsString('status=ativo', $linhas->url(1));
    }

    public function test_produtos_pagina_de_vinte_em_vinte(): void
    {
        Sistema::factory()->count(self::CADASTROS)->create();

        $this->assertPaginada(route('produtos.index'), 'produtos');
    }

    public function test_as_contagens_de_produtos_falam_do_catalogo_e_nao_da_pagina(): void
    {
        Sistema::factory()->count(self::CADASTROS)->create();

        $primeira = $this->dadosDaTela(route('produtos.index'), 'contagens');
        $segunda = $this->dadosDaTela(route('produtos.index', ['page' => 2]), 'contagens');

        $this->assertSame(self::CADASTROS, $primeira['sistemas']);
        $this->assertSame($primeira, $segunda, 'As contagens do rodapé mudaram na página 2.');
    }

    public function test_despesas_fixas_paginam_de_vinte_em_vinte(): void
    {
        $this->criarDespesasFixas();

        $this->assertPaginada(route('contas-fixas-pagar.index'), 'contasFixas');
    }

    public function test_o_compromisso_mensal_soma_todas_as_recorrencias_ativas(): void
    {
        $this->criarDespesasFixas();

        $esperado = self::CADASTROS * 100.0;

        // Este número decide se o mês foi fechado. Somando a página, a
        // primeira diria R$ 2.000,00 e a segunda R$ 500,00.
        foreach ([1, 2] as $pagina) {
            $tela = $this->get(route('contas-fixas-pagar.index', ['page' => $pagina]))->assertOk();

            $tela->assertViewHas('totalMensal', $esperado);
            $tela->assertViewHas('quantidadeAtivas', self::CADASTROS);
        }
    }

    public function test_historico_de_tarefas_pagina_de_vinte_em_vinte(): void
    {
        Tarefa::factory()->count(self::CADASTROS)->create(['status' => 'concluida']);

        $this->assertPaginada(route('tarefas.historico'), 'tarefas');
    }

    public function test_o_historico_nao_recorta_nada_ao_paginar(): void
    {
        // A tela é caminho de auditoria (AC-097): paginar entrega a lista em
        // pedaços, mas a soma dos pedaços tem que ser a lista inteira.
        Tarefa::factory()->count(self::CADASTROS)->create(['status' => 'concluida']);

        $vistas = collect([1, 2])
            ->flatMap(fn ($pagina) => $this->dadosDaTela(route('tarefas.historico', ['page' => $pagina]), 'tarefas')->pluck('id'))
            ->unique();

        $this->assertCount(self::CADASTROS, $vistas);
    }

    /**
     * O contrato comum das cinco telas: 20 na primeira página, o resto na
     * segunda, e o total sempre descrevendo a lista inteira.
     */
    private function assertPaginada(string $rota, string $variavel): void
    {
        $primeira = $this->dadosDaTela($rota, $variavel);

        $this->assertInstanceOf(LengthAwarePaginator::class, $primeira, "A tela não devolveu `{$variavel}` paginado.");
        $this->assertSame(self::POR_PAGINA, $primeira->count());
        $this->assertSame(self::CADASTROS, $primeira->total());
        $this->assertTrue($primeira->hasPages());

        $segunda = $this->dadosDaTela($rota.(str_contains($rota, '?') ? '&' : '?').'page=2', $variavel);

        $this->assertSame(self::CADASTROS - self::POR_PAGINA, $segunda->count());
        $this->assertSame(self::CADASTROS, $segunda->total());
    }

    /** Abre a tela e devolve uma variável da view. */
    private function dadosDaTela(string $rota, string $variavel): mixed
    {
        return $this->get($rota)->assertOk()->viewData($variavel);
    }

    private function criarRevendas(): void
    {
        for ($i = 1; $i <= self::CADASTROS; $i++) {
            $revenda = Revenda::create([
                'nome' => sprintf('Revenda %02d', $i),
                'ativo' => true,
            ]);

            // Só algumas têm cliente: é o que dá ao total um valor diferente
            // de zero E uma régua de barra com vencedor claro.
            foreach (range(1, $i % 4) as $numero) {
                Cliente::create([
                    'nome' => "Cliente {$i}-{$numero}",
                    'revenda_id' => $revenda->id,
                    'ativo' => true,
                ]);
            }
        }
    }

    private function criarDespesasFixas(): void
    {
        for ($i = 1; $i <= self::CADASTROS; $i++) {
            ContaFixaPagar::create([
                'descricao' => sprintf('Recorrência %02d', $i),
                'valor' => 100.00,
                'dia_vencimento' => 10,
                'data_inicio' => now()->startOfMonth()->toDateString(),
                'ativo' => true,
            ]);
        }
    }
}
