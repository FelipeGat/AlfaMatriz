<?php

namespace Tests\Feature;

use App\Contracts\IdentityGateway;
use App\Models\CentralIdentity;
use App\Models\User;
use App\Services\Identity\AlfaIdentityAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IdentitySessionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central.identity_bridge_enabled' => true]);
        $this->owner = User::factory()->create();
    }

    private function snapshot(): array
    {
        $identity = CentralIdentity::where('user_id', $this->owner->id)->firstOrFail();

        return ['version' => 1, 'issuer' => 'alfamatriz', 'audience' => 'central-agentes', 'subject' => $identity->subject,
            'active' => true, 'ready' => true, 'session_epoch' => (int) $identity->session_epoch, 'operations' => ['read', 'send']];
    }

    private function start(): string
    {
        return $this->postJson('https://localhost/identity/start')->assertOk()->json('flow');
    }

    public function test_login_uses_redeemed_identity_and_never_browser_user_id(): void
    {
        $other = User::factory()->create();
        $flow = $this->start();
        $ticket = str_repeat('a', 64);
        $gateway = $this->mock(IdentityGateway::class);
        $gateway->shouldReceive('redeem')->once()->with($ticket, $flow)->andReturn($this->snapshot());
        $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => $ticket, 'user_id' => $other->id])->assertStatus(422);
        $this->assertGuest();
        $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => $ticket])->assertNoContent();
        $this->assertAuthenticatedAs($this->owner);
        $this->assertDatabaseCount('users', 2);
    }

    public function test_flow_from_another_session_or_expired_flow_cannot_reach_authority(): void
    {
        $gateway = $this->mock(IdentityGateway::class);
        $gateway->shouldNotReceive('redeem');
        $this->start();
        $this->postJson('https://localhost/identity/complete', ['flow' => str_repeat('b', 64), 'ticket' => str_repeat('a', 64)])->assertForbidden();
        $flow = $this->start();
        $this->travel(121)->seconds();
        $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => str_repeat('a', 64)])->assertForbidden();
        $this->assertGuest();
    }

    public function test_unprovisioned_subject_cannot_link_existing_account_by_name_or_email(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['subject'] = '00000000-0000-4000-8000-000000000099';
        $this->mock(IdentityGateway::class)->shouldReceive('redeem')->once()->andReturn($snapshot);
        $flow = $this->start();
        $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => str_repeat('a', 64)])->assertForbidden();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('central_identities', 1);
    }

    public function test_inactive_unready_or_wrong_audience_identity_never_creates_session(): void
    {
        foreach ([['active', false], ['ready', false], ['audience', 'other-service'], ['operations', []]] as [$field, $value]) {
            $snapshot = $this->snapshot();
            $snapshot[$field] = $value;
            $this->mock(IdentityGateway::class)->shouldReceive('redeem')->once()->andReturn($snapshot);
            $flow = $this->start();
            $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => str_repeat('a', 64)])->assertForbidden();
            $this->assertGuest();
        }
    }

    public function test_new_verified_login_refreshes_epoch_but_never_reactivates_local_revocation(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['session_epoch'] = 7;
        $this->mock(IdentityGateway::class)->shouldReceive('redeem')->once()->andReturn($snapshot);
        $flow = $this->start();
        $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => str_repeat('a', 64)])->assertNoContent();
        $this->assertSame(7, session('central.identity_epoch'));
        $this->post('/logout')->assertRedirect();
        CentralIdentity::where('user_id', $this->owner->id)->update(['status' => 'revoked']);
        $this->mock(IdentityGateway::class)->shouldReceive('redeem')->once()->andReturn($snapshot);
        $flow = $this->start();
        $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => str_repeat('a', 64)])->assertForbidden();
        $this->assertGuest();
    }

    public function test_authority_failure_is_generic_and_never_falls_back_to_local_password(): void
    {
        $this->mock(IdentityGateway::class)->shouldReceive('redeem')->once()->andThrow(new \RuntimeException('sensitive-fixture-that-must-not-leak'));
        $flow = $this->start();
        $response = $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => str_repeat('a', 64)])->assertStatus(503);
        $this->assertStringNotContainsString('sensitive-fixture-that-must-not-leak', $response->getContent());
        $this->postJson('/login', ['email' => $this->owner->email, 'password' => 'password'])->assertForbidden();
        $this->assertGuest();
    }

    public function test_bridge_disabled_and_plain_http_are_refused(): void
    {
        $this->postJson('http://localhost/identity/start')->assertForbidden();
        config(['central.identity_bridge_enabled' => false]);
        $this->postJson('https://localhost/identity/start')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_https_client_rejects_redirects_wrong_contract_and_paid_fallback(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'bridge-key-');
        file_put_contents($file, bin2hex(random_bytes(32)));
        try {
            config(['central.identity_authority_key_file' => $file,
                'central.identity_authority_url' => 'https://authority.invalid/internal/central-ia/inspect',
                'central.identity_redeem_url' => 'https://authority.invalid/internal/central-ia/redeem']);
            Http::fake(['authority.invalid/*' => Http::sequence()
                ->push(['version' => 1, 'issuer' => 'alfamatriz', 'audience' => 'wrong'], 200)
                ->push('', 302, ['Location' => 'https://other.invalid'])]);
            try {
                app(AlfaIdentityAuthority::class)->inspect('00000000-0000-4000-8000-000000000001');
                $this->fail('Invalid audience accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('identity_authority_unavailable', $error->getMessage());
            }
            try {
                app(AlfaIdentityAuthority::class)->inspect('00000000-0000-4000-8000-000000000001');
                $this->fail('Redirect accepted');
            } catch (\RuntimeException $error) {
                $this->assertSame('identity_authority_unavailable', $error->getMessage());
            }
            Http::assertSentCount(2);
        } finally {
            unlink($file);
        }
    }
}
