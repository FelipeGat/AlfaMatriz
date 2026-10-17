<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaRunner;
use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioGoogleEvento;
use App\Services\Secretario\AgendaConsolidada;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** A agenda do Google lida pela máquina (a ponte pessoal já autorizada). Quarta, 21/10/2026, 10:00. */
class AgendaPelaMaquinaTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
    }

    private function lida(array $extra = []): array
    {
        return array_replace(['maquina' => 'wsl', 'lido_em' => now()->toIso8601String(),
            'agendas' => [['id' => 'vix@group', 'nome' => 'VixCard', 'primaria' => false], ['id' => 'eu@gmail.com', 'nome' => 'Felipe', 'primaria' => true]],
            'eventos' => [
                ['agenda' => 'vix@group', 'id' => 'e1', 'titulo' => 'Visita RFID', 'inicio' => '2026-10-21T15:00:00-03:00', 'fim' => '2026-10-21T16:00:00-03:00', 'dia_inteiro' => false, 'link' => 'https://www.google.com/calendar/event?eid=e1'],
                ['agenda' => 'eu@gmail.com', 'id' => 'e2', 'titulo' => 'Feriado', 'inicio' => '2026-11-02', 'fim' => '2026-11-03', 'dia_inteiro' => true, 'link' => 'javascript:x'],
            ]], $extra);
    }

    public function test_maquina_manda_a_agenda_e_ela_entra_em_tudo(): void
    {
        $u = $this->dono(telegram: false);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => [], 'visto_em' => now()]);
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.agenda'), $this->lida())->assertOk();

        $conta = SecretarioGoogleConta::sole();
        $this->assertTrue($conta->pelaMaquina());
        $this->assertSame('ok', app(AgendaConsolidada::class)->situacaoGoogle($u)['estado']);
        $this->assertSame(2, SecretarioGoogleEvento::count());
        $this->assertNull(SecretarioGoogleEvento::where('google_id', 'e2')->value('link'), 'Só https');

        $this->actingAs($u);
        $j = $this->getJson(route('secretario.agenda.eventos', ['de' => '2026-10-21', 'ate' => '2026-10-21']))->assertOk()->json();
        $this->assertNull($j['google']['aviso']);
        $this->assertSame(['Visita RFID', 'vixcard', '2026-10-21T15:00'], [$j['eventos'][0]['titulo'], $j['eventos'][0]['calendario'], $j['eventos'][0]['inicio']]);
        $this->get(route('secretario.configuracoes'))->assertOk()->assertSee('Lido pela sua máquina')->assertSee('Pela máquina')->assertDontSee('Sincronizar agora');

        // Agenda desligada em Configurações: na próxima leitura, os eventos dela não entram.
        SecretarioGoogleAgenda::where('google_id', 'vix@group')->update(['incluida' => false]);
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.agenda'), $this->lida())->assertOk();
        $this->assertSame(['e2'], SecretarioGoogleEvento::pluck('google_id')->all());
        $this->assertFalse(SecretarioGoogleAgenda::where('google_id', 'vix@group')->value('incluida'), 'A escolha da pessoa fica');

        // Evento apagado no Google some daqui; agenda que sumiu também.
        $this->postJson(route('secretario.runner.agenda'), $this->lida(['agendas' => [['id' => 'eu@gmail.com', 'nome' => 'Felipe', 'primaria' => true]], 'eventos' => []]))->assertOk();
        $this->assertSame([0, 1], [SecretarioGoogleEvento::count(), SecretarioGoogleAgenda::count()]);

        // Leitura velha: a tela avisa.
        Carbon::setTestNow(now()->addHour());
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $this->assertSame('desatualizado', app(AgendaConsolidada::class)->situacaoGoogle($u)['estado']);
    }

    public function test_conta_oauth_tem_precedencia_e_outra_conta_nao_entra(): void
    {
        $u = $this->dono(telegram: false);
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['codex'], 'projetos' => [], 'visto_em' => now()]);
        SecretarioGoogleConta::create(['user_id' => $u->id, 'access_token' => 'tok', 'refresh_token' => 'ref', 'expira_em' => now()->addHour(), 'conta' => 'eu@gmail.com']);
        Sanctum::actingAs($u, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.agenda'), $this->lida())->assertOk()->assertJsonPath('ignorado', 'a conta OAuth do Matriz já lê o Google');
        $this->assertSame(0, SecretarioGoogleEvento::count());

        // Máquina que não é desta conta: 404.
        $outro = $this->dono(telegram: false, chat: 999);
        Sanctum::actingAs($outro, ['central-ia-runner']);
        $this->postJson(route('secretario.runner.agenda'), $this->lida())->assertNotFound();
        $this->assertSame(0, SecretarioGoogleConta::where('user_id', $outro->id)->count());
    }
}
