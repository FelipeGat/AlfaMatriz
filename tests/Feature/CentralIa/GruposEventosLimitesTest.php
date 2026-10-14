<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaGrupo;
use App\Models\CentralIaProjeto;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Grupos de projetos, log de eventos, limites das IAs e acesso total.
 */
class GruposEventosLimitesTest extends TestCase
{
    use RefreshDatabase;

    private const SESSAO = 'bc6adf44-a516-40dd-a659-e28fc190d90d';

    private function comCentral(array $consumo = [], array $sessoes = []): User
    {
        $user = User::factory()->create();
        $user->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        CentralIaRunner::create(['user_id' => $user->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'],
            'projetos' => ['gestor', 'alfahome'], 'consumo' => $consumo, 'sessoes' => $sessoes, 'visto_em' => now()]);

        return $user;
    }

    private function pedir(User $user, string $texto, array $extra = [])
    {
        return $this->actingAs($user)->postJson(route('central-ia.enviar'), $extra + [
            'conteudo' => $texto, 'client_id' => (string) Str::uuid(), 'projeto' => 'gestor', 'agente' => 'auto', 'modo' => 'auto',
        ]);
    }

    public function test_grupos_da_conta_e_projeto_no_grupo(): void
    {
        $user = $this->comCentral();
        $outro = $this->comCentral();

        $id = $this->actingAs($user)->postJson(route('central-ia.grupos.criar'), ['nome' => 'VixCard'])->assertCreated()->json('id');
        $this->actingAs($user)->postJson(route('central-ia.grupos.criar'), ['nome' => 'VixCard'])->assertUnprocessable();
        $this->actingAs($outro)->postJson(route('central-ia.grupos.criar'), ['nome' => 'VixCard'])->assertCreated();

        $this->actingAs($user)->postJson(route('central-ia.projetos.grupo', 'alfahome'), ['grupo_id' => $id])->assertOk();
        $this->actingAs($outro)->postJson(route('central-ia.projetos.grupo', 'alfahome'), ['grupo_id' => $id])->assertNotFound();

        $painel = $this->actingAs($user)->getJson(route('central-ia.painel'))->json();
        $this->assertSame([['id' => $id, 'nome' => 'VixCard', 'ordem' => 1]], $painel['grupos']);
        $this->assertSame($id, collect($painel['projetos'])->firstWhere('nome', 'alfahome')['grupo_id'], 'Pôr no grupo também fixa.');

        $this->actingAs($user)->putJson(route('central-ia.grupos.renomear', $id), ['nome' => 'VixCard e VIX'])->assertOk();
        $this->actingAs($outro)->deleteJson(route('central-ia.grupos.apagar', $id))->assertNotFound();
        $this->actingAs($user)->deleteJson(route('central-ia.grupos.apagar', $id))->assertOk();

        $this->assertNull(CentralIaProjeto::where('user_id', $user->id)->where('projeto', 'alfahome')->value('grupo_id'), 'Apagar o grupo não apaga o projeto.');
        $this->assertSame(1, CentralIaGrupo::count());
    }

    /**
     * "Algo deu errado, quem foi que fez, qual agente, em qual projeto."
     */
    public function test_log_registra_quem_qual_agente_projeto_e_falha(): void
    {
        $user = $this->comCentral();
        $tarefa = $this->pedir($user, 'Implemente o filtro', ['agente' => 'claude'])->json('tarefa_id');
        Sanctum::actingAs($user, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('central-ia.runner.concluir', $job['id']), ['status' => 'falhou', 'erro' => 'teste quebrou'])->assertOk();
        $this->pedir($user, 'Revise o filtro', ['agente' => 'codex', 'tarefa_id' => $tarefa, 'modo' => 'analisar']);

        $eventos = collect($this->actingAs($user)->getJson(route('central-ia.painel'))->json('eventos'));

        // A falha também dispara a escalada automática (12/10/2026), registrada no log.
        $this->assertSame(['troca_de_agente', 'pedido', 'escalada', 'falhou', 'inicio', 'pedido'], $eventos->pluck('tipo')->all());
        $falha = $eventos->firstWhere('tipo', 'falhou');
        $this->assertSame(['erro', 'Claude', 'gestor'], [$falha['nivel'], $falha['agente'], $falha['projeto']]);
        $this->assertStringContainsString('teste quebrou', $falha['descricao']);
        $this->assertStringContainsString($user->name, $eventos->last()['descricao']);

        $this->assertSame(0, DB::table('central_ia_eventos')->where('user_id', '!=', $user->id)->count());
    }

