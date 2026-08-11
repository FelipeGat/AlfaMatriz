<?php

namespace Tests\Feature\IntegracaoMultiSistema;

use App\Models\Cliente;
use App\Models\ClienteModulo;
use App\Models\FaturamentoSnapshot;
use App\Models\Modulo;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Services\FaturamentoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulos entram na fatura como segunda parcela da mesma linha.
 *
 * O valor é o que a Alfa registra ao ativar o módulo no AlfaControl — a tela é
 * exclusiva de `super_admin`, então é preço de atacado (Alfa→revenda) e cabe na
 * cobrança da revenda. Se um dia a revenda passar a registrar o preço de varejo
 * dela ali, esta é a premissa a rever.
 */
class FaturamentoComModulosTest extends TestCase
{
    use RefreshDatabase;

    private function competencia(): string
    {
        return now()->format('Y-m');
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

    private function contratarModulo(Cliente $cliente, Modulo $modulo, float $valor, array $extra = []): ClienteModulo
    {
        return ClienteModulo::create(array_merge([
            'cliente_id' => $cliente->id,
            'modulo_id' => $modulo->id,
            'status' => 'ativo',
            'data_inicio' => now()->subYear()->toDateString(),
            'valor_mensal' => $valor,
        ], $extra));
    }

    /**
     * @spec:AC-151 CADEADO: competência só com clientes do AlfaGym gera
     * exatamente o total de antes. O gym não tem módulos, então a soma é zero e
     * `total` não pode mudar de valor.
     */
    public function test_fatura_do_alfagym_sai_identica(): void
    {
        $gym = $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 499.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);
        $this->clienteDe($revenda, $gym, 'Academia A');
        $this->clienteDe($revenda, $gym, 'Academia B');

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $snapshot = FaturamentoSnapshot::firstOrFail();

        $this->assertSame('499.00', $snapshot->total);
        $this->assertSame('499.00', $snapshot->valor_licenciamento);
        $this->assertSame('0.00', $snapshot->valor_modulos);
        $this->assertNull($snapshot->detalhe_modulos);
    }

    /**
     * @spec:AC-150 A cobrança é licenciamento + módulos, com as duas partes
     * separadas: quem recebe a fatura precisa ver de onde vem cada parcela.
     */
    public function test_cobranca_soma_licenciamento_e_modulos(): void
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

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $snapshot = FaturamentoSnapshot::firstOrFail();

        $this->assertSame('299.00', $snapshot->valor_licenciamento);
        $this->assertSame('99.80', $snapshot->valor_modulos);
        $this->assertSame('398.80', $snapshot->total);

        $detalhe = $snapshot->detalhe_modulos;
        $this->assertCount(1, $detalhe);
        $this->assertSame('FINANCEIRO', $detalhe[0]['codigo']);
        $this->assertSame(2, $detalhe[0]['clientes']);
        $this->assertSame(99.80, $detalhe[0]['valor']);
    }

    /**
     * @spec:AC-152 Módulo encerrado antes da competência não entra; iniciado
     * no meio do mês entra.
     */
    public function test_vigencia_decide_o_que_entra_na_competencia(): void
    {
        $control = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(), 100.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $modulo = Modulo::create([
            'sistema_id' => $control->id, 'codigo' => 'REFEITORIO', 'nome' => 'Refeitório', 'ativo' => true,
        ]);

        $encerrado = $this->clienteDe($revenda, $control, 'Encerrado antes');
        $noMeio = $this->clienteDe($revenda, $control, 'Começou no meio');

        // Terminou no mês anterior: fora.
        $this->contratarModulo($encerrado, $modulo, 30.00, [
            'data_fim' => now()->subMonth()->endOfMonth()->toDateString(),
        ]);
        // Começou no dia 20 desta competência: dentro.
        $this->contratarModulo($noMeio, $modulo, 30.00, [
            'data_inicio' => now()->startOfMonth()->addDays(19)->toDateString(),
        ]);

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $this->assertSame('30.00', FaturamentoSnapshot::firstOrFail()->valor_modulos);
    }

    /**
     * @spec:AC-152 Contratação suspensa não é receita corrente.
     */
    public function test_modulo_suspenso_nao_entra(): void
    {
        $control = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(), 100.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $modulo = Modulo::create([
            'sistema_id' => $control->id, 'codigo' => 'FINANCEIRO', 'nome' => 'Financeiro', 'ativo' => true,
        ]);

        $cliente = $this->clienteDe($revenda, $control, 'Condomínio');
        $this->contratarModulo($cliente, $modulo, 49.90, ['status' => 'suspenso']);

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $this->assertSame('0.00', FaturamentoSnapshot::firstOrFail()->valor_modulos);
    }

    /**
     * @spec:AC-152 Módulo ativado sem valor soma zero, em vez de quebrar. O
     * campo é opcional no AlfaControl: é lacuna de cadastro, não erro de
     * cálculo.
     */
    public function test_modulo_sem_valor_nao_quebra_a_fatura(): void
    {
        $control = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(), 100.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $modulo = Modulo::create([
            'sistema_id' => $control->id, 'codigo' => 'NOTIFICACOES', 'nome' => 'Notificações', 'ativo' => true,
        ]);

        $cliente = $this->clienteDe($revenda, $control, 'Condomínio');
        $this->contratarModulo($cliente, $modulo, 0)->update(['valor_mensal' => null]);

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $snapshot = FaturamentoSnapshot::firstOrFail();
        $this->assertSame('0.00', $snapshot->valor_modulos);
        $this->assertSame('100.00', $snapshot->total);
    }

    /**
     * @spec:AC-150 Módulo de um sistema não entra na linha de outro: a fatura
     * é por (competência, sistema, revenda).
     */
    public function test_modulo_nao_vaza_entre_sistemas(): void
    {
        $gym = $this->sistemaComTier(Sistema::factory()->alfagym()->create(), 499.00);
        $control = $this->sistemaComTier(Sistema::factory()->alfacontrol()->create(), 299.00);
        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $modulo = Modulo::create([
            'sistema_id' => $control->id, 'codigo' => 'FINANCEIRO', 'nome' => 'Financeiro', 'ativo' => true,
        ]);

        // O MESMO cliente nos dois sistemas, com módulo só no AlfaControl.
        $cliente = Cliente::create(['nome' => 'Misto', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $cliente->sistemas()->attach($gym->id, ['ativo' => true]);
        $cliente->sistemas()->attach($control->id, ['ativo' => true]);
        $this->contratarModulo($cliente, $modulo, 49.90);

        (new FaturamentoService)->gerarParaCompetencia($this->competencia());

        $doGym = FaturamentoSnapshot::where('sistema_id', $gym->id)->firstOrFail();
        $doControl = FaturamentoSnapshot::where('sistema_id', $control->id)->firstOrFail();

        $this->assertSame('0.00', $doGym->valor_modulos, 'O módulo é do AlfaControl.');
        $this->assertSame('499.00', $doGym->total);
        $this->assertSame('49.90', $doControl->valor_modulos);
        $this->assertSame('348.90', $doControl->total);
    }
}
