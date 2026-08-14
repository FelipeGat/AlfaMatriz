<?php

namespace Tests\Feature\Desempenho;

use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\Modulo;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A previsão da competência é o que os painéis mostram como receita contratada
 * — o que o fechamento cobraria se rodasse agora. Ela era calculada num laço
 * aninhado: para cada revenda ativa, uma consulta de sistemas; para cada
 * sistema daquela revenda, os clientes, o tier e as contratações de módulo. O
 * custo crescia com revendas × sistemas.
 *
 * É cálculo de dinheiro, então o par de testes é obrigatório: o custo parou de
 * crescer E o valor não mudou. Se um dia só o primeiro passar, o segundo é que
 * está dizendo a verdade.
 */
class PrevisaoEmBlocoTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Um sistema com faixa de atacado e um módulo, e `$revendas` revendas com
     * dois clientes cada uma dentro dele.
     */
    private function baseCom(int $revendas): Sistema
    {
        $sistema = Sistema::factory()->create(['ativo' => true, 'natureza' => 'produto']);

        PrecoAtacado::create([
            'sistema_id' => $sistema->id,
            'nome' => 'Faixa única',
            'preco_base' => 200,
            'unidades_inclusas' => 10,
            'valor_excedente_unidade' => 15,
            'ordem' => 1,
            'vigencia_inicio' => now()->subMonth()->toDateString(),
        ]);

        $modulo = Modulo::create([
            'sistema_id' => $sistema->id, 'codigo' => 'FISCAL', 'nome' => 'Fiscal', 'ativo' => true,
        ]);

        for ($i = 0; $i < $revendas; $i++) {
            $revenda = Revenda::create(['nome' => "Revenda {$i}", 'ativo' => true]);

            foreach (range(1, 2) as $n) {
                $cliente = Cliente::create([
                    'nome' => "Cliente {$i}-{$n}",
                    'revenda_id' => $revenda->id,
                    'ativo' => true,
                    'tipo_pessoa' => 'PJ',
                ]);

                $sistema->clientes()->attach($cliente->id, ['ativo' => true]);

                ClienteModulo::create([
                    'cliente_id' => $cliente->id,
                    'modulo_id' => $modulo->id,
                    'status' => 'ativo',
                    'valor_mensal' => 40,
                    'data_inicio' => now()->subMonth()->toDateString(),
                ]);
            }
        }

        return $sistema;
    }

    /** Quantas consultas o trecho fez. */
    private function consultasDe(callable $trecho): int
    {
        $quantas = 0;

        DB::listen(function () use (&$quantas): void {
            $quantas++;
        });

        $trecho();

        return $quantas;
    }

    /**
     * @spec:AC-248 O custo da previsão não cresce com o número de revendas:
     * quadruplicar a base não muda o número de consultas.
     */
    public function test_o_custo_da_previsao_nao_cresce_com_as_revendas(): void
    {
        $this->baseCom(3);

        $comTres = $this->consultasDe(
            fn () => app(FaturamentoService::class)->previsaoDaCompetencia()
        );

        // Mais nove revendas no MESMO sistema, cada uma com dois clientes.
        Revenda::query()->delete();
        Cliente::query()->forceDelete();
        $this->baseCom(12);

        $comDoze = $this->consultasDe(
            fn () => app(FaturamentoService::class)->previsaoDaCompetencia()
        );

        $this->assertLessThanOrEqual(2, $comDoze - $comTres,
            "A previsão passou de {$comTres} para {$comDoze} consultas ao sair de 3 para 12 ".
            'revendas — o laço voltou a consultar por revenda.');
    }

    /**
     * @spec:AC-249 O valor previsto não muda: o total e a abertura por revenda
     * saem idênticos, com o tier da revenda e os módulos vigentes.
     */
    public function test_o_valor_previsto_nao_muda(): void
    {
        $this->baseCom(3);

        $previsao = app(FaturamentoService::class)->previsaoDaCompetencia();

        // Por revenda: dois clientes cabem nas dez unidades inclusas, então a
        // licença é o preço base (200), mais dois módulos de 40 = 280.
        $this->assertCount(3, $previsao['porRevenda']);

        foreach ($previsao['porRevenda'] as $valor) {
            $this->assertSame(280.0, $valor);
        }

        $this->assertSame(840.0, $previsao['total']);
    }

    /**
     * @spec:AC-249 Revenda sem nada a cobrar continua fora da abertura, e
     * cliente inativo continua sem contar — a previsão não pode passar a somar
     * o que o fechamento nunca cobraria.
     */
    public function test_revenda_sem_nada_a_cobrar_fica_de_fora(): void
    {
        $sistema = $this->baseCom(1);

        // Uma revenda ativa, com cliente ativo, mas com o VÍNCULO cancelado.
        $vazia = Revenda::create(['nome' => 'Revenda sem base', 'ativo' => true]);
        $cliente = Cliente::create([
            'nome' => 'Cliente sem vínculo ativo', 'revenda_id' => $vazia->id,
            'ativo' => true, 'tipo_pessoa' => 'PJ',
        ]);
        $sistema->clientes()->attach($cliente->id, ['ativo' => false]);

        // E uma revenda com cliente DESATIVADO e vínculo ativo.
        $outra = Revenda::create(['nome' => 'Revenda com cliente desativado', 'ativo' => true]);
        $inativo = Cliente::create([
            'nome' => 'Cliente desativado', 'revenda_id' => $outra->id,
            'ativo' => false, 'tipo_pessoa' => 'PJ',
        ]);
        $sistema->clientes()->attach($inativo->id, ['ativo' => true]);

        $previsao = app(FaturamentoService::class)->previsaoDaCompetencia();

        $this->assertArrayNotHasKey($vazia->id, $previsao['porRevenda']);
        $this->assertArrayNotHasKey($outra->id, $previsao['porRevenda']);
        $this->assertSame(280.0, $previsao['total']);
    }
}