    public function test_limite_da_sessao_do_claude_desvia_e_a_semana_so_avisa(): void
    {
        $semanaCheia = ['limites_claude' => ['janelas' => [
            ['rotulo' => 'Sessão (5 h)', 'usado_pct' => 3, 'renova' => 'x'],
            ['rotulo' => 'Semana (todos os modelos)', 'usado_pct' => 100, 'renova' => 'y'],
        ]], 'coletado_em' => now()->toIso8601String()];
        $user = $this->comCentral($semanaCheia);
        $this->pedir($user, 'Corrija o filtro')->assertCreated();
        $this->assertSame('claude', CentralIaExecucao::latest('id')->value('agente'));

        $sessaoCheia = $semanaCheia;
        $sessaoCheia['limites_claude']['janelas'][0]['usado_pct'] = 100;
        CentralIaRunner::where('user_id', $user->id)->update(['consumo' => json_encode($sessaoCheia)]);
        $this->pedir($user, 'Corrija o rótulo')->assertCreated();

        $e = CentralIaExecucao::latest('id')->first();
        $this->assertSame('codex', $e->agente);
        $this->assertStringContainsString('Claude no limite', $e->roteamento['motivo']);
    }

    public function test_codex_com_cota_esgotada_sai_da_escolha_automatica(): void
    {
        $user = $this->comCentral(['cota_codex' => ['em' => now()->toIso8601String(),
            'primaria' => ['usado_pct' => 100, 'renova_em' => now()->addHour()->toIso8601String()]], 'coletado_em' => now()->toIso8601String()]);

        $this->pedir($user, 'Faça um resumo do projeto')->assertCreated();

        $this->assertSame('claude', CentralIaExecucao::value('agente'));
        $this->assertStringContainsString('Codex no limite', CentralIaExecucao::first()->roteamento['motivo']);
    }

    /**
     * O Claude da conversa no limite: o Codex assume, com o histórico dela.
     */
    public function test_continuacao_com_a_ia_de_origem_no_limite_vai_ao_codex_com_historico(): void
    {
        $user = $this->comCentral(
            ['limites_claude' => ['janelas' => [['rotulo' => 'Sessão (5 h)', 'usado_pct' => 100, 'renova' => '23h']]], 'coletado_em' => now()->toIso8601String()],
            [['agente' => 'claude', 'id' => self::SESSAO, 'projeto' => 'gestor', 'cwd' => '/home/x/projetos', 'ultima_atividade' => now()->subHour()->toIso8601String(), 'mensagens' => 403]],
        );
        $tarefa = $this->actingAs($user)->postJson(route('central-ia.sessoes.continuar'), ['agente' => 'claude', 'sessao' => self::SESSAO])->json('tarefa_id');

        $this->pedir($user, 'Siga de onde parou', ['tarefa_id' => $tarefa])->assertCreated();
        Sanctum::actingAs($user, ['central-ia-runner']);
        $job = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();

        $this->assertSame('codex', $job['agente']);
        $this->assertNull($job['continuar']);
        $this->assertSame(self::SESSAO, $job['importar']['sessao']);
        $this->assertSame('/home/x/projetos', $job['tarefa']['worktree']);
    }

    /**
     * O "continuar" do gestor foi cortado aos 15 min sem devolver a sessão
     * (10/10/2026). O pedido seguinte precisa retomar a última sessão
     * CONHECIDA, e não começar uma conversa nova sem histórico.
     */
    public function test_depois_de_execucao_cortada_retoma_a_ultima_sessao_conhecida(): void
    {
        $user = $this->comCentral();
        $tarefa = $this->pedir($user, 'Implemente o filtro', ['agente' => 'claude'])->json('tarefa_id');
        Sanctum::actingAs($user, ['central-ia-runner']);
        $j1 = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->assertSame(60, $j1['timeout_minutos'], 'Implementar tem 60 min.');
        $this->postJson(route('central-ia.runner.concluir', $j1['id']), ['status' => 'concluida', 'resposta' => 'ok',
            'sessao_agente' => 'sessao-boa', 'checkpoint' => ['cwd' => '/p/gestor']])->assertOk();

        $this->pedir($user, 'continuar', ['agente' => 'claude', 'tarefa_id' => $tarefa]);
        Sanctum::actingAs($user, ['central-ia-runner']);
        $j2 = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('central-ia.runner.concluir', $j2['id']), ['status' => 'falhou', 'erro' => 'passou de 60 minutos'])->assertOk();

