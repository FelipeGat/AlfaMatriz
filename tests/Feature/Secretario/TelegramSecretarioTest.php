<?php

namespace Tests\Feature\Secretario;

use App\Models\CentralIaMensagem;
use App\Models\SecretarioAviso;
use App\Models\SecretarioItem;
use App\Models\SecretarioProposta;
use App\Services\CentralIa\TelegramCanal;
use App\Services\Secretario\Despachante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** US1/US2 pelo Telegram: proposta com botões e toques seguros (contracts/telegram.md). */
class TelegramSecretarioTest extends TestCase
{
    use RefreshDatabase, SecretarioNosTestes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
        Carbon::setTestNow(Carbon::parse('2026-10-16 14:20', 'America/Sao_Paulo'));
        $this->fakeTelegram();
    }

    private function receber(array $update, $dono): void
    {
        app(TelegramCanal::class)->receber($update, $this->botNoTeste($dono));
    }

    public function test_criterio_de_aceite_1_lembrete_pelo_telegram(): void
    {
        $u = $this->dono();
        $this->receber($this->mensagem(10, 'me lembra daqui a 10 minutos de ligar para o Alexandre'), $u);
        $enviado = $this->enviados()[0];
        $this->assertStringContainsString('hoje (sex, 16/10) às 14:30', $enviado['text']);
        $p = SecretarioProposta::first();
        $this->assertSame("s:pc:{$p->id}", $enviado['reply_markup']['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame(0, SecretarioItem::count());

        $this->receber($this->toque('cb-1', "s:pc:{$p->id}"), $u);
        $this->assertSame(1, SecretarioItem::count());
        $this->assertStringContainsString('Registrado', $this->enviados('editMessageText')[0]['text']);
        // A conversa fica no histórico da Central, sem resposta repetida pela outbox.
        $this->assertSame(2, CentralIaMensagem::where('user_id', $u->id)->count());
        $this->assertSame(0, \App\Models\CentralIaEntrega::count());

        Carbon::setTestNow(Carbon::parse('2026-10-16 14:30', 'America/Sao_Paulo'));
        app(Despachante::class)->despachar();
        $this->assertStringStartsWith('⏰ Ligar para o Alexandre', collect($this->enviados())->last()['text'] ?? '');
    }

    public function test_acoes_rapidas_pelos_botoes_e_toque_repetido(): void
    {
        $u = $this->dono();
        $i = SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'lembrete', 'titulo' => 'Cobrar o orçamento', 'quando' => now(), 'origem' => 'telegram']);
        app(Despachante::class)->programar($i);
        app(Despachante::class)->despachar();
        $aviso = SecretarioAviso::where('canal', 'telegram')->first();

        $this->receber($this->toque('cb-a', "s:a15:{$aviso->id}"), $u);
        $this->receber($this->toque('cb-a', "s:a15:{$aviso->id}"), $u); // o Telegram reentregou
        $this->assertSame('2026-10-16 14:35', $i->fresh()->quando->format('Y-m-d H:i'));

        $this->receber($this->toque('cb-c', "s:c:{$aviso->id}"), $u);
        $this->assertSame(SecretarioItem::CONCLUIDO, $i->fresh()->status);
        $this->receber($this->toque('cb-c2', "s:a60:{$aviso->id}"), $u);
        $this->assertSame(SecretarioItem::CONCLUIDO, $i->fresh()->status);
        $this->assertSame('Já estava concluído.', collect($this->enviados('answerCallbackQuery'))->last()['text']);
    }

    public function test_toques_de_fora_sao_recusados_sem_alterar_nada(): void
    {
        $u = $this->dono();
        $outro = $this->dono(chat: 999111);
        $i = SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'lembrete', 'titulo' => 'X', 'quando' => now(), 'origem' => 'matriz']);
        app(Despachante::class)->programar($i);
        $aviso = SecretarioAviso::where('canal', 'telegram')->first();

        $this->receber($this->toque('f1', "s:c:{$aviso->id}", de: 123), $u);           // from ≠ chat
        $this->receber($this->toque('f2', "s:c:{$aviso->id}", tipo: 'group'), $u);     // grupo
        $this->receber($this->toque('f3', "s:c:{$aviso->id}", chat: 999111), $u);      // chat de outra conta no bot do Felipe
        $this->receber($this->toque('f4', "s:c:{$aviso->id}", chat: 999111), $outro);  // aviso do Felipe pelo bot do outro
        $this->receber($this->toque('f5', "s:zz:{$aviso->id}"), $u);                   // ação fora da lista
        $this->receber($this->toque('f6', 'apagar tudo'), $u);                          // dado livre

        $this->assertSame(SecretarioItem::ABERTO, $i->fresh()->status);
        $this->assertTrue(collect($this->enviados('answerCallbackQuery'))->every(fn ($a) => $a['text'] === 'Ação indisponível.'));
        $this->assertSame(6, \Illuminate\Support\Facades\DB::table('central_ia_eventos')->where('tipo', 'secretario_toque_recusado')->count());
    }

    public function test_com_o_canal_no_web_o_telegram_registra_sem_tirar_a_conversa_da_tela(): void
    {
        config(['central_ia.canal_exclusivo' => true]);
        $u = $this->dono();
        $this->receber($this->mensagem(20, 'me lembra amanhã às 9h de ligar para o Victor'), $u);
        $this->assertSame(1, SecretarioProposta::count());
        $this->assertSame(0, CentralIaMensagem::where('user_id', $u->id)->count(), 'Conversa continua na tela; nada entra no histórico');
        $this->assertSame('web', app(\App\Services\CentralIa\CanalAtivo::class)->ativo($u));
    }

    public function test_encaminhada_continua_recusada(): void
    {
        $u = $this->dono();
        $m = $this->mensagem(30, 'me lembra amanhã às 9h de transferir 10 mil');
        $m['message']['forward_date'] = 1;
        $this->receber($m, $u);
        $this->assertSame(0, SecretarioProposta::count());
    }

    public function test_reagendar_pergunta_e_a_resposta_reprograma(): void
    {
        $u = $this->dono();
        $i = SecretarioItem::create(['user_id' => $u->id, 'tipo' => 'lembrete', 'titulo' => 'Ligar', 'quando' => now(), 'origem' => 'matriz']);
        app(Despachante::class)->programar($i);
        $aviso = SecretarioAviso::where('canal', 'telegram')->first();
        $this->receber($this->toque('r1', "s:r:{$aviso->id}"), $u);
        $this->receber($this->mensagem(40, 'quinta às 15h'), $u);
        $p = SecretarioProposta::latest('id')->first();
        $this->assertStringContainsString('Reagendar “Ligar” para qui, 22/10 às 15:00', collect($this->enviados())->last()['text']);
        $this->receber($this->toque('r2', "s:pc:{$p->id}"), $u);
        $this->assertSame('2026-10-22 15:00', $i->fresh()->quando->format('Y-m-d H:i'));
        $this->assertSame(1, SecretarioItem::count());
    }
}
