<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralIaInterfaceTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    private string $key;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.exclusive_enabled' => false]);
        $this->file = tempnam('/tmp', 'central-ui-key-');
        $this->key = bin2hex(random_bytes(32));
        file_put_contents($this->file, $this->key);
        chmod($this->file, 0600);
        config(['central_ia.enabled' => true, 'central_ia.ui_enabled' => true,
            'central_ia.backend_url' => 'https://central.fixture', 'central_ia.backend_key_file' => $this->file]);
        $this->owner = $this->account('Felipe');
    }

    protected function tearDown(): void
    {
        unlink($this->file);
        parent::tearDown();
    }

    private function account(string $name, bool $send = true): User
    {
        $user = User::factory()->semPerfil()->create(['name' => $name, 'ativo' => true, 'primeiro_acesso' => false]);
        $profile = Perfil::create(['nome' => $name, 'slug' => 'central-'.$user->id]);
        $profile->permissoes()->attach(Permissao::where('recurso', 'central_ia')->value('id'), ['ler' => true, 'incluir' => $send, 'editar' => false]);
        $user->perfis()->attach($profile);

        return $user;
    }

    private function backend(int $historyStatus = 200): void
    {
        Http::fake([
            'https://central.fixture/internal/alfa/session' => Http::response(['session_token' => str_repeat('a', 64), 'expires_in' => 900]),
            'https://central.fixture/internal/alfa/history' => Http::response(['messages' => [], 'deliveries' => [], 'has_more' => false, 'telegram_paired' => false, 'secret' => $this->key], $historyStatus),
            'https://central.fixture/internal/alfa/send' => Http::response(['id' => 7]),
        ]);
    }

    public function test_native_shell_menu_and_composer_without_backend_or_executor(): void
    {
        $this->actingAs($this->owner)->get('/central-ia')->assertOk()->assertSee('Central de IA')->assertSee('data-central-ia', false)->assertSee('Sua conversa privada');
        Http::assertNothingSent();
        config(['central_ia.ui_enabled' => false]);
        $this->get('/central-ia')->assertNotFound();
    }

    public function test_admin_without_explicit_permission_cannot_open_module(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/central-ia')->assertForbidden();
        $this->getJson('/central-ia/messages')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_guest_and_inactive_accounts_cannot_reach_backend(): void
    {
        $this->getJson('/central-ia/messages')->assertUnauthorized();
        $this->owner->update(['ativo' => false]);
        $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_tickets_and_technical_session_stay_on_server(): void
    {
        $this->backend();
        $response = $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertOk()->assertJsonPath('messages', []);
        foreach ([$this->key, str_repeat('a', 64), 'secret', 'session_token', 'subject'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/session') && isset($r['ticket'],$r['flow']) && ! isset($r['user_id']));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/history') && $r->hasHeader('Authorization', 'Bearer '.$this->key) && $r->hasHeader('X-Central-Session', str_repeat('a', 64)));
        $this->assertStringNotContainsString(str_repeat('a', 64), json_encode(session()->get('central_ia.backend')));
        $this->actingAs($this->owner)->postJson('/central-ia/tickets', ['flow' => str_repeat('b', 64)])->assertNotFound();
        $ticket = DB::table('central_ia_tickets')->first();
        $this->assertSame(64, strlen($ticket->ticket_hash));
    }

    public function test_browser_owner_and_session_ids_are_refused(): void
    {
        foreach (['user_id', 'owner_id', 'subject', 'session_token', 'conversation_id'] as $field) {
            $this->actingAs($this->owner)->getJson('/central-ia/messages?'.$field.'=2')->assertStatus(422);
            $this->postJson('/central-ia/messages', ['content' => 'fixture', 'client_id' => (string) Str::uuid(), $field => '2'])->assertStatus(422);
        }
        Http::assertNothingSent();
    }

    public function test_read_permission_does_not_grant_send(): void
    {
        $reader = $this->account('Rossini', false);
        $this->backend();
        $this->actingAs($reader)->get('/central-ia')->assertOk()->assertSee('canSend: false', false);
        $this->getJson('/central-ia/messages')->assertOk();
        $this->postJson('/central-ia/messages', ['content' => 'fixture', 'client_id' => (string) Str::uuid()])->assertForbidden();
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/send'));
    }

    public function test_send_keeps_client_id_and_payload_and_does_not_include_identity(): void
    {
        $this->backend();
        $id = (string) Str::uuid();
        $this->actingAs($this->owner)->postJson('/central-ia/messages', ['content' => 'mensagem de teste', 'client_id' => $id])->assertCreated()->assertExactJson(['id' => 7]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/send') && $r->data() === ['content' => 'mensagem de teste', 'client_id' => $id]);
    }

    public function test_revoked_permission_does_not_reuse_backend_session(): void
    {
        $this->backend();
        $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertOk();
        $this->owner->perfis->first()->permissoes()->updateExistingPivot(Permissao::where('recurso', 'central_ia')->value('id'), ['ler' => false]);
        $this->getJson('/central-ia/messages')->assertForbidden();
    }

    public function test_backend_failure_is_sanitized_and_private(): void
    {
        $this->backend(503);
        $response = $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertStatus(503)->assertHeader('Cache-Control', 'no-store, private');
        foreach ([$this->key, 'central.fixture', 'session_token', 'ticket'] as $value) {
            $this->assertStringNotContainsString($value, $response->getContent());
        }
    }

    public function test_redirects_and_paid_fallback_are_not_used(): void
    {
        $this->backend(302);
        $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertStatus(503);
        Http::assertSentCount(2);
    }

    public function test_cookie_backed_session_is_refused_before_network(): void
    {
        config(['session.driver' => 'cookie']);
        $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_plaintext_backend_or_invalid_ca_configuration_cannot_bypass_tls(): void
    {
        config(['central_ia.backend_url' => 'http://central.fixture']);
        $this->actingAs($this->owner)->getJson('/central-ia/messages')->assertStatus(503);
        Http::assertNothingSent();
    }
}
