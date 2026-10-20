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
    use BotProprioNosTestes, RefreshDatabase;

    private const CHAT = 5550001;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]); // Compatibilidade antes da ativação.
        // A suíte usa SQLite; a trava real MariaDB tem testes próprios.
        $this->mock(\App\Services\CentralIa\TelegramConsumerLock::class, function ($mock) {
            $mock->shouldReceive('acquire')->andReturn(true);
            $mock->shouldReceive('assertOwned')->andReturnNull();
            $mock->shouldReceive('release')->andReturnNull();
        });
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
            'api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['id' => self::CHAT, 'type' => 'private']]),
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
        $this->entregarAoBot(app(TelegramCanal::class), $this->update(random_int(1, 1_000_000), '/start '.$codigo, $chat));
    }

    private function textosEnviados(): array
    {
        return collect(Http::recorded())
            ->filter(fn ($par) => str_ends_with($par[0]->url(), '/sendMessage'))
            ->map(fn ($par) => $par[0]['text'])->values()->all();
    }

    /** T-374: cada pessoa usa o próprio bot; sem ele, não há para onde mandar. */
    public function test_sem_bot_cadastrado_a_tela_nao_gera_pareamento(): void
    {
        $this->actingAs($this->comCentral())->postJson(route('central-ia.telegram.codigo'))->assertUnprocessable();
        $this->assertSame(0, DB::table('central_ia_telegram_codigos')->count());
    }

    public function test_a_tela_gera_o_link_para_o_bot_da_pessoa_e_guarda_so_o_hash(): void
    {
        $user = $this->comCentral();
        $bot = $this->botNoTeste($user);

        $resposta = $this->actingAs($user)->postJson(route('central-ia.telegram.codigo'))->assertOk();

        $this->assertMatchesRegularExpression('#^https://t\.me/'.$bot->usuario.'\?start=[a-f0-9]{32}$#', $resposta->json('link'));
        $codigo = Str::after($resposta->json('link'), 'start=');
        $this->assertSame(hash('sha256', $codigo), DB::table('central_ia_telegram_codigos')->value('codigo_hash'));
    }

    private const TOKEN = '7012345678:AAHkT3stT0kenD3B0tDaCentral_abc-123';

    private function fakeBotFather(int $botId = 7012345678, string $usuario = 'central_da_ana_bot', string $webhook = '', int $statusMe = 200): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => $statusMe === 200
                ? Http::response(['ok' => true, 'result' => ['id' => $botId, 'is_bot' => true, 'username' => $usuario]])
                : Http::response(['ok' => false, 'description' => 'Unauthorized'], $statusMe),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => $webhook]]),
        ]);
    }

    public function test_cadastrar_o_bot_confere_no_telegram_guarda_cifrado_e_ja_devolve_o_pareamento(): void
    {
        $this->fakeBotFather();
        $ana = $this->comCentral();

        $r = $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertOk()
            ->assertJsonPath('bot', 'central_da_ana_bot')->assertJsonPath('vinculado', false);

        $this->assertMatchesRegularExpression('#^https://t\.me/central_da_ana_bot\?start=[a-f0-9]{32}$#', $r->json('pareamento.link'));
        $cru = DB::table('central_ia_telegram_bots')->value('token');
        $this->assertStringNotContainsString(self::TOKEN, $cru, 'O token mora cifrado no banco.');
        $this->assertSame(self::TOKEN, \App\Models\CentralIaTelegramBot::first()->token);
        Http::assertSent(fn (Request $q) => str_contains($q->url(), '/bot'.self::TOKEN.'/getMe'));
        $painel = $this->actingAs($ana)->getJson(route('central-ia.painel'))->assertJsonPath('telegram.bot', 'central_da_ana_bot');
        $this->assertStringNotContainsString(self::TOKEN, $painel->getContent(), 'O token nunca volta para a tela.');
    }

    public function test_token_errado_recusado_pelo_telegram_ou_com_webhook_nao_e_guardado(): void
    {
        Http::fake([
            'api.telegram.org/*/getMe' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Unauthorized'], 401)
                ->push(['ok' => true, 'result' => ['id' => 7012345678, 'is_bot' => true, 'username' => 'central_da_ana_bot']]),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://outro.sistema/hook']]),
        ]);
        $ana = $this->comCentral();

        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => 'nao-e-token'])->assertUnprocessable();
        Http::assertNothingSent();
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'não reconheceu'));
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'webhook'));

        $this->assertSame(0, \App\Models\CentralIaTelegramBot::count());
    }

    public function test_o_mesmo_bot_nao_serve_a_duas_contas(): void
    {
        $this->fakeBotFather();
        $this->actingAs($this->comCentral())->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertOk();

        $this->actingAs($this->comCentral())->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertUnprocessable()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'outra conta'));
        $this->assertSame(1, \App\Models\CentralIaTelegramBot::count());
    }

    public function test_trocar_de_bot_desfaz_o_vinculo_e_renovar_o_token_do_mesmo_bot_mantem(): void
    {
        $eu = fn (int $id, string $u) => ['ok' => true, 'result' => ['id' => $id, 'is_bot' => true, 'username' => $u]];
        Http::fake([
            'api.telegram.org/*/getMe' => Http::sequence()->push($eu(7012345678, 'central_da_ana_bot'))
                ->push($eu(7012345678, 'central_da_ana_bot'))->push($eu(7099999999, 'outro_da_ana_bot')),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['id' => self::CHAT, 'type' => 'private']]),
        ]);
        $ana = $this->comCentral();
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertOk();
        CentralIaTelegramVinculo::create(['user_id' => $ana->id, 'chat_id' => self::CHAT]);

        // Token renovado no @BotFather: mesmo bot, a conversa continua ligada.
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => '7012345678:BBnov0T0kenD0MesmoB0tDaCentral_xyz'])->assertOk()
            ->assertJsonPath('vinculado', true)->assertJsonPath('pareamento', null);
        $this->assertSame(1, CentralIaTelegramVinculo::count());

        // Outro bot: a pessoa ainda não tocou em Começar nele.
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => '7099999999:CCoutr0B0tDaAnaParaACentral_qwe12'])->assertOk()
            ->assertJsonPath('vinculado', false);
        $this->assertSame(0, CentralIaTelegramVinculo::count());
        $this->assertSame('outro_da_ana_bot', \App\Models\CentralIaTelegramBot::value('usuario'));
    }

    /**
     * Revisão da T-374: quem pareou com o bot antigo (o do .env) e cadastra
     * agora o próprio bot só continua vinculado se esse bot já conversou com
     * o chat — bot que a pessoa nunca abriu não consegue escrever primeiro.
     */
    public function test_vinculo_herdado_so_continua_se_o_bot_novo_conhece_o_chat(): void
    {
        $eu = fn (int $id, string $u) => ['ok' => true, 'result' => ['id' => $id, 'is_bot' => true, 'username' => $u]];
        Http::fake([
            'api.telegram.org/*/getMe' => Http::sequence()->push($eu(7012345678, 'central_da_ana_bot'))->push($eu(7099999999, 'central_do_bruno_bot')),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getChat' => Http::sequence()
                ->push(['ok' => true, 'result' => ['id' => self::CHAT, 'type' => 'private']])
                ->push(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400),
        ]);
        $ana = $this->comCentral();
        $bruno = $this->comCentral();
        CentralIaTelegramVinculo::create(['user_id' => $ana->id, 'chat_id' => self::CHAT]);
        CentralIaTelegramVinculo::create(['user_id' => $bruno->id, 'chat_id' => self::CHAT + 1]);
        // Vínculos do bot antigo, de antes de cada pessoa ter o seu: nenhum bot cadastrado ainda.
        \App\Models\CentralIaTelegramBot::query()->delete();

        Http::preventStrayRequests();
        // O mesmo bot que ela já usava (caso do Felipe): a conversa continua ligada.
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertOk()->assertJsonPath('vinculado', true);
        // Um bot que o Bruno nunca abriu: precisa tocar em Começar nele.
        $this->actingAs($bruno)->putJson(route('central-ia.telegram.bot'), ['token' => '7099999999:CCoutr0B0tDoBrunoParaACentral_qwe1'])->assertOk()
            ->assertJsonPath('vinculado', false);
        $this->assertSame([$ana->id], CentralIaTelegramVinculo::pluck('user_id')->all());
    }

    public function test_retirar_o_bot_para_a_conversa_pelo_telegram(): void
    {
        $this->fakeTelegram();
        $ana = $this->comCentral();
        $this->parear($ana);

        $this->actingAs($ana)->deleteJson(route('central-ia.telegram.bot.remover'))->assertOk();

        $this->assertSame(0, \App\Models\CentralIaTelegramBot::count());
        $this->assertSame(0, CentralIaTelegramVinculo::count());
        $this->actingAs($ana)->getJson(route('central-ia.painel'))->assertJsonPath('telegram.bot', null)->assertJsonPath('telegram.vinculado', false);
    }

    /** O código de uma conta não liga o bot de outra, e o bot de Ana não ouve o chat de Bruno. */
    public function test_cada_bot_so_atende_o_proprio_dono(): void
    {
        $this->fakeTelegram();
        $ana = $this->comCentral();
        $bruno = $this->comCentral();
        $botDaAna = $this->botNoTeste($ana);
        $this->parear($bruno, 5550009);

        $codigoDoBruno = app(TelegramCanal::class)->gerarCodigo($bruno);
        app(TelegramCanal::class)->receber($this->update(70, '/start '.$codigoDoBruno, 5550009), $botDaAna);
        app(TelegramCanal::class)->receber($this->update(71, 'pelo bot errado', 5550009), $botDaAna);

        $this->assertSame([$bruno->id], CentralIaTelegramVinculo::pluck('user_id')->all());
        $this->assertSame(0, CentralIaMensagem::count());
        Http::assertSent(fn (Request $q) => str_contains($q->url(), '/bot'.$botDaAna->token.'/sendMessage') && str_contains($q['text'], 'inválido'));
    }

    public function test_start_com_o_codigo_pareia_e_o_codigo_nao_serve_de_novo(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $codigo = app(TelegramCanal::class)->gerarCodigo($user);

        $this->entregarAoBot(app(TelegramCanal::class), $this->update(1, '/start '.$codigo));

        $this->assertSame(self::CHAT, CentralIaTelegramVinculo::where('user_id', $user->id)->value('chat_id'));
        $this->assertStringContainsString('agora é a sua conversa', $this->textosEnviados()[0]);
        $this->assertSame(0, CentralIaMensagem::count(), 'O /start nunca vira texto da conversa.');

        $this->entregarAoBot(app(TelegramCanal::class), $this->update(2, '/start '.$codigo, 5550002));
        $this->assertSame(1, CentralIaTelegramVinculo::count());
    }

    public function test_codigo_vencido_nao_pareia(): void
    {
        $this->fakeTelegram();
        $user = $this->comCentral();
        $codigo = app(TelegramCanal::class)->gerarCodigo($user);
        $this->travel(11)->minutes();

        $this->entregarAoBot(app(TelegramCanal::class), $this->update(1, '/start '.$codigo));

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

        $this->entregarAoBot(app(TelegramCanal::class), $this->update(10, 'Anotação: rever o contrato'));
        $this->entregarAoBot(app(TelegramCanal::class), $this->update(10, 'Anotação: rever o contrato'));

        $this->assertSame(1, CentralIaMensagem::count());
        $mensagem = CentralIaMensagem::first();
        $this->assertSame($user->id, $mensagem->user_id);
        $this->assertSame('telegram', $mensagem->origem);
        $this->assertSame(0, CentralIaEntrega::count(), 'O que veio do Telegram não volta para ele.');

        $this->actingAs($user)->getJson(route('central-ia.mensagens'))
            ->assertJsonPath('mensagens.0.conteudo', 'Anotação: rever o contrato');
        $this->actingAs($user)->getJson(route('central-ia.painel'))->assertJsonPath('telegram.vinculado', true);
    }

    public function test_grupo_remetente_diferente_e_chat_desconhecido_nao_entram(): void
    {
        $this->fakeTelegram();
        $this->parear($this->comCentral());

        $this->entregarAoBot(app(TelegramCanal::class), $this->update(20, 'em grupo', tipo: 'group'));
        $this->entregarAoBot(app(TelegramCanal::class), $this->update(21, 'outro remetente', de: 777));
        $this->entregarAoBot(app(TelegramCanal::class), $this->update(22, 'quem sou eu?', chat: 999));

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

        $this->entregarAoBot(app(TelegramCanal::class), $this->update(30, 'ainda entro?'));

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

    /**
     * Falha de CONEXÃO quer dizer que nada saiu: volta para a fila e sai na
     * próxima volta (13/10/2026: a resposta de um assunto ficou incerta num
     * soluço de rede e a pessoa perguntou "travou?").
     */
    public function test_falha_de_conexao_volta_para_a_fila_e_sai_depois(): void
    {
        $this->fakeTelegram(extra: ['api.telegram.org/*/sendMessage' => Http::sequence()
            ->push(['ok' => true, 'result' => ['message_id' => 1]])
            ->pushFailedConnection('cURL error 7: Failed to connect to api.telegram.org port 443')
            ->push(['ok' => true, 'result' => ['message_id' => 2]])]);
        $user = $this->comCentral();
        $this->parear($user);
        app(ConversaService::class)->registrarDoUsuario($user, 'tem que chegar', CentralIaMensagem::ORIGEM_WEB);

        app(TelegramCanal::class)->entregarPendentes();
        $this->assertSame(CentralIaEntrega::PENDENTE, CentralIaEntrega::value('status'));
        app(TelegramCanal::class)->entregarPendentes();
        $this->assertSame(CentralIaEntrega::ENTREGUE, CentralIaEntrega::value('status'));
        $this->assertSame(2, (int) CentralIaEntrega::value('tentativas'));
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
        $this->actingAs($user)->getJson(route('central-ia.painel'))->assertJsonPath('telegram.vinculado', false);
    }

    public function test_o_bot_le_os_updates_e_o_cursor_anda(): void
    {
        $this->fakeTelegram([$this->update(41, 'segunda'), $this->update(40, 'primeira')]);
        $user = $this->comCentral();
        $this->parear($user);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        $this->assertSame(['primeira', 'segunda'], CentralIaMensagem::orderBy('id')->pluck('conteudo')->all());
        $this->assertSame(42, \App\Models\CentralIaTelegramBot::where('user_id', $user->id)->value('proximo_update_id'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates') && $r['offset'] === 0);
    }

    /** Dois bots, duas pessoas: cada update entra na conversa do dono do bot que o recebeu. */
    public function test_o_processo_ouve_o_bot_de_cada_pessoa(): void
    {
        $ana = $this->comCentral();
        $bruno = $this->comCentral();
        $botAna = $this->botNoTeste($ana);
        $botBruno = $this->botNoTeste($bruno);
        CentralIaTelegramVinculo::create(['user_id' => $ana->id, 'chat_id' => 6001]);
        CentralIaTelegramVinculo::create(['user_id' => $bruno->id, 'chat_id' => 6002]);
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/bot'.$botAna->token.'/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(10, 'da Ana', 6001)]]),
            'api.telegram.org/bot'.$botBruno->token.'/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(10, 'do Bruno', 6002)]]),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        $this->assertSame('da Ana', CentralIaMensagem::where('user_id', $ana->id)->value('conteudo'));
        $this->assertSame('do Bruno', CentralIaMensagem::where('user_id', $bruno->id)->value('conteudo'));
        $this->assertSame([11, 11], \App\Models\CentralIaTelegramBot::orderBy('id')->pluck('proximo_update_id')->all());
    }

    /**
     * Revisão da T-374: a pessoa troca de bot pela tela enquanto o processo lê
     * o antigo. O cursor do antigo não pode ir para a linha do novo — o
     * Telegram daria por lido o /start do bot novo.
     */
    public function test_bot_trocado_no_meio_do_ciclo_nao_herda_o_cursor_do_antigo(): void
    {
        $ana = $this->comCentral();
        $bot = $this->botNoTeste($ana);
        CentralIaTelegramVinculo::create(['user_id' => $ana->id, 'chat_id' => self::CHAT]);
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getUpdates' => function () use ($bot) {
                // A tela grava o bot novo enquanto o pedido ao Telegram está no ar.
                \App\Models\CentralIaTelegramBot::whereKey($bot->id)->update(['telegram_bot_id' => 7099999999, 'usuario' => 'novo_da_ana_bot',
                    'proximo_update_id' => 0, 'updated_at' => now()->addSecond()]);

                return Http::response(['ok' => true, 'result' => [$this->update(500, 'pelo bot antigo')]]);
            },
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 1]]),
        ]);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        $this->assertSame([7099999999, 0], [$bot->fresh()->telegram_bot_id, $bot->fresh()->proximo_update_id]);
    }

    /** Revisão da T-374: o update que explode no bot de uma pessoa não para os bots das outras. */
    public function test_update_com_erro_so_para_o_bot_de_quem_o_mandou(): void
    {
        $ana = $this->comCentral();
        $bruno = $this->comCentral();
        $botAna = $this->botNoTeste($ana);
        $botBruno = $this->botNoTeste($bruno);
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(10, 'oi')]]),
        ]);
        $this->partialMock(TelegramCanal::class, fn ($m) => $m->shouldReceive('receber')->andReturnUsing(function ($update, $bot) use ($ana) {
            if ($bot->user_id === $ana->id) throw new \RuntimeException('update ruim');
        }));

        $this->artisan('central-ia:telegram', ['--uma-vez' => true]);

        $this->assertSame(0, $botAna->fresh()->proximo_update_id, 'o update da Ana é tentado de novo');
        $this->assertSame(11, $botBruno->fresh()->proximo_update_id, 'o bot do Bruno foi lido no mesmo ciclo');
    }

    /**
     * Revisão da T-374: com o bot de outra pessoa recebendo, a volta segue sem
     * pausa, e as três tentativas do update ruim saíam em segundos. Depois de
     * uma falha, o bot dele fica 5 s de lado.
     */
    public function test_update_com_erro_espera_antes_de_ser_tentado_de_novo(): void
    {
        $ana = $this->comCentral();
        $bruno = $this->comCentral();
        $botAna = $this->botNoTeste($ana);
        $this->botNoTeste($bruno);
        $proximo = 10;
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getUpdates' => function () use (&$proximo) { return Http::response(['ok' => true, 'result' => [$this->update($proximo++, 'oi')]]); },
        ]);
        $this->partialMock(TelegramCanal::class, fn ($m) => $m->shouldReceive('receber')->andReturnUsing(function ($update, $bot) use ($ana) {
            if ($bot->user_id === $ana->id) throw new \RuntimeException('update ruim');
        }));

        // Duas voltas no MESMO processo, uma colada na outra, como no laço.
        $cmd = $this->app->make(\App\Console\Commands\CentralIaTelegram::class);
        $cmd->setLaravel($this->app);
        $cmd->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $cmd->getDefinition()));
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));
        $ciclo = new \ReflectionMethod($cmd, 'ciclo');
        $args = [app(\App\Services\CentralIa\TelegramApi::class), app(TelegramCanal::class), app(\App\Services\CentralIa\TelegramConsumerLock::class)];
        $ciclo->invoke($cmd, ...$args);
        $ciclo->invoke($cmd, ...$args);

        $leiturasDaAna = collect(Http::recorded())->filter(fn ($par) => str_contains($par[0]->url(), $botAna->token.'/getUpdates'))->count();
        $this->assertSame(1, $leiturasDaAna, 'o bot da Ana ficou de lado na volta seguinte');
        $this->assertSame(0, $botAna->fresh()->proximo_update_id, 'e o update dele continua para ser tentado');
    }

    /**
     * 3ª revisão da T-374: passados os 5 s de lado, o mesmo update é tentado
     * de novo; na terceira falha ele é deixado para trás, o cursor anda e o
     * descarte fica no log da pessoa.
     */
    public function test_update_com_erro_volta_depois_dos_5_s_e_e_descartado_na_terceira_falha(): void
    {
        $ana = $this->comCentral();
        $botAna = $this->botNoTeste($ana);
        Http::fake([
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getUpdates' => fn () => Http::response(['ok' => true, 'result' => [$this->update(10, 'oi')]]),
        ]);
        $this->partialMock(TelegramCanal::class, fn ($m) => $m->shouldReceive('receber')->andThrow(new \RuntimeException('update ruim')));

        $cmd = $this->app->make(\App\Console\Commands\CentralIaTelegram::class);
        $cmd->setLaravel($this->app);
        $cmd->setInput(new \Symfony\Component\Console\Input\ArrayInput([], $cmd->getDefinition()));
        $cmd->setOutput(new \Illuminate\Console\OutputStyle(new \Symfony\Component\Console\Input\ArrayInput([]), new \Symfony\Component\Console\Output\BufferedOutput));
        $ciclo = new \ReflectionMethod($cmd, 'ciclo');
        $deLado = new \ReflectionProperty($cmd, 'deLado');
        $args = [app(\App\Services\CentralIa\TelegramApi::class), app(TelegramCanal::class), app(\App\Services\CentralIa\TelegramConsumerLock::class)];
        $leituras = fn () => collect(Http::recorded())->filter(fn ($par) => str_contains($par[0]->url(), $botAna->token.'/getUpdates'))->count();
        $descartes = fn () => \Illuminate\Support\Facades\DB::table('central_ia_eventos')->where('user_id', $ana->id)->where('tipo', 'telegram_update_descartado')->count();

        foreach ([1, 2] as $tentativa) {
            $ciclo->invoke($cmd, ...$args);
            $this->assertSame($tentativa, $leituras());
            $this->assertSame(0, $botAna->fresh()->proximo_update_id, "falha {$tentativa}: o update fica para ser tentado");
            $this->assertSame(0, $descartes());
            // Os 5 s de lado passam (o comando mede pelo relógio do processo).
            $this->assertGreaterThan(time(), $deLado->getValue($cmd)[$botAna->id] ?? 0, 'o bot fica de lado depois da falha');
            $deLado->setValue($cmd, [$botAna->id => time() - 1]);
        }

        $ciclo->invoke($cmd, ...$args);
        $this->assertSame(3, $leituras(), 'a terceira tentativa aconteceu');
        $this->assertSame(11, $botAna->fresh()->proximo_update_id, 'na terceira falha o cursor anda');
        $this->assertSame(1, $descartes(), 'e o descarte fica no log');
    }

    /**
     * Revisão da T-374: o número do update é de cada bot. Trocado o bot, os
     * números do antigo saem, e o update 10 do novo não some como repetido.
     */
    public function test_trocar_de_bot_libera_os_numeros_de_update_do_antigo(): void
    {
        $eu = fn (int $id, string $u) => ['ok' => true, 'result' => ['id' => $id, 'is_bot' => true, 'username' => $u]];
        Http::fake([
            'api.telegram.org/*/getMe' => Http::sequence()->push($eu(7012345678, 'central_da_ana_bot'))->push($eu(7099999999, 'outro_da_ana_bot')),
            'api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]),
            'api.telegram.org/*/getChat' => Http::response(['ok' => true, 'result' => ['id' => self::CHAT, 'type' => 'private']]),
        ]);
        $ana = $this->comCentral();
        $outro = $this->comCentral();
        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => self::TOKEN])->assertOk();
        app(ConversaService::class)->registrarDoUsuario($ana, 'pelo bot antigo', 'telegram', telegramUpdateId: 10);
        app(ConversaService::class)->registrarDoUsuario($outro, 'de outra pessoa', 'telegram', telegramUpdateId: 10);

        $this->actingAs($ana)->putJson(route('central-ia.telegram.bot'), ['token' => '7099999999:CCoutr0B0tDaAnaParaACentral_qwe12'])->assertOk();

        $this->assertNull(CentralIaMensagem::where('user_id', $ana->id)->value('telegram_update_id'));
        $this->assertSame(10, CentralIaMensagem::where('user_id', $outro->id)->value('telegram_update_id'), 'só os números da Ana');
    }

    /** O ambiente local roda com cópia do banco: os bots de lá não são lidos aqui. */
    public function test_bot_cadastrado_em_outro_servidor_nao_e_lido(): void
    {
        $this->fakeTelegram();
        $this->botNoTeste($this->comCentral())->forceFill(['servidor' => hash('sha256', 'https://outro.servidor')])->save();

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_outro_consumidor_no_mesmo_bot_e_avisado_e_so_ele_fica_de_lado(): void
    {
        $this->fakeTelegram(extra: ['api.telegram.org/*/getUpdates' => Http::response(['ok' => false], 409)]);
        $this->botNoTeste($this->comCentral());

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])
            ->expectsOutputToContain('Outro consumidor')
            ->assertFailed();
        $this->assertSame('conflito', \App\Models\CentralIaTelegramBot::value('situacao'));
    }

    public function test_bot_com_webhook_nao_e_tocado(): void
    {
        $this->fakeTelegram(extra: ['api.telegram.org/*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => 'https://x.test/hook']])]);
        $this->botNoTeste($this->comCentral());

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertFailed();

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates'));
        $this->assertSame('webhook', \App\Models\CentralIaTelegramBot::value('situacao'));
    }

    /** Token revogado no @BotFather: o bot sai da leitura e a tela pede o novo. */
    public function test_token_revogado_tira_o_bot_da_leitura(): void
    {
        $this->fakeTelegram(extra: ['api.telegram.org/*/getUpdates' => Http::response(['ok' => false], 401)]);
        $user = $this->comCentral();
        $this->botNoTeste($user);

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertFailed();

        $this->assertSame('token_invalido', \App\Models\CentralIaTelegramBot::value('situacao'));
        $this->actingAs($user)->getJson(route('central-ia.painel'))->assertJsonPath('telegram.bot_situacao', 'token_invalido');
    }

    public function test_sem_bot_nenhum_o_processo_fica_de_pe_sem_chamar_o_telegram(): void
    {
        Http::fake();

        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_trava_recusada_nao_faz_nem_a_primeira_chamada_http(): void
    {
        $this->fakeTelegram();
        $this->mock(\App\Services\CentralIa\TelegramConsumerLock::class, function ($mock) {
            $mock->shouldReceive('acquire')->once()->andReturn(false);
            $mock->shouldReceive('release')->once()->andReturnNull();
        });
        $this->artisan('central-ia:telegram', ['--uma-vez' => true])->assertFailed();
        Http::assertNothingSent();
    }
}
