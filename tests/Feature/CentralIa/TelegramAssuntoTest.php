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

    public function test_botao_do_telegram_num_assunto_aponta_o_assunto(): void
    {
        config(['central_ia.canal_exclusivo' => true]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => [], 'visto_em' => now()]);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 702, 'vinculado_em' => now(), 'projeto_atual' => 'gestor']);
        $vix = CentralIaTarefa::create(['user_id' => $u->id, 'tipo' => 'assunto', 'titulo' => 'Proposta Comercial VixCard', 'objetivo' => 'x', 'projeto' => '', 'ultima_atividade_em' => now()]);

        $this->actingAs($u)->postJson(route('central-ia.canal'), ['canal' => 'telegram', 'projeto' => null, 'tarefa_id' => $vix->id])->assertOk();
        $v = CentralIaTelegramVinculo::where('chat_id', 702)->first();
        $this->assertSame([$vix->id, null], [$v->assunto_atual_id, $v->projeto_atual]);

        $this->falar(702, 'o Lorenzo respondeu?');
        $this->assertSame($vix->id, CentralIaExecucao::latest('id')->value('tarefa_id'), 'a mensagem vai para o assunto, não para a Geral');
    }

    public function test_continuar_numero_traz_o_chat_pelo_numero_do_chat_ou_do_pedido(): void
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 703, 'vinculado_em' => now()]);
        $vix = CentralIaTarefa::create(['user_id' => $u->id, 'tipo' => 'assunto', 'titulo' => 'Proposta Comercial VixCard', 'objetivo' => 'x', 'projeto' => '', 'ultima_atividade_em' => now(),
            'dossie' => ['duvida' => 'Posso salvar como v3?']]);
        $pedido = app(\App\Services\CentralIa\AgentesService::class)->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $vix->id, 'agente' => 'claude', 'modo' => 'analisar', 'instrucao' => 'x'], 'web', 'c1');
        // O agente parou perguntando (o pedido limpa a dúvida anterior; esta é a nova).
        $vix->update(['dossie' => array_merge($vix->fresh()->dossie, ['duvida' => 'Posso salvar como v3?'])]);
        $gestor = CentralIaTarefa::create(['user_id' => $u->id, 'titulo' => 'Relatório de custos', 'objetivo' => 'x', 'projeto' => 'gestor', 'ultima_atividade_em' => now()]);

        // Pelo número do PEDIDO: vai para o chat dele.
        $this->falar(703, 'Continuar #'.$pedido->id);
        $this->respondeu('Continuando “Proposta Comercial VixCard” aqui');
        $this->respondeu('Esperando você: Posso salvar como v3?');
        $this->assertSame($vix->id, CentralIaTelegramVinculo::where('chat_id', 703)->value('assunto_atual_id'));

        // Pelo número do chat comum: vira o projeto em uso, e o assunto sai.
        $this->falar(703, '/continuar #'.$gestor->id);
        $v = CentralIaTelegramVinculo::where('chat_id', 703)->first();
        $this->assertSame(['gestor', null], [$v->projeto_atual, $v->assunto_atual_id]);

        $outro = User::factory()->create();
        $alheio = CentralIaTarefa::create(['user_id' => $outro->id, 'tipo' => 'assunto', 'titulo' => 'Do outro', 'objetivo' => 'x', 'projeto' => '', 'ultima_atividade_em' => now()]);
        $this->falar(703, 'continuar '.$alheio->id);
        $this->respondeu('Não achei o chat nem o pedido #'.$alheio->id);
        $this->assertSame('gestor', CentralIaTelegramVinculo::where('chat_id', 703)->value('projeto_atual'));
    }

    /** Com o canal exclusivo LIGADO (padrão de produção) — revisão de 13/10/2026. */
    public function test_canal_exclusivo_assunto_com_projeto_e_chat_da_geral_recebem_as_mensagens(): void
    {
        config(['central_ia.canal_exclusivo' => true]);
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['gestor'], 'visto_em' => now()]);
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 704, 'vinculado_em' => now()]);
        $sg = CentralIaTarefa::create(['user_id' => $u->id, 'tipo' => 'assunto', 'titulo' => 'Gestor.SG', 'objetivo' => 'x', 'projeto' => 'gestor', 'ultima_atividade_em' => now()]);
        $geral = CentralIaTarefa::create(['user_id' => $u->id, 'titulo' => 'Resumo da semana', 'objetivo' => 'x', 'projeto' => '', 'ultima_atividade_em' => now()]);
        app(\App\Services\CentralIa\CanalAtivo::class)->transferir($u, 'telegram');

        // Assunto COM projeto: o pedido não é recusado e cai nele.
        $this->falar(704, '/assunto gestor');
        $this->falar(704, 'liste os orçamentos em aberto');
        $e = CentralIaExecucao::latest('id')->first();
        $this->assertSame([$sg->id, 'gestor'], [$e?->tarefa_id, $e?->projeto]);
        // E a resposta pode ser entregue no Telegram.
        $resposta = \App\Models\CentralIaMensagem::create(['conversa_id' => \App\Models\CentralIaMensagem::where('tarefa_id', $sg->id)->value('conversa_id'),
            'user_id' => $u->id, 'projeto' => 'gestor', 'tarefa_id' => $sg->id, 'autor_tipo' => CentralIaMensagem::AUTOR_AGENTE, 'autor_nome' => 'Claude', 'origem' => 'agente', 'conteudo' => 'ok']);
        $this->assertTrue(app(\App\Services\CentralIa\CanalAtivo::class)->entregaPermitida($u, $resposta));

        // Chat comum da Geral trazido por "continuar #N": "pode seguir" vai para ele, não vira anotação.
        $this->falar(704, 'continuar #'.$geral->id);
        $this->falar(704, 'refaz com mais detalhes');
        $this->assertSame($geral->id, CentralIaExecucao::latest('id')->value('tarefa_id'));
    }
}
