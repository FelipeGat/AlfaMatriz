<?php

namespace Tests\Feature\CentralIa;

use App\Models\{CentralIaExecucao, CentralIaMensagem, CentralIaTarefa, CentralIaTelegramVinculo, Perfil, User};
use App\Services\CentralIa\ConversaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HistoricoCompartilhadoTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        return $u;
    }

    private function tarefa(User $u, string $projeto): CentralIaTarefa
    {
        return CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => $projeto, 'titulo' => 'Teste', 'objetivo' => 'Teste']);
    }

    public function test_atalho_indica_a_ultima_conversa_real_da_propria_conta(): void
    {
        $u = $this->pessoa(); $outro = $this->pessoa();
        $t = $this->tarefa($u, 'alfamatriz'); $alheia = $this->tarefa($outro, 'gestor');
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 100, 'projeto_atual' => 'alfamatriz-felipe-central-ia']);
        $c = app(ConversaService::class);
        $m = $c->registrarDoUsuario($u, 'Pedido pelo Telegram', 'telegram', telegramUpdateId: 1, projeto: 'alfamatriz', tarefaId: $t->id);
        $c->registrarDoUsuario($outro, 'Mensagem privada', 'telegram', telegramUpdateId: 2, projeto: 'gestor', tarefaId: $alheia->id);
        $c->registrarDoAgente($u, 'Codex', 'Resposta compartilhada', projeto: 'alfamatriz', tarefaId: $t->id);
        $this->actingAs($u)->getJson(route('central-ia.painel'))->assertOk()
            ->assertJsonPath('conta_nome', $u->name)->assertJsonPath('telegram.projeto', 'alfamatriz')
            ->assertJsonPath('telegram.tarefa_id', $t->id)->assertJsonPath('telegram.mensagem_id', $m->id)
            ->assertJsonMissingPath('telegram.chat_id');
        $this->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz']))->assertJsonCount(2, 'mensagens')
            ->assertJsonPath('mensagens.0.conteudo', 'Pedido pelo Telegram')->assertJsonPath('mensagens.1.conteudo', 'Resposta compartilhada');
        $this->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz-felipe-central-ia']))->assertJsonCount(0, 'mensagens');
        $this->assertSame(0, CentralIaExecucao::count(), 'Consultar histórico não aciona IA.');
        $this->assertSame('alfamatriz-felipe-central-ia', CentralIaTelegramVinculo::where('user_id', $u->id)->value('projeto_atual'), 'Navegação web não muda contexto do bot.');
    }

    public function test_conversa_geral_preserva_projeto_nulo_e_sem_vinculo_nao_expoe_metadados(): void
    {
        $u = $this->pessoa();
        CentralIaTelegramVinculo::create(['user_id' => $u->id, 'chat_id' => 100, 'projeto_atual' => 'alfamatriz']);
        app(ConversaService::class)->registrarDoUsuario($u, 'Anotação na Geral', 'telegram', telegramUpdateId: 1);
        $this->actingAs($u)->getJson(route('central-ia.painel'))->assertJsonPath('telegram.projeto', null)->assertJsonPath('telegram.tarefa_id', null);
        $this->actingAs($this->pessoa())->getJson(route('central-ia.painel'))->assertJsonPath('telegram.vinculado', false)
            ->assertJsonPath('telegram.projeto', null)->assertJsonPath('telegram.mensagem_id', null);
    }

    public function test_paginacao_de_tarefa_com_mais_de_50_mensagens_e_poll_incremental(): void
    {
        $u = $this->pessoa(); $t = $this->tarefa($u, 'alfamatriz');
        $c = app(ConversaService::class); $ids = [];
        for ($i = 0; $i < 61; $i++) {
            $ids[] = $c->registrarDoUsuario($u, "Mensagem {$i}", 'telegram', telegramUpdateId: $i + 1, projeto: 'alfamatriz', tarefaId: $t->id)->id;
        }
        $ultima = $this->actingAs($u)->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz', 'tarefa' => $t->id]))
            ->assertJsonCount(50, 'mensagens')->assertJsonPath('ha_mais', true)->json('mensagens');
        $antigas = $this->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz', 'tarefa' => $t->id, 'antes' => $ultima[0]['id']]))
            ->assertJsonCount(11, 'mensagens')->assertJsonPath('ha_mais', false)->json('mensagens');
        $this->assertSame($ids, array_column(array_merge($antigas, $ultima), 'id'));
        $nova = $c->registrarDoAgente($u, 'Codex', 'Resposta nova', projeto: 'alfamatriz', tarefaId: $t->id);
        $this->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz', 'depois' => end($ids)]))
            ->assertJsonCount(1, 'mensagens')->assertJsonPath('mensagens.0.id', $nova->id);
        $this->actingAs($this->pessoa())->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz', 'tarefa' => $t->id]))->assertJsonCount(0, 'mensagens');
    }
}
