<?php

namespace Tests\Feature;

use App\Models\CentralIdentity;
use App\Models\ChannelBinding;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Identity\Access;
use App\Services\Identity\OwnerPolicy;
use App\Services\TelegramIngestor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExclusiveOwnerTest extends TestCase
{
    use RefreshDatabase;
    private string $file;
    private User $owner;
    protected function setUp(): void
    {
        parent::setUp();$this->owner=User::factory()->create(['name'=>'Felipe']);
        $this->file=tempnam('/tmp','exclusive-policy-');chmod($this->file,0600);
        $s=CentralIdentity::where('user_id',$this->owner->id)->firstOrFail();
        $s->update(['subject'=>(string)\Illuminate\Support\Str::uuid()]);$s->refresh();
        file_put_contents($this->file,json_encode(['version'=>1,'issuer'=>'alfamatriz','confirmed'=>true,'user_id'=>77,'subject'=>$s->subject,'telegram_user_id'=>'1234567']));
        config(['central.exclusive_enabled'=>true,'central.owner_policy_file'=>$this->file]);
    }
    protected function tearDown(): void { unlink($this->file);parent::tearDown(); }
    public function test_owner_access_and_every_other_identity_denied_before_remote_inspection(): void
    {
        $this->assertSame($this->owner->id,app(Access::class)->authorize($this->owner,'read')->user_id);
        foreach (['Rossini','Alexandre','Administrador'] as $name) {
            $u=User::factory()->create(['name'=>$name]);
            $this->actingAs($u)->getJson('/messages')->assertForbidden();
            $this->getJson('/integrations')->assertForbidden();
            $this->getJson('/telegram/pair/pending')->assertForbidden();
        }
    }
    public function test_internal_only_disables_every_independent_web_route(): void
    {
        config(['central.internal_only'=>true]);
        foreach (['/','/login','/messages','/integrations','/telegram/pair/pending','/agent-runs/1'] as $path) $this->getJson($path)->assertNotFound();
        foreach (['/login','/identity/start','/identity/complete','/messages','/telegram/pair','/messages/1/run'] as $path) $this->postJson($path)->assertNotFound();
        $this->get('/up')->assertOk();
        $this->postJson('https://localhost/internal/alfa/history')->assertNotFound(); // disabled bridge, never human login
    }
    public function test_telegram_rejects_wrong_numeric_sender_even_with_existing_owner_binding(): void
    {
        $c=Conversation::create(['user_id'=>$this->owner->id]);
        ChannelBinding::create(['user_id'=>$this->owner->id,'conversation_id'=>$c->id,'telegram_user_id'=>'9999999','chat_id'=>'9999999']);
        app(TelegramIngestor::class)->ingest(['update_id'=>11,'message'=>['chat'=>['id'=>9999999,'type'=>'private'],'from'=>['id'=>9999999],'text'=>'wrong sender']]);
        $this->assertDatabaseCount('messages',0);
        $this->assertTrue(app(OwnerPolicy::class)->telegram('1234567'));
        $this->assertFalse(app(OwnerPolicy::class)->telegram('9999999'));
    }
    public function test_telegram_owner_binding_does_not_authorize_other_central_user(): void
    {
        $other=User::factory()->create();$c=Conversation::create(['user_id'=>$other->id]);
        ChannelBinding::create(['user_id'=>$other->id,'conversation_id'=>$c->id,'telegram_user_id'=>'1234567','chat_id'=>'1234567']);
        app(TelegramIngestor::class)->ingest(['update_id'=>12,'message'=>['chat'=>['id'=>1234567,'type'=>'private'],'from'=>['id'=>1234567],'text'=>'wrong owner']]);
        $this->assertDatabaseCount('messages',0);
    }
    public function test_missing_policy_rejects_existing_sessions_and_telegram(): void
    {
        file_put_contents($this->file,'{}');$this->actingAs($this->owner)->getJson('/messages')->assertForbidden();
        $this->assertFalse(app(OwnerPolicy::class)->telegram('1234567'));
    }
}
