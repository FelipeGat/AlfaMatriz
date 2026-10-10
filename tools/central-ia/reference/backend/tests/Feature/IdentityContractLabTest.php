<?php

namespace Tests\Feature;

use App\Contracts\IdentityAuthority;
use App\Contracts\IdentityGateway;
use App\Models\CentralIdentity;
use App\Models\User;
use App\Services\Identity\AlfaIdentityAuthority;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IdentityContractLabTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'https://alfamatriz.test:8443';

    private const SUBJECTS = ['00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002', '00000000-0000-4000-8000-000000000003'];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('IDENTITY_CONTRACT_LAB') !== 'true') {
            $this->markTestSkipped('Run only in the disposable HTTPS identity lab.');
        }
        config(['central.identity_bridge_enabled' => true,
            'central.identity_authority_key_file' => '/run/lab/bridge-key', 'central.identity_ca_file' => '/run/lab/ca.pem',
            'central.identity_authority_url' => self::URL.'/internal/central-ia/inspect',
            'central.identity_redeem_url' => self::URL.'/internal/central-ia/redeem']);
        $gateway = new AlfaIdentityAuthority;
        $this->app->instance(IdentityAuthority::class, $gateway);
        $this->app->instance(IdentityGateway::class, $gateway);
        Http::allowStrayRequests([self::URL.'/*', 'https://alfamatriz.test:8444/*']);
    }

    private function client(?CookieJar $jar = null)
    {
        return Http::acceptJson()->withoutRedirecting()->connectTimeout(2)->timeout(10)
            ->withOptions(['verify' => '/run/lab/ca.pem', 'cookies' => $jar ?? new CookieJar]);
    }

    private function ticket(string $name, string $flow): string
    {
        $jar = new CookieJar;
        $csrf = $this->client($jar)->get(self::URL.'/identity-lab/csrf');
        $this->assertSame(200, $csrf->status());
        $login = $this->client($jar)->withHeader('X-CSRF-TOKEN', $csrf->json('csrf'))->post(self::URL.'/login', [
            'email' => $name.'@example.invalid', 'password' => trim(file_get_contents('/run/lab/fixture-password'))]);
        $this->assertSame(302, $login->status());
        $csrf = $this->client($jar)->get(self::URL.'/identity-lab/csrf');
        $response = $this->client($jar)->withHeader('X-CSRF-TOKEN', $csrf->json('csrf'))->post(self::URL.'/central-ia/tickets', ['flow' => $flow]);
        $this->assertSame(200, $response->status());

        return $response->json('ticket');
    }

    private function project(string $name, int $index): User
    {
        $owner = User::factory()->create(['name' => ucfirst($name), 'email' => 'central-'.$name.'@example.invalid']);
        CentralIdentity::where('user_id', $owner->id)->update(['subject' => self::SUBJECTS[$index]]);

        return $owner;
    }

    private function control(int $index, string $action): void
    {
        $response = $this->client()->withToken(trim(file_get_contents('/run/lab/bridge-key')))
            ->post(self::URL.'/identity-lab/control', ['subject' => self::SUBJECTS[$index], 'action' => $action]);
        $this->assertSame(204, $response->status());
    }

    public function test_https_login_and_real_permissions_keep_three_private_histories_isolated(): void
    {
        $owners = [];
        $ids = [];
        foreach (['felipe', 'rossini', 'alexandre'] as $index => $name) {
            $owners[$name] = $this->project($name, $index);
            $flow = $this->postJson('https://localhost/identity/start')->assertOk()->json('flow');
            $ticket = $this->ticket($name, $flow);
            $this->postJson('https://localhost/identity/complete', ['flow' => $flow, 'ticket' => $ticket])->assertNoContent();
            $this->assertAuthenticatedAs($owners[$name]);
            $ids[$name] = $this->postJson('/messages', ['content' => 'fixture-private-'.$name, 'client_id' => (string) Str::uuid()])->assertCreated()->json('id');
            $this->post('/logout')->assertRedirect();
        }
        foreach ($owners as $name => $owner) {
            $this->actingAs($owner);
            $response = $this->getJson('/messages');
            if ($response->status() !== 200 && is_file(storage_path('logs/laravel.log'))) {
                $log = file_get_contents(storage_path('logs/laravel.log'));
                preg_match_all('/identity_authority_unavailable (\{[^\n]+\})/', $log, $matches);
                foreach ($matches[1] as $metadata) {
                    $decoded = json_decode($metadata, true);
                    fwrite(STDERR, json_encode(array_intersect_key($decoded ?? [], array_flip(['category', 'status', 'transport_code'])))."\n");
                }
            }
            $response->assertOk();
            foreach ($ids as $other => $id) {
                if ($other !== $name) {
                    $this->getJson('/messages/'.$id)->assertNotFound();
                    $this->assertStringNotContainsString('fixture-private-'.$other, $response->getContent());
                }
            }
        }
        $this->assertDatabaseCount('users', 3);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_csrf_and_human_session_cannot_replace_machine_authentication(): void
    {
        $jar = new CookieJar;
        $this->client($jar)->get(self::URL.'/identity-lab/csrf');
        $response = $this->client($jar)->post(self::URL.'/login', ['email' => 'felipe@example.invalid', 'password' => trim(file_get_contents('/run/lab/fixture-password'))]);
        $this->assertSame(419, $response->status());
        $response = $this->client($jar)->post(self::URL.'/internal/central-ia/inspect', ['subject' => self::SUBJECTS[0]]);
        $this->assertSame(401, $response->status());
        $this->assertGuest();
    }

    public function test_real_tls_and_machine_key_fail_closed_and_service_outage_has_no_fallback(): void
    {
        $owner = $this->project('felipe', 0);
        $this->actingAs($owner)->getJson('/messages')->assertOk();
        config(['central.identity_ca_file' => '']);
        $this->getJson('/messages')->assertStatus(503);
        config(['central.identity_ca_file' => '/run/lab/ca.pem']);
        $file = tempnam(sys_get_temp_dir(), 'incorrect-fixture-key-');
        file_put_contents($file, bin2hex(random_bytes(32)));
        try {
            config(['central.identity_authority_key_file' => $file]);
            $this->getJson('/messages')->assertStatus(503);
        } finally {
            unlink($file);
        }
        config(['central.identity_authority_key_file' => '/run/lab/bridge-key', 'central.identity_authority_url' => 'https://alfamatriz.test:8444/internal/central-ia/inspect']);
        $this->getJson('/messages')->assertStatus(503);
        $this->assertDatabaseCount('agent_runs', 0);
    }

    public function test_real_permission_revocation_blocks_only_its_owner_and_invalidates_old_session(): void
    {
        $owner = $this->project('rossini', 1);
        $other = $this->project('felipe', 0);
        $this->actingAs($owner)->getJson('/messages')->assertOk();
        $this->control(1, 'revoke');
        $this->getJson('/messages')->assertForbidden();
        $this->actingAs($other)->getJson('/messages')->assertOk();
    }

    public function test_real_inactive_account_is_denied_without_breaking_other_users(): void
    {
        $owner = $this->project('alexandre', 2);
        $other = $this->project('felipe', 0);
        $this->actingAs($owner)->getJson('/messages')->assertOk();
        $this->control(2, 'deactivate');
        $this->getJson('/messages')->assertForbidden();
        $this->actingAs($other)->getJson('/messages')->assertOk();
        Auth::logout();
    }

    public function test_central_csrf_is_enforced_outside_unit_test_mode(): void
    {
        $this->app['env'] = 'identity-lab';
        $this->postJson('https://localhost/identity/start')->assertStatus(419);
        $token = bin2hex(random_bytes(20));
        $this->withSession(['_token' => $token])->withHeader('X-CSRF-TOKEN', $token)
            ->postJson('https://localhost/identity/start')->assertOk();
        $this->getJson('/messages')->assertUnauthorized();
        $this->assertGuest();
    }
}
