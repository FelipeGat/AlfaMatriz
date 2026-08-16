<?php

namespace Tests\Feature\SinoForaDoQuadro;

use App\Models\Cliente;
use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\PrecoAtacado;
use App\Models\Revenda;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A geração de faturamento avisa no sino (US-091) — inclusive quando é o cron
 * do último dia do mês que gera, sem ninguém olhando o stdout dele.
 */
class FaturamentoAvisaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mesma âncora dos testes de faturamento: a competência sai de `now()`
        // em mais de um lugar, e uma data de borda mediria meses diferentes.
        Carbon::setTestNow(Carbon::parse('2026-08-13 10:00:00', 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function cicloFaturavel(): void
    {
        $sistema = Sistema::factory()->alfagym()->create(['ativo' => true]);

        PrecoAtacado::create([
            'sistema_id' => $sistema->id,
            'revenda_id' => null,
            'nome' => 'Único',
            'preco_base' => 499.00,
            'unidades_inclusas' => 100,
            'limite_unidades' => null,
            'ordem' => 1,
            'vigencia_inicio' => now()->subYear()->toDateString(),
        ]);

        $revenda = Revenda::create(['nome' => 'Invest', 'ativo' => true]);

        $cliente = Cliente::create(['nome' => 'Academia Alfa', 'revenda_id' => $revenda->id, 'ativo' => true]);
        $cliente->sistemas()->attach($sistema->id, ['ativo' => true]);
    }

    /**
     * @spec:AC-332 Gerar pelo botão avisa quem vê faturamento na matriz — menos
     * quem apertou o botão, menos quem só trabalha no quadro, e menos a conta
     * com escopo de revenda (a tela responde 403 do lado dela).
     */
    public function test_gerar_avisa_quem_ve_faturamento_menos_quem_gerou(): void
    {
        $this->cicloFaturavel();

        $quemGera = User::factory()->create(['name' => 'Camila Reis']);
        $outroAdmin = User::factory()->create(['name' => 'Bruno Costa']);
        $membro = User::factory()->membro()->create();

        $financeiro = User::factory()->semPerfil()->create(['name' => 'Sueli Prado']);
        $financeiro->perfis()->attach(Perfil::where('slug', 'financeiro')->value('id'));

        $daRevenda = User::factory()->create(['revenda_id' => Revenda::first()->id]);

        $this->actingAs($quemGera)->post(route('faturamento.gerar'), ['competencia' => '2026-08']);

        $avisos = Notificacao::where('tipo', 'faturamento')->get();

        $this->assertEqualsCanonicalizing(
            [$outroAdmin->id, $financeiro->id],
            $avisos->pluck('destinatario_id')->all(),
        );

        $aviso = $avisos->first();

        $this->assertStringContainsString('2026-08', $aviso->titulo);
        $this->assertStringContainsString('1 cobrança(s)', $aviso->titulo);
        $this->assertStringContainsString('R$ 499,00', $aviso->meta);
        $this->assertStringContainsString('competencia=2026-08', $aviso->rota);

        $this->assertSame(0, Notificacao::where('destinatario_id', $membro->id)->count());
        $this->assertSame(0, Notificacao::where('destinatario_id', $daRevenda->id)->count());
    }

    /**
     * @spec:AC-332 A geração que não criou nada não avisa: reapertar o botão de
     * uma competência já fechada é o caso comum, e "nada aconteceu" no sino
     * ensinaria a dispensá-lo.
     */
    public function test_geracao_vazia_nao_avisa(): void
    {
        $this->cicloFaturavel();

        $quemGera = User::factory()->create();
        User::factory()->create();

        $this->actingAs($quemGera)->post(route('faturamento.gerar'), ['competencia' => '2026-08']);
        $antes = Notificacao::where('tipo', 'faturamento')->count();

        $this->actingAs($quemGera)->post(route('faturamento.gerar'), ['competencia' => '2026-08']);

        $this->assertSame($antes, Notificacao::where('tipo', 'faturamento')->count());
    }

    /**
     * @spec:AC-332 O fechamento do cron não tem autor, então todo mundo que vê
     * faturamento recebe — ninguém ali agiu, e era exatamente a porta invisível.
     */
    public function test_fechamento_pelo_cron_avisa_todo_mundo(): void
    {
        $this->cicloFaturavel();

        $adminA = User::factory()->create();
        $adminB = User::factory()->create();

        $this->artisan('app:fechar-competencia-mensal', ['competencia' => '2026-08'])
            ->assertSuccessful();

        $avisos = Notificacao::where('tipo', 'faturamento')->get();

        $this->assertEqualsCanonicalizing(
            [$adminA->id, $adminB->id],
            $avisos->pluck('destinatario_id')->all(),
        );
    }
}
