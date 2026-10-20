<?php

namespace Tests\Feature\Suporte;

use App\Models\Cliente;
use App\Models\ClienteTelefone;
use App\Models\Perfil;
use App\Models\SuporteConversa;
use App\Models\SuporteMensagem;
use App\Models\User;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** O WhatsApp do suporte, etapa 1 (docs/specs/suporte-pedro-whatsapp). */
class SuporteWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private User $rossini;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-20 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 'token-falso', 'app_secret' => 'segredo', 'verify_token' => 'verifica', 'graph_version' => 'v20.0']]);
        (new PerfilPermissaoSeeder)->run();
        $this->rossini = User::factory()->create(['name' => 'Rossini']);
    }

    private function webhook(array $valor, string $campo = 'messages', ?string $assinatura = null)
    {
        $corpo = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '9', 'changes' => [['field' => $campo,
            'value' => ['messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => '552731093265', 'phone_number_id' => '111222']] + $valor]]]]]);

        return $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $assinatura ?? 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo);
    }

    private function mensagem(string $id, string $de, string $texto, string $nome = 'Ana'): array
    {
        return ['contacts' => [['wa_id' => $de, 'profile' => ['name' => $nome]]],
            'messages' => [['from' => $de, 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
    }

    public function test_aperto_de_mao_e_assinatura(): void
    {
        $this->get(route('suporte.webhook.verificar', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verifica', 'hub.challenge' => 'abc123']))->assertOk()->assertSee('abc123');
        $this->get(route('suporte.webhook.verificar', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'errado', 'hub.challenge' => 'x']))->assertForbidden();
        $this->webhook($this->mensagem('wamid.1', '5527999998888', 'oi'), assinatura: 'sha256=falsa')->assertForbidden();
        $this->assertSame(0, SuporteConversa::count());
    }

    public function test_mensagem_do_cliente_reconhece_pelo_telefone_sem_duplicar(): void
    {
        $academia = Cliente::create(['nome' => 'Academia Boa Forma', 'ativo' => true]);
        // Cadastrado com o 9º dígito; o WhatsApp manda sem ele: a chave é DDD + 8 últimos.
        ClienteTelefone::create(['cliente_id' => $academia->id, 'telefone' => '(27) 99999-8888', 'principal' => true]);

        $this->webhook($this->mensagem('wamid.1', '552799998888', 'A catraca não libera'))->assertOk();
        $this->webhook($this->mensagem('wamid.1', '552799998888', 'A catraca não libera'))->assertOk();
        $c = SuporteConversa::sole();
        $this->assertSame([$academia->id, 'Ana', 1, 'pedro'], [$c->cliente_id, $c->nome_perfil, $c->nao_lidas, $c->situacao]);
        $this->assertSame(1, SuporteMensagem::count(), 'a Meta reentrega; a mesma mensagem não entra duas vezes');
        $this->assertTrue($c->janelaAberta());

        // Outro número de telefone da Meta (outro app) não entra.
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => '999']] + $this->mensagem('wamid.2', '5511911112222', 'x')]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
        $this->assertSame(1, SuporteConversa::count());
    }

    public function test_resposta_pelo_app_do_celular_tira_o_pedro_da_conversa(): void
    {
        $this->webhook($this->mensagem('wamid.1', '5527988887777', 'oi'));
        $this->webhook(['message_echoes' => [['from' => '552731093265', 'to' => '5527988887777', 'id' => 'wamid.eco', 'timestamp' => (string) now()->timestamp,
            'type' => 'text', 'text' => ['body' => 'Bom dia, aqui é o Rossini']]]], 'smb_message_echoes')->assertOk();
        $c = SuporteConversa::sole();
        $this->assertSame('humano', $c->situacao);
        $this->assertTrue($c->comHumano());
        $this->assertSame(['entrada', 'humano_app'], $c->mensagens()->pluck('direcao')->all());
        Carbon::setTestNow(now()->addHours(25));
        $this->assertFalse($c->fresh()->comHumano(), 'passado o prazo, volta ao Pedro');
    }

    public function test_responder_pelo_matriz_assume_e_respeita_a_janela(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.saida']]])]);
        $this->webhook($this->mensagem('wamid.1', '5527988887777', 'oi'));
        $c = SuporteConversa::sole();

        $this->actingAs($this->rossini)->post(route('suporte.responder', $c->id), ['texto' => 'Olá, vou verificar'])->assertRedirect();
        Http::assertSent(fn ($r) => $r->url() === 'https://graph.facebook.com/v20.0/111222/messages' && $r['to'] === '5527988887777' && $r['text']['body'] === 'Olá, vou verificar');
        $c->refresh();
        $this->assertSame(['humano', $this->rossini->id], [$c->situacao, $c->humano_id]);
        $this->assertSame('wamid.saida', SuporteMensagem::where('direcao', 'humano_matriz')->value('wamid'));

        $this->webhook(['statuses' => [['id' => 'wamid.saida', 'status' => 'read']]])->assertOk();
        $this->assertSame('read', SuporteMensagem::where('wamid', 'wamid.saida')->value('status'));

        $this->post(route('suporte.devolver', $c->id));
        $this->assertSame('pedro', $c->fresh()->situacao);

        Carbon::setTestNow(now()->addHours(25));
        $this->post(route('suporte.responder', $c->id), ['texto' => 'Ainda aí?'])->assertSessionHasErrors('texto');
        Http::assertSentCount(1);
    }

    public function test_tela_vincular_numero_e_permissao(): void
    {
        $this->webhook($this->mensagem('wamid.1', '5527977776666', 'Sou da Padaria Monte Líbano', 'Joana'));
        $c = SuporteConversa::sole();
        $padaria = Cliente::create(['nome' => 'Padaria Monte Líbano', 'ativo' => true]);

        $this->actingAs($this->rossini)->get(route('suporte.conversas', $c->id))->assertOk()
            ->assertSee('Joana')->assertSee('Sou da Padaria Monte Líbano')->assertSee('Número sem cadastro');
        $this->assertSame(0, $c->fresh()->nao_lidas);
        $this->post(route('suporte.vincular', $c->id), ['cliente_id' => $padaria->id])->assertSessionHas('status');
        $this->assertSame($padaria->id, $c->fresh()->cliente_id);
        $this->assertSame('+55 (27) 97777-6666', ClienteTelefone::where('cliente_id', $padaria->id)->value('telefone'));

        $victor = User::factory()->semPerfil()->create();
        $victor->perfis()->attach(Perfil::where('slug', 'vixcard')->value('id'));
        $this->actingAs($victor)->get(route('suporte.conversas'))->assertForbidden();
    }
}
