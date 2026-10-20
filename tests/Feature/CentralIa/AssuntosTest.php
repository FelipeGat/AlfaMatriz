<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaGrupo;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Chats Business (docs/specs/chats-business), fase 1: o chat de assunto
 * lembra do que foi dito, qualquer agente recebe a memória e o histórico
 * dentro do orçamento, ele nunca se conclui sozinho e é só da conta dona.
 */
class AssuntosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
    }

    private function comCentral(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex'], 'projetos' => ['gestor'], 'visto_em' => now()]);

        return $u;
    }

    private function criar(User $u, array $dados = []): int
    {
        return $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), $dados + ['titulo' => 'Comercial VixCard'])
            ->assertCreated()->json('id');
    }

    /** Um pedido no assunto, entregue ao runner: devolve o payload. */
    private function pedirNoAssunto(User $u, int $id, string $texto, string $agente = 'claude', string $cid = 'c1'): array
    {
        $a = CentralIaTarefa::find($id);
        $e = app(AgentesService::class)->pedir($u, $texto, ['projeto' => $a->projeto, 'tarefa_id' => $id, 'agente' => $agente, 'modo' => 'analisar', 'instrucao' => $texto], 'web', $cid);

        return app(AgentesService::class)->proxima($u, CentralIaRunner::where('user_id', $u->id)->first()) + ['_execucao' => $e->id];
    }

    private function responder(User $u, int $execucao, string $texto, array $bloco = []): void
    {
        $bloco += ['resumo' => 'ok', 'tarefa_concluida' => true];
        app(AgentesService::class)->concluir($u, $execucao, ['status' => CentralIaExecucao::CONCLUIDA,
            'resposta' => $texto."\n<<<CENTRAL-IA\n".json_encode($bloco)."\nCENTRAL-IA>>>"]);
    }

    public function test_cria_assunto_com_grupo_e_ele_aparece_so_na_aba_business(): void
    {
        $u = $this->comCentral();
        $g = CentralIaGrupo::create(['user_id' => $u->id, 'nome' => 'VixCard', 'ordem' => 1]);
        $id = $this->criar($u, ['grupo_id' => $g->id]);

        $painel = $this->getJson(route('central-ia.painel'))->assertOk();
        $painel->assertJsonPath('assuntos.0.id', $id)->assertJsonPath('assuntos.0.grupo_id', $g->id)->assertJsonPath('assuntos.0.projeto', '');
        $this->assertNotContains($id, array_column($painel->json('chats'), 'id'), 'assunto não é chat ativo');
        $this->assertSame('assunto', CentralIaTarefa::find($id)->tipo);
    }

    public function test_pergunta_do_agente_no_assunto_vai_no_painel(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);
        $p = $this->pedirNoAssunto($u, $id, 'crie o orçamento');
        $this->responder($u, $p['_execucao'], 'Aguardo esclarecimento.', ['tarefa_concluida' => false, 'duvida' => 'Serão duas diárias?', 'opcoes_duvida' => ['Sim', 'Não']]);

        $this->assertSame('aguardando_voce', CentralIaTarefa::find($id)->status);
        $this->actingAs($u)->getJson(route('central-ia.painel'))->assertOk()
            ->assertJsonPath('assuntos.0.duvida', 'Serão duas diárias?')->assertJsonPath('assuntos.0.opcoes_duvida', ['Sim', 'Não']);
    }

    public function test_assunto_lembra_e_outro_agente_recebe_a_mesma_memoria(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);

        $p1 = $this->pedirNoAssunto($u, $id, 'toda primeira versão de proposta vai sem valores', 'claude', 'c1');
        $this->assertStringContainsString('Comercial VixCard', $p1['contexto']);
        $this->responder($u, $p1['_execucao'], 'Combinado.', ['decisoes' => ['Primeira versão de proposta vai sem valores']]);

        $p2 = $this->pedirNoAssunto($u, $id, 'monte a proposta do cliente Teste', 'codex', 'c2');
        $this->assertStringContainsString('Primeira versão de proposta vai sem valores', $p2['contexto'], 'a decisão vai na memória');
        $this->assertStringContainsString('toda primeira versão de proposta vai sem valores', $p2['contexto'], 'o pedido anterior vai no histórico');
        $this->assertStringNotContainsString('monte a proposta do cliente Teste', $p2['contexto'], 'o pedido atual não se repete no histórico');
        $this->assertNull($p2['continuar'], 'assunto não retoma sessão interna');
        $this->assertSame('inteiro', CentralIaExecucao::find($p2['_execucao'])->roteamento['contexto']['modo']);
    }

    public function test_assunto_nunca_se_conclui_sozinho_nem_pela_tela(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);
        $p = $this->pedirNoAssunto($u, $id, 'corrija o texto da proposta');
        $this->responder($u, $p['_execucao'], 'Feito.');
        $this->assertSame(CentralIaTarefa::ABERTA, CentralIaTarefa::find($id)->status);

        $this->postJson(route('central-ia.tarefas.status', $id), ['status' => CentralIaTarefa::CONCLUIDA])->assertUnprocessable();
        $this->putJson(route('central-ia.assuntos.salvar', $id), ['status' => CentralIaTarefa::ARQUIVADA])->assertOk();
        $this->getJson(route('central-ia.painel'))->assertJsonCount(0, 'assuntos');
        $this->putJson(route('central-ia.assuntos.salvar', $id), ['status' => CentralIaTarefa::ABERTA])->assertOk();
    }

    public function test_contexto_nunca_passa_do_orcamento(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);
        $prefs = app(\App\Services\CentralIa\Preferencias::class)->ler($u);
        $prefs['assunto_contexto_tokens'] = 20000;
        $this->putJson(route('central-ia.preferencias'), $prefs)->assertOk();

        // ~100 mil tokens de histórico: muito acima do orçamento.
        $primeiro = $this->pedirNoAssunto($u, $id, 'pedido 0', 'claude', 'c0');
        $this->responder($u, $primeiro['_execucao'], 'ok');
        $conversa = CentralIaMensagem::where('tarefa_id', $id)->value('conversa_id');
        foreach (range(1, 50) as $i) {
            CentralIaMensagem::create(['conversa_id' => $conversa, 'user_id' => $u->id, 'projeto' => '', 'tarefa_id' => $id, 'autor_tipo' => CentralIaMensagem::AUTOR_USUARIO,
                'autor_nome' => 'Felipe', 'origem' => 'web', 'conteudo' => "pedido {$i} ".str_repeat('x', 6900)]);
        }
        $p = $this->pedirNoAssunto($u, $id, 'resuma o que está pendente', 'claude', 'c51');

        $resumo = CentralIaExecucao::find($p['_execucao'])->roteamento['contexto'];
        $this->assertSame('condensado', $resumo['modo']);
        $this->assertLessThanOrEqual(20000, $resumo['tokens_estimados']);
        $this->assertGreaterThan(0, $resumo['mensagens_fora']);
        $this->assertStringContainsString('pedido 50 ', $p['contexto'], 'a parte recente vai literal');
        $this->assertStringNotContainsString('pedido 1 ', $p['contexto']);
    }

    public function test_projeto_so_muda_com_o_chat_vazio_e_precisa_existir(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u, ['projeto' => 'gestor']);
        $this->putJson(route('central-ia.assuntos.salvar', $id), ['projeto' => 'inexistente'])->assertUnprocessable();
        $this->putJson(route('central-ia.assuntos.salvar', $id), ['projeto' => ''])->assertOk();
        $this->pedirNoAssunto($u, $id, 'olá');
        $this->putJson(route('central-ia.assuntos.salvar', $id), ['projeto' => 'gestor'])->assertUnprocessable();
    }

    public function test_outra_conta_nao_ve_nem_altera_e_grupo_alheio_nao_serve(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);
        $outro = $this->comCentral();
        $grupoDoOutro = CentralIaGrupo::create(['user_id' => $outro->id, 'nome' => 'Dele', 'ordem' => 1]);

        $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), ['titulo' => 'x', 'grupo_id' => $grupoDoOutro->id])->assertNotFound();

        $this->actingAs($outro)->putJson(route('central-ia.assuntos.salvar', $id), ['titulo' => 'roubado'])->assertNotFound();
        $this->getJson(route('central-ia.painel'))->assertJsonCount(0, 'assuntos');
        $this->assertSame('Comercial VixCard', CentralIaTarefa::find($id)->titulo);

        // Nem pelo runner da outra conta o assunto é entregue.
        Sanctum::actingAs($outro, ['central-ia-runner']);
        $this->assertNull(app(AgentesService::class)->proxima($outro, CentralIaRunner::where('user_id', $outro->id)->first()));
    }

    public function test_assunto_nao_entra_no_contexto_de_chat_novo_do_projeto(): void
    {
        $u = $this->comCentral();
        $this->criar($u, ['projeto' => 'gestor', 'titulo' => 'Assunto secreto do gestor']);
        $this->assertNull(app(AgentesService::class)->contextoDoProjeto($u, 'gestor', 0));
    }

    /**
     * Uma implementação por projeto, mas "Geral" (sem projeto) não é projeto:
     * dois assuntos Geral implementam juntos (Felipe, 20/10/2026: "a não ser
     * que seja o mesmo projeto, aí realmente tem que aguardar").
     */
    public function test_implementacao_espera_so_no_mesmo_projeto_e_geral_nao_trava(): void
    {
        config(['central_ia.agentes.paralelo' => 4]);
        $u = $this->comCentral();
        \Illuminate\Support\Facades\DB::table('central_ia_preferencias')->insert(['user_id' => $u->id,
            'regras' => json_encode(['paralelo' => 4]), 'created_at' => now(), 'updated_at' => now()]);
        $agentes = app(AgentesService::class);
        $ids = [];
        foreach (['Geral A', 'Geral B'] as $t) {
            $ids[] = $agentes->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $this->criar($u, ['titulo' => $t]), 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => $t], 'web', $t)->id;
        }
        foreach (['Gestor A', 'Gestor B'] as $t) {
            $ids[] = $agentes->pedir($u, 'x', ['projeto' => 'gestor', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => $t], 'web', $t)->id;
        }
        $runner = CentralIaRunner::where('user_id', $u->id)->first();

        $pegos = [];
        while (count($pegos) < 5 && ($job = $agentes->proxima($u, $runner))) $pegos[] = $job['id'];

        $this->assertSame(array_slice($ids, 0, 3), $pegos, 'os dois Geral rodam juntos; o 2º do gestor espera o 1º');
    }

    public function test_assunto_sem_projeto_aciona_ia_sempre_e_implementa_na_raiz(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);

        // Na Geral isto seria só anotado; no assunto, vai a uma IA.
        $this->postJson(route('central-ia.enviar'), ['conteudo' => 'anotar: cliente Teste pediu proposta', 'client_id' => (string) \Illuminate\Support\Str::uuid(),
            'projeto' => '', 'tarefa_id' => $id, 'agente' => 'auto', 'modo' => 'auto'])->assertSuccessful();
        $this->assertSame(1, CentralIaExecucao::where('tarefa_id', $id)->count());

        $e = app(AgentesService::class)->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $id, 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'monte a proposta'], 'web', 'imp');
        $this->assertSame('implementar', $e->modo);
        $runner = CentralIaRunner::where('user_id', $u->id)->first();
        // Os dois pedidos do chat saem um de cada vez; acha o de implementar.
        $job = null;
        for ($i = 0; $i < 2 && ($job['id'] ?? null) !== $e->id; $i++) {
            $job = app(AgentesService::class)->proxima($u, $runner);
            $this->assertNotNull($job);
            if ($job['id'] !== $e->id) app(AgentesService::class)->concluir($u, $job['id'], ['status' => CentralIaExecucao::CONCLUIDA, 'resposta' => 'ok']);
        }
        $this->assertSame($e->id, $job['id']);
        $this->assertTrue($job['na_pasta'], 'sem projeto: implementa na raiz, sem worktree');

        // Fora de assunto, a Geral continua sem implementar.
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(AgentesService::class)->pedir($u, 'x', ['projeto' => '', 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'corrija'], 'web', 'geral');
    }

    public function test_no_assunto_feedback_continua_com_quem_implementou_e_reuniao_nao_vira_agenda(): void
    {
        $u = $this->comCentral();
        $id = $this->criar($u);
        $svc = app(AgentesService::class);
        $runner = CentralIaRunner::where('user_id', $u->id)->first();
        $e = $svc->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $id, 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => 'salve a proposta como v3'], 'web', 'v3');
        $svc->proxima($u, $runner);
        $this->responder($u, $e->id, 'Proposta v3 salva.');

        $novo = $svc->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $id, 'agente' => 'auto', 'modo' => 'auto',
            'instrucao' => 'ótimo, mas está com cara de reunião, quero com cara de proposta comercial, sem informações de reunião. Tem que trazer o que será feito e valores.'], 'web', 'fb');
        $this->assertSame(['claude', 'implementar'], [$novo->agente, $novo->modo]);
        $this->assertSame([], $novo->roteamento['fontes'] ?? [], 'reunião não é consulta de agenda');
        $this->assertNotSame('sem_ia', $novo->roteamento['nivel']);

        // Pergunta não vira implementação.
        $svc->proxima($u, $runner);
        $this->responder($u, $novo->id, 'ok');
        $pergunta = $svc->pedir($u, 'x', ['projeto' => '', 'tarefa_id' => $id, 'agente' => 'auto', 'modo' => 'auto', 'instrucao' => 'quanto ficou o total?'], 'web', 'pq');
        $this->assertSame('claude', $pergunta->agente);
        $this->assertNotSame('implementar', $pergunta->modo);
    }
}
