<?php

namespace Tests\Feature\CentralIa;

use App\Models\{CentralIaTarefa, Perfil, User};
use App\Services\CentralIa\ConversaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChatsAtivosTest extends TestCase
{
    use RefreshDatabase;

    private function pessoa(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        return $u;
    }

    private function tarefa(User $u, string $titulo, string $status = 'aberta'): CentralIaTarefa
    {
        return CentralIaTarefa::create(['user_id' => $u->id, 'projeto' => 'alfamatriz', 'titulo' => $titulo,
            'objetivo' => 'Teste', 'status' => $status, 'agente_atual' => 'codex', 'ultima_atividade_em' => now()]);
    }

    public function test_cinco_assuntos_do_mesmo_projeto_sao_cinco_chats_e_um_projeto(): void
    {
        $u = $this->pessoa();
        foreach (range(1, 5) as $n) $this->tarefa($u, 'Assunto '.$n);
        $this->tarefa($u, 'Encerrado', 'concluida');
        $this->tarefa($this->pessoa(), 'Privado de outro usuário');
        $r = $this->actingAs($u)->getJson(route('central-ia.painel'))->assertOk()
            ->assertJsonCount(5, 'chats')->assertJsonCount(1, 'projetos');
        $this->assertCount(5, array_unique(array_column($r->json('chats'), 'id')));
        $this->assertNotContains('Encerrado', array_column($r->json('chats'), 'titulo'));
        $this->assertNotContains('Privado de outro usuário', array_column($r->json('chats'), 'titulo'));
        $this->assertArrayNotHasKey('dossie', $r->json('chats.0'));
    }

    public function test_chat_filtra_historico_antigo_e_estados_recentes_no_servidor(): void
    {
        $u = $this->pessoa(); $a = $this->tarefa($u, 'A'); $b = $this->tarefa($u, 'B');
        $c = app(ConversaService::class);
        $antiga = $c->registrarDoAgente($u, 'Codex', 'A antiga', projeto: 'alfamatriz', tarefaId: $a->id);
        foreach (range(1, 60) as $n) $c->registrarDoAgente($u, 'Codex', 'B '.$n, projeto: 'alfamatriz', tarefaId: $b->id);
        $this->actingAs($u)->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz', 'tarefa' => $a->id]))
            ->assertOk()->assertJsonCount(1, 'mensagens')->assertJsonPath('mensagens.0.id', $antiga->id)
            ->assertJsonMissing(['conteudo' => 'B 60']);
    }

    public function test_administrador_nao_recebe_chats_privados_de_outros(): void
    {
        $dono = $this->pessoa(); $this->tarefa($dono, 'Privado');
        $admin = $this->pessoa(); $admin->perfis()->attach(Perfil::where('slug', 'administrador')->value('id'));
        $this->actingAs($admin)->getJson(route('central-ia.painel'))->assertOk()->assertJsonCount(0, 'chats');
        $this->actingAs($admin)->getJson(route('central-ia.mensagens', ['projeto' => 'alfamatriz', 'tarefa' => CentralIaTarefa::first()->id]))
            ->assertOk()->assertJsonCount(0, 'mensagens');
    }
}
