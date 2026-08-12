<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\Cobranca;
use App\Models\Modulo;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use App\Services\FaturamentoService;
use App\Services\IndicadoresService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulos fora da fatura: os lugares que somavam só a licença.
 *
 * O `FaturamentoComModulosTest` prova que a COBRANÇA soma licença + módulos.
 * Só que os três lugares que anunciam esse mesmo dinheiro antes de ele virar
 * cobrança — a prévia da tela de Faturamento, o MRR de atacado dos painéis e o
 * MRR do produto — somavam apenas a licença. A tela prometia um total menor do
 * que o botão "Gerar" ia produzir, e o painel mostrava um recorrente menor do
 * que a casa fatura.
 */
class ModulosForaDaFaturaTest extends TestCase
{
    use RefreshDatabase;

    private function competencia(): string
    {
        return now()->format('Y-m');
    }

    private function admin(): User
    {
        return User::factory()->create();
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

    private function clienteDe(Revenda $revenda, Sistema $sistema, string $nome): Cliente
    {
        $cliente = Cliente::create(['nome' => $nome, 'revenda_id' => $revenda->id, 'ativo' => true]);
        $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);

        return $cliente;
    }

    private function contratarModulo(Cliente $cliente, Modulo $modulo, float $valor): ClienteModulo
    {
        return ClienteModulo::create([
            'cliente_id' => $cliente->id,
            'modulo_id' => $modulo->id,
            'status' => 'ativo',
            'data_inicio' => now()->subMonths(6)->toDateString(),
            'valor_mensal' => $valor,
        ]);
    }

    /**
     * Um cenário só, usado por quase todos os testes: licença de 299 e dois
     * clientes com um módulo de 49,90 cada — 99,80 de módulos, 398,80 no total.
     */
    private function cenario(): array
    {
        $control = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(), 299.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $financeiro = Modulo::create([
            'sistema_id' => $control->id, 'codigo' => 'FINANCEIRO', 'nome' => 'Financeiro', 'ativo' => true,
        ]);

        $a = $this->clienteDe($revenda, $control, 'Condomínio A');
        $b = $this->clienteDe($revenda, $control, 'Condomínio B');
        $this->contratarModulo($a, $financeiro, 49.90);
        $this->contratarModulo($b, $financeiro, 49.90);

        return ['sistema' => $control, 'revenda' => $revenda, 'modulo' => $financeiro, 'clientes' => [$a, $b]];
    }

    /**
     * @spec:AC-150 O total da PRÉVIA é o total que o "Gerar" produz.
     *
     * Esta tela existe para ser conferida antes de gerar. Se ela promete um
     * número e o botão produz outro, ela deixa de servir para a única coisa
     * que faz.
     */
    public function test_o_total_da_previa_e_o_total_que_o_gerar_produz(): void
    {
        $this->cenario();

        $previa = $this->actingAs($this->admin())->get(route('faturamento.index'));
        $previa->assertOk();
        $totalPrevisto = (float) $previa->viewData('ciclo')['total'];

        // Agora gera de verdade e compara com o que a tela tinha prometido.
        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $gerado = (float) Cobranca::where('tipo', 'locacao_sistema')->sum('valor');

        $this->assertEqualsWithDelta(398.80, $totalPrevisto, 0.01, 'A prévia somou só a licença e escondeu os módulos.');
        $this->assertEqualsWithDelta($gerado, $totalPrevisto, 0.01, 'A prévia prometeu um total e o Gerar produziu outro.');
    }

    /**
     * @spec:AC-150 A conta escrita por extenso declara a parcela de módulos.
     * Um valor com uma parte que a frase não menciona não é auditável.
     */
    public function test_a_conta_da_linha_declara_a_parcela_de_modulos(): void
    {
        $this->cenario();

        $resposta = $this->actingAs($this->admin())->get(route('faturamento.index'));
        $resposta->assertOk();

        $linha = $resposta->viewData('preview')->first()['linhas']->first();

        $this->assertEqualsWithDelta(299.00, (float) $linha['valor_licenca'], 0.01);
        $this->assertEqualsWithDelta(99.80, (float) $linha['valor_modulos'], 0.01);
        $this->assertEqualsWithDelta(398.80, (float) $linha['valor'], 0.01);

        $this->assertStringContainsString('módulos', $linha['calculo']);
        $this->assertStringContainsString('99,80', $linha['calculo']);

        // E a frase chega à tela, não só ao controller.
        $resposta->assertSee('módulos', escape: false);
    }

