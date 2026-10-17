<?php

namespace Tests\Feature\Secretario;

use App\Models\Notificacao;
use App\Models\Perfil;
use App\Models\SecretarioGoogleAgenda;
use App\Models\SecretarioGoogleConta;
use App\Models\SecretarioGoogleEvento;
use App\Services\Secretario\GoogleAgenda;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** US1: conectar o Google com segurança (FR-001–FR-008, FR-019–FR-021). */
class GoogleConexaoTest extends TestCase
{
    use GoogleNosTestes, RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'America/Sao_Paulo'));
    }

    public function test_sem_cliente_configurado_a_tela_avisa_e_nao_redireciona(): void
    {
        $this->actingAs($this->dono(telegram: false));
        $this->get(route('secretario.configuracoes'))->assertOk()->assertSee('ainda não está configurada');
        $this->get(route('secretario.google.conectar'))->assertRedirect(route('secretario.configuracoes'));
    }

    public function test_conecta_com_state_de_uso_unico_e_guarda_cifrado(): void
    {
        $this->configurarGoogle();
        $this->fakeGoogle();
        $u = $this->dono(telegram: false);
        $this->actingAs($u);
        $ida = $this->get(route('secretario.google.conectar'))->assertRedirect();
        $url = $ida->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame(GoogleAgenda::ESCOPOS, $q['scope']);
        $this->assertSame('offline', $q['access_type']);

        $this->get(route('secretario.google.retorno', ['state' => $q['state'], 'code' => 'codigo-1']))->assertRedirect(route('secretario.configuracoes'));
        $conta = SecretarioGoogleConta::sole();
        $this->assertSame('ya29.ACESSO-SECRETO', $conta->access_token);
        $cru = DB::table('secretario_google_contas')->value('access_token');
        $this->assertStringNotContainsString('ACESSO-SECRETO', $cru, 'Token cifrado no banco');
        $this->assertArrayNotHasKey('access_token', $conta->toArray());
        $this->assertSame(['Financeiro Grupo', 'VixCard'], SecretarioGoogleAgenda::orderBy('nome')->pluck('nome')->all());
        $this->assertTrue(SecretarioGoogleAgenda::where('primaria', true)->value('padrao'));

        // O mesmo state de novo: uso único.
        $this->get(route('secretario.google.retorno', ['state' => $q['state'], 'code' => 'codigo-2']))->assertSessionHasErrors('google');
        $this->get(route('secretario.configuracoes'))->assertDontSee('ACESSO-SECRETO')->assertDontSee('RENOVA-SECRETO');
    }

    public function test_state_de_outra_conta_ou_inventado_e_recusado(): void
    {
        $this->configurarGoogle();
        $this->fakeGoogle();
        $a = $this->dono(telegram: false);
        $this->actingAs($a)->get(route('secretario.google.retorno', ['state' => 'inventado', 'code' => 'x']))->assertSessionHasErrors('google');
        $ida = $this->get(route('secretario.google.conectar'));
        parse_str(parse_url($ida->headers->get('Location'), PHP_URL_QUERY), $q);
        // Outra conta com a mesma sessão (troca de login no meio).
        $b = $this->dono(telegram: false);
        $this->actingAs($b)->get(route('secretario.google.retorno', ['state' => $q['state'], 'code' => 'x']))->assertSessionHasErrors('google');
        $this->assertSame(0, SecretarioGoogleConta::count());
    }

    public function test_desconectar_revoga_e_apaga_tudo(): void
    {
        $this->configurarGoogle();
        $this->fakeGoogle();
        $u = $this->dono(telegram: false);
        $this->evento('felipe@gmail.com', 'e1', 'Reunião', '2026-10-16T10:00:00-03:00', '2026-10-16T11:00:00-03:00');
        $this->conectarGoogle($u);
        $this->assertSame(1, SecretarioGoogleEvento::count());
        $this->actingAs($u)->post(route('secretario.google.desconectar'))->assertRedirect();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
        $this->assertSame([0, 0, 0], [SecretarioGoogleConta::count(), SecretarioGoogleAgenda::count(), SecretarioGoogleEvento::count()]);
    }

    public function test_sincroniza_o_minimo_e_tira_o_que_saiu(): void
    {
        $this->configurarGoogle();
        $this->fakeGoogle();
        $u = $this->dono(telegram: false);
        $this->evento('felipe@gmail.com', 'e1', 'Reunião comercial', '2026-10-16T10:00:00-03:00', '2026-10-16T11:00:00-03:00');
        $this->evento('felipe@gmail.com', 'e2', 'Feriado', '2026-10-17', '2026-10-18', diaInteiro: true);
        $this->evento('vixcard@group.calendar.google.com', 'e3', 'Call em Lisboa', '2026-10-16T15:00:00+01:00', '2026-10-16T16:00:00+01:00');
        $conta = $this->conectarGoogle($u);
        $this->assertSame(3, SecretarioGoogleEvento::count());
        $this->assertSame('11:00', SecretarioGoogleEvento::where('google_id', 'e3')->value('inicio')->format('H:i'), 'Fuso convertido para Brasília');
        $this->assertTrue(SecretarioGoogleEvento::where('google_id', 'e2')->value('dia_inteiro'));
        $this->assertStringNotContainsString('DESCRICAO-PRIVADA', json_encode(DB::table('secretario_google_eventos')->get()));
        $this->assertStringNotContainsString('terceiro@x.com', json_encode(DB::table('secretario_google_eventos')->get()));

        $this->eventosGoogle['felipe@gmail.com'] = [];          // e1 e e2 cancelados no Google
        $this->agendaVix($u)->update(['incluida' => false]);     // VixCard desmarcada
        app(\App\Services\Secretario\SincronizadorGoogle::class)->sincronizar($conta);
        $this->assertSame(0, SecretarioGoogleEvento::count());
    }

    public function test_autorizacao_retirada_marca_reconectar_e_avisa_uma_vez(): void
    {
        $this->configurarGoogle();
        Log::spy();
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $u = $this->dono(telegram: false);
        $conta = $this->conectarGoogle($u, sincronizar: false);
        $conta->update(['expira_em' => now()->subMinute()]);
        $this->artisan('secretario:sincronizar-google')->assertSuccessful();
        $this->artisan('secretario:sincronizar-google')->assertSuccessful();
        $this->assertSame(SecretarioGoogleConta::RECONECTAR, $conta->fresh()->situacao);
        $this->assertSame(1, Notificacao::where('destinatario_id', $u->id)->where('titulo', 'like', '%Google%')->count());
        Log::shouldNotHaveReceived('warning', [\Mockery::any(), \Mockery::on(fn ($c) => str_contains(json_encode($c), 'RENOVA-SECRETO'))]);
        $this->assertStringNotContainsString('RENOVA-SECRETO', json_encode(DB::table('central_ia_eventos')->get()));
    }

    public function test_renovacao_sob_demanda_guarda_o_token_novo(): void
    {
        $this->configurarGoogle();
        $this->fakeGoogle(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.NOVO', 'expires_in' => 3600])]);
        $u = $this->dono(telegram: false);
        $conta = $this->conectarGoogle($u, sincronizar: false);
        $conta->update(['expira_em' => now()->addMinute()]);
        $this->assertSame('ya29.NOVO', app(GoogleAgenda::class)->tokenValido($conta));
        $this->assertSame('1//RENOVA-SECRETO', $conta->fresh()->refresh_token, 'Refresh antigo mantido');
    }

    public function test_outra_conta_nao_mexe_nas_agendas_nem_sincroniza(): void
    {
        $this->configurarGoogle();
        $this->fakeGoogle();
        $u = $this->dono(telegram: false);
        $this->conectarGoogle($u);
        $outro = $this->admin();
        $outro->perfis()->syncWithoutDetaching([Perfil::where('slug', 'secretario')->value('id')]);
        $this->actingAs($outro)->putJson(route('secretario.google.agendas', $this->agendaVix($u)->id), ['incluida' => false, 'padrao' => true])->assertNotFound();
        $this->postJson(route('secretario.google.sincronizar'))->assertNotFound();
        $this->get(route('secretario.agenda'))->assertOk()->assertDontSee('felipe@gmail.com');
        $this->assertTrue($this->agendaVix($u)->incluida);
    }
}