        $this->pedir($user, 'continuar de novo', ['agente' => 'claude', 'tarefa_id' => $tarefa]);
        Sanctum::actingAs($user, ['central-ia-runner']);
        $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])
            ->assertJsonPath('continuar.sessao', 'sessao-boa')
            ->assertJsonPath('continuar.cwd', '/p/gestor');

        $this->pedir($user, 'Analise o desempenho', ['agente' => 'codex', 'modo' => 'analisar', 'projeto' => 'alfahome']);
        Sanctum::actingAs($user, ['central-ia-runner']);
        $this->assertSame(15, $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json('timeout_minutos'));
    }

    /**
     * "Armazenar quando quisermos" (dono, 10/10/2026): o ponto é escrito pela
     * sessão que tem o contexto, e depois dele a conversa recomeça enxuta.
     */
    public function test_ponto_salvo_resume_e_a_conversa_recomeca_enxuta(): void
    {
        $user = $this->comCentral();
        $tarefa = $this->pedir($user, 'Implemente o filtro', ['agente' => 'claude'])->json('tarefa_id');
        Sanctum::actingAs($user, ['central-ia-runner']);
        $j = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->postJson(route('central-ia.runner.concluir', $j['id']), ['status' => 'concluida', 'resposta' => 'feito', 'sessao_agente' => 'longa', 'checkpoint' => ['cwd' => '/p']]);

        $this->actingAs($user)->postJson(route('central-ia.tarefas.ponto', $tarefa))->assertCreated();
        Sanctum::actingAs($user, ['central-ia-runner']);
        $p = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->assertJsonPath('continuar.sessao', 'longa')->json();
        $this->assertSame('analisar', $p['modo']);
        $this->postJson(route('central-ia.runner.concluir', $p['id']), ['status' => 'concluida', 'resposta' => 'RESUMO: filtro pronto, falta o teste X.', 'sessao_agente' => 'longa', 'checkpoint' => ['cwd' => '/p']]);

        $this->pedir($user, 'continuar', ['agente' => 'claude', 'tarefa_id' => $tarefa]);
        Sanctum::actingAs($user, ['central-ia-runner']);
        $depois = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();

        $this->assertNull($depois['continuar'], 'Depois do ponto, sessão nova.');
        $this->assertStringContainsString('PONTO SALVO', $depois['contexto']);
        $this->assertStringContainsString('falta o teste X', $depois['contexto']);
        $this->actingAs($user)->getJson(route('central-ia.tarefas', 'gestor'))->assertJsonPath('tarefas.0.ponto.agente', 'Claude');
        $this->assertSame(1, DB::table('central_ia_eventos')->where('tipo', 'ponto_salvo')->count());
        $ponto = $this->getJson(route('central-ia.tarefas', 'gestor'))->json('tarefas.0.ponto');
        $this->assertSame((int) DB::table('central_ia_execucoes')->where('id', $p['id'])->value('resposta_id'), $ponto['mensagem_id']);
        $this->assertTrue(DB::table('central_ia_mensagens')->where('tarefa_id', $tarefa)->where('id', '<', $ponto['mensagem_id'])->exists(), 'Salvar ponto preserva as mensagens anteriores.');
    }

    /** O servidor pede `restrito` por padrão desde 14/10/2026 (T-368); `total` só configurado. */
    public function test_acesso_restrito_por_padrao_e_uma_implementacao_por_projeto(): void
    {
        $user = $this->comCentral();
        $this->pedir($user, 'Implemente o filtro', ['agente' => 'claude']);
        $this->pedir($user, 'Implemente o relatório', ['agente' => 'codex']);
        $this->pedir($user, 'Analise o desempenho', ['agente' => 'copilot', 'modo' => 'analisar']);
        Sanctum::actingAs($user, ['central-ia-runner']);

        $primeiro = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->assertSame(['restrito', true], [$primeiro['acesso'], $primeiro['na_pasta']]);

        // A 2ª implementação no gestor espera; a análise passa na frente.
        $segundo = $this->postJson(route('central-ia.runner.proxima'), ['maquina' => 'wsl'])->json();
        $this->assertSame(['copilot', 'analisar'], [$segundo['agente'], $segundo['modo']]);
    }
}
