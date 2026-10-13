<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\CentralIaTelegramVinculo;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\TelegramCanal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Chats Business, FR-019: `/assunto` no Telegram aponta para um chat de
 * assunto; o que entra por lá cai no mesmo histórico da tela.
 */
class TelegramAssuntoTest extends TestCase
{
    use RefreshDatabase;

    private int $update = 100;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false, 'central_ia.telegram.token' => 'TESTE']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);
    }

    private function falar(int $chat, string $texto): void
    {
        app(TelegramCanal::class)->receber(['update_id' => ++$this->update, 'message' => ['message_id' => $this->update,
            'chat' => ['id' => $chat, 'type' => 'private'], 'from' => ['id' => $chat, 'is_bot' => false], 'text' => $texto]]);
    }

    private function respondeu(string $trecho): void
    {
        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && str_contains((string) $r['text'], $trecho));
    }

    public function test_listar_apontar_falar_e_sair(): void
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 700, 'vinculado_em' => now()]);
        $vix = CentralIaTarefa::create(['user_id' => $u->id, 'tipo' => 'assunto', 'titulo' => 'Proposta Comercial VixCard', 'objetivo' => 'x', 'projeto' => '', 'ultima_atividade_em' => now()]);
        CentralIaTarefa::create(['user_id' => $u->id, 'tipo' => 'assunto', 'titulo' => 'Gestor.SG', 'objetivo' => 'x', 'projeto' => 'gestor', 'ultima_atividade_em' => now()->subHour()]);

        $this->falar(700, '/assunto');
        $this->respondeu('1. Proposta Comercial VixCard');

        $this->falar(700, '/assunto comercial');
        $this->respondeu('Falando em “Proposta Comercial VixCard”');
        $this->assertSame($vix->id, CentralIaTelegramVinculo::where('chat_id', 700)->value('assunto_atual_id'));

        // Mensagem comum vira pedido NO assunto (não anotação na Geral).
        $this->falar(700, 'o Lorenzo respondeu?');
        $e = CentralIaExecucao::latest('id')->first();
        $this->assertSame($vix->id, $e->tarefa_id);
        $this->assertDatabaseHas('central_ia_mensagens', ['tarefa_id' => $vix->id, 'origem' => CentralIaMensagem::ORIGEM_TELEGRAM, 'conteudo' => 'o Lorenzo respondeu?']);

        $this->falar(700, '/assunto 2');
        $this->respondeu('Falando em “Gestor.SG”');

        $this->falar(700, '/geral');
        $this->assertNull(CentralIaTelegramVinculo::where('chat_id', 700)->value('assunto_atual_id'));
    }

    public function test_assunto_arquivado_ou_de_outra_conta_nao_recebe(): void
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        $outro = User::factory()->create();
        $alheio = CentralIaTarefa::create(['user_id' => $outro->id, 'tipo' => 'assunto', 'titulo' => 'Do outro', 'objetivo' => 'x', 'projeto' => '', 'ultima_atividade_em' => now()]);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 701, 'vinculado_em' => now(), 'assunto_atual_id' => $alheio->id]);

        $this->falar(701, '/assunto Do outro');
        $this->respondeu('Nenhum chat de assunto ainda');
        $this->falar(701, 'anote isto');
        $this->assertSame(0, CentralIaExecucao::count(), 'ponteiro para assunto alheio não vira pedido');
        $this->assertSame(0, CentralIaMensagem::where('tarefa_id', $alheio->id)->count());
    }
}
