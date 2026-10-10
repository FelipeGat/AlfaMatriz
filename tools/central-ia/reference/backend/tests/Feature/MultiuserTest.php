<?php

namespace Tests\Feature;

use App\Contracts\IdentityAuthority;
use App\Jobs\DeliverTelegramMessage;
use App\Jobs\RunAgent;
use App\Models\AgentIntegration;
use App\Models\AgentRun;
use App\Models\CentralIdentity;
use App\Models\ChannelBinding;
use App\Models\Message;
use App\Models\MessageDelivery;
use App\Models\User;
use App\Services\AgentExecutor;
use App\Services\AgentService;
use App\Services\ConversationService;
use App\Services\Identity\IdentityLinker;
use App\Services\Identity\OwnershipBackfill;
use App\Services\Identity\PrivateAudit;
use App\Services\Identity\Revocation;
use App\Services\Identity\Sessions;
use App\Services\TelegramClient;
use App\Services\TelegramIngestor;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MultiuserTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;

    private User $rossini;

    private User $alexandre;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['central.telegram_enabled' => true, 'central.telegram_token' => 'simulation-only']);
        $this->felipe = User::factory()->create(['name' => 'Felipe']);
        $this->rossini = User::factory()->create(['name' => 'Rossini']);
        $this->alexandre = User::factory()->create(['name' => 'Alexandre']);
    }

    private function emit(User $owner, string $text = 'private text'): Message
    {
        return app(ConversationService::class)->post($owner, $text, (string) Str::uuid());
    }

    private function deny(User $owner, array $operations = []): void
    {
        $identity = CentralIdentity::where('user_id', $owner->id)->firstOrFail();
        $authority = app(IdentityAuthority::class);
        $data = $authority->inspect($identity->subject);
        $data['operations'] = $operations;
        $authority->overrides[$identity->subject] = $data;
    }

    private function update(int $id, int $sender, string $text): array
    {
        return ['update_id' => $id, 'message' => ['from' => ['id' => $sender, 'is_bot' => false], 'chat' => ['id' => $sender, 'type' => 'private'], 'text' => $text]];
    }

    private function bind(User $owner, int $sender): ChannelBinding
    {
        return ChannelBinding::create(['user_id' => $owner->id, 'conversation_id' => app(ConversationService::class)->forUser($owner)->id,
            'telegram_user_id' => (string) $sender, 'chat_id' => (string) $sender]);
    }

    public function test_three_users_have_separate_histories_and_id_manipulation_is_refused(): void
    {
        foreach ([$this->felipe, $this->rossini, $this->alexandre] as $u) {
            $messages[$u->id] = $this->emit($u, $u->name.' private');
        }
        foreach ([$this->felipe, $this->rossini, $this->alexandre] as $u) {
            $this->actingAs($u)->getJson('/messages')->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.content', $u->name.' private');
            foreach ($messages as $owner => $message) {
                $response = $this->getJson('/messages/'.$message->id);
                $owner === $u->id ? $response->assertOk() : $response->assertNotFound();
            }
        }
    }

    public function test_admin_label_does_not_grant_private_content_or_other_integrations(): void
    {
        $this->felipe->name = 'Administrador';
        $this->felipe->save();
        $secret = $this->emit($this->rossini);
        $integration = AgentIntegration::create(['owner_id' => $this->rossini->id, 'provider' => 'codex']);
        $this->actingAs($this->felipe)->getJson('/messages/'.$secret->id)->assertNotFound();
        $this->getJson('/integrations')->assertOk()->assertExactJson([]);
        $this->deleteJson('/integrations/'.$integration->id)->assertNotFound();
        $this->assertDatabaseHas('agent_integrations', ['id' => $integration->id, 'status' => 'disconnected']);
    }

    public function test_spoofed_owners_authors_and_ids_do_not_transfer_ownership(): void
    {
        $this->actingAs($this->felipe)->postJson('/messages', ['content' => 'mine', 'client_id' => (string) Str::uuid(),
            'owner_id' => $this->rossini->id, 'user_id' => $this->rossini->id, 'actor_type' => 'agent'])->assertCreated();
        $this->assertDatabaseHas('messages', ['owner_id' => $this->felipe->id, 'actor_type' => 'user']);
        $this->assertDatabaseMissing('messages', ['owner_id' => $this->rossini->id]);
    }

    public function test_source_permissions_are_checked_on_every_request_and_fail_closed(): void
    {
        $this->actingAs($this->rossini)->getJson('/messages')->assertOk();
        $this->deny($this->rossini);
        $this->getJson('/messages')->assertForbidden();
        $this->postJson('/messages', ['content' => 'x', 'client_id' => (string) Str::uuid()])->assertForbidden();
        $this->postJson('/telegram/pair')->assertForbidden();
        app(IdentityAuthority::class)->available = false;
        $this->actingAs($this->felipe)->getJson('/messages')->assertStatus(503)->assertJsonPath('message', 'identity_authority_unavailable');
    }

    public function test_disabled_account_and_changed_epoch_revoke_web_sessions(): void
    {
        $this->actingAs($this->alexandre)->getJson('/messages')->assertOk();
        $i = CentralIdentity::where('user_id', $this->alexandre->id)->firstOrFail();
        $data = app(IdentityAuthority::class)->inspect($i->subject);
        $data['active'] = false;
        app(IdentityAuthority::class)->overrides[$i->subject] = $data;
        $this->getJson('/messages')->assertForbidden();
        unset(app(IdentityAuthority::class)->overrides[$i->subject]);
        $i->increment('session_epoch');
        $this->getJson('/messages')->assertForbidden();
        $this->actingAs($this->felipe)->getJson('/messages')->assertOk();
    }

    public function test_private_telegram_chats_are_owned_and_replays_do_not_cross_histories(): void
    {
        foreach ([101 => $this->felipe, 102 => $this->rossini, 103 => $this->alexandre] as $sender => $owner) {
            $this->bind($owner, $sender);
            $update = $this->update($sender, $sender, $owner->name.' telegram');
            app(TelegramIngestor::class)->ingest($update);
            app(TelegramIngestor::class)->ingest($update);
            $this->assertDatabaseHas('messages', ['owner_id' => $owner->id, 'content' => $owner->name.' telegram']);
        }
        $this->assertDatabaseCount('messages', 3);
        app(Revocation::class)->telegram($this->rossini);
        app(TelegramIngestor::class)->ingest($this->update(200, 102, 'revoked telegram'));
        $this->assertDatabaseCount('messages', 3);
    }

    public function test_telegram_source_permission_revocation_is_enforced(): void
    {
        $this->bind($this->rossini, 102);
        $this->deny($this->rossini, ['read']);
        app(TelegramIngestor::class)->ingest($this->update(1, 102, 'blocked'));
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseHas('telegram_updates', ['update_id' => 1]);
        $this->bind($this->felipe, 101);
        app(TelegramIngestor::class)->ingest($this->update(2, 101, 'allowed'));
        $this->assertDatabaseHas('messages', ['owner_id' => $this->felipe->id, 'content' => 'allowed']);
    }

    public function test_telegram_authority_outage_accepts_nothing_and_keeps_update_retryable(): void
    {
        $this->bind($this->felipe, 101);
        $authority = app(IdentityAuthority::class);
        $authority->available = false;
        try {
            app(TelegramIngestor::class)->ingest($this->update(701, 101, 'retry after outage'));
            $this->fail('Authority outage must deny ingestion');
        } catch (HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseCount('messages', 0);
        $this->assertDatabaseMissing('telegram_updates', ['update_id' => 701]);
        $authority->available = true;
        app(TelegramIngestor::class)->ingest($this->update(701, 101, 'retry after outage'));
        app(TelegramIngestor::class)->ingest($this->update(701, 101, 'retry after outage'));
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('messages', ['owner_id' => $this->felipe->id]);
    }

    public function test_send_permission_does_not_grant_execution_or_block_conversation(): void
    {
        config(['central.agents_enabled' => true]);
        $this->bind($this->felipe, 101);
        $this->deny($this->felipe, ['read', 'send', 'pair']);
        $message = $this->emit($this->felipe, 'web without agent');
        app(TelegramIngestor::class)->ingest($this->update(702, 101, 'telegram without agent'));
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->actingAs($this->felipe)->postJson('/messages/'.$message->id.'/run')->assertForbidden();
        Queue::assertNotPushed(RunAgent::class);
    }

    public function test_outbox_cannot_deliver_to_another_user_and_revocation_blocks_delivery(): void
    {
        $this->bind($this->felipe, 101);
        $wrong = $this->bind($this->rossini, 102);
        $m = $this->emit($this->felipe);
        $d = MessageDelivery::firstOrFail();
        DB::table('message_deliveries')->where('id', $d->id)->update(['channel_binding_id' => $wrong->id]);
        $client = $this->mock(TelegramClient::class, fn ($mock) => $mock->shouldNotReceive('call'));
        (new DeliverTelegramMessage($d->id))->handle($client);
        $this->assertDatabaseHas('message_deliveries', ['id' => $d->id, 'status' => 'failed']);
    }

    public function test_queued_job_rechecks_permissions_and_does_not_call_executor(): void
    {
        config(['central.agents_enabled' => true]);
        $this->emit($this->rossini);
        $run = AgentRun::firstOrFail();
        $this->deny($this->rossini, ['read', 'send']);
        $executor = $this->mock(AgentExecutor::class, fn ($mock) => $mock->shouldNotReceive('run'));
        (new RunAgent($run->id))->handle($executor);
        $this->assertDatabaseHas('agent_runs', ['id' => $run->id, 'status' => 'uncertain']);
    }

    public function test_execution_and_result_ids_are_private_and_integration_is_owned(): void
    {
        config(['central.agents_enabled' => true]);
        $m = $this->emit($this->rossini);
        $run = AgentRun::firstOrFail();
        $this->assertSame($this->rossini->id, $run->owner_id);
        $this->assertSame($this->rossini->id, AgentIntegration::findOrFail($run->integration_id)->owner_id);
        $this->actingAs($this->felipe)->getJson('/agent-runs/'.$run->id)->assertNotFound();
        $this->postJson('/messages/'.$m->id.'/run')->assertNotFound();
        $this->assertDatabaseCount('agent_runs', 1);
    }

    public function test_individual_and_global_limits_are_persistent_and_duplicates_cost_nothing(): void
    {
        config(['central.agents_enabled' => true, 'central.agent_user_daily_limit' => 1, 'central.agent_daily_limit' => 2]);
        $m = $this->emit($this->felipe);
        app(AgentService::class)->schedule($m);
        $this->emit($this->felipe);
        $this->assertDatabaseHas('agent_runs', ['owner_id' => $this->felipe->id, 'error_code' => 'user_daily_limit']);
        $this->emit($this->rossini);
        $this->emit($this->alexandre);
        $this->assertDatabaseHas('agent_runs', ['owner_id' => $this->alexandre->id, 'error_code' => 'global_daily_limit']);
        $this->assertSame(2, (int) DB::table('agent_daily_usage')->value('requests'));
        $this->assertSame(1, (int) DB::table('agent_user_daily_usage')->where('owner_id', $this->felipe->id)->value('requests'));
    }

    public function test_personal_and_global_execution_locks_block_the_executor(): void
    {
        config(['central.agents_enabled' => true]);
        $this->emit($this->felipe);
        $run = AgentRun::firstOrFail();
        $executor = $this->mock(AgentExecutor::class, fn ($mock) => $mock->shouldNotReceive('run'));
        $lock = Cache::lock('central:agent-execution:user:'.$this->felipe->id, 150);
        $this->assertTrue($lock->get());
        (new RunAgent($run->id))->handle($executor);
        $this->assertDatabaseHas('agent_runs', ['id' => $run->id, 'status' => 'queued']);
        $lock->release();
        $lock = Cache::lock('central:agent-execution', 150);
        $this->assertTrue($lock->get());
        (new RunAgent($run->id))->handle($executor);
        $lock->release();
    }

    public function test_credential_references_never_appear_in_api_or_model_serialization(): void
    {
        $i = AgentIntegration::create(['owner_id' => $this->felipe->id, 'provider' => 'codex']);
        $ref = (string) Str::uuid();
        DB::table('agent_integrations')->where('id', $i->id)->update(['credential_ref' => $ref]);
        $this->assertStringNotContainsString($ref, $i->fresh()->toJson());
        $this->actingAs($this->felipe)->getJson('/integrations')->assertOk()->assertJsonMissingPath('0.credential_ref');
        $columns = DB::getSchemaBuilder()->getColumnListing('agent_integrations');
        foreach (['token', 'password', 'secret', 'auth_json', 'cookies'] as $column) {
            $this->assertNotContains($column, $columns);
        }
        Http::assertNothingSent();
    }

    public function test_integration_and_session_revocation_is_individual(): void
    {
        $i = AgentIntegration::create(['owner_id' => $this->rossini->id, 'provider' => 'codex', 'status' => 'connected']);
        $c = app(ConversationService::class)->forUser($this->rossini);
        $s = app(Sessions::class)->create($this->rossini, $i->id, $c->id);
        $this->actingAs($this->felipe)->deleteJson('/sessions/'.$s->id)->assertNotFound();
        $this->actingAs($this->rossini)->deleteJson('/integrations/'.$i->id)->assertNoContent();
        $this->assertDatabaseHas('agent_sessions', ['id' => $s->id, 'status' => 'revoked']);
        $this->assertDatabaseHas('agent_integrations', ['id' => $i->id, 'status' => 'revoked']);
        $this->actingAs($this->felipe)->getJson('/messages')->assertOk();
    }

    public function test_identity_revocation_preserves_history_and_revokes_only_owner(): void
    {
        $this->bind($this->rossini, 102);
        $message = $this->emit($this->rossini);
        config(['central.agents_enabled' => true]);
        $this->emit($this->rossini);
        app(Revocation::class)->identity(CentralIdentity::where('user_id', $this->rossini->id)->firstOrFail());
        $this->actingAs($this->rossini)->getJson('/messages')->assertForbidden();
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'content' => 'private text']);
        $this->assertDatabaseHas('agent_runs', ['owner_id' => $this->rossini->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('channel_bindings', ['user_id' => $this->rossini->id]);
        $this->actingAs($this->alexandre)->getJson('/messages')->assertOk();
    }

    public function test_legacy_link_and_backfill_preserve_history_pairing_and_cursor(): void
    {
        $b = $this->bind($this->felipe, 101);
        $m = $this->emit($this->felipe, 'preserved');
        DB::table('central_states')->insert(['key' => 'telegram_offset', 'value' => '999']);
        DB::table('messages')->where('id', $m->id)->update(['owner_id' => null]);
        DB::table('message_deliveries')->update(['owner_id' => null]);
        CentralIdentity::where('user_id', $this->felipe->id)->delete();
        $identity = app(IdentityLinker::class)->link($this->felipe, 'confirmed-alfa-fixture');
        app(OwnershipBackfill::class)->run();
        app(OwnershipBackfill::class)->run();
        $this->assertSame($this->felipe->id, $identity->user_id);
        $this->assertDatabaseHas('messages', ['id' => $m->id, 'owner_id' => $this->felipe->id, 'content' => 'preserved']);
        $this->assertDatabaseHas('channel_bindings', ['id' => $b->id, 'chat_id' => '101', 'conversation_id' => $m->conversation_id]);
        $this->assertDatabaseHas('central_states', ['key' => 'telegram_offset', 'value' => '999']);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_conflicting_mapping_cannot_overwrite_identity(): void
    {
        $this->expectExceptionMessage('identity_link_conflict');
        app(IdentityLinker::class)->link($this->felipe, 'another-account');
    }

    public function test_no_identity_no_access_and_no_paid_or_real_mode(): void
    {
        CentralIdentity::where('user_id', $this->alexandre->id)->delete();
        $this->actingAs($this->alexandre)->getJson('/messages')->assertForbidden();
        config(['central.agents_enabled' => true, 'central.agent_mode' => 'real']);
        $message = $this->emit($this->felipe);
        try {
            app(AgentService::class)->schedule($message);
            $this->fail('Real inference permitted');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('agent_runs', 0);
        Queue::assertNotPushed(RunAgent::class);
        Http::assertNothingSent();
    }

    public function test_pairing_requires_confirmation_by_the_same_authenticated_owner(): void
    {
        $this->actingAs($this->felipe);
        $command = $this->postJson('/telegram/pair')->assertOk()->json('command');
        app(TelegramIngestor::class)->ingest($this->update(301, 101, $command));
        $this->assertDatabaseCount('channel_bindings', 0);
        $this->actingAs($this->rossini)->postJson('/telegram/pair/confirm', ['chat_id' => '101'])->assertConflict();
        $this->getJson('/telegram/pair/pending')->assertJsonPath('pending', null);
        $this->actingAs($this->felipe)->postJson('/telegram/pair/confirm', ['chat_id' => '102'])->assertConflict();
        $this->postJson('/telegram/pair/confirm', ['chat_id' => '101'])->assertNoContent();
        $this->postJson('/telegram/pair/confirm', ['chat_id' => '101'])->assertConflict();
        $this->assertDatabaseHas('channel_bindings', ['user_id' => $this->felipe->id, 'chat_id' => '101']);
    }

    public function test_expired_pending_pairing_and_changed_session_cannot_be_confirmed(): void
    {
        $this->actingAs($this->felipe);
        $command = $this->postJson('/telegram/pair')->json('command');
        app(TelegramIngestor::class)->ingest($this->update(1, 101, $command));
        $this->travel(6)->minutes();
        $this->postJson('/telegram/pair/confirm', ['chat_id' => '101'])->assertConflict();
        $this->travelBack();
        $command = $this->postJson('/telegram/pair')->json('command');
        app(TelegramIngestor::class)->ingest($this->update(2, 101, $command));
        CentralIdentity::where('user_id', $this->felipe->id)->increment('session_epoch');
        $this->actingAs($this->felipe)->postJson('/telegram/pair/confirm', ['chat_id' => '101'])->assertConflict();
        $this->assertDatabaseCount('channel_bindings', 0);
    }

    public function test_repairing_only_replaces_revoked_binding_after_confirmation(): void
    {
        $old = $this->bind($this->felipe, 101);
        app(Revocation::class)->telegram($this->felipe);
        $this->actingAs($this->felipe);
        $command = $this->postJson('/telegram/pair')->assertOk()->json('command');
        app(TelegramIngestor::class)->ingest($this->update(1, 111, $command));
        $this->postJson('/telegram/pair/confirm', ['chat_id' => '111'])->assertNoContent();
        $this->assertDatabaseHas('channel_bindings', ['id' => $old->id, 'user_id' => $this->felipe->id, 'chat_id' => '111', 'revoked_at' => null]);
        app(TelegramIngestor::class)->ingest($this->update(2, 101, 'old chat'));
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_sessions_cannot_mix_conversation_or_integration_owners(): void
    {
        $integration = AgentIntegration::create(['owner_id' => $this->rossini->id, 'provider' => 'codex', 'status' => 'connected']);
        $conversation = app(ConversationService::class)->forUser($this->felipe);
        try {
            app(Sessions::class)->create($this->felipe, $integration->id, $conversation->id);
            $this->fail('Foreign integration accepted');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('agent_sessions', 0);
        }
        try {
            app(Sessions::class)->create($this->rossini, $integration->id, $conversation->id);
            $this->fail('Foreign conversation accepted');
        } catch (ModelNotFoundException) {
            $this->assertDatabaseCount('agent_sessions', 0);
        }
    }

    public function test_revocation_during_execution_prevents_response_publication(): void
    {
        config(['central.agents_enabled' => true]);
        $this->emit($this->rossini);
        $run = AgentRun::firstOrFail();
        $executor = $this->mock(AgentExecutor::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturnUsing(function () {
                $this->deny($this->rossini, ['read', 'send']);

                return ['ok' => true, 'text' => 'must not publish', 'usage' => ['available' => false]];
            });
        });
        (new RunAgent($run->id))->handle($executor);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseHas('agent_runs', ['id' => $run->id, 'status' => 'uncertain']);
    }

    public function test_audit_metadata_refuses_content_and_credentials(): void
    {
        $audit = app(PrivateAudit::class);
        try {
            $audit->record($this->felipe, 'integration_revoked', ['token' => 'synthetic-secret']);
            $this->fail('Credential entered audit');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit_metadata_refused', $e->getMessage());
        }
        $this->assertDatabaseCount('audit_events', 0);
        $audit->record($this->felipe, 'identity_revoked', ['identity_id' => 1]);
        $this->assertSame('{"identity_id":1}', DB::table('audit_events')->value('metadata'));
    }

    public function test_raw_authority_failures_never_reach_errors_or_logs(): void
    {
        $authority = new class implements IdentityAuthority
        {
            public function inspect(string $subject): array
            {
                throw new \RuntimeException('synthetic-token-cookie-private-body');
            }
        };
        $this->app->instance(IdentityAuthority::class, $authority);
        $this->actingAs($this->felipe)->getJson('/messages')->assertStatus(503)
            ->assertDontSee('synthetic-token-cookie-private-body')->assertJsonPath('message', 'identity_authority_unavailable');
        $log = storage_path('logs/laravel.log');
        if (file_exists($log)) {
            $this->assertStringNotContainsString('synthetic-token-cookie-private-body', file_get_contents($log));
        }
    }

    public function test_schema_migration_is_additive_and_does_not_backfill_automatically(): void
    {
        $message = $this->emit($this->felipe);
        DB::table('messages')->where('id', $message->id)->update(['owner_id' => null]);
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'owner_id' => null]);
        $migration = require database_path('migrations/2026_10_11_000001_add_multiuser_identity.php');
        try {
            $migration->down();
            $this->fail('Destructive rollback permitted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('destructive schema rollback refused', $e->getMessage());
        }
        $this->assertDatabaseHas('messages', ['id' => $message->id, 'content' => 'private text']);
    }
}
