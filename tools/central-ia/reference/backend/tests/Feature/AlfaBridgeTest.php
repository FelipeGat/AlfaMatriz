<?php

namespace Tests\Feature;

use App\Contracts\IdentityAuthority;
use App\Contracts\IdentityGateway;
use App\Models\CentralIdentity;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlfaBridgeTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private string $key;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = tempnam('/tmp', 'alfa-bridge-');
        $this->key = bin2hex(random_bytes(32));
        file_put_contents($this->file, $this->key);
        chmod($this->file, 0600);
        config(['central.alfa_backend_enabled' => true, 'central.alfa_backend_key_file' => $this->file, 'central.agents_enabled' => false, 'central.telegram_enabled' => false]);
        $this->owner = User::factory()->create(['name' => 'Felipe']);
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function exchange(User $user): string
    {
        $identity = CentralIdentity::where('user_id', $user->id)->firstOrFail();
        $snapshot = ['version' => 1, 'issuer' => 'alfamatriz', 'audience' => 'central-agentes', 'subject' => $identity->subject, 'active' => true, 'ready' => true, 'session_epoch' => 1, 'operations' => ['read', 'send']];
        $this->mock(IdentityGateway::class)->shouldReceive('redeem')->once()->andReturn($snapshot);

        return $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('https://localhost/internal/alfa/session', ['ticket' => str_repeat('a', 64), 'flow' => str_repeat('b', 64)])->assertOk()->json('session_token');
    }

    private function request(string $path, string $token, array $data = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$this->key, 'X-Central-Session' => $token])->postJson('https://localhost/internal/alfa/'.$path, $data);
    }

    public function test_machine_key_tls_and_owner_session_are_all_required(): void
    {
        $this->postJson('https://localhost/internal/alfa/history')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('http://localhost/internal/alfa/history')->assertForbidden();
        $this->postJson('https://localhost/internal/alfa/history')->assertUnauthorized();
    }

    public function test_token_is_hashed_and_cannot_provision_an_unknown_identity(): void
    {
        $token = $this->exchange($this->owner);
        $this->assertSame(hash('sha256', $token), DB::table('central_bridge_sessions')->value('token_hash'));
        $this->assertDatabaseCount('users', 1);
    }

    public function test_each_owner_has_private_history_even_when_admin(): void
    {
        $tokens = [];
        foreach ([$this->owner, User::factory()->create(['name' => 'Rossini']), User::factory()->create(['name' => 'Alexandre'])] as $user) {
            $tokens[$user->name] = $this->exchange($user);
            $this->request('send', $tokens[$user->name], ['content' => 'privado-'.$user->name, 'client_id' => (string) Str::uuid()])->assertCreated();
        }
        foreach ($tokens as $name => $token) {
            $response = $this->request('history', $token)->assertOk()->assertJsonCount(1, 'messages')->assertJsonPath('messages.0.content', 'privado-'.$name);
            foreach (array_diff(array_keys($tokens), [$name]) as $other) {
                $this->assertStringNotContainsString('privado-'.$other, $response->getContent());
            }
        }
    }

    public function test_manipulating_ids_and_reusing_another_bearer_does_not_change_owner(): void
    {
        $token = $this->exchange($this->owner);
        foreach (['user_id', 'owner_id', 'subject', 'conversation_id'] as $field) {
            $this->request('history', $token, [$field => 99])->assertStatus(422);
        }
        $this->withHeaders(['Authorization' => 'Bearer wrong', 'X-Central-Session' => $token])->postJson('https://localhost/internal/alfa/history')->assertUnauthorized();
    }

    public function test_repeated_send_after_ambiguous_timeout_creates_one_message_and_no_agent(): void
    {
        $token = $this->exchange($this->owner);
        $payload = ['content' => 'teste-idempotente', 'client_id' => (string) Str::uuid()];
        $first = $this->request('send', $token, $payload)->assertCreated()->json('id');
        $this->request('send', $token, $payload)->assertCreated()->assertJsonPath('id', $first);
        $this->assertDatabaseCount('messages', 1);
        $this->assertDatabaseCount('agent_runs', 0);
        $this->assertDatabaseCount('message_deliveries', 0);
        $this->assertDatabaseHas('messages', ['origin' => 'alfamatriz', 'owner_id' => $this->owner->id]);
    }

    public function test_expired_revoked_and_stale_epoch_sessions_cannot_read(): void
    {
        $token = $this->exchange($this->owner);
        DB::table('central_bridge_sessions')->update(['expires_at' => now()->subSecond()]);
        $this->request('history', $token)->assertUnauthorized();
        DB::table('central_bridge_sessions')->update(['expires_at' => now()->addMinute(), 'revoked_at' => now()]);
        $this->request('history', $token)->assertUnauthorized();
        DB::table('central_bridge_sessions')->update(['revoked_at' => null]);
        CentralIdentity::where('user_id', $this->owner->id)->update(['session_epoch' => 2]);
        $this->request('history', $token)->assertForbidden();
    }

    public function test_history_paginates_and_polls_without_duplicates(): void
    {
        $token = $this->exchange($this->owner);
        $conversation = app(ConversationService::class)->forUser($this->owner);
        for ($i = 1; $i <= 62; $i++) {
            Message::create(['conversation_id' => $conversation->id, 'owner_id' => $this->owner->id, 'actor_type' => 'user', 'actor_name' => 'Felipe', 'origin' => 'alfamatriz', 'content' => 'linha-'.$i, 'client_id' => (string) Str::uuid()]);
        }
        $recent = $this->request('history', $token)->assertOk()->assertJsonCount(50, 'messages')->assertJsonPath('has_more', true)->json('messages');
        $this->request('history', $token, ['before' => $recent[0]['id']])->assertOk()->assertJsonCount(12, 'messages')->assertJsonPath('has_more', false);
        $this->request('history', $token, ['after' => $recent[49]['id']])->assertOk()->assertJsonCount(0, 'messages');
        $this->request('history', $token, ['after' => 0, 'before' => 5])->assertStatus(422);
    }

    public function test_authority_is_checked_once_per_request_and_fresh_on_next_request(): void
    {
        $token = $this->exchange($this->owner);
        $identity = CentralIdentity::where('user_id', $this->owner->id)->firstOrFail();
        $current = ['issuer' => 'alfamatriz', 'subject' => $identity->subject, 'active' => true, 'ready' => true, 'session_epoch' => 1, 'operations' => ['read', 'send']];
        $authority = $this->mock(IdentityAuthority::class);
        $authority->shouldReceive('inspect')->once()->andReturn($current);
        $this->request('history', $token)->assertOk();
        $authority = $this->mock(IdentityAuthority::class);
        $current['operations'] = [];
        $authority->shouldReceive('inspect')->once()->andReturn($current);
        $this->request('history',$token)->assertForbidden();
    }
}
