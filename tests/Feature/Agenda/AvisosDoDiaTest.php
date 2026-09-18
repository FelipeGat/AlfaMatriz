<?php

namespace Tests\Feature\Agenda;

use App\Models\ClienteSistema;
use App\Models\Cobranca;
use App\Models\ContaPagar;
use App\Models\Lead;
use App\Models\Notificacao;
use App\Models\Sistema;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Os avisos antecipatórios do dia: o que VAI vencer, uma vez, antes da hora.
 *
 * A linha que atravessa: EVENTO no sino, CONDIÇÃO na fila de ação. Estes avisos
 * são a véspera ("vence amanhã", "expira em 7 dias") — o que a fila, que mostra
 * o que já deu errado, não cobre. Disparam uma vez, pela cadência diária.
 */
class AvisosDoDiaTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Um admin recebe tudo que é escopado por permissão (idsDeQuemVe). */
    private function admin(): User
    {
        return User::factory()->create();
    }

    private function cliente(string $nome): int
    {
        return DB::table('clientes')->insertGetId([
            'nome' => $nome, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /* ---------- despesa ---------- */

    public function test_despesa_que_vence_amanha_avisa_quem_ve_despesas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $admin = $this->admin();

        ContaPagar::create([
            'descricao' => 'Aluguel do escritório', 'valor' => 3200,
            'data_vencimento' => '2026-10-13', 'status' => 'em_aberto',
        ]);

        $this->artisan('avisos:do-dia')->assertSuccessful();

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $admin->id,
            'tipo' => 'lembrete',
            'titulo' => 'Vence amanhã: 1 despesa',
        ]);
    }

    public function test_despesa_que_vence_hoje_ou_depois_de_amanha_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $this->admin();

        ContaPagar::create(['descricao' => 'Hoje', 'valor' => 10, 'data_vencimento' => '2026-10-12', 'status' => 'em_aberto']);
        ContaPagar::create(['descricao' => 'Depois', 'valor' => 10, 'data_vencimento' => '2026-10-14', 'status' => 'em_aberto']);
        // Vence amanhã, mas já foi paga.
        ContaPagar::create(['descricao' => 'Paga', 'valor' => 10, 'data_vencimento' => '2026-10-13', 'status' => 'pago']);

        $this->artisan('avisos:do-dia');

        $this->assertSame(0, Notificacao::count());
    }

    public function test_varias_despesas_do_dia_agrupam_num_aviso_so(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $admin = $this->admin();

        ContaPagar::create(['descricao' => 'Aluguel', 'valor' => 3200, 'data_vencimento' => '2026-10-13', 'status' => 'em_aberto']);
        ContaPagar::create(['descricao' => 'Energia', 'valor' => 480, 'data_vencimento' => '2026-10-13', 'status' => 'em_aberto']);

        $this->artisan('avisos:do-dia');

        $this->assertSame(1, Notificacao::where('destinatario_id', $admin->id)->count());
        $aviso = Notificacao::where('destinatario_id', $admin->id)->first();
        $this->assertSame('Vencem amanhã: 2 despesas', $aviso->titulo);
        // A prévia lista os itens e fecha com o total em reais.
        $this->assertStringContainsString('Aluguel · Energia', $aviso->meta);
        $this->assertStringContainsString('R$ 3.680,00', $aviso->meta);
    }

    /* ---------- receita ---------- */

    public function test_receita_que_vence_amanha_avisa_quem_ve_receitas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $admin = $this->admin();

        Cobranca::create([
            'descricao' => 'Mensalidade Revenda Sul', 'valor' => 900,
            'data_vencimento' => '2026-10-13', 'status' => 'pendente',
        ]);

        $this->artisan('avisos:do-dia');

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $admin->id,
            'titulo' => 'A receber vence amanhã: 1 receita',
        ]);
    }

    /* ---------- licença ---------- */

    public function test_licenca_que_expira_em_7_dias_avisa_quem_ve_clientes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $admin = $this->admin();

        $clienteId = $this->cliente('Academia Fit');
        $sistema = Sistema::factory()->create(['nome' => 'AlfaGym']);

        ClienteSistema::create([
            'cliente_id' => $clienteId, 'sistema_id' => $sistema->id,
            'licenca_fim_em' => '2026-10-19', 'ativo' => true,
        ]);

        $this->artisan('avisos:do-dia');

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $admin->id,
            'titulo' => 'Licença expira em 7 dias: Academia Fit (AlfaGym)',
        ]);
    }

    public function test_licenca_que_expira_antes_ou_depois_de_7_dias_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $this->admin();

        $clienteId = $this->cliente('Outro');
        $sistema = Sistema::factory()->create();
        // Expira em 5 dias (não é o marco de 7).
        ClienteSistema::create(['cliente_id' => $clienteId, 'sistema_id' => $sistema->id, 'licenca_fim_em' => '2026-10-17', 'ativo' => true]);

        $this->artisan('avisos:do-dia');

        $this->assertSame(0, Notificacao::count());
    }

    /* ---------- lead ---------- */

    public function test_lead_parado_ha_7_dias_avisa_o_vendedor_dono(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $vendedor = $this->admin();
        $outro = $this->admin();

        // Parado há exatamente 7 dias, do vendedor.
        Lead::create([
            'nome' => 'Padaria do João', 'estagio' => 'contato',
            'estagio_atualizado_em' => '2026-10-05 08:00', 'vendedor_id' => $vendedor->id,
        ]);
        // Do outro vendedor, mas parado só há 3 dias: não entra.
        Lead::create([
            'nome' => 'Recente', 'estagio' => 'contato',
            'estagio_atualizado_em' => '2026-10-09 08:00', 'vendedor_id' => $outro->id,
        ]);

        $this->artisan('avisos:do-dia');

        $this->assertDatabaseHas('notificacoes', [
            'destinatario_id' => $vendedor->id,
            'titulo' => 'Lead parado há 7 dias: Padaria do João',
        ]);
        // O aviso é do DONO: o outro vendedor não recebe o lead alheio.
        $this->assertSame(0, Notificacao::where('destinatario_id', $outro->id)->count());
    }

    public function test_lead_ja_fechado_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $vendedor = $this->admin();

        // Parado há 7 dias, mas já é cliente ativo (estágio terminal).
        Lead::create([
            'nome' => 'Fechado', 'estagio' => 'cliente_ativo',
            'estagio_atualizado_em' => '2026-10-05 08:00', 'vendedor_id' => $vendedor->id,
        ]);

        $this->artisan('avisos:do-dia');

        $this->assertSame(0, Notificacao::where('destinatario_id', $vendedor->id)->count());
    }

    /** Roda uma vez por dia: a mesma passada não duplica dentro do dia. */
    public function test_dia_sem_nada_a_vencer_nao_avisa(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-12 08:00'));
        $this->admin();

        $this->artisan('avisos:do-dia')->assertSuccessful();

        $this->assertSame(0, Notificacao::count());
    }
}
