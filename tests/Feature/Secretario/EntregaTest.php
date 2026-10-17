<?php

namespace Tests\Feature\Secretario;

use App\Models\Notificacao;
use App\Models\SecretarioAviso;
use App\Models\SecretarioItem;
use App\Services\Secretario\Despachante;
use App\Services\Secretario\Itens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** US2: o lembrete chega na hora, uma vez, sem IA e sem conversa aberta (FR-012–FR-015). */
class EntregaTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'America/Sao_Paulo'));
    }

    private function item($u, string $quando, array $extra = []): SecretarioItem
    {
        $i = SecretarioItem::create($extra + ['user_id' => $u->id, 'tipo' => 'lembrete', 'titulo' => 'Ligar para o Alexandre',
            'quando' => Carbon::parse($quando, 'America/Sao_Paulo'), 'origem' => 'matriz']);
        app(Despachante::class)->programar($i);

        return $i;
    }

    public function test_entrega_uma_vez_no_sino_e_no_telegram_com_botoes(): void
    {
        $this->fakeTelegram();
        $u = $this->dono();
        $this->item($u, '2026-10-16 09:10');
        $d = app(Despachante::class);
        $d->despachar();
        $this->assertSame([], $this->enviados(), 'Antes da hora, nada');

        Carbon::setTestNow(Carbon::parse('2026-10-16 09:10', 'America/Sao_Paulo'));
        $d->despachar();
        $d->despachar();
        $this->artisan('secretario:despachar')->assertSuccessful();

        $this->assertCount(1, $this->enviados());
        $this->assertSame('⏰ Ligar para o Alexandre', explode("\n", $this->enviados()[0]['text'])[0]);
        $botoes = collect($this->enviados()[0]['reply_markup']['inline_keyboard'])->flatten(1)->pluck('callback_data');
        $this->assertCount(6, $botoes);
        $this->assertTrue($botoes->every(fn ($b) => preg_match('/^s:(c|a15|a60|am|r|x):\d+$/', $b) === 1));
        $this->assertSame(1, Notificacao::where('destinatario_id', $u->id)->count());
        $this->assertSame(2, SecretarioAviso::where('status', 'entregue')->count());
    }

    public function test_cinquenta_horarios_cada_um_uma_vez_mesmo_com_rodadas_repetidas(): void
    {
        $this->fakeTelegram();
        $u = $this->dono();
        for ($m = 1; $m <= 50; $m++) {
            $this->item($u, '2026-10-16 09:00', ['quando' => Carbon::parse('2026-10-16 09:00', 'America/Sao_Paulo')->addMinutes($m), 'titulo' => "Item {$m}"]);
        }
        for ($m = 1; $m <= 52; $m++) {
            Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'America/Sao_Paulo')->addMinutes($m));
            app(Despachante::class)->despachar();
            if ($m === 25) {
                app(Despachante::class)->despachar(); // a mesma volta duas vezes
            }
        }
        $this->assertCount(50, $this->enviados());
        $this->assertSame(50, Notificacao::count());
        $this->assertSame(0, SecretarioAviso::where('atrasado', true)->count());
    }

    public function test_parada_entrega_atrasado_sem_perder_nem_duplicar(): void
    {
        $this->fakeTelegram();
        $u = $this->dono();
        $this->item($u, '2026-10-16 09:01');
        $this->item($u, '2026-10-16 09:02', ['titulo' => 'Segundo']);
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:07', 'America/Sao_Paulo'));
        app(Despachante::class)->despachar();
        $this->assertCount(2, $this->enviados());
        $this->assertStringContainsString('(atrasado, era 09:01)', $this->enviados()[0]['text']);
    }

    public function test_concluido_antes_da_hora_nao_e_avisado(): void
    {
        $this->fakeTelegram();
        $u = $this->dono();
        $i = $this->item($u, '2026-10-16 09:10');
        app(Itens::class)->agir($u, $i, 'concluir');
        Carbon::setTestNow(Carbon::parse('2026-10-16 09:10', 'America/Sao_Paulo'));
        app(Despachante::class)->despachar();
        $this->assertSame([], $this->enviados());
        $this->assertSame(0, Notificacao::count());
    }

    public function test_rede_fora_tenta_de_novo_e_desiste_em_30_min_com_o_sino_entregue(): void
    {
        Http::fake(['api.telegram.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect')]);
        $u = $this->dono();
        $this->item($u, '2026-10-16 09:00');
        for ($m = 0; $m <= 35; $m++) {
            Carbon::setTestNow(Carbon::parse('2026-10-16 09:00', 'America/Sao_Paulo')->addMinutes($m));
            app(Despachante::class)->despachar();
        }
        $tg = SecretarioAviso::where('canal', 'telegram')->first();
        $this->assertSame('falhou', $tg->status);
        $this->assertGreaterThanOrEqual(4, $tg->tentativas);
        $this->assertLessThanOrEqual(6, $tg->tentativas);
        $this->assertSame('entregue', SecretarioAviso::where('canal', 'sino')->value('status'));
    }

    public function test_texto_do_item_nao_vai_para_o_log(): void
    {
        Log::spy();
        Http::fake(['api.telegram.org/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: Failed to connect')]);
        $u = $this->dono();
        $this->item($u, '2026-10-16 09:00', ['titulo' => 'Senha do cofre na gaveta']);
        app(Despachante::class)->despachar();
        Log::shouldNotHaveReceived('warning', [\Mockery::on(fn ($m) => str_contains(json_encode($m), 'cofre'))]);
        $this->assertDatabaseMissing('central_ia_eventos', ['descricao' => 'Senha do cofre na gaveta']);
    }

    public function test_recorrente_avanca_sozinho_e_cada_dia_avisa_uma_vez(): void
    {
        $this->fakeTelegram();
        $u = $this->dono();
        $this->item($u, '2026-10-16 18:00', ['recorrencia' => ['freq' => 'diaria', 'hora' => '18:00'], 'titulo' => 'Fechar o caixa']);
        foreach (['2026-10-16 18:00', '2026-10-16 18:05', '2026-10-17 18:00', '2026-10-17 18:05'] as $t) {
            Carbon::setTestNow(Carbon::parse($t, 'America/Sao_Paulo'));
            app(Despachante::class)->despachar();
        }
        $this->assertCount(2, $this->enviados());
        $this->assertSame('2026-10-18 18:00', SecretarioItem::first()->quando->format('Y-m-d H:i'));
        $i = SecretarioItem::first();
        app(Itens::class)->agir($u, $i, 'cancelar_todas');
        Carbon::setTestNow(Carbon::parse('2026-10-18 18:00', 'America/Sao_Paulo'));
        app(Despachante::class)->despachar();
        $this->assertCount(2, $this->enviados());
    }
}
