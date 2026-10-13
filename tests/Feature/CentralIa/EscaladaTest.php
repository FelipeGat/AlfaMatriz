<?php

namespace Tests\Feature\CentralIa;

use App\Models\CentralIaExecucao;
use App\Models\CentralIaRunner;
use App\Models\Perfil;
use App\Models\User;
use App\Services\CentralIa\AgentesService;
use App\Services\CentralIa\Preferencias;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Escalada automática (dono, 12/10/2026): quem não resolve passa o pedido a
 * um nível — e, no máximo, a uma IA — mais capaz, sempre avisando.
 */
class EscaladaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['central_ia.canal_exclusivo' => false]);
    }

    private function comMaquina(): array
    {
        $u = User::factory()->create();
        $u->perfis()->attach(Perfil::where('slug', 'central_ia')->value('id'));
        $r = CentralIaRunner::create(['user_id' => $u->id, 'maquina' => 'wsl', 'agentes' => ['claude', 'codex', 'copilot'], 'projetos' => ['alfahome'], 'visto_em' => now()]);

        return [$u, $r];
    }

    private function pedir(User $u, string $texto, array $extra = []): CentralIaExecucao
    {
        return app(AgentesService::class)->pedir($u, $texto, $extra + ['projeto' => 'alfahome', 'agente' => 'auto', 'modo' => 'auto', 'instrucao' => $texto],
            'web', (string) \Illuminate\Support\Str::uuid());
    }

    /** O runner pega e conclui com o bloco dado. */
    private function rodar(User $u, CentralIaRunner $r, array $bloco, bool $ok = true): CentralIaExecucao
    {
        $job = app(AgentesService::class)->proxima($u, $r);
        $resposta = "Resposta.\n<<<CENTRAL-IA\n".json_encode($bloco + ['resumo' => 'tentei'])."\nCENTRAL-IA>>>";

        return app(AgentesService::class)->concluir($u, $job['id'], $ok ? ['status' => 'concluida', 'resposta' => $resposta] : ['status' => 'falhou', 'erro' => 'passou de 15 minutos e foi encerrado']);
    }

    public function test_quem_nao_conseguiu_sobe_de_nivel_com_aviso(): void
    {
        [$u, $r] = $this->comMaquina();
        $e = $this->pedir($u, 'quais branches estão abertas?');
        $this->assertSame('leve', $e->roteamento['nivel']);
        $this->rodar($u, $r, ['conseguiu' => false]);

        $nova = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertSame($e->agente, $nova->agente);
        $this->assertSame('medio', $nova->roteamento['nivel']);
        $this->assertSame($e->tarefa_id, $nova->tarefa_id);
        $this->assertStringContainsString('Escalada automática', $nova->roteamento['motivo']);
        $this->assertDatabaseHas('central_ia_mensagens', ['user_id' => $u->id, 'autor_tipo' => 'sistema', 'tarefa_id' => $e->tarefa_id]);
        $this->assertDatabaseHas('central_ia_eventos', ['user_id' => $u->id, 'tipo' => 'escalada']);
    }

    public function test_no_maximo_troca_de_ia_e_para_no_teto(): void
    {
        [$u, $r] = $this->comMaquina();
        $e = $this->pedir($u, 'implemente o filtro de status');
        $this->assertSame('forte', $e->roteamento['nivel']);
        $this->rodar($u, $r, ['conseguiu' => false]);
        $segunda = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertNotSame($e->agente, $segunda->agente, 'no máximo, outra IA assume');
        $this->assertSame('forte', $segunda->roteamento['nivel']);
        $this->assertStringContainsString('a IA mudou', \App\Models\CentralIaMensagem::find($segunda->pedido_id)->conteudo);

        $this->rodar($u, $r, ['conseguiu' => false]);
        $terceira = CentralIaExecucao::where('status', 'pendente')->sole();
        $this->assertNotContains($terceira->agente, [$e->agente, $segunda->agente]);
        $this->rodar($u, $r, ['conseguiu' => false]);
        // Sem outra IA no máximo: para e avisa.
        $this->assertSame(0, CentralIaExecucao::where('status', 'pendente')->count());
        $this->assertDatabaseHas('central_ia_eventos', ['user_id' => $u->id, 'tipo' => 'escalada_sem_alternativa']);
    }

    public function test_falha_que_nao_e_do_provedor_tambem_escala_mas_recusa_da_maquina_nao(): void
    {
        [$u, $r] = $this->comMaquina();
        $this->pedir($u, 'revise o módulo de faturamento');
        $this->rodar($u, $r, [], ok: false);
        $this->assertSame('forte', CentralIaExecucao::where('status', 'pendente')->sole()->roteamento['nivel']);

        CentralIaExecucao::where('status', 'pendente')->delete();
        $e = $this->pedir($u, 'implemente o botão', ['tarefa_id' => null]);
        $job = app(AgentesService::class)->proxima($u, $r);
        app(AgentesService::class)->concluir($u, $job['id'], ['status' => 'falhou', 'erro' => 'não executado: três códigos errados; pedido recusado pela máquina']);
        $this->assertSame(0, CentralIaExecucao::where('status', 'pendente')->count(), 'recusa do dono não é "não conseguiu"');
    }

    public function test_desligado_nos_ajustes_nao_escala(): void
    {
        [$u, $r] = $this->comMaquina();
        $regras = app(Preferencias::class)->ler($u);
        $regras['escalar'] = false;
        $this->actingAs($u)->putJson(route('central-ia.preferencias'), $regras)->assertOk();
        $this->pedir($u, 'quais branches estão abertas?');
        $this->rodar($u, $r, ['conseguiu' => false]);
        $this->assertSame(0, CentralIaExecucao::where('status', 'pendente')->count());
    }

    public function test_pedir_para_refazer_sobe_o_nivel_e_insistir_no_maximo_troca_a_ia(): void
    {
        [$u, $r] = $this->comMaquina();
        $e = $this->pedir($u, 'revise o filtro de status');
        $this->assertSame('medio', $e->roteamento['nivel']);
        $this->rodar($u, $r, ['conseguiu' => true]);

        $refaca = $this->pedir($u, 'não funcionou, refaça', ['tarefa_id' => $e->tarefa_id]);
        $this->assertSame('forte', $refaca->roteamento['nivel']);
        $this->assertStringStartsWith('Escalada: você pediu para refazer', $refaca->roteamento['motivo']);
        $this->rodar($u, $r, ['conseguiu' => true]);

        $deNovo = $this->pedir($u, 'continua errado, tente de novo', ['tarefa_id' => $e->tarefa_id]);
        $this->assertNotSame($e->agente, $deNovo->agente, 'segunda insistência no máximo troca a IA');
        $this->assertStringContainsString('a IA mudou', $deNovo->roteamento['motivo']);
    }
}
