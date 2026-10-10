<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\Permissao;
use App\Models\User;
use App\Services\CentralIdentitySnapshot;
use App\Services\CentralOwnerPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CentralOwnerTest extends TestCase
{
    use RefreshDatabase;
    private string $file;
    private User $owner;
    private array $policy;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        config(['central_ia.enabled'=>true,'central_ia.ui_enabled'=>true,'central_ia.exclusive_enabled'=>false]);
        $this->file=tempnam('/tmp','owner-policy-'); chmod($this->file,0600);
        $this->owner=$this->account('Felipe');
        $s=app(CentralIdentitySnapshot::class)->forUser($this->owner);
        $this->policy=['version'=>1,'issuer'=>'alfamatriz','confirmed'=>true,'user_id'=>$this->owner->id,'subject'=>$s['subject']];
        file_put_contents($this->file,json_encode($this->policy));
        config(['central_ia.exclusive_enabled'=>true,'central_ia.owner_policy_file'=>$this->file]);
    }
    protected function tearDown(): void { unlink($this->file); parent::tearDown(); }
    private function account(string $name): User
    {
        $u=User::factory()->semPerfil()->create(['name'=>$name,'ativo'=>true,'primeiro_acesso'=>false]);
        $p=Perfil::create(['nome'=>'owner-'.$u->id,'slug'=>'owner-'.$u->id]);
        $p->permissoes()->attach(Permissao::where('recurso','central_ia')->value('id'),['ler'=>true,'incluir'=>true,'editar'=>true]);
        $u->perfis()->attach($p); return $u;
    }
    public function test_confirmed_owner_with_permission_can_open_native_module(): void
    {
        $this->actingAs($this->owner)->get('/central-ia')->assertOk();
        $this->assertContains('read',app(CentralIdentitySnapshot::class)->forUser($this->owner)['operations']);
        Http::assertNothingSent();
    }
    public function test_all_other_users_even_admin_with_explicit_permissions_are_denied(): void
    {
        foreach (['Rossini','Alexandre','Administrador'] as $name) {
            $u=$this->account($name);
            if ($name==='Administrador') $u->perfis()->attach(Perfil::where('slug','admin')->value('id'));
            $this->actingAs($u)->get('/central-ia')->assertForbidden();
            $this->getJson('/central-ia/messages?owner_id='.$this->owner->id)->assertForbidden();
            $this->postJson('/central-ia/messages',['content'=>'invasao','client_id'=>(string)\Illuminate\Support\Str::uuid()])->assertForbidden();
            $this->postJson('/central-ia/tickets',['flow'=>str_repeat('a',64)])->assertForbidden();
            $this->assertSame([],app(CentralIdentitySnapshot::class)->forUser($u)['operations']);
            $this->actingAs($u)->get('/profile')->assertDontSee('Central de IA');
        }
        $this->assertDatabaseCount('central_ia_tickets',0); Http::assertNothingSent();
    }
    public function test_name_email_and_browser_ids_do_not_authorize_another_account(): void
    {
        $email=$this->owner->email; $this->owner->update(['email'=>'old-owner@example.invalid']);
        $other=$this->account('Felipe'); $other->update(['email'=>$email]);
        $this->actingAs($other)->get('/central-ia?user_id='.$this->owner->id)->assertForbidden();
    }
    public function test_missing_unconfirmed_mismatched_or_insecure_policy_fails_closed(): void
    {
        foreach ([[],array_merge($this->policy,['confirmed'=>false]),array_merge($this->policy,['user_id'=>$this->owner->id+1]),array_merge($this->policy,['subject'=>(string)\Illuminate\Support\Str::uuid()])] as $p) {
            file_put_contents($this->file,json_encode($p)); $this->actingAs($this->owner)->get('/central-ia')->assertForbidden();
        }
        file_put_contents($this->file,json_encode($this->policy));chmod($this->file,0644);
        $this->assertFalse(app(CentralOwnerPolicy::class)->allows($this->owner));
    }
    public function test_owner_still_requires_active_account_and_native_permission(): void
    {
        $this->owner->update(['ativo'=>false]); $this->actingAs($this->owner)->get('/central-ia')->assertForbidden();
        $this->owner->update(['ativo'=>true]);
        $permission=Permissao::where('recurso','central_ia')->value('id');
        foreach ($this->owner->perfis as $p) $p->permissoes()->updateExistingPivot($permission,['ler'=>false]);
        $this->get('/central-ia')->assertForbidden();
    }
    public function test_old_tickets_are_not_redeemable_after_owner_policy_changes(): void
    {
        $flow=str_repeat('b',64);$ticket=app(\App\Services\CentralIdentityTickets::class)->issue($this->owner,$flow);
        file_put_contents($this->file,'{}');
        $key=tempnam('/tmp','owner-key-');file_put_contents($key,str_repeat('k',64));chmod($key,0600);
        try { config(['central_ia.key_file'=>$key]);
            $this->withHeader('Authorization','Bearer '.str_repeat('k',64))->postJson('https://localhost/internal/central-ia/redeem',['ticket'=>$ticket['ticket'],'flow'=>$flow])->assertForbidden();
            $this->assertNull(DB::table('central_ia_tickets')->value('used_at'));
        } finally { unlink($key); }
    }
}
