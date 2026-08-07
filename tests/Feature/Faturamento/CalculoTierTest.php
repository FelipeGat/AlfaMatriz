<?php

namespace Tests\Feature\Faturamento;

use App\Models\Cliente;
use App\Models\Cobranca;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalculoTierTest extends TestCase
{
    use RefreshDatabase;

    private const COMPETENCIA = '2026-08';

    /**
     * @spec:AC-016 Quando o volume cabe nas unidades inclusas, o valor é
     * exatamente o preço base — o excedente não é cobrado por antecipação.
     */
    public function test_volume_dentro_das_inclusas_custa_o_preco_base(): void
    {
        $tier = PrecoAtacado::factory()->make([
            'preco_base' => 299.00,
            'unidades_inclusas' => 5,
            'valor_excedente_unidade' => 40.00,
            'limite_unidades' => 20,
        ]);

        $this->assertEqualsWithDelta(299.00, $tier->calcularMensalidade(5), 0.001, 'No limite das inclusas, só o base.');
        $this->assertEqualsWithDelta(299.00, $tier->calcularMensalidade(3), 0.001, 'Abaixo das inclusas, só o base.');
        $this->assertEqualsWithDelta(299.00, $tier->calcularMensalidade(0), 0.001, 'Sem nenhuma unidade, só o base.');
    }

    /**
     * @spec:AC-016 Acima das inclusas, o valor é o preço base somado ao
     * excedente multiplicado pelo valor por unidade.
     */
    public function test_acima_das_inclusas_soma_o_excedente_por_unidade(): void
    {
        $tier = PrecoAtacado::factory()->make([
            'preco_base' => 800.00,
            'unidades_inclusas' => 5,
            'valor_excedente_unidade' => 40.00,
            'limite_unidades' => null,
        ]);

        // 8 unidades = 800 + (8 - 5) × 40
        $this->assertEqualsWithDelta(920.00, $tier->calcularMensalidade(8), 0.001);
        $this->assertEqualsWithDelta(840.00, $tier->calcularMensalidade(6), 0.001, 'Uma unidade acima cobra um excedente.');
    }

    /**
     * @spec:AC-016 Tier metrado (sem unidades inclusas) cobra por unidade
     * ativa desde a primeira — é como AlfaHome e AlfaJornada são vendidos.
     */
    public function test_tier_metrado_cobra_por_unidade_desde_a_primeira(): void
    {
        $tier = PrecoAtacado::factory()->metrado(10.00)->make(['preco_base' => 0.00]);

        $this->assertEqualsWithDelta(0.00, $tier->calcularMensalidade(0), 0.001);
        $this->assertEqualsWithDelta(10.00, $tier->calcularMensalidade(1), 0.001);
        $this->assertEqualsWithDelta(610.00, $tier->calcularMensalidade(61), 0.001);
    }

    /**
     * @spec:AC-016 Tier fechado (sem valor de excedente) cobra o preço base
     * dentro do limite, mesmo com volume acima das inclusas.
     */
    public function test_tier_fechado_cobra_o_preco_base_ate_o_limite(): void
    {
        $tier = PrecoAtacado::factory()->fechado()->make([
            'preco_base' => 249.00,
            'unidades_inclusas' => 1,
            'limite_unidades' => 10,
        ]);

        $this->assertEqualsWithDelta(249.00, $tier->calcularMensalidade(10), 0.001);
    }

    /**
     * @spec:AC-017 Volume acima do limite do tier não é cobrado por uma faixa
     * que não o comporta: o cálculo recusa, em vez de devolver um valor errado.
     */
    public function test_volume_acima_do_limite_do_tier_e_recusado_pelo_calculo(): void
    {
        $tier = PrecoAtacado::factory()->make([
            'preco_base' => 99.00,
            'unidades_inclusas' => 1,
            'limite_unidades' => 5,
        ]);

        $this->assertFalse($tier->comportaUnidades(6));
        $this->assertNull(
            $tier->calcularMensalidade(6),
            'Um valor qualquer aqui viraria cobrança errada na conta da revenda.'
        );
    }

    /**
     * @spec:AC-016 O faturamento escolhe a faixa pelo volume da revenda: com
     * três faixas cadastradas, a que vale é a primeira que comporta o volume.
     */
    public function test_o_faturamento_aplica_a_faixa_que_comporta_o_volume(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $this->faixa($sistema, 'Start', preco: 99.00, limite: 5, ordem: 1);
        $this->faixa($sistema, 'Growth', preco: 249.00, limite: 20, ordem: 2);
        $this->faixa($sistema, 'Scale', preco: 499.00, limite: 100, ordem: 3);

        $this->clientesAtivos($revenda, $sistema, 8);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();

        $this->assertEqualsWithDelta(249.00, (float) $cobranca->valor, 0.001, '8 clientes não cabem no Start.');
        $this->assertSame('Growth', collect($cobranca->detalhamento)->first()['tier']);
    }

    /**
     * @spec:AC-017 Volume acima de TODAS as faixas vigentes: o sistema fica de
     * fora da cobrança e o resultado sinaliza "sem tier compatível" com a
     * quantidade encontrada, para alguém tratar — em vez de cobrar errado ou
     * cobrar nada em silêncio.
     */
    public function test_volume_acima_de_todas_as_faixas_e_sinalizado_e_nao_cobrado(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        $this->faixa($sistema, 'Start', preco: 99.00, limite: 5, ordem: 1);
        $this->faixa($sistema, 'Growth', preco: 249.00, limite: 20, ordem: 2);

        $this->clientesAtivos($revenda, $sistema, 25);

        $resultado = app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $linha = collect($resultado['Invest Soluções'])->firstWhere('status', 'sem_tier_compativel');

        $this->assertNotNull($linha, 'O caso precisa ser sinalizado, não engolido.');
        $this->assertSame('AlfaGym', $linha['sistema']);
        $this->assertSame(25, $linha['clientes_ativos'], 'A quantidade encontrada precisa aparecer para quem for tratar.');

        $this->assertSame(
            0,
            Cobranca::where('revenda_id', $revenda->id)->count(),
            'Sem faixa compatível, nada é cobrado por esse sistema.'
        );
    }

    /**
     * @spec:AC-017 Um sistema sem faixa compatível não derruba a cobrança dos
     * outros: a revenda continua sendo faturada pelo que dá para faturar.
     */
    public function test_sistema_sem_faixa_nao_impede_a_cobranca_dos_demais(): void
    {
        $revenda = Revenda::factory()->create(['nome' => 'Invest Soluções']);

        $estourado = Sistema::factory()->create(['nome' => 'AlfaGym']);
        $this->faixa($estourado, 'Start', preco: 99.00, limite: 5, ordem: 1);
        $this->clientesAtivos($revenda, $estourado, 25);

        $normal = Sistema::factory()->create(['nome' => 'AlfaControl']);
        $this->faixa($normal, 'Start', preco: 149.00, limite: 10, ordem: 1);
        $this->clientesAtivos($revenda, $normal, 2);

        app(FaturamentoService::class)->gerarParaCompetencia(self::COMPETENCIA);

        $cobranca = Cobranca::where('revenda_id', $revenda->id)->firstOrFail();

        $this->assertEqualsWithDelta(149.00, (float) $cobranca->valor, 0.001);
        $this->assertSame(
            ['AlfaControl'],
            collect($cobranca->detalhamento)->pluck('sistema')->all(),
            'Só o sistema com faixa compatível entra no detalhamento.'
        );
    }

    /**
     * @spec:AC-016 Faixa fora da vigência não é aplicada: um preço que já
     * expirou não pode continuar cobrando.
     */
    public function test_faixa_fora_da_vigencia_nao_e_aplicada(): void
    {
        $sistema = Sistema::factory()->create();

        PrecoAtacado::factory()->vencido()->create([
            'sistema_id' => $sistema->id,
            'nome' => 'Preço antigo',
            'preco_base' => 50.00,
            'limite_unidades' => 100,
            'ordem' => 1,
        ]);

        $this->assertNull(
            $sistema->tierParaVolume(3),
            'Uma faixa vencida não pode continuar valendo.'
        );
    }

    private function faixa(Sistema $sistema, string $nome, float $preco, ?int $limite, int $ordem): void
    {
        PrecoAtacado::factory()->fechado()->create([
            'sistema_id' => $sistema->id,
            'nome' => $nome,
            'preco_base' => $preco,
            'unidades_inclusas' => $limite,
            'limite_unidades' => $limite,
            'ordem' => $ordem,
        ]);
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
