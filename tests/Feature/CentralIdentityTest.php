<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use App\Services\CentralIdentitySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CentralIdentityTest extends TestCase
{
    use RefreshDatabase;

    private string $keyFile;

    private string $key;

    private User $felipe;

    private User $rossini;

    private User $alexandre;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.exclusive_enabled' => false]);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'bridge-test-');
        $this->key = bin2hex(random_bytes(32));
        file_put_contents($this->keyFile, $this->key);
        chmod($this->keyFile, 0600);
        config(['central_ia.browser_tickets_enabled' => true, 'central_ia.enabled' => true, 'central_ia.key_file' => $this->keyFile]);
        foreach (['felipe' => 'Felipe', 'rossini' => 'Rossini', 'alexandre' => 'Alexandre'] as $property => $name) {
            $this->$property = User::factory()->semPerfil()->create(['name' => $name, 'email' => $property.'@example.invalid', 'ativo' => true, 'primeiro_acesso' => false]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->keyFile)) {
            unlink($this->keyFile);
        }
        parent::tearDown();
    }

    private function grant(User $user, bool $write = true): Perfil
    {
        $profile = Perfil::create(['nome' => 'fixture-'.$user->id, 'slug' => 'fixture-'.$user->id]);
        $profile->permissoes()->attach(Permissao::where('recurso', 'central_ia')->value('id'), ['ler' => true, 'incluir' => $write, 'editar' => $write]);
        $user->perfis()->attach($profile);

        return $profile;
    }

    private function issue(User $user, string $flow): string
    {
        return $this->actingAs($user)->postJson('https://localhost/central-ia/tickets', ['flow' => $flow])->assertOk()->json('ticket');
    }

    private function technical(string $path, array $payload)
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('https://localhost/internal/central-ia/'.$path, $payload);
    }

    public function test_existing_login_authenticates_a_fixture_without_new_accounts(): void
    {
        $this->felipe->update(['password' => 'fixture-only-password']);
        $count = User::count();
        $this->post('/login', ['email' => $this->felipe->email, 'password' => 'fixture-only-password'])->assertRedirect();
        $this->assertAuthenticatedAs($this->felipe);
        $this->assertSame($count, User::count());
    }

    public function test_existing_login_rejects_inactive_account(): void
    {
        $this->felipe->update(['password' => 'fixture-only-password', 'ativo' => false]);
        $this->post('/login', ['email' => $this->felipe->email, 'password' => 'fixture-only-password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_anonymous_or_browser_supplied_identity_cannot_issue_ticket(): void
    {
        $this->postJson('https://localhost/central-ia/tickets', ['flow' => str_repeat('a', 64)])->assertUnauthorized();
        $this->grant($this->felipe);
        foreach (['user_id', 'subject', 'owner_id', 'redirect_uri'] as $field) {
            $this->actingAs($this->felipe)->postJson('https://localhost/central-ia/tickets', ['flow' => str_repeat('a', 64), $field => $this->rossini->id])->assertStatus(422);
        }
        $this->assertDatabaseCount('central_ia_tickets', 0);
    }

    public function test_technical_endpoint_requires_dedicated_key_and_https_even_for_admin_session(): void
    {
        $snapshot = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $this->actingAs($this->felipe)->postJson('https://localhost/internal/central-ia/inspect', ['subject' => $snapshot['subject']])->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer wrong-fixture')->postJson('https://localhost/internal/central-ia/inspect', ['subject' => $snapshot['subject']])->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$this->key)->postJson('http://localhost/internal/central-ia/inspect', ['subject' => $snapshot['subject']])->assertForbidden();
    }

    public function test_snapshot_reuses_existing_permissions_and_exposes_no_account_or_credentials(): void
    {
        $this->grant($this->felipe, false);
        $snapshot = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $response = $this->technical('inspect', ['subject' => $snapshot['subject']])->assertOk()->assertJsonPath('operations', ['read']);
        foreach (['password', 'email', 'user_id', 'profiles', 'remember_token'] as $field) {
            $this->assertArrayNotHasKey($field, $response->json());
        }
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_three_users_receive_independent_subjects_and_tickets(): void
    {
        $subjects = [];
        foreach ([$this->felipe, $this->rossini, $this->alexandre] as $user) {
            $this->grant($user);
            $flow = bin2hex(random_bytes(32));
            $ticket = $this->issue($user, $flow);
            $snapshot = $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertOk()->json();
            $subjects[] = $snapshot['subject'];
            $this->assertSame($user->id, DB::table('central_ia_subjects')->where('subject', $snapshot['subject'])->value('user_id'));
            $this->assertDatabaseMissing('central_ia_tickets', ['ticket_hash' => $ticket]);
        }
        $this->assertCount(3, array_unique($subjects));
    }

    public function test_no_permission_and_admin_role_do_not_implicitly_grant_central_access(): void
    {
        $admin = Perfil::firstOrCreate(['slug' => 'admin'], ['nome' => 'Administrador']);
        $this->felipe->perfis()->attach($admin);
        $this->assertTrue($this->felipe->fresh()->ehAdmin());
        $this->actingAs($this->felipe)->postJson('https://localhost/central-ia/tickets', ['flow' => str_repeat('a', 64)])->assertForbidden();
        $this->assertDatabaseCount('central_ia_tickets', 0);
    }

    public function test_ticket_is_single_use_and_bound_to_flow(): void
    {
        $this->grant($this->felipe);
        $flow = str_repeat('a', 64);
        $ticket = $this->issue($this->felipe, $flow);
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => str_repeat('b', 64)])->assertForbidden();
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertOk();
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertForbidden();
    }

    public function test_expired_ticket_is_refused(): void
    {
        $this->grant($this->felipe);
        $flow = str_repeat('a', 64);
        $ticket = $this->issue($this->felipe, $flow);
        $this->travel(121)->seconds();
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertForbidden();
    }

    public function test_permission_revocation_is_fresh_and_invalidates_pending_ticket(): void
    {
        $profile = $this->grant($this->felipe);
        $flow = str_repeat('a', 64);
        $ticket = $this->issue($this->felipe, $flow);
        $before = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $profile->permissoes()->updateExistingPivot(Permissao::where('recurso', 'central_ia')->value('id'), ['ler' => false]);
        $this->technical('inspect', ['subject' => $before['subject']])->assertOk()->assertJsonPath('operations', [])->assertJsonPath('session_epoch', $before['session_epoch'] + 1);
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertForbidden();
    }

    public function test_inactive_or_password_reset_account_invalidates_ticket(): void
    {
        $this->grant($this->felipe);
        $flow = str_repeat('a', 64);
        $ticket = $this->issue($this->felipe, $flow);
        $this->felipe->update(['password' => 'another-fixture-password']);
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertForbidden();
        $snapshot = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $this->felipe->update(['ativo' => false]);
        $this->technical('inspect', ['subject' => $snapshot['subject']])->assertOk()->assertJsonPath('active', false)->assertJsonPath('operations', []);
    }

    public function test_bridge_disabled_or_key_missing_is_fail_closed(): void
    {
        config(['central_ia.enabled' => false]);
        $this->technical('inspect', ['subject' => '00000000-0000-4000-8000-000000000001'])->assertNotFound();
        config(['central_ia.enabled' => true, 'central_ia.key_file' => '/tmp/nonexistent-fixture-key']);
        $this->technical('inspect', ['subject' => '00000000-0000-4000-8000-000000000001'])->assertStatus(503);
    }

    public function test_first_access_deleted_account_and_logout_cannot_keep_central_session_valid(): void
    {
        $this->grant($this->felipe);
        $snapshot = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $this->actingAs($this->felipe)->post('/logout')->assertRedirect();
        $after = $this->technical('inspect', ['subject' => $snapshot['subject']])->assertOk()->json();
        $this->assertGreaterThan($snapshot['session_epoch'], $after['session_epoch']);
        $this->felipe->update(['primeiro_acesso' => true]);
        $this->technical('inspect', ['subject' => $snapshot['subject']])->assertOk()->assertJsonPath('ready', false)->assertJsonPath('operations', []);
        $this->felipe->delete();
        $this->technical('inspect', ['subject' => $snapshot['subject']])->assertOk()->assertJsonPath('active', false)->assertJsonPath('operations', []);
    }

    public function test_ticket_flow_password_and_machine_key_are_absent_from_storage_and_logs(): void
    {
        $this->grant($this->felipe);
        $password = bin2hex(random_bytes(24));
        $this->felipe->update(['password' => $password]);
        $flow = bin2hex(random_bytes(32));
        $ticket = $this->issue($this->felipe, $flow);
        $stored = json_encode(DB::table('central_ia_tickets')->get(), JSON_THROW_ON_ERROR);
        $log = is_file(storage_path('logs/laravel.log')) ? file_get_contents(storage_path('logs/laravel.log')) : '';
        $audit = json_encode(DB::table('auditorias')->get(), JSON_THROW_ON_ERROR);
        foreach ([$flow, $ticket, $this->key, $password] as $value) {
            $this->assertFalse(str_contains($stored, $value), 'secret_in_ticket_storage');
            $this->assertFalse(str_contains($log, $value), 'secret_in_log');
            $this->assertFalse(str_contains($audit, $value), 'secret_in_audit');
        }
    }

    public function test_real_profile_controller_revokes_generation_even_if_permission_is_immediately_restored(): void
    {
        $profile = $this->grant($this->felipe);
        $flow = str_repeat('a', 64);
        $ticket = $this->issue($this->felipe, $flow);
        $before = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $operator = User::factory()->create();
        $this->actingAs($operator)->put('/perfis/'.$profile->id.'/permissoes', ['grade' => []])->assertRedirect();
        $this->put('/perfis/'.$profile->id.'/permissoes', ['grade' => ['central_ia' => ['ler' => true, 'incluir' => true, 'editar' => true]]])->assertRedirect();
        $after = app(CentralIdentitySnapshot::class)->forUser($this->felipe);
        $this->assertGreaterThan($before['session_epoch'], $after['session_epoch']);
        $this->technical('redeem', ['ticket' => $ticket, 'flow' => $flow])->assertForbidden();
    }
}
