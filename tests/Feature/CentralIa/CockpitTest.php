<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaMensagem;
use App\Models\CentralIaRunner;
use App\Models\CentralIaTarefa;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\Roteador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * O cockpit multiprojetos: tarefas, roteamento automático, dossiê, troca de
 * agente e o retrato de todos os projetos.
 */
class CockpitTest extends TestCase
{
    use RefreshDatabase;

    private const BLOCO = "\n<<<CENTRAL-IA\n%s\nCENTRAL-IA>>>";

    private function comCentral(): User
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));

        return $user;
    }

    private function maquina(User $user, array $agentes = ['claude', 'codex', 'copilot'], array $estado = []): CentralIaRunner
    {
        return CentralIaRunner::create([
            'user_id' => $user->id, 'maquina' => 'wsl', 'agentes' => $agentes,
            'projetos' => ['alfacontrol', 'alfahome', 'gestor', 'vixcard', 'alfagym'], 'estado' => $estado, 'visto_em' => now(),
        ]);
    }

    private function pedir(User $user, string $texto, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('central-ia.enviar'), $extra + [
            'conteudo' => $texto, 'client_id' => (string) Str::uuid(), 'projeto' => 'alfacontrol', 'agente' => 'auto', 'modo' => 'auto',
        ]);
    }

    /** Pega a próxima como o runner e conclui com o que se passar. */
    private function rodar(User $user, array $conclusao): array
    {
        Sanctum::actingAs($user, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();
        $this->postJson(route('central-ia.runner.concluir', $job['id']), $conclusao + ['status' => 'concluida'])->assertOk();

        return $job;
    }

    public function test_roteador_escolhe_por_tipo_de_pedido_e_explica(): void
    {
        $r = app(Roteador::class);
        $todos = ['claude', 'codex', 'copilot'];

        $this->assertSame(['claude', 'implementar'], array_values(array_intersect_key($r->decidir('Corrija o filtro do quadro', $todos), array_flip(['agente', 'modo']))));
        $this->assertSame('codex', $r->decidir('Revise o que mudou na branch', $todos)['agente']);
        $this->assertSame('codex', $r->decidir('Planeje a arquitetura do módulo', $todos)['agente']);
        $this->assertSame('copilot', $r->decidir('Qual comando para listar as rotas?', $todos)['agente']);

        $padrao = $r->decidir('Dê uma olhada no módulo de obras', $todos);
        $this->assertSame(['codex', 'analisar'], [$padrao['agente'], $padrao['modo']]);
        $this->assertStringContainsString('padrão', $padrao['motivo']);

        $semClaude = $r->decidir('Implemente a exportação', ['codex', 'copilot']);
        $this->assertSame('codex', $semClaude['agente']);
        $this->assertStringContainsString('claude não está disponível', $semClaude['motivo']);
    }

    public function test_pedido_automatico_abre_tarefa_com_motivo_e_o_seguinte_entra_nela(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);

        $tarefaId = $this->pedir($user, "Implemente o filtro por obra\nDetalhes: só obras ativas")->assertCreated()->json('tarefa_id');
        $this->pedir($user, 'Ajuste também o rótulo', ['tarefa_id' => $tarefaId])->assertCreated();

        $tarefa = CentralIaTarefa::findOrFail($tarefaId);
        $this->assertSame('Implemente o filtro por obra', $tarefa->titulo);
        $this->assertCount(2, $tarefa->dossie['pedidos']);
        $this->assertSame(2, $tarefa->execucoes()->count());
        $e = $tarefa->execucoes()->first();
        $this->assertSame(['claude', 'implementar'], [$e->agente, $e->modo]);
        $this->assertStringContainsString('implementação', $e->roteamento['motivo']);
    }

    public function test_duas_execucoes_em_paralelo_e_a_terceira_espera(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        foreach (['alfacontrol', 'gestor', 'vixcard'] as $p) {
            $this->pedir($user, "Analise o {$p}", ['projeto' => $p])->assertCreated();
        }
        Sanctum::actingAs($user, ['central-ia-runner']);

        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk();
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk();
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertNoContent();

        $this->assertSame(2, CentralIaExecucao::where('status', 'executando')->count());
        $this->actingAs($user)->getJson(route('central-ia.painel'))
            ->assertJsonPath('paralelo', 2)
            ->assertJsonCount(3, 'atividade');
    }

    /**
     * O bloco final vira dossiê e sai do texto; o git medido pelo runner
     * entra como fato; a worktree passa a ser da tarefa.
     */
    public function test_conclusao_alimenta_o_dossie_e_a_worktree_fica_com_a_tarefa(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $tarefaId = $this->pedir($user, 'Implemente o filtro')->json('tarefa_id');

        $bloco = json_encode(['resumo' => 'Filtro criado', 'decisoes' => ['Filtrar no banco, não na tela'],
            'testes' => [['comando' => 'php artisan test --filter=Filtro', 'resultado' => 'passou']],
            'proximos_passos' => ['Revisar'], 'pendencias' => [], 'riscos' => ['Índice faltando'], 'duvida' => null, 'tarefa_concluida' => false]);
        $this->rodar($user, [
            'resposta' => 'Fiz o filtro.'.sprintf(self::BLOCO, $bloco),
            'worktree' => '/home/x/projetos/.central-ia/worktrees/alfacontrol-t'.$tarefaId, 'branch' => 'central-ia/t'.$tarefaId, 'commit_base' => 'abc123',
            'checkpoint' => ['cwd' => '/home/x/wt', 'branch' => 'central-ia/t'.$tarefaId, 'head' => 'abc123', 'arquivos' => ['app/Filtro.php'], 'status' => ['?? app/Filtro.php']],
        ]);

        $tarefa = CentralIaTarefa::findOrFail($tarefaId);
        $this->assertSame('aberta', $tarefa->status);
        $this->assertSame('central-ia/t'.$tarefaId, $tarefa->branch);
        $this->assertSame('abc123', $tarefa->commit_base);
        $this->assertSame(['app/Filtro.php'], $tarefa->dossie['git']['arquivos']);
        $this->assertSame('Filtrar no banco, não na tela', $tarefa->dossie['decisoes'][0]['texto']);
        $this->assertSame(['Índice faltando'], $tarefa->dossie['riscos']);
        $this->assertSame('Fiz o filtro.', CentralIaMensagem::where('autor_tipo', 'agente')->value('conteudo'), 'O bloco não aparece na conversa.');

        $this->actingAs($user)->getJson(route('central-ia.tarefas', 'alfacontrol'))
            ->assertJsonPath('tarefas.0.testes.0.resultado', 'passou')
            ->assertJsonPath('tarefas.0.resumo', 'Filtro criado');
    }

    public function test_duvida_do_agente_poe_a_tarefa_aguardando_voce_e_acende_o_projeto(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $this->pedir($user, 'Implemente o relatório');

        $this->rodar($user, ['resposta' => 'Antes de seguir:'.sprintf(self::BLOCO, json_encode(['resumo' => 'parei', 'duvida' => 'Por mês ou por obra?']))]);

        $this->assertSame('aguardando_voce', CentralIaTarefa::value('status'));
        $projeto = collect($this->actingAs($user)->getJson(route('central-ia.painel'))->json('projetos'))->firstWhere('nome', 'alfacontrol');
        $this->assertSame('aguardando_voce', $projeto['estado']);
        $this->assertTrue($projeto['atencao']);
    }

    public function test_sem_o_bloco_o_dossie_registra_a_lacuna(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $this->pedir($user, 'Analise o módulo');

        $this->rodar($user, ['resposta' => 'Resposta solta, sem bloco.']);

        $this->assertStringContainsString('não devolveu o bloco', CentralIaTarefa::first()->dossie['lacunas'][0]);
    }

    /**
     * Troca de agente no meio: o novo recebe o dossiê com a instrução de
     * conferir o estado antes de alterar, e não herda a sessão do anterior.
     */
    public function test_troca_de_agente_entrega_o_contexto_e_pede_conferencia(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $tarefaId = $this->pedir($user, 'Implemente o filtro', ['agente' => 'claude'])->json('tarefa_id');
        $this->rodar($user, [
            'resposta' => 'Feito.'.sprintf(self::BLOCO, json_encode(['resumo' => 'Filtro pronto', 'decisoes' => ['Usar escopo do model']])),
            'sessao_agente' => 'sessao-claude', 'branch' => 'central-ia/t'.$tarefaId, 'worktree' => '/wt', 'commit_base' => 'abc',
            'checkpoint' => ['cwd' => '/wt', 'arquivos' => ['app/Models/Obra.php'], 'head' => 'abc'],
        ]);

        $this->pedir($user, 'Revise o filtro', ['tarefa_id' => $tarefaId, 'agente' => 'codex', 'modo' => 'analisar'])->assertCreated();
        $this->assertSame('claude', CentralIaExecucao::latest('id')->first()->roteamento['handoff_de']);

        Sanctum::actingAs($user, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertOk()->json();

        $this->assertSame('codex', $job['agente']);
        $this->assertNull($job['continuar'], 'A sessão do Claude não serve ao Codex.');
        $this->assertSame('/wt', $job['tarefa']['worktree']);
        $this->assertStringContainsString('ASSUMINDO esta tarefa, que estava com Claude', $job['contexto']);
        $this->assertStringContainsString('Usar escopo do model', $job['contexto']);
        $this->assertStringContainsString('app/Models/Obra.php', $job['contexto']);
        $this->assertSame('codex', CentralIaTarefa::find($tarefaId)->agente_atual);
    }

    public function test_depois_de_falha_outro_agente_assume_com_o_git_medido(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $tarefaId = $this->pedir($user, 'Implemente a exportação', ['agente' => 'claude'])->json('tarefa_id');
        Sanctum::actingAs($user, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('central-ia.runner.concluir', $job['id']), ['status' => 'falhou', 'erro' => 'interrompida porque o runner foi reiniciado',
            'checkpoint' => ['cwd' => '/wt', 'arquivos' => ['app/Export.php']]])->assertOk();

        $this->assertSame('falhou', CentralIaTarefa::find($tarefaId)->status);

        $this->pedir($user, 'Continue a exportação', ['tarefa_id' => $tarefaId, 'agente' => 'codex', 'modo' => 'implementar'])->assertCreated();
        Sanctum::actingAs($user, ['central-ia-runner']);
        $novo = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();

        $this->assertStringContainsString('app/Export.php', $novo['contexto']);
        $this->assertStringContainsString('ASSUMINDO', $novo['contexto']);
    }

    public function test_painel_mostra_projetos_com_estado_git_e_so_os_da_conta(): void
    {
        $user = $this->comCentral();
        $outro = $this->comCentral();
        $this->maquina($user, estado: ['alfahome' => ['git' => true, 'branch' => 'main', 'pendentes' => 3, 'sessoes' => ['claude']]]);
        $this->maquina($outro);
        $this->pedir($user, 'Analise', ['projeto' => 'gestor']);
        $this->pedir($outro, 'Analise', ['projeto' => 'vixcard']);
        $this->actingAs($user)->postJson(route('central-ia.fixar', 'alfahome'))->assertOk()->assertJsonPath('fixado', true);

        $painel = $this->actingAs($user)->getJson(route('central-ia.painel'))->assertOk()->json();
        $nomes = array_column($painel['projetos'], 'nome');

        $this->assertEqualsCanonicalizing(['alfahome', 'gestor'], $nomes);
        $alfahome = collect($painel['projetos'])->firstWhere('nome', 'alfahome');
        $this->assertSame([3, ['claude']], [$alfahome['git']['pendentes'], $alfahome['git']['sessoes']]);
        $this->assertSame(['gestor'], array_column($painel['atividade'], 'projeto'));

        $this->actingAs($user)->postJson(route('central-ia.fixar', 'alfahome'))->assertJsonPath('fixado', false);
        $this->actingAs($user)->postJson(route('central-ia.fixar', 'nao-existe'))->assertNotFound();
    }

    public function test_batida_guarda_so_o_estado_permitido(): void
    {
        $user = $this->comCentral();
        Sanctum::actingAs($user, ['central-ia-runner']);

        $this->postJson(route('central-ia.runner.batida'), ['maquina' => 'wsl', 'agentes' => ['claude'], 'projetos' => ['alfahome'],
            'estado' => ['alfahome' => ['git' => true, 'pendentes' => 2, 'segredo' => 'x', 'sessoes' => ['claude', 'bash']], 'intruso' => ['git' => true]],
        ])->assertOk();

        $estado = CentralIaRunner::first()->estado;
        $this->assertSame(['alfahome'], array_keys($estado));
        $this->assertArrayNotHasKey('segredo', $estado['alfahome']);
        $this->assertSame(['claude'], $estado['alfahome']['sessoes']);
    }

    public function test_tarefa_nao_encerra_com_pedido_na_fila_e_e_so_da_conta(): void
    {
        $user = $this->comCentral();
        $outro = $this->comCentral();
        $this->maquina($user);
        $tarefaId = $this->pedir($user, 'Analise')->json('tarefa_id');

        $this->actingAs($user)->postJson(route('central-ia.tarefas.status', $tarefaId), ['status' => 'concluida'])->assertUnprocessable();
        $this->actingAs($outro)->postJson(route('central-ia.tarefas.status', $tarefaId), ['status' => 'pausada'])->assertNotFound();

        $this->rodar($user, ['resposta' => 'ok']);
        $this->actingAs($user)->postJson(route('central-ia.tarefas.status', $tarefaId), ['status' => 'concluida'])->assertOk();
        $this->assertSame('concluida', CentralIaTarefa::find($tarefaId)->status);
    }

    public function test_tarefa_de_outra_conta_nao_recebe_pedido(): void
    {
        $user = $this->comCentral();
        $outro = $this->comCentral();
        $this->maquina($user);
        $this->maquina($outro);
        $tarefaId = $this->pedir($user, 'Analise')->json('tarefa_id');

        $this->pedir($outro, 'Mexa na tarefa dele', ['tarefa_id' => $tarefaId])->assertUnprocessable()->assertJsonValidationErrors('tarefa_id');
        $this->actingAs($outro)->getJson(route('central-ia.tarefas', 'alfacontrol'))->assertJsonCount(0, 'tarefas');
    }

    public function test_conversa_e_separada_por_projeto(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);
        $this->pedir($user, 'No alfacontrol');
        $this->pedir($user, 'No gestor', ['projeto' => 'gestor']);
        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => 'Geral', 'client_id' => (string) Str::uuid()])->assertCreated();

        $ler = fn (?string $p) => array_column($this->actingAs($user)->getJson(route('central-ia.mensagens', array_filter(['projeto' => $p])))->json('mensagens'), 'conteudo');

        $this->assertSame(['No alfacontrol'], $ler('alfacontrol'));
        $this->assertSame(['No gestor'], $ler('gestor'));
        $this->assertSame(['Geral'], $ler(null));
    }

    public function test_atalho_auto_pelo_texto(): void
    {
        $user = $this->comCentral();
        $this->maquina($user);

        $this->actingAs($user)->postJson(route('central-ia.enviar'), ['conteudo' => '/auto gestor corrija o cálculo', 'client_id' => (string) Str::uuid()])->assertCreated();

        $e = CentralIaExecucao::first();
        $this->assertSame(['gestor', 'claude', 'implementar'], [$e->projeto, $e->agente, $e->modo]);
        $this->assertSame('gestor', CentralIaMensagem::first()->projeto);
        $this->assertNotNull(app(AgentesService::class)->interpretar('/auto gestor x'));
    }
}
