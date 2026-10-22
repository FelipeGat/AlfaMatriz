<?php

namespace Tests\Feature\Suporte;

use App\Models\CentralIaRunner;
use App\Models\Notificacao;
use App\Models\SuporteAvaliacao;
use App\Models\SuporteConversa;
use App\Models\SuportePedido;
use App\Models\Tarefa;
use App\Models\User;
use App\Services\Suporte\Encerramento;
use App\Services\Suporte\Pedro;
use App\Services\Suporte\Transferencia;
use Database\Seeders\PerfilPermissaoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A conversa não fica presa com o Pedro: fila, atendente pelo nome, lembrete da fila,
 * e o encerramento com avaliação (docs/specs/suporte-pedro-whatsapp/encerramento.md).
 */
class PedroFilaEAvaliacaoTest extends TestCase
{
    use RefreshDatabase;

    private User $felipe;
    private User $alexandre;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-21 10:00', 'America/Sao_Paulo'));
        config(['services.whatsapp_suporte' => ['phone_number_id' => '111222', 'token' => 't', 'app_secret' => 'segredo', 'verify_token' => 'v', 'graph_version' => 'v20.0'],
            'services.suporte_pedro.conta' => 'felipe@teste.local', 'services.suporte_pedro.responsaveis' => ['alexandre@teste.local']]);
        Http::fake(['graph.facebook.com/*' => fn () => Http::response(['messages' => [['id' => 'wamid.out'.uniqid('', true)]]])]);
        (new PerfilPermissaoSeeder)->run();
        $this->felipe = User::factory()->create(['name' => 'Felipe Gat', 'email' => 'felipe@teste.local']);
        $this->alexandre = User::factory()->create(['name' => 'Alexandre Blank', 'email' => 'alexandre@teste.local']);
        CentralIaRunner::create(['user_id' => $this->felipe->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
    }

    private function chega(string $texto, string $id): void
    {
        $valor = ['metadata' => ['phone_number_id' => '111222'], 'contacts' => [['wa_id' => '5527988887777', 'profile' => ['name' => 'Síndica']]],
            'messages' => [['from' => '5527988887777', 'id' => $id, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $texto]]]];
        $corpo = json_encode(['entry' => [['changes' => [['field' => 'messages', 'value' => $valor]]]]]);
        $this->call('POST', route('suporte.webhook'), [], [], [], ['CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $corpo, 'segredo')], $corpo)->assertOk();
    }

    private function responde(array $bloco): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->felipe, ['central-ia-runner']);
        $job = $this->postJson(route('suporte.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
        $this->postJson(route('suporte.runner.resposta', $job['id']), ['maquina' => 'wsl',
            'texto' => "<<<PEDRO\n".json_encode($bloco, JSON_UNESCAPED_UNICODE)."\nPEDRO>>>"])->assertOk();
    }

    private function ultimaDoPedro(): string
    {
        return (string) SuporteConversa::sole()->mensagens()->reorder()->whereIn('direcao', ['pedro', 'humano_matriz'])->orderByDesc('id')->value('texto');
    }

    public function test_cliente_pede_um_atendente_pelo_nome(): void
    {
        $this->chega('Quero falar com o Alexandre', 'wamid.1');
        $this->responde(['resposta' => 'Claro!', 'acao' => 'transferir', 'resumo' => 'quer falar com o Alexandre sobre a catraca', 'atendente' => 'Alexandre']);

        $c = SuporteConversa::sole();
        $this->assertSame(['humano', $this->alexandre->id], [$c->situacao, $c->humano_id]);
        $this->assertStringContainsString('Já chamei o Alexandre, ele continua com você por aqui.', $this->ultimaDoPedro());
        $this->assertSame(1, Notificacao::where('destinatario_id', $this->alexandre->id)->where('titulo', 'like', 'Chamaram você no WhatsApp%')->count());
        $this->assertSame(0, Notificacao::where('destinatario_id', $this->felipe->id)->count(), 'só quem foi chamado');

        // Com uma pessoa, o Pedro não responde mais.
        $this->chega('alô?', 'wamid.2');
        $this->assertSame(1, SuportePedido::count());
    }

    public function test_nome_desconhecido_vai_para_a_fila_e_a_fila_parada_e_lembrada(): void
    {
        $this->chega('quero falar com a Marta', 'wamid.1');
        $this->responde(['resposta' => 'Certo.', 'acao' => 'transferir', 'resumo' => 'quer a Marta', 'atendente' => 'Marta']);
        $c = SuporteConversa::sole();
        $this->assertNull($c->humano_id);
        $this->assertNotNull($c->fila_desde);
        $this->assertStringContainsString('Já avisei a equipe que você deseja falar com Marta', $this->ultimaDoPedro());
        $this->assertSame(2, Notificacao::where('titulo', 'like', 'Na fila do WhatsApp%')->count(), 'toda a equipe de atendimento');

        $this->assertSame(0, app(Transferencia::class)->lembrarFila(), 'antes de 15 min, não');
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, app(Transferencia::class)->lembrarFila());
        $this->assertSame(2, Notificacao::where('titulo', 'like', 'Ainda sem resposta%')->count());
        $this->assertSame(0, Notificacao::where('titulo', 'like', 'Na fila do WhatsApp%')->count(), 'o lembrete substitui o aviso anterior, sem empilhar');
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(1, app(Transferencia::class)->lembrarFila());
        $this->assertSame(2, Notificacao::where('titulo', 'like', 'Ainda sem resposta%')->count(), 'um aviso por pessoa, mesmo depois de vários lembretes');
        Carbon::setTestNow(now()->addMinutes(16)); // 48 min na fila
        $this->assertSame(1, app(Transferencia::class)->lembrarFila());
        Carbon::setTestNow(now()->addMinutes(16)); // 64 min: passada a primeira hora, de hora em hora
        $this->assertSame(0, app(Transferencia::class)->lembrarFila());
        Carbon::setTestNow(now()->addMinutes(45)); // 1 h depois do último
        $this->assertSame(1, app(Transferencia::class)->lembrarFila());

        // Alguém respondeu: para de lembrar.
        $this->actingAs($this->alexandre)->post(route('suporte.responder', $c->id), ['texto' => 'Oi! Sou o Alexandre.'])->assertRedirect();
        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame(0, app(Transferencia::class)->lembrarFila());
    }

    public function test_esgotou_vai_para_a_fila_e_nao_para_o_quadro(): void
    {
        config(['services.suporte_pedro.limite_respostas' => 1]);
        $this->chega('a catraca não libera', 'wamid.1');
        $this->responde(['resposta' => 'Desligue e ligue de novo.', 'acao' => 'responder']);
        $this->chega('não resolveu', 'wamid.2');

        $c = SuporteConversa::sole();
        $this->assertSame(['humano', null], [$c->situacao, $c->humano_id]);
        $this->assertSame(0, Tarefa::count(), 'nada no quadro do Matriz');
        $this->assertStringContainsString(Pedro::ESPECIALISTA, $this->ultimaDoPedro());
    }

    public function test_pedro_encerra_e_a_nota_e_registrada(): void
    {
        $this->chega('resolveu, obrigada!', 'wamid.1');
        $this->responde(['resposta' => 'Que bom! Até mais.', 'acao' => 'encerrar']);
        $c = SuporteConversa::sole();
        $this->assertSame('avaliacao', $c->situacao);
        $this->assertSame(Encerramento::PEDIDO, $this->ultimaDoPedro());

        $this->chega('5 - muito rápido', 'wamid.2');
        $a = SuporteAvaliacao::sole();
        $this->assertSame([5, 'muito rápido', null], [$a->nota, $a->comentario, $a->encerrado_por_id]);
        $this->assertSame(Encerramento::OBRIGADO, $this->ultimaDoPedro());
        $this->assertSame('pedro', $c->fresh()->situacao);
        $this->assertSame(1, SuportePedido::count(), 'a nota não vai para a IA');
    }

    public function test_atendente_encerra_nota_baixa_avisa_e_texto_qualquer_volta_ao_pedro(): void
    {
        $this->chega('o portão travou', 'wamid.1');
        $c = SuporteConversa::sole();
        $this->actingAs($this->alexandre)->post(route('suporte.assumir', $c->id))->assertRedirect();
        $this->actingAs($this->alexandre)->post(route('suporte.encerrar', $c->id))->assertRedirect();
        $this->assertSame([$this->alexandre->id, $this->alexandre->id], [SuporteAvaliacao::sole()->atendente_id, SuporteAvaliacao::sole()->encerrado_por_id]);

        $this->chega('1 ninguém veio', 'wamid.2');
        $this->assertSame(1, SuporteAvaliacao::sole()->nota);
        $this->assertSame(2, Notificacao::pluck('titulo')->filter(fn ($t) => str_starts_with($t, 'Avaliação 1 (Péssimo)'))->count(), 'nota baixa avisa toda a equipe');

        // Encerrado de novo, e o cliente escreve outra coisa: conversa nova, com o Pedro.
        $this->actingAs($this->alexandre)->post(route('suporte.encerrar', $c->id))->assertRedirect();
        // O pedido do começo foi descartado quando o Alexandre assumiu (no runner real, ao voltar).
        SuportePedido::query()->update(['situacao' => 'descartado']);
        $antes = SuportePedido::count();
        $this->chega('outro problema: a câmera', 'wamid.3');
        $this->assertSame('pedro', $c->fresh()->situacao);
        $this->assertSame($antes + 1, SuportePedido::count());
        $this->assertNull(SuporteAvaliacao::latest('id')->first()->nota);
    }
}
