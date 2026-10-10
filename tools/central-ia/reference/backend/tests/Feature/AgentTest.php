<?php

namespace Tests\Feature;

use App\Jobs\RunAgent;
use App\Models\AgentRun;
use App\Models\ChannelBinding;
use App\Models\Message;
use App\Models\User;
use App\Services\AgentExecutor;
use App\Services\AgentService;
use App\Services\ConversationService;
use App\Services\TelegramIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central.agents_enabled' => true, 'central.telegram_enabled' => false, 'central.executor_key_file' => base_path('tests/fixtures/executor-key')]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function message(): Message
    {
        return app(ConversationService::class)->post(User::factory()->create(), 'Planejar', (string) Str::uuid());
    }

    private function mockResult(): array
    {
        return ['ok' => true, 'text' => '[SIMULAÇÃO] Resposta de teste', 'usage' => ['available' => false, 'reason' => 'simulation']];
    }

    public function test_web_retry_schedules_exactly_one_run_and_preserves_author(): void
    {
        $u = User::factory()->create();
        $id = (string) Str::uuid();
        $this->actingAs($u)->postJson('/messages', ['content' => 'x', 'client_id' => $id, 'actor_type' => 'agent'])->assertCreated();
        $this->postJson('/messages', ['content' => 'x', 'client_id' => $id])->assertCreated();
        $this->assertDatabaseCount('agent_runs', 1);
        $this->assertDatabaseHas('messages', ['actor_type' => 'user']);
        $this->getJson('/messages')->assertJsonPath('agent_runs.0.mode', 'mock');
    }

    public function test_daily_limit_is_global_persistent_and_duplicate_does_not_consume_quota(): void
    {
        $m = $this->message();
        app(AgentService::class)->schedule($m);
        $this->assertSame(1, (int) DB::table('agent_daily_usage')->value('requests'));
        for ($i = 1; $i < 21; $i++) {
            $this->message();
        }
        $this->assertSame(20, (int) DB::table('agent_daily_usage')->value('requests'));
        $this->assertSame(20, AgentRun::where('status', 'queued')->count());
        $this->assertDatabaseHas('agent_runs', ['status' => 'rejected', 'error_code' => 'global_daily_limit']);
    }

    public function test_worker_records_response_and_outbox_once_without_loop(): void
    {
        $m = $this->message();
        $conversation = DB::table('conversations')->find($m->conversation_id);
        ChannelBinding::create(['user_id' => $conversation->user_id, 'conversation_id' => $m->conversation_id, 'chat_id' => '999', 'telegram_user_id' => '999']);
        $executor = $this->mock(AgentExecutor::class, fn ($mock) => $mock->shouldReceive('run')->once()->andReturn($this->mockResult()));
        $run = AgentRun::firstOrFail();
        (new RunAgent($run->id))->handle($executor);
        (new RunAgent($run->id))->handle($executor);
        $response = Message::where('actor_type', 'agent')->firstOrFail();
        $this->assertSame('Codex (simulado)', $response->actor_name);
        $this->assertNull(app(AgentService::class)->schedule($response));
        $this->assertDatabaseCount('agent_runs', 1);
        $this->assertDatabaseCount('message_deliveries', 1);
        $this->assertDatabaseHas('agent_runs', ['status' => 'completed', 'response_message_id' => $response->id]);
    }

    public function test_executor_failure_is_uncertain_and_never_repeated(): void
    {
        $this->message();
        $executor = $this->mock(AgentExecutor::class, fn ($mock) => $mock->shouldReceive('run')->once()->andReturn(['ok' => false, 'error' => 'executor_uncertain']));
        $run = AgentRun::firstOrFail();
        (new RunAgent($run->id))->handle($executor);
        (new RunAgent($run->id))->handle($executor);
        $this->assertDatabaseHas('agent_runs', ['status' => 'uncertain', 'error_code' => 'executor_uncertain']);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_global_lock_blocks_second_execution(): void
    {
        $this->message();
        $lock = Cache::lock('central:agent-execution', 150);
        $lock->get();
        $executor = $this->mock(AgentExecutor::class, fn ($mock) => $mock->shouldNotReceive('run'));
        (new RunAgent(AgentRun::firstOrFail()->id))->handle($executor);
        $this->assertDatabaseHas('agent_runs', ['status' => 'queued']);
        $lock->release();
    }

    public function test_stale_execution_is_not_replayed_and_histories_are_private(): void
    {
        $m = $this->message();
        AgentRun::firstOrFail()->update(['status' => 'running', 'started_at' => now()->subMinutes(5)]);
        $this->artisan('central:agents-dispatch')->assertSuccessful();
        $this->assertDatabaseHas('agent_runs', ['status' => 'uncertain']);
        $this->actingAs(User::factory()->create())->getJson('/messages')->assertJsonCount(0, 'agent_runs');
    }

    public function test_telegram_replay_schedules_one_run_and_bot_messages_are_ignored(): void
    {
        $u = User::factory()->create();
        $c = app(ConversationService::class)->forUser($u);
        ChannelBinding::create(['user_id' => $u->id, 'conversation_id' => $c->id, 'chat_id' => '999', 'telegram_user_id' => '999']);
        $update = ['update_id' => 1, 'message' => ['from' => ['id' => 999, 'is_bot' => false], 'chat' => ['id' => 999, 'type' => 'private'], 'text' => 'Olá']];
        app(TelegramIngestor::class)->ingest($update);
        app(TelegramIngestor::class)->ingest($update);
        $update['update_id'] = 2;
        $update['message']['from']['is_bot'] = true;
        app(TelegramIngestor::class)->ingest($update);
        $this->assertDatabaseCount('agent_runs', 1);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_http_executor_accepts_only_mock_and_sanitizes_remote_error(): void
    {
        Http::fake(['executor:8090/*' => Http::sequence()->push(['mode' => 'mock', 'text' => '[SIMULAÇÃO] OK'])->push(['error' => 'secret-token'], 500)]);
        $result = app(AgentExecutor::class)->run(1, [['author' => 'x', 'role' => 'user', 'text' => 'x']]);
        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($r) => $r['mode'] === 'mock');
        $this->assertSame(['ok' => false, 'error' => 'executor_invalid_response'], app(AgentExecutor::class)->run(2, []));
    }
}
