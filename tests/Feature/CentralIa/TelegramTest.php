<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaEntrega;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\ConversaService;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * O Telegram como segundo canal da mesma conversa.
 *
 * A API do bot é simulada; o que se confere é o que o AlfaMatriz faz com o
 * que chega e o que ele manda — e, sobretudo, o que ele se recusa a fazer.
 */
class TelegramTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 5550001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.telegram.token' => 'token-de-teste']);
    }

    private function comCentral(): User
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $user;
    }

    private function fakeTelegram(array $updates = [], array $extra = []): void
    {
        Http::fake($extra + [
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => $updates]),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 99]]),
        ]);
    }

    private function update(int $id, string $texto, int $chat = self::CHAT, string $tipo = 'private', ?int $de = null): array
    {
        return ['update_id' => $id, 'message' => [
            'message_id' => $id, 'text' => $texto,
            'chat' => ['id' => $chat, 'type' => $tipo],
            'from' => ['id' => $de ?? $chat, 'is_bot' => false, 'username' => 'felipe'],
        ]];
    }

    private function parear(User $user, int $chat = self::CHAT): void
    {
        $codigo = app(TelegramCanal::class)->gerarCodigo($user);
        app(TelegramCanal::class)->receber($this->update(random_int(1, 1_000_000), '/start '.$codigo, $chat));
    }

    private function textosEnviados(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($par) => str_ends_with($par[0]->url(), '/sendMessage'))
            ->map(fn ($par) => $par[0]['text'])->values()->all();
    }

    public function test_a_tela_gera_o_link_de_pareamento_e_guarda_so_o_hash(): void
    {
        $user = $this->comCentral();

        $resposta = $this->actingAs($user)->postJson(route('central-ia.telegram.codigo'))->assertOk();

        $this->assertMatchesRegularExpression('#^https://t\.me/GrupoSolucoesAgentBot\?start=[a-f0-9]{32}$#', $resposta->json('link'));
        $codigo = Str::after($resposta->json('link'), 'start=');
        $this->assertSame(hash('sha256', $codigo), DB::table('central_ia_telegram_codigos')->value('codigo_hash'));
    }

    public function test_start_com_o_codigo_pareia_e_o_codigo_nao_serve_de_novo(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $codigo = app(TelegramCanal::class)->gerarCodigo($user);

        app(TelegramCanal::class)->receber($this->update(1, '/start '.$codigo));

        $this->assertSame(self::CHAT, CentralIaTelegramVinculo::where('user_id', $user->id)->value('chat_id'));
        $this->assertStringContainsString('agora é a sua conversa', $this->textosEnviados()[0]);
        $this->assertSame(0, CentralIaMensagem::count(), 'O /start nunca vira texto da conversa.');

        app(TelegramCanal::class)->receber($this->update(2, '/start '.$codigo, 5550002));
        $this->assertSame(1, CentralIaTelegramVinculo::count());
    }

    public function test_codigo_vencido_nao_pareia(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $codigo = app(TelegramCanal::class)->gerarCodigo($user);
        $this->travel(11)->minutes();

        app(TelegramCanal::class)->receber($this->update(1, '/start '.$codigo));

        $this->assertSame(0, CentralIaTelegramVinculo::count());
        $this->assertStringContainsString('vencido', $this->textosEnviados()[0]);
    }

    public function test_chat_ja_ligado_a_outra_conta_nao_e_tomado(): void
    {
        $this->fakeTelegram();
        $felipe = $this->comCentral();
        $rossini = $this->comCentral();
        $this->parear($felipe);

        $this->parear($rossini);

        $this->assertSame($felipe->id, CentralIaTelegramVinculo::where('chat_id', self::CHAT)->value('user_id'));
        $this->assertSame(1, CentralIaTelegramVinculo::count());
    }

    public function test_mensagem_do_chat_pareado_entra_na_conversa_uma_vez_so(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $this->parear($user);

        app(TelegramCanal::class)->receber($this->update(10, 'Resuma o dia'));
        app(TelegramCanal::class)->receber($this->update(10, 'Resuma o dia'));

        $this->assertSame(1, CentralIaMensagem::count());
        $mensagem = CentralIaMensagem::first();
        $this->assertSame($user->id, $mensagem->user_id);
        $this->assertSame('telegram', $mensagem->origem);
        $this->assertSame(0, CentralIaEntrega::count(), 'O que veio do Telegram não volta para ele.');

        $this->actingAs($user)->getJson(route('central-ia.mensagens'))
            ->assertJsonPath('mensagens.0.conteudo', 'Resuma o dia')
            ->assertJsonPath('telegram.vinculado', true);
    }

    public function test_grupo_remetente_diferente_e_chat_desconhecido_nao_entram(): void
    {
        $this->fakeTelegram();
        $this->parear($this->comCentral());

        app(TelegramCanal::class)->receber($this->update(20, 'em grupo', tipo: 'group'));
        app(TelegramCanal::class)->receber($this->update(21, 'outro remetente', de: 777));
        app(TelegramCanal::class)->receber($this->update(22, 'quem sou eu?', chat: 999));

        $this->assertSame(0, CentralIaMensagem::count());
        $this->assertStringContainsString('não está ligado', last($this->textosEnviados()));
    }

    /**
     * O vínculo não é permissão: tirar o perfil corta o Telegram na hora.
     */
    public function test_quem_perde_o_perfil_para_de_falar_pelo_telegram(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $this->parear($user);
        $user->perfis()->detach(Perfil::where('slug', 'central_ia')->value('id'));

        app(TelegramCanal::class)->receber($this->update(30, 'ainda entro?'));

        $this->assertSame(0, CentralIaMensagem::count());
        $this->assertStringContainsString('não tem acesso', last($this->textosEnviados()));
    }

    public function test_mensagem_da_tela_vai_ao_telegram_pelo_processo_do_bot(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $this->parear($user);

        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'Do computador', 'client_id' => (string) Str::uuid()])->assertCreated();
        $this->assertSame(CentralIaEntrega::PENDENTE, CentralIaEntrega::value('status'));

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        $this->assertSame(CentralIaEntrega::ENTREGUE, CentralIaEntrega::value('status'));
        $this->assertSame(99, (int) CentralIaEntrega::value('telegram_message_id'));
        $this->assertStringContainsString('Do computador', last($this->textosEnviados()));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === self::CHAT);
    }

    /**
     * 5xx pode ser "chegou e a resposta se perdeu". Incerta não sai de novo.
     */
    public function test_entrega_incerta_nao_e_reenviada(): void
    {
        // A primeira resposta é a do pareamento; a segunda, a da entrega.
        $this->fakeTelegram(extra: ['api.telegram.org/*/sendMessage' => Http::sequence()
            ->push(['ok' => true, 'result' => ['message_id' => 1]])
            ->push(['ok' => false], 502)]);
        $user = $this->comCentral();
        $this->parear($user);
        app(ConversaService::class)->registrarDoUsuario($user, 'talvez chegue', CentralIaMensagem::ORIGEM_WEB);

        app(TelegramCanal::class)->entregarPendentes();
        app(TelegramCanal::class)->entregarPendentes();

        $this->assertSame(CentralIaEntrega::INCERTA, CentralIaEntrega::value('status'));
        $this->assertSame(1, (int) CentralIaEntrega::value('tentativas'));
    }

    public function test_entrega_presa_em_enviando_vira_incerta(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $this->parear($user);
        app(ConversaService::class)->registrarDoUsuario($user, 'processo caiu', CentralIaMensagem::ORIGEM_WEB);
        CentralIaEntrega::query()->update(['status' => CentralIaEntrega::ENVIANDO]);
        $this->travel(3)->minutes();

        app(TelegramCanal::class)->entregarPendentes();

        $this->assertSame(CentralIaEntrega::INCERTA, CentralIaEntrega::value('status'));
    }

    public function test_desvincular_cancela_o_que_ia_para_o_chat(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $this->parear($user);
        app(ConversaService::class)->registrarDoUsuario($user, 'não vai mais', CentralIaMensagem::ORIGEM_WEB);

        $this->actingAs($user)->deleteJson(route('central-ia.telegram.desvincular'))->assertOk();

        $this->assertSame(0, CentralIaTelegramVinculo::count());
        $this->assertSame(CentralIaEntrega::FALHOU, CentralIaEntrega::value('status'));
        $this->actingAs($user)->getJson(route('central-ia.mensagens'))->assertJsonPath('telegram.vinculado', false);
    }

    public function test_o_bot_le_os_updates_e_o_cursor_anda(): void
    {
        $this->fakeTelegram([$this->update(41, 'segunda'), $this->update(40, 'primeira')]);
        $user = $this->comCentral();
        $this->parear($user);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        $this->assertSame(['primeira', 'segunda'], CentralIaMensagem::orderBy('id')->pluck('conteudo')->all());
        $this->assertSame(42, (int) DB::table('central_ia_telegram_cursor')->value('proximo_update_id'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates') && $r['offset'] === 0);
    }

    public function test_outro_consumidor_no_mesmo_bot_e_avisado_e_nada_e_lido(): void
    {
        $this->fakeTelegram(extra: ['api.telegram.org/*/getUpdates' => Http::response(['ok' => false], 409)]);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])
            ->expectsOutputToContain('Outro consumidor')
            ->assertFailed();
    }

    public function test_bot_com_webhook_nao_e_tocado(): void
    {
        $this->fakeTelegram(extra: ['api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://x.test/hook']])]);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates'));
    }

    public function test_sem_token_o_bot_nao_sobe(): void
    {
        config(['central_ia.telegram.token' => null]);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertFailed();
    }
}