    /**
     * @spec:AC-150 Sem módulo contratado, a frase da conta não muda — o
     * cadeado que impede a linha de ganhar texto que não descreve nada.
     */
    public function test_sem_modulo_a_conta_continua_como_era(): void
    {
        $gym = $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 499.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);
        $this->clienteDe($revenda, $gym, 'Academia A');

        $resposta = $this->actingAs($this->admin())->get(route('faturamento.index'));
        $linha = $resposta->viewData('preview')->first()['linhas']->first();

        $this->assertEqualsWithDelta(499.00, (float) $linha['valor'], 0.01);
        $this->assertStringNotContainsString('módulos', $linha['calculo']);
    }

    /**
     * @spec:AC-151 O MRR de atacado inclui os módulos, porque a fatura os
     * cobra. Sem eles o painel anunciava menos do que a casa fatura.
     */
    public function test_o_mrr_de_atacado_inclui_os_modulos(): void
    {
        $this->cenario();

        $this->assertEqualsWithDelta(398.80, app(IndicadoresService::class)->mrrAtacado(), 0.01);

        // E o número chega igual às duas telas que o mostram.
        $comercial = $this->actingAs($this->admin())->get(route('comercial'));
        $sistemas = $this->actingAs($this->admin())->get(route('sistemas.index'));

        $this->assertEqualsWithDelta(398.80, (float) $comercial->viewData('mrrEstimado'), 0.01);
        $this->assertEqualsWithDelta(398.80, (float) $sistemas->viewData('mrrAtacado'), 0.01);
    }

    /**
     * @spec:AC-151 O MRR do produto também é o recorrente inteiro, e o topo da
     * tela continua sendo a soma das linhas.
     */
    public function test_o_mrr_do_produto_inclui_os_modulos_e_o_topo_continua_somando_as_linhas(): void
    {
        $this->cenario();

        $resposta = $this->actingAs($this->admin())->get(route('produtos.index'));
        $resposta->assertOk();

        $mrrTotal = (float) $resposta->viewData('mrrTotal');
        $this->assertEqualsWithDelta(398.80, $mrrTotal, 0.01);

        // A invariante da tela: o topo é a soma das linhas, não um número à parte.
        $this->assertEqualsWithDelta(
            $mrrTotal,
            (float) collect($resposta->viewData('produtos')->items())->sum('mrr'),
            0.01,
            'O topo deixou de ser a soma das linhas.'
        );

        // E o MRR de atacado dos painéis não pode discordar do MRR de Produtos.
        $this->assertEqualsWithDelta($mrrTotal, app(IndicadoresService::class)->mrrAtacado(), 0.01);
    }

    /**
     * @spec:AC-152 Módulo de cliente desativado não é receita corrente. A
     * contagem própria que a tela de Produtos fazia somava a contratação
     * mesmo depois de o cliente sair.
     */
    public function test_modulo_de_cliente_desativado_nao_soma(): void
    {
        ['clientes' => [$a, $b]] = $this->cenario();

        $b->update(['ativo' => false]);

        // Sobra o módulo de um cliente só.
        $resposta = $this->actingAs($this->admin())->get(route('produtos.index'));
        $produto = collect($resposta->viewData('produtos')->items())->first();

        $this->assertEqualsWithDelta(49.90, (float) $produto['mrr_modulos'], 0.01, 'Somou módulo de cliente já desativado.');

        $this->assertTrue($a->fresh()->ativo);
    }

    /**
     * @spec:AC-151 Sistema sem tier fica fora do faturamento inteiro — e os
     * módulos dele saem junto, do mesmo jeito que o motor que gera os deixa de
     * fora. Cobrar o módulo de uma linha que não é cobrada seria inventar.
     */
    public function test_modulo_de_sistema_sem_tier_fica_fora_do_total(): void
    {
        $control = Sistema::factory()->alfacontrol()->create(); // sem tier nenhum
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $financeiro = Modulo::create([
            'sistema_id' => $control->id, 'codigo' => 'FINANCEIRO', 'nome' => 'Financeiro', 'ativo' => true,
        ]);

        $cliente = $this->clienteDe($revenda, $control, 'Condomínio A');
        $this->contratarModulo($cliente, $financeiro, 49.90);

        $resposta = $this->actingAs($this->admin())->get(route('faturamento.index'));

        $this->assertEqualsWithDelta(0.0, (float) $resposta->viewData('ciclo')['total'], 0.01);

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());
        $this->assertSame(0, Cobranca::count(), 'O motor não gerou nada — a prévia não podia prometer valor.');
    }
}
