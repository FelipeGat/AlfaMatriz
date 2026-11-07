<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\ProgressoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fila por projeto sem espera parada (docs/specs/central-ia-fila-por-projeto).
 * A trava de uma implementação por projeto continua; o que muda é que a vaga
 * livre faz o levantamento do pedido em espera, e a pessoa pode pôr um pedido
 * na frente, pausando o que ocupa a pasta e retomando-o depois.
 *
 * Caso real (07/11/2026): o #823 (chat Matriz) esperou 24 min com três vagas
 * livres pelo #822 (chat Pedro(IA)), os dois em `alfamatriz`.
 */
class FilaPorProjetoTest extends TestCase
{
    use RefreshDatabase;

    private AgentesService $agentes;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false, 'central_ia.agentes.paralelo' => 4, 'central_ia.agentes.implementar_na_pasta' => true,
            'central_ia.agentes.levantamento_na_espera' => true, 'central_ia.agentes.acesso' => 'total']);
        $this->agentes = app(AgentesService::class);
    }

    private function comCentral(): User
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex'], 'projetos' => ['alfamatriz', 'gestor'], 'visto_em' => now()]);
        \Illuminate\Support\Facades\DB::table('central_ia_preferencias')->insert(['user_id' => $u->id,
            'regras' => json_encode(['paralelo' => 4]), 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    private function runner(User $u): CentralIaRunner
    {
        return CentralIaRunner::where('user_id', $u->id)->first();
    }

    /** Um chat de assunto no projeto, com um pedido de implementar na fila. */
    private function implementar(User $u, string $chat, string $texto, string $projeto = 'alfamatriz'): CentralIaExecucao
    {
        $id = $this->actingAs($u)->postJson(route('central-ia.assuntos.criar'), ['titulo' => $chat, 'projeto' => $projeto])->assertCreated()->json('id');

        return $this->agentes->pedir($u, $texto, ['projeto' => $projeto, 'tarefa_id' => $id, 'agente' => 'claude', 'modo' => 'implementar', 'instrucao' => $texto], 'web', $chat);
    }

    private function concluir(User $u, int $id, string $texto, array $bloco = [], array $extra = []): void
    {
        $this->agentes->concluir($u, $id, $extra + ['status' => CentralIaExecucao::CONCLUIDA,
            'resposta' => $texto."\n<<<CENTRAL-IA\n".json_encode($bloco + ['resumo' => 'ok', 'tarefa_concluida' => false])."\nCENTRAL-IA>>>"]);
    }

    /** O runner manda o andamento, dizendo que sabe pausar; devolve o `pausar` da resposta. */
    private function andamento(User $u, int $id, int $sequencia = 1): bool
    {
        Sanctum::actingAs($u, ['central-ia-runner']);

        return (bool) $this->postJson(route('central-ia.runner.progresso', $id), ['maquina' => 'wsl', 'sequencia' => $sequencia,
            'etapa' => 'agente', 'processo_ativo' => true, 'pode_pausar' => true])->assertOk()->json('pausar');
    }

    public function test_vaga_livre_faz_o_levantamento_so_leitura_do_pedido_que_espera_o_projeto(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'corrija a identificação do cliente');
        $matriz = $this->implementar($u, 'Matriz', 'notificações uma a uma em Acompanhamentos');

        $this->assertSame($pedro->id, $this->agentes->proxima($u, $this->runner($u))['id']);
        $job = $this->agentes->proxima($u, $this->runner($u));

        $this->assertNotNull($job, 'a vaga livre não fica ociosa com o projeto ocupado');
        $this->assertNotSame($matriz->id, $job['id'], 'o original continua na fila; quem roda é o levantamento');
        $this->assertSame('analisar', $job['modo']);
        $this->assertSame('claude', $job['agente'], 'a MESMA IA, para a implementação continuar a sessão dela');
        $this->assertSame('leitura', $job['acesso'], 'só leitura, mesmo com acesso total na conta');
        $this->assertSame('notificações uma a uma em Acompanhamentos', $job['instrucao']);
        $this->assertStringContainsString('FASE 1 DE 2', $job['contexto']);
        $this->assertSame(CentralIaExecucao::PENDENTE, $matriz->fresh()->status);
        $this->assertSame($job['id'], $matriz->fresh()->roteamento['levantamento']);
        $this->assertStringContainsString('«Pedro(IA)» está implementando em alfamatriz', CentralIaMensagem::where('tarefa_id', $matriz->tarefa_id)->where('autor_tipo', 'sistema')->value('conteudo'));

        $this->assertNull($this->agentes->proxima($u, $this->runner($u)), 'um levantamento por pedido, e o original espera a pasta');
    }

    public function test_depois_do_levantamento_a_implementacao_continua_a_sessao_com_a_nota_curta(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'corrija a identificação');
        $matriz = $this->implementar($u, 'Matriz', 'notificações');
        $this->agentes->proxima($u, $this->runner($u));
        $levantamento = $this->agentes->proxima($u, $this->runner($u))['id'];
        $this->concluir($u, $levantamento, 'Achei: falta a seção em Acompanhamentos. Plano: …', extra: ['sessao_agente' => 'sessao-do-levantamento', 'checkpoint' => ['cwd' => '/p/alfamatriz']]);

        $this->assertStringContainsString('🔎 Levantamento (só leitura)', CentralIaMensagem::where('tarefa_id', $matriz->tarefa_id)->where('autor_tipo', 'agente')->value('conteudo'));
        $this->assertSame(CentralIaExecucao::PENDENTE, $matriz->fresh()->status);
        $this->assertNull($this->agentes->proxima($u, $this->runner($u)), 'a pasta continua ocupada');

        $this->concluir($u, $pedro->id, 'Pronto.');
        $job = $this->agentes->proxima($u, $this->runner($u));
        $this->assertSame($matriz->id, $job['id']);
        $this->assertSame('implementar', $job['modo']);
        $this->assertSame(['sessao' => 'sessao-do-levantamento', 'cwd' => '/p/alfamatriz', 'fork' => false], $job['continuar'],
            'continua a conversa do levantamento, mesmo sendo chat de assunto');
        $this->assertStringContainsString('FASE 2 DE 2', $job['retomada']['contexto']);
        $this->assertStringContainsString('git status', $job['retomada']['contexto']);
        $this->assertStringContainsString('FASE 2 DE 2', $job['contexto'], 'o runner que não retomar ainda recebe o histórico e a nota');
        $this->assertSame('total', $job['acesso']);
    }

    public function test_levantamento_com_duvida_tira_a_implementacao_da_fila(): void
    {
        $u = $this->comCentral();
        $this->implementar($u, 'Pedro(IA)', 'x');
        $matriz = $this->implementar($u, 'Matriz', 'y');
        $this->agentes->proxima($u, $this->runner($u));
        $levantamento = $this->agentes->proxima($u, $this->runner($u))['id'];

        $this->concluir($u, $levantamento, 'Preciso saber uma coisa.', ['duvida' => 'Vale para todas as contas?', 'opcoes_duvida' => ['Todas', 'Só a minha']]);

        $this->assertSame(CentralIaExecucao::EXPIRADA, $matriz->fresh()->status);
        $this->assertSame(CentralIaTarefa::AGUARDANDO_VOCE, CentralIaTarefa::find($matriz->tarefa_id)->status);
        $this->assertStringContainsString('o levantamento terminou com uma pergunta', CentralIaMensagem::where('tarefa_id', $matriz->tarefa_id)->latest('id')->value('conteudo'));
    }

    public function test_levantamento_que_falha_nao_troca_de_ia_nem_escala_e_a_implementacao_segue(): void
    {
        $u = $this->comCentral();
        $this->implementar($u, 'Pedro(IA)', 'x');
        $matriz = $this->implementar($u, 'Matriz', 'y');
        $this->agentes->proxima($u, $this->runner($u));
        $levantamento = $this->agentes->proxima($u, $this->runner($u))['id'];

        $this->agentes->concluir($u, $levantamento, ['status' => CentralIaExecucao::FALHOU, 'erro' => 'You have hit your weekly limit']);

        $this->assertSame(CentralIaExecucao::PENDENTE, $matriz->fresh()->status);
        $this->assertSame(1, CentralIaExecucao::where('tarefa_id', $matriz->tarefa_id)->where('status', CentralIaExecucao::PENDENTE)->count(),
            'nenhuma troca de provedor nem escalada nasceu do levantamento');
        $this->assertStringContainsString('começa do zero', CentralIaMensagem::where('tarefa_id', $matriz->tarefa_id)->latest('id')->value('conteudo'));
    }

    public function test_sem_levantamento_com_a_opcao_desligada(): void
    {
        $u = $this->comCentral();
        $this->implementar($u, 'Pedro(IA)', 'x');
        $this->implementar($u, 'Matriz', 'y');
        $this->agentes->proxima($u, $this->runner($u));

        config(['central_ia.agentes.levantamento_na_espera' => false]);
        $this->assertNull($this->agentes->proxima($u, $this->runner($u)));
    }

    public function test_motivo_da_espera_na_tela(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'x');
        $matriz = $this->implementar($u, 'Matriz', 'y');
        config(['central_ia.agentes.levantamento_na_espera' => false]);
        $this->agentes->proxima($u, $this->runner($u));

        $linha = collect($this->actingAs($u)->getJson(route('central-ia.painel'))->assertOk()->json('atividade'))->firstWhere('id', $matriz->id);
        $this->assertSame('Na fila — o projeto alfamatriz está ocupado com «Pedro(IA)»', $linha['espera']);
        $this->assertFalse($linha['pode_passar_na_frente'], 'a máquina ainda não disse que sabe pausar');

        $this->andamento($u, $pedro->id);
        $linha = collect($this->actingAs($u)->getJson(route('central-ia.painel'))->json('atividade'))->firstWhere('id', $matriz->id);
        $this->assertTrue($linha['pode_passar_na_frente']);
    }

    public function test_passar_na_frente_pausa_o_ocupante_e_retoma_depois_com_a_sessao(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'corrija a identificação');
        $outro = $this->implementar($u, 'Gestor', 'outro projeto', 'gestor');
        $urgente = $this->implementar($u, 'Matriz', 'urgente');
        config(['central_ia.agentes.levantamento_na_espera' => false]);
        $this->agentes->proxima($u, $this->runner($u));
        $this->agentes->proxima($u, $this->runner($u)); // o do gestor roda junto
        $this->assertFalse($this->andamento($u, $pedro->id));

        $this->actingAs($u)->postJson(route('central-ia.execucoes.passar-na-frente', $urgente->id))->assertOk()->assertJsonPath('prioridade', 1);
        $this->assertStringContainsString('vai pausar aqui', CentralIaMensagem::where('tarefa_id', $pedro->tarefa_id)->latest('id')->value('conteudo'));
        $linha = collect($this->getJson(route('central-ia.painel'))->json('atividade'))->firstWhere('id', $urgente->id);
        $this->assertStringStartsWith('Passando na frente — pausando «Pedro(IA)»', $linha['espera']);
        $this->assertFalse($linha['pode_passar_na_frente']);

        $this->assertTrue($this->andamento($u, $pedro->id, 2), 'a máquina fica sabendo pela resposta do andamento');
        $this->agentes->concluir($u, $pedro->id, ['status' => CentralIaExecucao::PAUSADA, 'sessao_agente' => 'sessao-do-pedro', 'checkpoint' => ['cwd' => '/p/alfamatriz']]);

        $this->assertSame(CentralIaExecucao::PAUSADA, $pedro->fresh()->status);
        $retomada = CentralIaExecucao::where('tarefa_id', $pedro->tarefa_id)->where('status', CentralIaExecucao::PENDENTE)->sole();
        $this->assertSame($pedro->id, $retomada->roteamento['retomada_de']);
        $this->assertSame('corrija a identificação', $retomada->instrucao);
        $this->assertSame(1, $retomada->prioridade);

        $job = $this->agentes->proxima($u, $this->runner($u));
        $this->assertSame($urgente->id, $job['id'], 'o urgente sai antes da retomada');
        $this->assertNull($this->agentes->proxima($u, $this->runner($u)), 'a retomada espera a pasta (e não ganha levantamento)');

        $this->concluir($u, $urgente->id, 'Feito.');
        $job = $this->agentes->proxima($u, $this->runner($u));
        $this->assertSame($retomada->id, $job['id']);
        $this->assertSame(['sessao' => 'sessao-do-pedro', 'cwd' => '/p/alfamatriz', 'fork' => false], $job['continuar']);
        $this->assertStringContainsString('RETOMADA', $job['retomada']['contexto']);
        $this->assertSame(CentralIaExecucao::EXECUTANDO, $outro->fresh()->status, 'o outro projeto nem percebeu');
    }

    public function test_passar_na_frente_com_levantamento_rodando_para_ele_primeiro(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'x');
        $urgente = $this->implementar($u, 'Matriz', 'urgente');
        $this->agentes->proxima($u, $this->runner($u));
        $levantamento = $this->agentes->proxima($u, $this->runner($u))['id'];
        $this->andamento($u, $pedro->id);

        $this->actingAs($u)->postJson(route('central-ia.execucoes.passar-na-frente', $urgente->id))->assertOk();
        $this->assertFalse($this->andamento($u, $pedro->id, 2), 'o ocupante só pausa depois de o levantamento parar');
        $this->assertTrue($this->andamento($u, $levantamento, 1));

        $this->agentes->concluir($u, $levantamento, ['status' => CentralIaExecucao::PAUSADA, 'sessao_agente' => 'sessao-lev', 'checkpoint' => ['cwd' => '/p/alfamatriz']]);
        $this->assertSame(0, CentralIaExecucao::where('roteamento->retomada_de', $levantamento)->count(), 'levantamento pausado não ganha retomada');
        $this->assertTrue($this->andamento($u, $pedro->id, 3), 'agora sim o ocupante pausa');

        $this->agentes->concluir($u, $pedro->id, ['status' => CentralIaExecucao::PAUSADA, 'sessao_agente' => 'sessao-do-pedro']);
        $job = $this->agentes->proxima($u, $this->runner($u));
        $this->assertSame($urgente->id, $job['id']);
        $this->assertSame('sessao-lev', $job['continuar']['sessao'], 'continua a conversa do levantamento interrompido');
    }

    public function test_passar_na_frente_recusa_quem_nao_espera_projeto_e_conta_alheia(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'x');
        $this->agentes->proxima($u, $this->runner($u));
        $this->andamento($u, $pedro->id);

        $this->actingAs($u)->postJson(route('central-ia.execucoes.passar-na-frente', $pedro->id))->assertStatus(422);
        $sozinho = $this->implementar($u, 'Gestor', 'outro projeto', 'gestor');
        $this->actingAs($u)->postJson(route('central-ia.execucoes.passar-na-frente', $sozinho->id))->assertStatus(422);

        $espera = $this->implementar($u, 'Matriz', 'y');
        $outra = $this->comCentral();
        $this->actingAs($outra)->postJson(route('central-ia.execucoes.passar-na-frente', $espera->id))->assertNotFound();
        $this->assertSame(0, $espera->fresh()->prioridade);
        $this->assertArrayNotHasKey('pausar', $pedro->fresh()->roteamento ?? []);
    }

    public function test_maquina_que_nao_sabe_pausar_nao_recebe_o_pedido(): void
    {
        $u = $this->comCentral();
        $pedro = $this->implementar($u, 'Pedro(IA)', 'x');
        $espera = $this->implementar($u, 'Matriz', 'y');
        $this->agentes->proxima($u, $this->runner($u));
        app(ProgressoService::class)->registrar($u, 'wsl', $pedro->id, ['sequencia' => 1, 'etapa' => 'agente', 'processo_ativo' => true]);

        $this->actingAs($u)->postJson(route('central-ia.execucoes.passar-na-frente', $espera->id))->assertStatus(422)
            ->assertJsonPath('message', 'A máquina que roda o outro pedido ainda não sabe pausar: atualize o runner dela.');
    }
}
