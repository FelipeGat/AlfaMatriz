<?php

namespace Tests\Feature\Desempenho;

use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\Modulo;
use App\Models\PrecoAtacado;
use App\Models\Sistema;
use App\Models\User;
use App\Services\IndicadoresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * O ranking de sistemas do painel Comercial percorria o catálogo perguntando
 * ao banco por produto: os clientes daquele sistema, os tiers de cada revenda
 * que o usa, os clientes de novo para os módulos e as contratações vigentes.
 * Um catálogo com o dobro de produtos custava o dobro de consultas.
 *
 * O que estes testes guardam é o par: o custo parou de crescer com o catálogo
 * E o valor de atacado continua o mesmo — é ele que o card "MRR estimado"
 * imprime e que a tela de Produtos repete.
 */
class RankingSistemasTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Um catálogo com `$quantos` produtos, cada um com clientes, tier de
     * atacado e um módulo contratado.
     */
    private function catalogoCom(int $quantos): void
    {
        for ($i = 0; $i < $quantos; $i++) {
            $sistema = Sistema::factory()->create(['ativo' => true, 'natureza' => 'produto']);

            PrecoAtacado::create([
                'sistema_id' => $sistema->id,
                'nome' => 'Faixa única',
                'preco_base' => 100,
                'unidades_inclusas' => 10,
                'valor_excedente_unidade' => 5,
                'ordem' => 1,
                'vigencia_inicio' => now()->subMonth()->toDateString(),
            ]);

            $modulo = Modulo::create([
                'sistema_id' => $sistema->id,
                'codigo' => 'MOD'.$i,
                'nome' => 'Módulo extra',
                'ativo' => true,
            ]);

            foreach (range(1, 3) as $n) {
                $cliente = Cliente::create([
                    'nome' => "Cliente {$i}-{$n}", 'ativo' => true, 'tipo_pessoa' => 'PJ',
                ]);

                $sistema->clientes()->attach($cliente->id, ['ativo' => true]);

                ClienteModulo::create([
                    'cliente_id' => $cliente->id,
                    'modulo_id' => $modulo->id,
                    'status' => 'ativo',
                    'valor_mensal' => 30,
                    'data_inicio' => now()->subMonth()->toDateString(),
                ]);
            }
        }
    }

    /** Quantas consultas a requisição fez. */
    private function consultasDe(callable $requisicao): int
    {
        $quantas = 0;

        DB::listen(function () use (&$quantas): void {
            $quantas++;
        });

        $requisicao();

        return $quantas;
    }

    /**
     * @spec:AC-247 O ranking não consulta por sistema: quadruplicar o catálogo
     * não muda o número de consultas do painel Comercial.
     */
    public function test_o_ranking_nao_consulta_por_sistema(): void
    {
        $usuario = User::factory()->create();

        $this->catalogoCom(3);
        $comTres = $this->consultasDe(
            fn () => $this->actingAs($usuario)->get('/comercial')->assertOk()
        );

        // Mais nove produtos, cada um com clientes, tier e módulo.
        $this->catalogoCom(9);
        $comDoze = $this->consultasDe(
            fn () => $this->actingAs($usuario)->get('/comercial')->assertOk()
        );

        $this->assertLessThanOrEqual(2, $comDoze - $comTres,
            "O painel passou de {$comTres} para {$comDoze} consultas ao sair de 3 para 12 ".
            'produtos no catálogo — o ranking voltou a perguntar por sistema.');
    }

    /**
     * @spec:AC-247 O valor de atacado não muda: licença pelo tier mais os
     * módulos vigentes, por sistema.
     */
    public function test_o_valor_do_ranking_nao_muda(): void
    {
        $this->catalogoCom(1);

        $linha = app(IndicadoresService::class)->rankingSistemas()->sole();

        $this->assertSame(3, $linha['clientes_ativos']);
        // Três clientes cabem nas dez unidades inclusas: só o preço base.
        $this->assertSame(100.0, $linha['valor_licenca']);
        // Três contratações de 30.
        $this->assertSame(90.0, $linha['valor_modulos']);
        $this->assertSame(190.0, $linha['valor_estimado']);
    }
}
