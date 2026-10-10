<?php

namespace Tests\Feature;

use App\Jobs\DeliverTelegramMessage;
use App\Models\ChannelBinding;
use App\Models\Message;
use App\Models\MessageDelivery;
use App\Models\PairingCode;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\TelegramClient;
use App\Services\TelegramIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central.telegram_enabled' => true, 'central.telegram_token' => 'test-token-not-real']);
        Http::preventStrayRequests();
        Queue::fake();
    }

    private function user(): User
    {
        return User::factory()->create(['password' => Hash::make('test-password-1234')]);
    }

    private function bind(User $user): ChannelBinding
    {
        $c = app(ConversationService::class)->forUser($user);

        return ChannelBinding::create(['user_id' => $user->id, 'conversation_id' => $c->id, 'telegram_user_id' => '123', 'chat_id' => '123']);
    }

    private function update(int $id, string $text, int $sender = 123, string $type = 'private'): array
    {
        return ['update_id' => $id, 'message' => ['message_id' => $id, 'from' => ['id' => $sender, 'is_bot' => false], 'chat' => ['id' => $sender, 'type' => $type], 'text' => $text]];
    }

    public function test_guest_cannot_read_send_or_pair(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->getJson('/messages')->assertUnauthorized();
        $this->postJson('/messages', ['content' => 'x', 'client_id' => (string) Str::uuid()])->assertUnauthorized();
        $this->postJson('/telegram/pair')->assertUnauthorized();
        $this->get('/register')->assertNotFound();
    }

    public function test_local_login_disabled_and_authenticated_logout_safe(): void
    {
        $u = $this->user();
        $this->post('/login', ['email' => $u->email, 'password' => 'test-password-1234'])->assertForbidden();
        $this->assertGuest();
        $this->actingAs($u)->get('/')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_web_message_is_persistent_idempotent_and_cannot_spoof_agent(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $id = (string) Str::uuid();
        $this->postJson('/messages', ['content' => 'Planejar a central', 'client_id' => $id, 'actor_type' => 'agent', 'origin' => 'telegram'])->assertCreated();
        $this->postJson('/messages', ['content' => 'Outra mensagem', 'client_id' => $id])->assertCreated();
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['content' => 'Planejar a central', 'actor_type' => 'user', 'origin' => 'web']);
        $this->getJson('/messages')->assertOk()->assertJsonPath('messages.0.content', 'Planejar a central');
        $this->postJson('/messages', ['content' => '   ', 'client_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/messages', ['content' => str_repeat('a', 3001), 'client_id' => (string) Str::uuid()])->assertUnprocessable();
        $this->postJson('/messages', ['content' => 'x', 'client_id' => 'bad'])->assertUnprocessable();
    }

    public function test_history_cursors_and_user_isolation(): void
    {
        $u = $this->user();
        $other = $this->user();
        $svc = app(ConversationService::class);
        for ($i = 0; $i < 55; $i++) {
            $svc->post($u, 'Mensagem '.$i, (string) Str::uuid());
        }
        $svc->post($other, 'Privada', (string) Str::uuid());
        $this->actingAs($u);
        $data = $this->getJson('/messages')->assertOk()->json('messages');
        $this->assertCount(50, $data);
        $this->assertSame('Mensagem 5', $data[0]['content']);
        $this->getJson('/messages?before='.$data[0]['id'])->assertJsonCount(5, 'messages');
        $this->getJson('/messages?after='.$data[49]['id'])->assertJsonCount(0, 'messages');
        $this->getJson('/messages')->assertDontSee('Privada');
    }

    public function test_pairing_is_hashed_private_expiring_and_single_use(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $command = $this->postJson('/telegram/pair')->assertOk()->json('command');
        $code = substr($command, 7);
        $this->assertNotSame($code, PairingCode::first()->code_hash);
        $ing = app(TelegramIngestor::class);
        $ing->ingest($this->update(1, $command, 123, 'group'));
        $this->assertDatabaseCount('channel_bindings', 0);
        $ing->ingest($this->update(2, $command));
        $this->assertDatabaseCount('channel_bindings', 0);
        $this->postJson('/telegram/pair/confirm', ['chat_id' => '123'])->assertNoContent();
        $this->assertDatabaseCount('channel_bindings', 1);
        $this->assertDatabaseCount('pairing_codes', 0);
        $ing->ingest($this->update(3, $command, 456));
        $this->assertDatabaseCount('channel_bindings', 1);
        $this->assertDatabaseCount('messages', 0);
        $this->postJson('/telegram/pair')->assertConflict();
    }

    public function test_expired_pairing_and_unbound_sender_are_rejected(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $command = $this->postJson('/telegram/pair')->json('command');
        PairingCode::query()->update(['expires_at' => now()->subMinute()]);
        $ing = app(TelegramIngestor::class);
        $ing->ingest($this->update(1, $command));
        $ing->ingest($this->update(2, 'Mensagem invasora', 456));
        $this->assertDatabaseCount('channel_bindings', 0);
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_inbound_duplicate_and_bot_echo_do_not_create_extra_messages(): void
    {
        $u = $this->user();
        $this->bind($u);
        $ing = app(TelegramIngestor::class);
        $update = $this->update(10, 'Do celular');
        $ing->ingest($update);
        $ing->ingest($update);
        $echo = $this->update(11, 'Eco');
        $echo['message']['from']['is_bot'] = true;
        $ing->ingest($echo);
        $ing->ingest($this->update(12, 'Outro usuário', 456));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('message_deliveries', 0);
        $this->actingAs($u)->getJson('/messages')->assertJsonPath('messages.0.origin', 'telegram');
    }

    public function test_web_delivery_is_queued_and_job_is_idempotent(): void
    {
        $u = $this->user();
        $this->bind($u);
        $m = app(ConversationService::class)->post($u, 'Do VS Code', (string) Str::uuid());
        Queue::assertPushed(DeliverTelegramMessage::class);
        $d = MessageDelivery::first();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]])]);
        $job = new DeliverTelegramMessage($d->id);
        $job->handle(app(TelegramClient::class));
        $job->handle(app(TelegramClient::class));
        Http::assertSentCount(1);
        $this->assertSame('delivered', $d->fresh()->status);
        Http::assertSent(fn ($request) => $request['chat_id'] === '123' && str_contains($request['text'], 'Do VS Code'));
        $this->actingAs($u)->getJson('/messages?after='.$m->id)->assertJsonPath('deliveries.0.status', 'delivered');
    }

    public function test_ambiguous_delivery_is_not_retried_and_secret_is_not_returned(): void
    {
        $u = $this->user();
        $this->bind($u);
        app(ConversationService::class)->post($u, 'x', (string) Str::uuid());
        Http::fake(['*' => Http::failedConnection()]);
        $d = MessageDelivery::first();
        $job = new DeliverTelegramMessage($d->id);
        $job->handle(app(TelegramClient::class));
        $this->assertSame('uncertain', $d->fresh()->status);
        $result = app(TelegramClient::class)->call('getMe');
        $this->assertSame(['ok' => false, 'error' => 'uncertain'], $result);
        $this->assertStringNotContainsString('test-token', json_encode($result));
        $job->handle(app(TelegramClient::class));
        $this->assertSame(1, $d->fresh()->attempts);
    }

    public function test_rejected_delivery_and_disabled_transport(): void
    {
        $u = $this->user();
        $this->bind($u);
        app(ConversationService::class)->post($u, 'x', (string) Str::uuid());
        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Forbidden'], 403)]);
        $d = MessageDelivery::first();
        (new DeliverTelegramMessage($d->id))->handle(app(TelegramClient::class));
        $this->assertSame('failed', $d->fresh()->status);
        config(['central.telegram_enabled' => false]);
        $this->assertSame('not_configured', app(TelegramClient::class)->call('getMe')['error']);
    }

    public function test_poll_persists_cursor_and_recovers_pending_outbox(): void
    {
        $u = $this->user();
        $this->bind($u);
        app(ConversationService::class)->post($u, 'web', (string) Str::uuid());
        Http::fake(['*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]), '*/getUpdates' => Http::response(['ok' => true, 'result' => [$this->update(45, 'celular')]])]);
        $this->artisan('central:telegram-poll --once')->assertSuccessful();
        $this->assertDatabaseHas('central_states', ['key' => 'telegram_offset', 'value' => '46']);
        $this->assertDatabaseCount('messages', 2);
        Queue::assertPushed(DeliverTelegramMessage::class);
        $this->artisan('central:telegram-poll --once')->assertSuccessful();
        $this->assertDatabaseCount('messages', 2);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/getUpdates') && $r['offset'] === 46);
    }

    public function test_poll_refuses_existing_webhook_without_modifying_it(): void
    {
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['url' => 'https://example.invalid/existing']])]);
        $this->artisan('central:telegram-poll --once')->assertFailed();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('central_states', 0);
    }

    public function test_poll_failure_preserves_cursor(): void
    {
        DB::table('central_states')->insert(['key' => 'telegram_offset', 'value' => '80']);
        Http::fake(['*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]), '*/getUpdates' => Http::response(['ok' => false], 500)]);
        $this->artisan('central:telegram-poll --once')->assertFailed();
        $this->assertDatabaseHas('central_states', ['key' => 'telegram_offset', 'value' => '80']);
    }

    public function test_single_administrator_provisioning(): void
    {
        $this->artisan('central:create-admin')->expectsQuestion('Nome', 'Diretor')->expectsQuestion('E-mail', 'diretor@example.test')->expectsQuestion('Senha (mínimo 12 caracteres)', 'test-password-1234')->expectsQuestion('Confirme a senha', 'test-password-1234')->assertSuccessful();
        $this->assertDatabaseCount('users', 1);
        $this->artisan('central:create-admin')->assertFailed();
        $this->assertTrue(Hash::check('test-password-1234', User::first()->password));
    }

    public function test_pairing_attempts_are_rate_limited_and_chat_identity_is_verified(): void
    {
        $u = $this->user();
        $this->actingAs($u);
        $command = $this->postJson('/telegram/pair')->json('command');
        $ing = app(TelegramIngestor::class);
        $bad = $this->update(1, $command);
        $bad['message']['chat']['id'] = 456;
        $ing->ingest($bad);
        $this->assertDatabaseCount('channel_bindings', 0);
        for ($i = 2; $i < 7; $i++) {
            $ing->ingest($this->update($i, '/start '.str_repeat('a', 32)));
        }
        $ing->ingest($this->update(7, $command));
        $this->assertDatabaseCount('channel_bindings', 0);
        $this->travel(6)->minutes();
        $command = $this->postJson('/telegram/pair')->json('command');
        $ing->ingest($this->update(8, $command));
        $this->postJson('/telegram/pair/confirm', ['chat_id' => '123'])->assertNoContent();
        $this->assertDatabaseCount('channel_bindings', 1);
    }

    public function test_stale_delivery_is_marked_uncertain_without_resending(): void
    {
        $u = $this->user();
        $this->bind($u);
        app(ConversationService::class)->post($u, 'x', (string) Str::uuid());
        MessageDelivery::query()->update(['status' => 'sending', 'updated_at' => now()->subMinutes(2)]);
        Http::fake(['*/getWebhookInfo' => Http::response(['ok' => true, 'result' => ['url' => '']]), '*/getUpdates' => Http::response(['ok' => true, 'result' => []])]);
        $this->artisan('central:telegram-poll --once')->assertSuccessful();
        $this->assertSame('uncertain', MessageDelivery::first()->status);
        Http::assertSentCount(2);
    }

    public function test_exclusive_polling_lock_and_typed_history(): void
    {
        $lock = Cache::lock('central:telegram-poll', 60);
        $lock->get();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['url' => '']])]);
        $this->artisan('central:telegram-poll --once')->assertFailed();
        $lock->release();
        $u = $this->user();
        $c = app(ConversationService::class)->forUser($u);
        foreach (['agent', 'tool', 'event'] as $kind) {
            Message::create(['conversation_id' => $c->id, 'actor_type' => $kind, 'actor_name' => 'Preparação', 'kind' => 'text', 'origin' => 'system', 'content' => $kind]);
        }
        $this->actingAs($u)->getJson('/messages')->assertJsonCount(3, 'messages')->assertJsonPath('messages.0.actor_type', 'agent');
    }

    public function test_history_polling_does_not_consume_pairing_or_send_limits(): void
    {
        $this->actingAs($this->user());
        for ($i = 0; $i < 15; $i++) {
            $this->getJson('/messages')->assertOk();
        }
        $this->postJson('/telegram/pair')->assertOk();
        $this->postJson('/messages', ['content' => 'Ainda posso enviar', 'client_id' => (string) Str::uuid()])->assertCreated();
    }
}
